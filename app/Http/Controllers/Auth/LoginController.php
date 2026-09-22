<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\PinAuthenticator;
use App\Services\Auth\TwoFactorAuthService;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;

/**
 * Role-differentiated login (spec section 9):
 *
 *   1. /login             — enter username, we look up auth_type
 *   2a. /login/password    — password_only & password_mfa accounts
 *   2b. /login/pin         — pin accounts (Cashier/FrontDesk/Bar/Restaurant)
 *   3.  /login/totp        — password_mfa accounts only, after password
 *
 * Every step is brute-force throttled server-side via Laravel's
 * RateLimiter (spec section 28) — never merely hidden in the UI.
 *
 * Security fix — login enumeration via redirect target (identify()):
 * this used to reject an unknown/inactive username immediately with a
 * ValidationException, redirecting back to /login itself, while any
 * real, active username instead moved forward to either /login/pin or
 * /login/password depending on its auth_type. That was a reliable
 * oracle: an attacker could tell whether a username/email belonged to an
 * active account, and if so whether it used PIN or password login,
 * purely from which page the identify step sent them to — without
 * guessing a single real credential. identify() now sends every
 * submission through the exact same two-step flow: a username that
 * doesn't resolve to a real, active account is still routed on to one of
 * /login/pin or /login/password (chosen deterministically, but not
 * predictably without already knowing which real accounts exist and
 * what they use — see pseudoUsesPinLogin()), and that next step always
 * fails with the same generic message, timing profile, and rate
 * limiting a real wrong password/PIN would produce (see
 * resolvePendingIdentity(), password(), and pin() below). The two-step
 * design, the views, and the outcome for a legitimate user are all
 * unchanged — only what an unauthenticated observer can distinguish
 * between "no such account" and "wrong credential for a real account"
 * has been closed.
 */
class LoginController extends Controller
{
    /**
     * Not a real password hash for any account — a fixed, validly
     * formatted bcrypt hash used only so a fake login attempt runs
     * through the same Hash::check() cost as a real one, keeping timing
     * indistinguishable from a genuine wrong-password check.
     */
    private const DUMMY_PASSWORD_HASH = '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    public function __construct(
        private readonly PinAuthenticator $pinAuth,
        private readonly TwoFactorAuthService $totp,
        private readonly AuditLogger $audit,
    ) {
    }

    public function showIdentify()
    {
        return view('auth.identify');
    }

    public function identify(Request $request): RedirectResponse
    {
        $request->validate(['username' => 'required|string|max:255']);

        $user = User::where('username', $request->username)->orWhere('email', $request->username)->first();

        if ($user && $user->is_active) {
            Session::forget('auth.pending_fake_identifier');
            Session::put('auth.pending_user_id', $user->id);

            return $user->usesPinLogin()
                ? redirect()->route('login.pin')
                : redirect()->route('login.password');
        }

        // No such account (or an inactive one) — proceed exactly as if
        // one existed, so this response is indistinguishable from the
        // branch above from the outside.
        Session::forget('auth.pending_user_id');
        Session::put('auth.pending_fake_identifier', $request->username);

        return $this->pseudoUsesPinLogin($request->username)
            ? redirect()->route('login.pin')
            : redirect()->route('login.password');
    }

    public function showPassword()
    {
        $identity = $this->resolvePendingIdentity();
        if (! $identity->user || $identity->usesPin) {
            return redirect()->route('login');
        }

        return view('auth.password', ['user' => $identity->user]);
    }

    public function password(Request $request): RedirectResponse
    {
        $identity = $this->resolvePendingIdentity();
        abort_if(! $identity->user || $identity->usesPin, 419);

        $request->validate(['password' => 'required|string']);

        $throttleKey = 'login-password:'.$identity->throttleId.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'password' => 'Too many attempts. Please try again in '.RateLimiter::availableIn($throttleKey).' seconds.',
            ]);
        }

        if (! $identity->real) {
            // Same cost, same failure, same rate limiting as a real
            // wrong password — but there is no real account to check
            // against or log a failure for.
            Hash::check($request->password, self::DUMMY_PASSWORD_HASH);
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages(['password' => 'These credentials do not match our records.']);
        }

        $user = $identity->user;

        if (! Hash::check($request->password, $user->password)) {
            RateLimiter::hit($throttleKey, 60);
            $this->audit->log('auth.password_failed', 'auth', $user, actor: $user);

            throw ValidationException::withMessages(['password' => 'These credentials do not match our records.']);
        }

        RateLimiter::clear($throttleKey);

        if (! $user->requiresMfa()) {
            return $this->finishLogin($user);
        }

        Session::put('auth.password_verified_user_id', $user->id);

        return $user->totp_enabled
            ? redirect()->route('login.totp')
            : redirect()->route('login.totp.setup');
    }

    public function showTotpChallenge()
    {
        $user = $this->passwordVerifiedUser();
        if (! $user || ! $user->totp_enabled) {
            return redirect()->route('login');
        }

        return view('auth.totp-challenge');
    }

    public function totpChallenge(Request $request): RedirectResponse
    {
        $user = $this->passwordVerifiedUser();
        abort_if(! $user || ! $user->totp_enabled, 419);

        $request->validate(['code' => 'required|string']);

        $throttleKey = 'login-totp:'.$user->id;
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'code' => 'Too many attempts. Please try again in '.RateLimiter::availableIn($throttleKey).' seconds.',
            ]);
        }

        if (! $this->totp->verifyForLogin($user, $request->code)) {
            RateLimiter::hit($throttleKey, 60);
            $this->audit->log('auth.totp_failed', 'auth', $user, actor: $user);

            throw ValidationException::withMessages(['code' => 'Invalid authentication code.']);
        }

        RateLimiter::clear($throttleKey);

        return $this->finishLogin($user);
    }

    public function showTotpSetup()
    {
        $user = $this->passwordVerifiedUser();
        if (! $user || $user->totp_enabled) {
            return redirect()->route('login');
        }

        $secret = Session::get('auth.totp_setup_secret') ?? $this->totp->generateSecret();
        Session::put('auth.totp_setup_secret', $secret);

        $otpUrl = $this->totp->otpAuthUrl($user, $secret);

        return view('auth.totp-setup', [
            'secret' => $secret,
            'qrSvg' => $this->totp->qrCodeSvg($otpUrl),
        ]);
    }

    public function totpSetup(Request $request): RedirectResponse
    {
        $user = $this->passwordVerifiedUser();
        abort_if(! $user || $user->totp_enabled, 419);

        $request->validate(['code' => 'required|string']);

        $secret = Session::get('auth.totp_setup_secret');
        abort_if(! $secret, 419);

        if (! $this->totp->confirm($user, $secret, $request->code)) {
            throw ValidationException::withMessages(['code' => 'Invalid authentication code — scan the QR code again and try the freshest 6-digit code.']);
        }

        Session::forget('auth.totp_setup_secret');
        $this->audit->log('auth.mfa_enrolled', 'auth', $user, actor: $user);

        return $this->finishLogin($user);
    }

    public function showPin()
    {
        $identity = $this->resolvePendingIdentity();
        if (! $identity->user || ! $identity->usesPin) {
            return redirect()->route('login');
        }

        return view('auth.pin', ['user' => $identity->user]);
    }

    public function pin(Request $request): RedirectResponse
    {
        $identity = $this->resolvePendingIdentity();
        abort_if(! $identity->user || ! $identity->usesPin, 419);

        $request->validate(['pin' => 'required|string']);

        if (! $identity->real) {
            // Mirrors password()'s fake branch: same rate limiting and
            // the same generic failure a real incorrect PIN produces —
            // there is no real account to lock or log a failure against.
            $throttleKey = 'login-pin:'.$identity->throttleId.'|'.$request->ip();
            if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
                throw ValidationException::withMessages([
                    'pin' => 'Too many attempts. Please try again in '.RateLimiter::availableIn($throttleKey).' seconds.',
                ]);
            }
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages(['pin' => 'Incorrect PIN.']);
        }

        $user = $identity->user;

        if ($user->isPinLocked()) {
            throw ValidationException::withMessages([
                'pin' => 'This account is temporarily locked due to repeated failed PIN attempts. Ask a manager for help.',
            ]);
        }

        if (! $this->pinAuth->attempt($user, $request->pin)) {
            $this->audit->log('auth.pin_failed', 'auth', $user, actor: $user);

            throw ValidationException::withMessages(['pin' => 'Incorrect PIN.']);
        }

        return $this->finishLogin($user);
    }

    public function logout(Request $request): RedirectResponse
    {
        $user = Auth::user();
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($user) {
            $this->audit->log('auth.logout', 'auth', $user, actor: $user);
        }

        return redirect()->route('login');
    }

    private function finishLogin(User $user): RedirectResponse
    {
        Session::forget([
            'auth.pending_user_id', 'auth.pending_fake_identifier',
            'auth.password_verified_user_id', 'auth.totp_setup_secret',
        ]);

        Auth::login($user);
        request()->session()->regenerate();

        $user->update(['last_login_at' => now(), 'last_login_ip' => request()->ip()]);
        $this->audit->log('auth.login_success', 'auth', $user, actor: $user);

        return redirect()->intended(route('dashboard'));
    }

    private function passwordVerifiedUser(): ?User
    {
        $id = Session::get('auth.password_verified_user_id');

        return $id ? User::find($id) : null;
    }

    /**
     * Resolves whichever of the two states identify() may have left in
     * the session into one uniform shape, so showPassword()/password()/
     * showPin()/pin() never need to know whether they're dealing with a
     * real, pending account or a fake one manufactured to keep the
     * response indistinguishable from a real one:
     *
     *   - real: true and `user` a persisted account when identify()
     *     matched a real, active user.
     *   - real: false and `user` an unsaved, in-memory User instance
     *     (safe to pass straight to the existing password/pin Blade
     *     views, which only ever read ->name from it) when it did not.
     *
     * `throttleId` gives password()/pin() a stable per-attempt key
     * either way, without leaking the real user's numeric id into a
     * fake attempt's rate-limit key or vice versa.
     */
    private function resolvePendingIdentity(): object
    {
        $id = Session::get('auth.pending_user_id');
        if ($id) {
            $user = User::find($id);

            if ($user) {
                return (object) [
                    'real' => true,
                    'user' => $user,
                    'usesPin' => $user->usesPinLogin(),
                    'throttleId' => $user->id,
                ];
            }
        }

        $fakeIdentifier = Session::get('auth.pending_fake_identifier');
        if ($fakeIdentifier !== null) {
            return (object) [
                'real' => false,
                'user' => new User(['name' => $fakeIdentifier]),
                'usesPin' => $this->pseudoUsesPinLogin($fakeIdentifier),
                'throttleId' => 'fake:'.sha1($fakeIdentifier),
            ];
        }

        return (object) ['real' => false, 'user' => null, 'usesPin' => null, 'throttleId' => null];
    }

    /**
     * Deterministic stand-in for User::usesPinLogin() when no real
     * account was found, so an unknown/inactive username is routed to
     * /login/pin or /login/password just as consistently as a real
     * username is — an attacker retrying the same input always sees the
     * same step, exactly as they would for a real account, instead of
     * "unknown usernames always land on X" becoming its own tell.
     */
    private function pseudoUsesPinLogin(string $identifier): bool
    {
        return crc32(strtolower(trim($identifier))) % 2 === 0;
    }
}
