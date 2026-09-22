<?php

namespace App\Services\Finance;

use App\Exceptions\HmsException;
use App\Models\AccountsReceivable;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PaymentLedgerService;
use Illuminate\Support\Facades\DB;

/**
 * Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §22): "create a
 * means of closing client debt." A guest (or a company billed for one or
 * more stays) who checked out with an unpaid balance already got an
 * AccountsReceivable row (CheckOutService::checkOut()) — until now there
 * was no way to actually close it out. Per the clarifying answer given
 * ("record a payment against it"), settlement here means real money
 * later received against the debt, recorded through the same central
 * ledger as every other payment. Deliberately NOT a write-off/forgive
 * feature — that option (the `written_off_by`/`written_off_reason`
 * columns already on the table) was explicitly NOT selected when asked,
 * so it stays unbuilt.
 *
 * This posts straight to PaymentLedgerService rather than through
 * FolioService — the folio this debt came from is already closed
 * (folios lock once a stay checks out), and FolioService::post() refuses
 * to post to a closed folio, on purpose. AR settlement is a distinct,
 * post-checkout collections action, not a folio transaction.
 */
class AccountsReceivableService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PaymentLedgerService $ledger,
    ) {
    }

    public function recordSettlement(AccountsReceivable $ar, float $amount, string $method, User $actor, ?string $notes = null, ?int $financialAccountId = null): AccountsReceivable
    {
        if ($amount <= 0) {
            throw new HmsException('Settlement amount must be greater than zero.');
        }

        return DB::transaction(function () use ($ar, $amount, $method, $actor, $notes, $financialAccountId) {
            /** @var AccountsReceivable $locked */
            $locked = AccountsReceivable::query()->whereKey($ar->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isSettleable()) {
                throw new HmsException("This debt is already {$locked->status} — there's nothing left to settle.");
            }

            if ($amount > (float) $locked->amount_outstanding) {
                throw new HmsException("This payment ({$amount}) exceeds the outstanding balance ({$locked->amount_outstanding}).");
            }

            $this->ledger->record(
                amount: $amount,
                method: $method,
                sourceModule: 'ar_settlement',
                recordedBy: $actor,
                sourceReference: "AR-{$locked->id}",
                reference: $locked,
                guestId: $locked->guest_id,
                notes: $notes ?? "Debt settlement — AR #{$locked->id}",
                financialAccountId: $financialAccountId,
            );

            $newOutstanding = round((float) $locked->amount_outstanding - $amount, 2);

            $locked->update([
                'amount_outstanding' => $newOutstanding,
                'status' => $newOutstanding <= 0 ? 'paid' : 'partially_paid',
            ]);

            $this->audit->log('finance.ar_settled', 'finance', $locked, null, $locked->fresh()->toArray(), actor: $actor);

            return $locked->fresh();
        });
    }
}
