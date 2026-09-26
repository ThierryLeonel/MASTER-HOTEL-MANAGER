<?php

use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\RoomCategoryController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SyncDashboardController;
use App\Http\Controllers\Admin\SystemDataController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Finance\AccountingDayController;
use App\Http\Controllers\Finance\AccountsReceivableController;
use App\Http\Controllers\Finance\CashDisbursementController;
use App\Http\Controllers\Finance\ExpenseController;
use App\Http\Controllers\Finance\FinancialAccountController;
use App\Http\Controllers\Finance\RefundController;
use App\Http\Controllers\Finance\ShiftController;
use App\Http\Controllers\FrontOffice\CompanyController;
use App\Http\Controllers\FrontOffice\DiscountApprovalController;
use App\Http\Controllers\FrontOffice\FacilityAccessController;
use App\Http\Controllers\FrontOffice\FacilityController;
use App\Http\Controllers\FrontOffice\CheckInController;
use App\Http\Controllers\FrontOffice\FolioController;
use App\Http\Controllers\FrontOffice\GuestController;
use App\Http\Controllers\FrontOffice\HousekeepingController;
use App\Http\Controllers\FrontOffice\MaintenanceController;
use App\Http\Controllers\FrontOffice\PaymentController;
use App\Http\Controllers\FrontOffice\ReservationController;
use App\Http\Controllers\FrontOffice\RoomController;
use App\Http\Controllers\FrontOffice\StayController;
use App\Http\Controllers\Hr\AttendanceController;
use App\Http\Controllers\Hr\EmployeeController;
use App\Http\Controllers\Hr\LeaveController;
use App\Http\Controllers\Hr\PayrollController;
use App\Http\Controllers\Inventory\GoodsReceiptController;
use App\Http\Controllers\Inventory\ProductController;
use App\Http\Controllers\Inventory\PurchaseOrderController;
use App\Http\Controllers\Inventory\RequisitionController;
use App\Http\Controllers\Inventory\StockCountController;
use App\Http\Controllers\Inventory\SupplierController;
use App\Http\Controllers\Inventory\WastageController;
use App\Http\Controllers\Pos\MenuController;
use App\Http\Controllers\Pos\OrderController;
use App\Http\Controllers\Public\WebsiteBookingController;
use App\Http\Controllers\Reporting\GuestTransactionHistoryController;
use App\Http\Controllers\Reporting\ReportController;
use App\Http\Controllers\Restaurant\BreakfastController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

// ---------------------------------------------------------------------
// Authentication — role-differentiated (spec section 9). Every step is
// its own route so it can be individually rate-limited and tested.
// ---------------------------------------------------------------------
// Security-audit fix (Phase 7): the password and TOTP-challenge attempt
// endpoints already had their own account-scoped RateLimiter checks
// in-controller, but /login (username lookup), /login/totp/setup (initial
// MFA-enrollment code confirmation), and PIN's own endpoint had no
// throttling at all beyond PIN's account-lockout counter — inconsistent
// with this file's own long-standing claim above that "every step is
// individually rate-limited." A blanket per-IP `throttle:20,1` across the
// whole unauthenticated login flow closes that gap for every current and
// future step here without having to remember to add a manual check to
// each new one.
Route::middleware(['guest', 'throttle:20,1'])->group(function () {
    Route::get('/login', [LoginController::class, 'showIdentify'])->name('login');
    Route::post('/login', [LoginController::class, 'identify'])->name('login.identify');

    Route::get('/login/password', [LoginController::class, 'showPassword'])->name('login.password');
    Route::post('/login/password', [LoginController::class, 'password'])->name('login.password.attempt');

    Route::get('/login/totp', [LoginController::class, 'showTotpChallenge'])->name('login.totp');
    Route::post('/login/totp', [LoginController::class, 'totpChallenge'])->name('login.totp.attempt');

    Route::get('/login/totp/setup', [LoginController::class, 'showTotpSetup'])->name('login.totp.setup');
    Route::post('/login/totp/setup', [LoginController::class, 'totpSetup'])->name('login.totp.setup.attempt');

    Route::get('/login/pin', [LoginController::class, 'showPin'])->name('login.pin');
    Route::post('/login/pin', [LoginController::class, 'pin'])->name('login.pin.attempt');
});

Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

// ---------------------------------------------------------------------
// Public website booking requests (fourth post-9.8 fix round, "update
// the system such that it can catch reservations from the website
// too") — deliberately unauthenticated, same `throttle` pattern as the
// login endpoints just above (10 submissions per IP per minute is far
// more than a real guest ever needs, but stops a naive scripted flood).
// Every submission always lands as a normal pending reservation — see
// ReservationService::createFromWebsite()'s docblock.
// ---------------------------------------------------------------------
Route::middleware('throttle:10,1')->prefix('book')->name('public.booking.')->group(function () {
    Route::get('/', [WebsiteBookingController::class, 'showForm'])->name('create');
    Route::post('/', [WebsiteBookingController::class, 'store'])->name('store');
    Route::get('/thank-you', [WebsiteBookingController::class, 'success'])->name('success');
});

// ---------------------------------------------------------------------
// Authenticated application
// ---------------------------------------------------------------------
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Post-9.8 fix round: self-service PIN change — see
    // MyPinController's docblock. Deliberately no permission middleware
    // beyond 'auth': every authenticated user may change their OWN PIN,
    // the same way every authenticated user can already log out.
    Route::get('/my-pin', [\App\Http\Controllers\MyPinController::class, 'edit'])->name('my-pin.edit');
    Route::post('/my-pin', [\App\Http\Controllers\MyPinController::class, 'update'])->name('my-pin.update');

    // Guests
    Route::middleware('permission:manage-guests')->prefix('guests')->name('guests.')->group(function () {
        Route::get('/', [GuestController::class, 'index'])->name('index');
        // Registered before '/{guest}' so the literal 'search' segment is
        // never captured by the {guest} route-model-binding parameter.
        Route::get('/search', [GuestController::class, 'search'])->name('search');
        Route::get('/create', [GuestController::class, 'create'])->name('create');
        Route::post('/', [GuestController::class, 'store'])->name('store');
        // Broader "edit/delete every category and input" request — Guest
        // already uses SoftDeletes, so "delete" here is reversible; see
        // GuestController::update()/destroy()/restore() docblocks.
        Route::post('/{guest}/restore', [GuestController::class, 'restore'])->name('restore');
        Route::get('/{guest}', [GuestController::class, 'show'])->name('show');
        Route::put('/{guest}', [GuestController::class, 'update'])->name('update');
        Route::delete('/{guest}', [GuestController::class, 'destroy'])->name('destroy');
    });

    // Rooms
    // Phase 9.0 security fix: index() eager-loads currentStay.guest, so this
    // list shows live room occupancy AND current guest names. It was
    // previously reachable by any authenticated role with no gate at all.
    // Gated on 'view-rooms' rather than 'manage-rooms' (which only
    // front-office management roles hold) because front_desk staff must be
    // able to see the room board to perform check-in, but must not be able
    // to add/edit rooms — see database/seeders/RolesAndPermissionsSeeder.php.
    Route::prefix('rooms')->name('rooms.')->group(function () {
        Route::get('/', [RoomController::class, 'index'])->middleware('permission:view-rooms')->name('index');
        Route::post('/', [RoomController::class, 'store'])->middleware('permission:manage-rooms')->name('store');
        // Owner-only edit/delete of an existing room — see
        // 'edit-room-details' in RolesAndPermissionsSeeder and
        // RoomController::update()/destroy().
        Route::put('/{room}', [RoomController::class, 'update'])->middleware('permission:edit-room-details')->name('update');
        Route::delete('/{room}', [RoomController::class, 'destroy'])->middleware('permission:edit-room-details')->name('destroy');
        // Post-9.8 fix round: lets front_desk (and anyone else holding
        // 'update-room-status') flip a room's status straight from the
        // room board — see RoomController::updateStatus()'s docblock.
        Route::post('/{room}/status', [RoomController::class, 'updateStatus'])->middleware('permission:update-room-status')->name('update-status');
    });

    // Companies (third post-9.8 fix round, PHASE9_AUDIT_AND_PLAN.md §22) —
    // corporate billing accounts a stay can optionally be checked in
    // "billed to". Same permission as Guests, since this is the same kind
    // of front-office record-keeping.
    Route::middleware('permission:manage-guests')->prefix('companies')->name('companies.')->group(function () {
        Route::get('/', [CompanyController::class, 'index'])->name('index');
        Route::post('/', [CompanyController::class, 'store'])->name('store');
        // Broader "edit/delete every category and input" request — see
        // CompanyController::update()/destroy() docblocks.
        Route::put('/{company}', [CompanyController::class, 'update'])->name('update');
        Route::delete('/{company}', [CompanyController::class, 'destroy'])->name('destroy');
    });

    // Reservations
    Route::middleware('permission:manage-reservations')->prefix('reservations')->name('reservations.')->group(function () {
        Route::get('/', [ReservationController::class, 'index'])->name('index');
        Route::get('/create', [ReservationController::class, 'create'])->name('create');
        Route::post('/', [ReservationController::class, 'store'])->name('store');
        Route::get('/{reservation}', [ReservationController::class, 'show'])->name('show');
        // Phase 9.5 (PHASE9_AUDIT_AND_PLAN.md §3.6): printable reservation
        // confirmation — same permission/data as show() above.
        Route::get('/{reservation}/print', [ReservationController::class, 'print'])->name('print');
        // Correction round (audited defect #33): the only authenticated,
        // permission-gated way to view a guest's uploaded deposit-proof
        // file — see ReservationController::depositProof().
        Route::get('/{reservation}/deposit-proof', [ReservationController::class, 'depositProof'])->name('deposit-proof');
        Route::post('/{reservation}/cancel', [ReservationController::class, 'cancel'])->name('cancel');
        Route::post('/{reservation}/no-show', [ReservationController::class, 'noShow'])->name('no-show');
    });
    Route::middleware('permission:approve-reservation')->prefix('reservations')->name('reservations.')->group(function () {
        Route::post('/{reservation}/approve', [ReservationController::class, 'approve'])->name('approve');
        Route::post('/{reservation}/reject', [ReservationController::class, 'reject'])->name('reject');
        // Correction round (audited defects #3 and #8): verifying a
        // website-claimed deposit and disposing of a cancelled/rejected
        // reservation's deposit are both financial-approval-grade actions,
        // gated the same as approve/reject above — see ReservationPolicy.
        Route::post('/{reservation}/verify-deposit', [ReservationController::class, 'verifyDeposit'])->name('verify-deposit');
        Route::post('/{reservation}/dispose-deposit', [ReservationController::class, 'disposeDeposit'])->name('dispose-deposit');
    });
    // "the advance on reservation are also payments" — deposits are a
    // cash-handling action, so gated on the same permission as posting a
    // payment on a guest's folio (post-folio-payment) rather than
    // manage-reservations, which some non-cash-handling reservation roles
    // could hold without also being trusted to take money.
    Route::middleware('permission:post-folio-payment')->prefix('reservations')->name('reservations.')->group(function () {
        Route::post('/{reservation}/deposit', [ReservationController::class, 'recordDeposit'])->name('deposit');
    });
    // Feature 6 ("Approved discounts on check-in"): open to anyone who
    // can already view/manage the reservation — the actual authorization
    // check is inside DiscountApprovalService itself (selfApprove()
    // refuses a non-eligible $request->user(), requestApproval() refuses
    // a non-eligible requested approver), not the route middleware, since
    // "eligible" is a permission ('approve-discount') orthogonal to
    // reservation management.
    Route::middleware('permission:manage-reservations')->prefix('reservations')->name('reservations.')->group(function () {
        Route::post('/{reservation}/discount/self-approve', [ReservationController::class, 'selfApproveDiscount'])->name('discount.self-approve');
        Route::post('/{reservation}/discount/request', [ReservationController::class, 'requestDiscount'])->name('discount.request');
    });
    // The addressed approver acting on a pending discount request from
    // their own session — see DiscountApprovalController's docblock.
    Route::middleware('permission:approve-discount')->prefix('discount-approvals')->name('discount-approvals.')->group(function () {
        Route::get('/', [DiscountApprovalController::class, 'index'])->name('index');
        Route::post('/{discountApproval}/approve', [DiscountApprovalController::class, 'approve'])->name('approve');
        Route::post('/{discountApproval}/reject', [DiscountApprovalController::class, 'reject'])->name('reject');
    });

    // Check-in / Stays / Folio / Checkout
    Route::middleware('permission:checkin-guest')->group(function () {
        Route::post('/reservations/{reservation}/check-in', [CheckInController::class, 'fromReservation'])->name('reservations.checkin');
        Route::get('/walk-in', [CheckInController::class, 'walkInForm'])->name('checkin.walk-in');
        Route::post('/walk-in', [CheckInController::class, 'walkInStore'])->name('checkin.walk-in.store');
    });

    // Phase 9.0 security fix: index()/show() were previously reachable by
    // any authenticated role with no gate at all. show() in particular
    // loads and renders the guest's full folio balance, transaction
    // ledger, and outstanding Accounts Receivable — a genuine financial
    // data exposure to roles (bar/restaurant staff, cashier) who have no
    // business reason to see it. Gated on 'manage-guests', the same
    // permission GuestController already uses, so front_desk/duty_manager
    // keep working exactly as before while bar/restaurant/cashier no
    // longer can.
    Route::middleware('permission:manage-guests')->prefix('stays')->name('stays.')->group(function () {
        Route::get('/', [StayController::class, 'index'])->name('index');
        Route::get('/{stay}', [StayController::class, 'show'])->name('show');
        // Phase 9.5 (PHASE9_AUDIT_AND_PLAN.md §3.6): printable "Guest
        // Invoice" (any time) and "Final Hotel Bill" (closed stays only,
        // enforced in the controller) — same permission as show() above,
        // since both render the same folio data show() already does.
        Route::get('/{stay}/invoice/print', [StayController::class, 'printInvoice'])->name('invoice.print');
        Route::get('/{stay}/final-bill/print', [StayController::class, 'printFinalBill'])->name('final-bill.print');
    });
    Route::prefix('stays')->name('stays.')->group(function () {
        Route::post('/{stay}/checkout', [StayController::class, 'checkout'])->middleware('permission:checkout-guest')->name('checkout');
    });
    // "Special guest lodging" (PHASE9_AUDIT_AND_PLAN.md §9): a bigger
    // revenue decision than a POS comp, so it's gated behind its own
    // permission (Duty Manager/GM/Owner) rather than reusing
    // manage-guests/checkout-guest, which front_desk already holds.
    Route::middleware('permission:mark-stay-complimentary')->prefix('stays')->name('stays.')->group(function () {
        Route::post('/{stay}/complimentary', [StayController::class, 'markComplimentary'])->name('mark-complimentary');
        Route::post('/{stay}/complimentary/revoke', [StayController::class, 'unmarkComplimentary'])->name('unmark-complimentary');
    });

    // Post-9.8 fix round: general-purpose Payments module — see
    // PaymentController's docblock for why this exists alongside (not
    // instead of) the folio/POS payment flows.
    Route::middleware('permission:record-payment')->prefix('payments')->name('payments.')->group(function () {
        Route::get('/', [PaymentController::class, 'index'])->name('index');
        Route::post('/', [PaymentController::class, 'store'])->name('store');
    });

    Route::prefix('folios')->name('folios.')->group(function () {
        Route::post('/{folio}/charge', [FolioController::class, 'charge'])->middleware('permission:post-folio-charge')->name('charge');
        Route::post('/{folio}/payment', [FolioController::class, 'payment'])->middleware('permission:post-folio-payment')->name('payment');
        Route::post('/transactions/{transaction}/void', [FolioController::class, 'void'])->middleware('permission:void-folio-transaction')->name('void');
        // Phase 9.5 (PHASE9_AUDIT_AND_PLAN.md §3.6): printable payment
        // receipt. OR-permission because Cashier holds post-folio-payment
        // but not manage-guests, while Front Desk/Duty Manager hold
        // manage-guests (the same permission stays.show uses) but not
        // necessarily post-folio-payment.
        Route::get('/transactions/{transaction}/receipt', [FolioController::class, 'printReceipt'])->middleware('permission:manage-guests|post-folio-payment')->name('receipt.print');
    });

    // Gym & Swimming Pool (post-9.8 second fix round, PHASE9_AUDIT_AND_PLAN.md
    // §20): free access for checked-in guests, paid registration/slot for
    // walk-ins, both handled at the front desk. 'manage-facilities'
    // (owner/GM/duty manager) configures the facilities and their rates;
    // 'grant-facility-access' (front desk and up) does the day-to-day
    // granting/selling.
    Route::middleware('permission:manage-facilities')->prefix('facilities')->name('facilities.')->group(function () {
        Route::get('/', [FacilityController::class, 'index'])->name('index');
        Route::post('/', [FacilityController::class, 'store'])->name('store');
        // Broader "edit/delete every category and input" request — see
        // FacilityController::update()/destroy()/updateRate()/destroyRate().
        Route::put('/{facility}', [FacilityController::class, 'update'])->name('update');
        Route::delete('/{facility}', [FacilityController::class, 'destroy'])->name('destroy');
        Route::post('/{facility}/rates', [FacilityController::class, 'storeRate'])->name('rates.store');
        Route::put('/rates/{rate}', [FacilityController::class, 'updateRate'])->name('rates.update');
        Route::post('/rates/{rate}/toggle', [FacilityController::class, 'toggleRate'])->name('rates.toggle');
        Route::delete('/rates/{rate}', [FacilityController::class, 'destroyRate'])->name('rates.destroy');
    });
    Route::middleware('permission:grant-facility-access')->prefix('facility-access')->name('facility-access.')->group(function () {
        Route::get('/', [FacilityAccessController::class, 'index'])->name('index');
        Route::post('/guest', [FacilityAccessController::class, 'grantForGuest'])->name('guest');
        Route::post('/walk-in', [FacilityAccessController::class, 'sellToWalkIn'])->name('walk-in');
        Route::get('/{facilityAccess}/print', [FacilityAccessController::class, 'print'])->name('print');
    });

    // Housekeeping
    Route::middleware('permission:manage-housekeeping')->prefix('housekeeping')->name('housekeeping.')->group(function () {
        Route::get('/', [HousekeepingController::class, 'index'])->name('index');
        Route::post('/', [HousekeepingController::class, 'store'])->name('store');
        Route::post('/{task}/start', [HousekeepingController::class, 'start'])->name('start');
        Route::post('/{task}/complete', [HousekeepingController::class, 'complete'])->name('complete');
    });
    // New requirement 5 ("define/complete housekeeping approval workflow
    // if missing"): separate permission, same reasoning as Maintenance's
    // report/approve split — a role can create housekeeping tasks without
    // necessarily being trusted to approve one, and vice versa.
    Route::middleware('permission:approve-housekeeping')->prefix('housekeeping')->name('housekeeping.')->group(function () {
        Route::post('/{task}/approve', [HousekeepingController::class, 'approve'])->name('approve');
        Route::post('/{task}/reject', [HousekeepingController::class, 'reject'])->name('reject');
    });

    // Maintenance
    // Phase 9.0 security fix: index() was previously reachable by any
    // authenticated role with no gate at all. Gated on 'report-maintenance'
    // — the broadest maintenance-related permission, held by every role
    // that legitimately interacts with tickets (front_desk, duty_manager,
    // bar_staff, restaurant_staff, owner, GM) — so nobody who currently
    // uses this screen loses access; only accountant/cashier (who hold
    // none of the maintenance permissions) are newly excluded.
    Route::prefix('maintenance')->name('maintenance.')->group(function () {
        Route::get('/', [MaintenanceController::class, 'index'])->middleware('permission:report-maintenance')->name('index');
        Route::post('/', [MaintenanceController::class, 'store'])->middleware('permission:report-maintenance')->name('store');
        Route::post('/{ticket}/approve', [MaintenanceController::class, 'approve'])->middleware('permission:approve-maintenance')->name('approve');
        Route::post('/{ticket}/resolve', [MaintenanceController::class, 'resolve'])->middleware('permission:resolve-maintenance')->name('resolve');
    });

    // POS (Bar / Restaurant / Front Desk) — {outlet} is constrained to the
    // valid outlets; per-outlet authorization (operate-bar-pos vs
    // operate-restaurant-pos vs operate-front_desk-pos) happens inside
    // OrderController, since a route-level permission string can't vary
    // by route parameter. Front Desk was added post-9.7.4 so receptionists
    // can ring up petty sales (juice, water, snacks, biscuits, etc.)
    // through the same POS module — see PHASE9_AUDIT_AND_PLAN.md §8.
    // Bug fix (first real `php artisan` boot ever run against this project,
    // via a Laravel Cloud build — see PHASE9_AUDIT_AND_PLAN.md §12):
    // RouteRegistrar::where() (the group-level `where`, returned by
    // Route::prefix(...)) only accepts ONE argument — a `['param' =>
    // 'pattern']` array — unlike a single Route's `->where($name, $pattern)`
    // two-argument form. Calling it with two positional arguments here
    // silently dropped the pattern and stored the plain string 'outlet' as
    // this group's `where` attribute, which crashed on the very next request
    // to define this route file with `array_merge(): Argument #2 must be of
    // type array, string given` in RouteGroup::formatWhere() the moment this
    // group's attributes were merged with its parent group's. Every request
    // to the app boots the router and hits this file, so the bug was fatal
    // to the entire application, not just the POS routes — `php -l` could
    // never catch it (it's a runtime type error, not a syntax error), which
    // is exactly the category of bug this project's documentation has
    // repeatedly warned that manual review alone cannot find. Fixed by
    // switching to the array form the group-level `where()` actually
    // requires.
    // 'bar_outside' and 'bucareaux' added post-9.8 second fix round — two
    // more separately-run sales points ("their sales and stocks are
    // managed separately") that reuse this same outlet-parametrized POS
    // module rather than a new one — see
    // database/migrations/2025_01_02_001200_add_bar_outside_and_bucareaux_outlets.php
    Route::prefix('pos/{outlet}')->where(['outlet' => 'bar|restaurant|front_desk|bar_outside|bucareaux'])->name('pos.')->group(function () {
        Route::get('/', [OrderController::class, 'index'])->name('index');
        Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
        Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        // Phase 9.5 (PHASE9_AUDIT_AND_PLAN.md §3.6): printable "Restaurant
        // Receipt" / "Bar Receipt" — authorization happens inside the
        // controller (authorizeOutlet()), same as every route in this group.
        Route::get('/orders/{order}/receipt', [OrderController::class, 'printReceipt'])->name('orders.receipt');
        Route::post('/orders/{order}/items', [OrderController::class, 'addItem'])->name('orders.items.store');
        Route::post('/orders/{order}/items/{item}/remove', [OrderController::class, 'removeItem'])->name('orders.items.remove');
        Route::post('/orders/{order}/confirm', [OrderController::class, 'confirm'])->name('orders.confirm');
        Route::post('/orders/{order}/close', [OrderController::class, 'close'])->name('orders.close');
        Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
        // Feature 4 (500 XAF room-service delivery charge): waiving and
        // correcting share the same operate-{outlet}-pos permission as
        // every other mutation in this group — see OrderController's own
        // docblocks for why neither needed a stricter gate.
        Route::post('/orders/{order}/delivery-fee/waive', [OrderController::class, 'waiveDeliveryFee'])->name('orders.delivery-fee.waive');
        Route::post('/orders/{order}/delivery-mode/correct', [OrderController::class, 'correctDeliveryMode'])->name('orders.delivery-mode.correct');

        Route::middleware('permission:manage-menu')->prefix('menu')->name('menu.')->group(function () {
            Route::get('/', [MenuController::class, 'index'])->name('index');
            Route::post('/categories', [MenuController::class, 'storeCategory'])->name('categories.store');
            // Broader "edit/delete every category and input" request —
            // see MenuController::updateCategory()/destroyCategory() and
            // ::updateItem()/destroyItem() docblocks.
            Route::put('/categories/{category}', [MenuController::class, 'updateCategory'])->name('categories.update');
            Route::delete('/categories/{category}', [MenuController::class, 'destroyCategory'])->name('categories.destroy');
            Route::post('/items', [MenuController::class, 'storeItem'])->name('items.store');
            Route::put('/items/{item}', [MenuController::class, 'updateItem'])->name('items.update');
            Route::delete('/items/{item}', [MenuController::class, 'destroyItem'])->name('items.destroy');
            Route::get('/items/{item}/recipe', [MenuController::class, 'recipe'])->name('items.recipe');
            Route::post('/items/{item}/recipe', [MenuController::class, 'storeRecipeItem'])->name('items.recipe.store');
            Route::post('/items/{item}/recipe/{recipeItem}/remove', [MenuController::class, 'removeRecipeItem'])->name('items.recipe.remove');
        });
    });

    // Feature 3 ("one complimentary breakfast per occupied unit per
    // business date"): a standalone screen, not a pos/{outlet} tab — see
    // BreakfastController's own docblock for why. 'operate-restaurant-pos'
    // (same permission restaurant staff already need to ring up a paid
    // sale) gates redemption; reversing an already-served entitlement
    // (correcting a mistaken redemption, restoring its stock deduction)
    // is gated on the new 'reverse-breakfast-entitlement' permission
    // (Owner/General Manager/Duty Manager) — see that permission's own
    // comment in RolesAndPermissionsSeeder for why 'approve-wastage' was
    // deliberately NOT reused here (it's held by nobody).
    Route::prefix('breakfast')->name('breakfast.')->group(function () {
        Route::get('/', [BreakfastController::class, 'index'])->middleware('permission:operate-restaurant-pos')->name('index');
        Route::post('/stays/{stay}/redeem', [BreakfastController::class, 'redeem'])->middleware('permission:operate-restaurant-pos')->name('redeem');
        Route::post('/entitlements/{entitlement}/reverse', [BreakfastController::class, 'reverse'])->middleware('permission:reverse-breakfast-entitlement')->name('reverse');
    });

    // ---------------------------------------------------------------
    // Inventory (spec section 15): products/units/suppliers, purchase
    // orders + goods receipts, department requisitions, stock counts,
    // and wastage — all quantity changes flow through StockService's
    // FIFO engine, never a direct write to stock_batches/stock_movements.
    // ---------------------------------------------------------------
    Route::prefix('inventory')->name('inventory.')->group(function () {
        Route::middleware('permission:manage-products')->group(function () {
            Route::get('/products', [ProductController::class, 'index'])->name('products.index');
            Route::post('/products', [ProductController::class, 'store'])->name('products.store');
            // Broader "edit/delete every category and input" request —
            // see ProductController::update()/destroy() docblocks.
            Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
            Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
            Route::post('/units', [ProductController::class, 'storeUnit'])->name('units.store');
            Route::put('/units/{unit}', [ProductController::class, 'updateUnit'])->name('units.update');
            Route::delete('/units/{unit}', [ProductController::class, 'destroyUnit'])->name('units.destroy');
        });

        Route::middleware('permission:manage-suppliers')->group(function () {
            Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
            Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
            Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
            Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('suppliers.destroy');
        });

        Route::middleware('permission:manage-purchase-orders')->prefix('purchase-orders')->name('purchase-orders.')->group(function () {
            Route::get('/create', [PurchaseOrderController::class, 'create'])->name('create');
            Route::post('/', [PurchaseOrderController::class, 'store'])->name('store');
            Route::get('/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->name('show');
            Route::post('/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit'])->name('submit');
            Route::post('/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->name('cancel');
        });

        Route::middleware('permission:approve-purchase-order')->prefix('purchase-orders')->name('purchase-orders.')->group(function () {
            Route::post('/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])->name('approve');
            Route::post('/{purchaseOrder}/reject', [PurchaseOrderController::class, 'reject'])->name('reject');
        });

        // Phase 9.4 fix (found while wiring the dashboard's "pending
        // approvals" widget, PHASE9_AUDIT_AND_PLAN.md §3.5): Accountant
        // holds approve-purchase-order but NOT manage-purchase-orders, so
        // the index route being gated on manage-purchase-orders alone
        // left Accountant with permission to approve a PO but no page
        // that ever lists one to approve — a real, pre-existing dead end.
        // Mirrors the OR-permission pattern already used for
        // finance.expenses.index/finance.refunds.index.
        Route::middleware('permission:manage-purchase-orders|approve-purchase-order')
            ->get('/purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index');

        Route::middleware('permission:receive-goods')->group(function () {
            Route::post('/purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive'])->name('purchase-orders.receive');
            Route::get('/goods-receipts', [GoodsReceiptController::class, 'index'])->name('goods-receipts.index');
            Route::post('/goods-receipts', [GoodsReceiptController::class, 'store'])->name('goods-receipts.store');
        });

        Route::middleware('permission:request-stock')->prefix('requisitions')->name('requisitions.')->group(function () {
            Route::get('/create', [RequisitionController::class, 'create'])->name('create');
            Route::post('/', [RequisitionController::class, 'store'])->name('store');
            Route::get('/{requisition}', [RequisitionController::class, 'show'])->name('show');
            Route::post('/{requisition}/cancel', [RequisitionController::class, 'cancel'])->name('cancel');
        });

        Route::middleware('permission:approve-requisition')->prefix('requisitions')->name('requisitions.')->group(function () {
            Route::post('/{requisition}/approve', [RequisitionController::class, 'approve'])->name('approve');
            Route::post('/{requisition}/reject', [RequisitionController::class, 'reject'])->name('reject');
        });

        // Phase 9.4 fix (found while wiring the dashboard's "pending
        // approvals" widget, PHASE9_AUDIT_AND_PLAN.md §3.5): Duty Manager
        // holds approve-requisition but NOT request-stock, so the index
        // route being gated on request-stock alone left Duty Manager with
        // permission to approve a requisition but no page that ever lists
        // one to approve — a real, pre-existing dead end, identical in
        // shape to the purchase-orders.index fix above.
        Route::middleware('permission:request-stock|approve-requisition')
            ->get('/requisitions', [RequisitionController::class, 'index'])->name('requisitions.index');

        Route::middleware('permission:manage-stock-count')->prefix('stock-counts')->name('stock-counts.')->group(function () {
            Route::get('/', [StockCountController::class, 'index'])->name('index');
            Route::post('/', [StockCountController::class, 'store'])->name('store');
            Route::get('/{stockCount}', [StockCountController::class, 'show'])->name('show');
            Route::post('/{stockCount}/items/{item}', [StockCountController::class, 'recordItem'])->name('items.record');
            Route::post('/{stockCount}/complete', [StockCountController::class, 'complete'])->name('complete');
        });

        Route::middleware('permission:record-wastage')->prefix('wastage')->name('wastage.')->group(function () {
            Route::get('/', [WastageController::class, 'index'])->name('index');
            Route::post('/', [WastageController::class, 'store'])->name('store');
        });

        Route::middleware('permission:approve-wastage')->prefix('wastage')->name('wastage.')->group(function () {
            Route::post('/{wastage}/approve', [WastageController::class, 'approve'])->name('approve');
            Route::post('/{wastage}/reject', [WastageController::class, 'reject'])->name('reject');
        });
    });

    // ---------------------------------------------------------------
    // Finance (spec sections 16-17, Phase 4): expense approval, refund
    // approval, cash reconciliation, and the accounting-day lock. Both
    // expenses (on markPaid) and refunds (on approve) post through the
    // same central PaymentLedgerService as Front Office/POS, which is
    // where the accounting-day lock is actually enforced — see
    // AccountingDayService's docblock for why it lives there.
    // ---------------------------------------------------------------
    Route::prefix('finance')->name('finance.')->group(function () {
        Route::middleware('permission:manage-expenses')->prefix('expenses')->name('expenses.')->group(function () {
            Route::post('/', [ExpenseController::class, 'store'])->name('store');
            Route::post('/{expense}/cancel', [ExpenseController::class, 'cancel'])->name('cancel');
        });
        Route::middleware('permission:approve-expense')->prefix('expenses')->name('expenses.')->group(function () {
            Route::post('/{expense}/approve', [ExpenseController::class, 'approve'])->name('approve');
            Route::post('/{expense}/reject', [ExpenseController::class, 'reject'])->name('reject');
        });
        Route::middleware('permission:pay-expense')->prefix('expenses')->name('expenses.')->group(function () {
            Route::post('/{expense}/pay', [ExpenseController::class, 'pay'])->name('pay');
        });
        // Viewing the expense list only requires one of the tiers above,
        // never all three — handled by trying each permission in turn
        // isn't possible via a single middleware string across three
        // *different* base permissions cleanly, so index is reachable by
        // anyone holding any finance-expense permission via the pipe
        // (OR) syntax Spatie's permission middleware supports natively.
        Route::middleware('permission:manage-expenses|approve-expense|approve-large-expense|pay-expense')
            ->get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');

        Route::middleware('permission:request-refund')->prefix('refunds')->name('refunds.')->group(function () {
            Route::post('/folios/{folio}', [RefundController::class, 'store'])->name('store');
        });
        Route::middleware('permission:approve-refund')->prefix('refunds')->name('refunds.')->group(function () {
            Route::post('/{refund}/approve', [RefundController::class, 'approve'])->name('approve');
            Route::post('/{refund}/reject', [RefundController::class, 'reject'])->name('reject');
        });
        Route::middleware('permission:request-refund|approve-refund')
            ->get('/refunds', [RefundController::class, 'index'])->name('refunds.index');

        Route::middleware('permission:record-cash-reconciliation|lock-accounting-day')
            ->get('/accounting-days', [AccountingDayController::class, 'index'])->name('accounting-days.index');
        // Phase 9.5 (PHASE9_AUDIT_AND_PLAN.md §3.6): printable "Daily Cash
        // Closing" for one reconciliation — same permission as the index
        // list above, since that's where this is linked from.
        Route::middleware('permission:record-cash-reconciliation|lock-accounting-day')
            ->get('/accounting-days/cash-reconciliation/{cashReconciliation}/print', [AccountingDayController::class, 'printCashClosing'])->name('accounting-days.cash-reconciliation.print');
        Route::middleware('permission:record-cash-reconciliation')
            ->post('/accounting-days/cash-reconciliation', [AccountingDayController::class, 'storeCashReconciliation'])->name('accounting-days.cash-reconciliation.store');
        // Fourth post-9.8 fix round (day-close rebuild): the real
        // per-account, per-day reconciliation this screen is built
        // around now — see AccountingDayController's own docblock.
        Route::middleware('permission:record-cash-reconciliation')
            ->post('/accounting-days/reconcile-account/{financialAccount}', [AccountingDayController::class, 'reconcileAccount'])->name('accounting-days.reconcile-account');
        Route::middleware('permission:lock-accounting-day')
            ->post('/accounting-days/lock', [AccountingDayController::class, 'lock'])->name('accounting-days.lock');

        // Cash disbursement (post-9.8 second fix round, PHASE9_AUDIT_AND_PLAN.md
        // §18) — distinct from Expenses. 'authorize-cash-disbursement' is
        // the management act of raising a ticket; 'record-cash-disbursement'
        // is the accountant/cashier act of actually paying cash out,
        // either against a ticket or (ticket-less path) with the
        // authorizing superior's details captured directly on the form.
        Route::middleware('permission:authorize-cash-disbursement')->prefix('disbursement-tickets')->name('disbursement-tickets.')->group(function () {
            Route::post('/', [CashDisbursementController::class, 'storeTicket'])->name('store');
            Route::post('/{ticket}/cancel', [CashDisbursementController::class, 'cancelTicket'])->name('cancel');
        });
        Route::middleware('permission:record-cash-disbursement')->prefix('cash-disbursements')->name('cash-disbursements.')->group(function () {
            Route::post('/', [CashDisbursementController::class, 'store'])->name('store');
        });
        Route::middleware('permission:authorize-cash-disbursement|record-cash-disbursement')->group(function () {
            Route::get('/cash-disbursements', [CashDisbursementController::class, 'index'])->name('cash-disbursements.index');
            Route::get('/cash-disbursements/{cashDisbursement}/print', [CashDisbursementController::class, 'print'])->name('cash-disbursements.print');
        });

        // Shift-based department cash reconciliation (post-9.8 second fix
        // round, §19) — "not really per day but in shifts because they
        // work in shifts." Each department's own staff opens/closes its
        // own shift and declares counted cash for it; 'view-shift-reports'
        // (accountant/duty manager and up) sees every department's shifts.
        Route::middleware('permission:manage-own-shift')->prefix('shifts')->name('shifts.')->group(function () {
            Route::post('/', [ShiftController::class, 'store'])->name('store');
            Route::post('/{shift}/close', [ShiftController::class, 'close'])->name('close');
        });
        Route::middleware('permission:manage-own-shift|view-shift-reports')->group(function () {
            Route::get('/shifts', [ShiftController::class, 'index'])->name('shifts.index');
            Route::get('/shifts/{shift}/print', [ShiftController::class, 'print'])->name('shifts.print');
        });

        // Financial accounts (third post-9.8 fix round,
        // PHASE9_AUDIT_AND_PLAN.md §21): "a reconciled cash account, bank
        // account, mtn mobile money, orange money etc." Owner/GM/Accountant
        // configure the account list and reconcile each one periodically
        // against its real statement/app balance.
        Route::middleware('permission:manage-financial-accounts')->prefix('financial-accounts')->name('financial-accounts.')->group(function () {
            Route::get('/', [FinancialAccountController::class, 'index'])->name('index');
            Route::post('/', [FinancialAccountController::class, 'store'])->name('store');
            // Sixth post-9.8 fix round, follow-up ("the accountant should
            // be able to add, modify and edit real accounts") — create
            // and deactivate already existed; edit/update and a way back
            // from deactivate did not. Mirrors admin.departments' exact
            // edit/update/activate/deactivate shape.
            Route::get('/{financialAccount}/edit', [FinancialAccountController::class, 'edit'])->name('edit');
            Route::put('/{financialAccount}', [FinancialAccountController::class, 'update'])->name('update');
            Route::post('/{financialAccount}/reconcile', [FinancialAccountController::class, 'reconcile'])->name('reconcile');
            Route::post('/{financialAccount}/activate', [FinancialAccountController::class, 'activate'])->name('activate');
            Route::post('/{financialAccount}/deactivate', [FinancialAccountController::class, 'deactivate'])->name('deactivate');
            Route::get('/reconciliations/{reconciliation}/print', [FinancialAccountController::class, 'printReconciliation'])->name('reconciliation.print');
        });

        // Client debt (third post-9.8 fix round, PHASE9_AUDIT_AND_PLAN.md
        // §22): "create a means of closing client debt" — recording a
        // payment against a guest's or company's outstanding post-checkout
        // balance.
        Route::middleware('permission:manage-accounts-receivable')->prefix('accounts-receivable')->name('accounts-receivable.')->group(function () {
            Route::get('/', [AccountsReceivableController::class, 'index'])->name('index');
            Route::post('/{accountsReceivable}/settle', [AccountsReceivableController::class, 'settle'])->name('settle');
        });
    });

    // ---------------------------------------------------------------
    // HR & Payroll (Phase 5): employee profiles, attendance, leave, and
    // a payroll run lifecycle (draft -> approved -> paid) whose "paid"
    // step posts through the same central PaymentLedgerService as every
    // other module — see PayrollService's docblock for why that means
    // the accounting-day lock applies to payroll too.
    // ---------------------------------------------------------------
    Route::prefix('hr')->name('hr.')->group(function () {
        Route::middleware('permission:manage-employees')->prefix('employees')->name('employees.')->group(function () {
            Route::get('/', [EmployeeController::class, 'index'])->name('index');
            Route::post('/', [EmployeeController::class, 'store'])->name('store');
            Route::post('/{employeeProfile}/terminate', [EmployeeController::class, 'terminate'])->name('terminate');
        });

        // Clocking your own attendance is self-service, but (post-9.8 fix
        // round, "view of HR") no longer universal: `clock-own-attendance`
        // is now held only by Owner, General Manager, and Accountant —
        // see RolesAndPermissionsSeeder's "HR visibility restriction"
        // note. Manual correction of someone else's record needs a
        // separate permission (`record-attendance`, Owner/GM-only too).
        Route::middleware('permission:clock-own-attendance')->prefix('attendance')->name('attendance.')->group(function () {
            Route::post('/clock-in', [AttendanceController::class, 'clockIn'])->name('clock-in');
            Route::post('/clock-out', [AttendanceController::class, 'clockOut'])->name('clock-out');
        });
        Route::middleware('permission:record-attendance')
            ->post('/attendance/manual', [AttendanceController::class, 'storeManual'])->name('attendance.manual.store');
        Route::middleware('permission:clock-own-attendance|record-attendance')
            ->get('/attendance', [AttendanceController::class, 'index'])->name('attendance.index');

        Route::middleware('permission:manage-employees')->prefix('leave-types')->name('leave-types.')->group(function () {
            Route::post('/', [LeaveController::class, 'storeType'])->name('store');
            // Broader "edit/delete every category and input" request —
            // see LeaveController::updateType()/destroyType() docblocks.
            Route::put('/{leaveType}', [LeaveController::class, 'updateType'])->name('update');
            Route::delete('/{leaveType}', [LeaveController::class, 'destroyType'])->name('destroy');
        });

        Route::middleware('permission:request-leave')->prefix('leave-requests')->name('leave-requests.')->group(function () {
            Route::post('/', [LeaveController::class, 'store'])->name('store');
            Route::post('/{leaveRequest}/cancel', [LeaveController::class, 'cancel'])->name('cancel');
        });
        Route::middleware('permission:approve-leave')->prefix('leave-requests')->name('leave-requests.')->group(function () {
            Route::post('/{leaveRequest}/approve', [LeaveController::class, 'approve'])->name('approve');
            Route::post('/{leaveRequest}/reject', [LeaveController::class, 'reject'])->name('reject');
        });
        Route::middleware('permission:request-leave|approve-leave')
            ->get('/leave-requests', [LeaveController::class, 'index'])->name('leave-requests.index');

        Route::middleware('permission:manage-employees')->prefix('payroll-deductions')->name('payroll-deductions.')->group(function () {
            Route::get('/', [PayrollController::class, 'deductionsIndex'])->name('index');
            Route::post('/', [PayrollController::class, 'storeDeductionType'])->name('store');
            // Broader "edit/delete every category and input" request —
            // see PayrollController::updateDeductionType()/
            // destroyDeductionType()/updateBracket()/destroyBracket().
            Route::put('/{payrollDeductionType}', [PayrollController::class, 'updateDeductionType'])->name('update');
            Route::delete('/{payrollDeductionType}', [PayrollController::class, 'destroyDeductionType'])->name('destroy');
            Route::post('/{payrollDeductionType}/brackets', [PayrollController::class, 'storeBracket'])->name('brackets.store');
            Route::put('/brackets/{bracket}', [PayrollController::class, 'updateBracket'])->name('brackets.update');
            Route::delete('/brackets/{bracket}', [PayrollController::class, 'destroyBracket'])->name('brackets.destroy');
        });

        Route::middleware('permission:manage-payroll')->prefix('payroll-runs')->name('payroll-runs.')->group(function () {
            Route::post('/', [PayrollController::class, 'store'])->name('store');
            Route::post('/{payrollRun}/generate', [PayrollController::class, 'generate'])->name('generate');
        });
        Route::middleware('permission:approve-payroll-run')
            ->post('/payroll-runs/{payrollRun}/approve', [PayrollController::class, 'approve'])->name('payroll-runs.approve');
        Route::middleware('permission:pay-payroll-run')
            ->post('/payroll-runs/{payrollRun}/pay', [PayrollController::class, 'pay'])->name('payroll-runs.pay');
        Route::middleware('permission:manage-payroll|approve-payroll-run|pay-payroll-run')->group(function () {
            Route::get('/payroll-runs', [PayrollController::class, 'index'])->name('payroll-runs.index');
            Route::get('/payroll-runs/{payrollRun}', [PayrollController::class, 'show'])->name('payroll-runs.show');
        });

        // Self-service — the controller scopes the query to auth()->id(),
        // so ownership (not the permission) decides *whose* payslips a
        // viewer sees. The permission gate below decides *whether* they
        // can reach the page at all: previously any authenticated user
        // could, but post-9.8's "view of HR" restriction means only
        // Owner/General Manager/Accountant (the same three roles that
        // hold `clock-own-attendance` now) get a payslips page to view in
        // the first place.
        Route::middleware('permission:clock-own-attendance')
            ->get('/my-payslips', [PayrollController::class, 'myPayslips'])->name('my-payslips');
    });

    // ---------------------------------------------------------------
    // Administration (Phase 9.2, PHASE9_AUDIT_AND_PLAN.md §3.2):
    // manage-users/manage-settings/manage-departments have existed in
    // RolesAndPermissionsSeeder since Phase 1 (granted only to Owner/GM,
    // since both hold every permission) but nothing was ever gated
    // behind them — this is the first time these permissions do
    // anything. Users are deactivated (is_active=false), never deleted,
    // since past transactions/audit log rows reference them by ID.
    // ---------------------------------------------------------------
    Route::middleware('permission:manage-users')->prefix('admin/users')->name('admin.users.')->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('index');
        Route::get('/create', [UserController::class, 'create'])->name('create');
        Route::post('/', [UserController::class, 'store'])->name('store');
        Route::get('/{user}/edit', [UserController::class, 'edit'])->name('edit');
        Route::put('/{user}', [UserController::class, 'update'])->name('update');
        Route::post('/{user}/activate', [UserController::class, 'activate'])->name('activate');
        Route::post('/{user}/deactivate', [UserController::class, 'deactivate'])->name('deactivate');
        Route::post('/{user}/reset-password', [UserController::class, 'resetPassword'])->name('reset-password');
        Route::post('/{user}/reset-pin', [UserController::class, 'resetPin'])->name('reset-pin');
        Route::post('/{user}/unlock-pin', [UserController::class, 'unlockPin'])->name('unlock-pin');
    });

    Route::middleware('permission:manage-departments')->prefix('admin/departments')->name('admin.departments.')->group(function () {
        Route::get('/', [DepartmentController::class, 'index'])->name('index');
        Route::get('/create', [DepartmentController::class, 'create'])->name('create');
        Route::post('/', [DepartmentController::class, 'store'])->name('store');
        Route::get('/{department}/edit', [DepartmentController::class, 'edit'])->name('edit');
        Route::put('/{department}', [DepartmentController::class, 'update'])->name('update');
        Route::post('/{department}/activate', [DepartmentController::class, 'activate'])->name('activate');
        Route::post('/{department}/deactivate', [DepartmentController::class, 'deactivate'])->name('deactivate');
        // Broader "edit/delete every category" request — see
        // DepartmentService::delete()'s docblock for the history guard.
        Route::delete('/{department}', [DepartmentController::class, 'destroy'])->name('destroy');
    });

    // Seventh post-9.8 fix round, follow-up (PHASE9_AUDIT_AND_PLAN.md
    // §20.12) — "Master Admin" reset/backup/export, deliberately gated on
    // its own Owner-only permission rather than 'manage-settings'. See
    // SystemDataService's docblock before changing anything here.
    Route::middleware('permission:manage-system-data')->prefix('admin/system-data')->name('admin.system-data.')->group(function () {
        Route::get('/', [SystemDataController::class, 'index'])->name('index');
        Route::post('/reset', [SystemDataController::class, 'reset'])->name('reset');
        Route::get('/backup', [SystemDataController::class, 'backup'])->name('backup');
        Route::get('/export', [SystemDataController::class, 'export'])->name('export');
        // Feature: Import — the mirror image of backup/export above, same
        // Owner-only 'manage-system-data' permission this whole group is
        // already gated on. See SystemDataController::importJson()/
        // importCsv() and SystemDataService's docblock.
        Route::post('/import/backup', [SystemDataController::class, 'importJson'])->name('import-backup');
        Route::post('/import/csv', [SystemDataController::class, 'importCsv'])->name('import-csv');
    });

    Route::middleware('permission:manage-settings')->prefix('admin/settings')->name('admin.settings.')->group(function () {
        Route::get('/', [SettingsController::class, 'index'])->name('index');
        Route::post('/', [SettingsController::class, 'update'])->name('update');
        Route::post('/logo', [SettingsController::class, 'uploadLogo'])->name('logo');
    });

    Route::middleware('permission:manage-settings')->prefix('admin/room-categories')->name('admin.room-categories.')->group(function () {
        Route::get('/', [RoomCategoryController::class, 'index'])->name('index');
        Route::post('/', [RoomCategoryController::class, 'store'])->name('store');
        Route::put('/{roomCategory}', [RoomCategoryController::class, 'update'])->name('update');
        Route::post('/{roomCategory}/activate', [RoomCategoryController::class, 'activate'])->name('activate');
        Route::post('/{roomCategory}/deactivate', [RoomCategoryController::class, 'deactivate'])->name('deactivate');
        // Broader "edit/delete every category" request — see
        // RoomCategoryController::destroy()'s docblock for the history guard.
        Route::delete('/{roomCategory}', [RoomCategoryController::class, 'destroy'])->name('destroy');
    });

    // Phase 9.7.6: the sync engine's human-facing dashboard — device
    // list/last-sync times and conflict resolution. Deliberately named
    // `admin.sync.*` (not `sync.*`, which routes/sync.php already owns
    // for the machine-to-machine transport registered outside the `web`
    // group) so the two are never confused despite sharing a domain.
    Route::middleware('permission:manage-sync')->prefix('admin/sync')->name('admin.sync.')->group(function () {
        Route::get('/', [SyncDashboardController::class, 'index'])->name('index');
        Route::get('/conflicts', [SyncDashboardController::class, 'conflicts'])->name('conflicts');
        Route::post('/conflicts/{conflict}/resolve', [SyncDashboardController::class, 'resolveConflict'])->name('conflicts.resolve');
    });

    // ---------------------------------------------------------------
    // Reporting (Phase 6, spec section 24): every figure is a live query
    // against the ledgers built in Phases 1-5 — nothing here is a
    // stored, separately-maintained total that could drift from the
    // transactions it describes. Read-only, so each report needs only
    // one permission (view), never the request/approve pairs the
    // transactional modules use.
    // ---------------------------------------------------------------
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::middleware('permission:view-financial-reports|view-operational-reports|view-inventory-reports|view-hr-reports|view-shift-reports')
            ->get('/', [ReportController::class, 'index'])->name('index');

        Route::middleware('permission:view-financial-reports')->group(function () {
            Route::get('/kpi-dashboard', [ReportController::class, 'kpiDashboard'])->name('kpi-dashboard');
            Route::get('/kpi-dashboard/print', [ReportController::class, 'kpiDashboardPrint'])->name('kpi-dashboard.print');
            Route::get('/financial', [ReportController::class, 'financial'])->name('financial');
            Route::get('/financial/print', [ReportController::class, 'financialPrint'])->name('financial.print');
            Route::get('/income-statement', [ReportController::class, 'incomeStatement'])->name('income-statement');
            Route::get('/income-statement/print', [ReportController::class, 'incomeStatementPrint'])->name('income-statement.print');
            // Phase 9.4 (PHASE9_AUDIT_AND_PLAN.md §3.5): the narrative-style
            // weekly rollup the plan calls for — reuses 9.3's report data
            // (occupancy + income statement + cash flow + receivables aging),
            // never recomputes any of it independently.
            Route::get('/weekly-owner-summary', [ReportController::class, 'weeklyOwnerSummary'])->name('weekly-owner-summary');
            // Phase 9.5 (PHASE9_AUDIT_AND_PLAN.md §3.6): printable/CSV
            // document routes — same permission as the screens above,
            // since every one of these reuses that screen's own data.
            Route::get('/weekly-owner-summary/print', [ReportController::class, 'weeklyOwnerSummaryPrint'])->name('weekly-owner-summary.print');
            Route::get('/payment-summary', [ReportController::class, 'paymentSummary'])->name('payment-summary');
            Route::get('/payment-summary/print', [ReportController::class, 'paymentSummaryPrint'])->name('payment-summary.print');
            Route::get('/payment-summary/csv', [ReportController::class, 'paymentSummaryCsv'])->name('payment-summary.csv');
            Route::get('/expense-report', [ReportController::class, 'expenseReport'])->name('expense-report');
            Route::get('/expense-report/print', [ReportController::class, 'expenseReportPrint'])->name('expense-report.print');
            Route::get('/expense-report/csv', [ReportController::class, 'expenseReportCsv'])->name('expense-report.csv');
            Route::get('/management-report', [ReportController::class, 'managementReport'])->name('management-report');
            Route::get('/management-report/print', [ReportController::class, 'managementReportPrint'])->name('management-report.print');
        });

        Route::middleware('permission:view-operational-reports')->group(function () {
            Route::get('/occupancy', [ReportController::class, 'occupancy'])->name('occupancy');
            Route::get('/occupancy/print', [ReportController::class, 'occupancyPrint'])->name('occupancy.print');
            Route::get('/arrivals-departures', [ReportController::class, 'arrivalsDepartures'])->name('arrivals-departures');
            Route::get('/arrivals-departures/print', [ReportController::class, 'arrivalsDeparturesPrint'])->name('arrivals-departures.print');
            Route::get('/guest-stay-history', [ReportController::class, 'guestStayHistory'])->name('guest-stay-history');
            Route::get('/guest-stay-history/print', [ReportController::class, 'guestStayHistoryPrint'])->name('guest-stay-history.print');
            // Phase 9.5 (PHASE9_AUDIT_AND_PLAN.md §3.6): CSV export of the
            // same data — same permission as the screen above.
            Route::get('/guest-stay-history/csv', [ReportController::class, 'guestStayHistoryCsv'])->name('guest-stay-history.csv');
        });

        // Feature 2 ("Complete guest transaction history report across
        // all stays"): its own permission, deliberately distinct from
        // 'view-operational-reports' above — see
        // GuestTransactionHistoryController's own docblock.
        Route::middleware('permission:view-guest-history')->prefix('guest-transaction-history')->name('guest-transaction-history.')->group(function () {
            Route::get('/', [GuestTransactionHistoryController::class, 'index'])->name('index');
            Route::get('/{guest}', [GuestTransactionHistoryController::class, 'show'])->name('show');
            Route::get('/{guest}/print', [GuestTransactionHistoryController::class, 'print'])->name('print');
            Route::get('/{guest}/csv', [GuestTransactionHistoryController::class, 'csv'])->name('csv');
        });

        Route::middleware('permission:view-inventory-reports')->group(function () {
            Route::get('/inventory', [ReportController::class, 'inventory'])->name('inventory');
            Route::get('/inventory/print', [ReportController::class, 'inventoryPrint'])->name('inventory.print');
        });

        Route::middleware('permission:view-hr-reports')->group(function () {
            Route::get('/hr', [ReportController::class, 'hr'])->name('hr');
            Route::get('/hr/print', [ReportController::class, 'hrPrint'])->name('hr.print');
        });

        // Department payment reconciliation (post-9.8 second fix round,
        // PHASE9_AUDIT_AND_PLAN.md §19) — cash + sales/income by
        // department (front desk, bar, restaurant, bar outside,
        // bucareaux). Visible to whoever can see financial reports OR
        // whoever reviews shift reconciliations (accountant, cashier,
        // duty manager and up), since cashier holds the latter but not
        // the former.
        Route::middleware('permission:view-financial-reports|view-shift-reports')->group(function () {
            Route::get('/department-reconciliation', [ReportController::class, 'departmentReconciliation'])->name('department-reconciliation');
            Route::get('/department-reconciliation/print', [ReportController::class, 'departmentReconciliationPrint'])->name('department-reconciliation.print');
            Route::get('/department-reconciliation/csv', [ReportController::class, 'departmentReconciliationCsv'])->name('department-reconciliation.csv');
        });
    });
});
