<?php

namespace App\Services\Finance;

use App\Exceptions\HmsException;
use App\Models\CashDisbursement;
use App\Models\DisbursementTicket;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PaymentLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cash disbursement (post-9.8 second fix round, PHASE9_AUDIT_AND_PLAN.md
 * §18) — money going out that is neither an Expense (which has its own
 * request -> approve -> pay lifecycle) nor a Refund. The user's own
 * description of the flow, followed exactly here:
 *
 *   "before a disbursement is done, the management creates the ticket,
 *   though disbursement should also be able to be done without ticket
 *   but the details will have to be clear and the detail of the
 *   superior authorising it [must be recorded]."
 *
 * So there are two ways in, both landing on disburse():
 *   - With a ticket (the normal path): a manager already raised a
 *     DisbursementTicket via createTicket(); disburse() just needs its
 *     id, and the authorizing manager's identity comes from the ticket.
 *   - Without a ticket (the exception path): disburse() requires the
 *     caller to supply either an in-system authorizing user or a clear
 *     name (+ optional title) for the authorizing superior — enforced
 *     below, not left to the UI to remember.
 *
 * Same DB-transaction + lockForUpdate() + PaymentLedgerService pattern as
 * ExpenseService::markPaid() (the identified template for this).
 */
class CashDisbursementService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PaymentLedgerService $ledger,
        private readonly ExpenseService $expenses,
    ) {
    }

    public function createTicket(float $amount, string $purpose, User $actor, ?int $departmentId = null, ?string $notes = null): DisbursementTicket
    {
        if ($amount <= 0) {
            throw new HmsException('Ticket amount must be greater than zero.');
        }

        $ticket = DisbursementTicket::create([
            'ticket_number' => 'DT-'.now()->format('Ymd').'-'.strtoupper(Str::random(5)),
            'department_id' => $departmentId,
            'purpose' => $purpose,
            'amount' => $amount,
            'status' => 'open',
            'authorized_by' => $actor->id,
            'notes' => $notes,
        ]);

        $this->audit->log('finance.disbursement_ticket_created', 'finance', $ticket, null, $ticket->toArray(), actor: $actor);

        return $ticket;
    }

    public function cancelTicket(DisbursementTicket $ticket, User $actor, string $reason): DisbursementTicket
    {
        if (! $ticket->isOpen()) {
            throw new HmsException("Only an open ticket can be cancelled (current status: {$ticket->status}).");
        }

        $ticket->update(['status' => 'cancelled', 'cancellation_reason' => $reason]);

        $this->audit->log('finance.disbursement_ticket_cancelled', 'finance', $ticket, null, $ticket->toArray(), $reason, $actor);

        return $ticket->fresh();
    }

    /**
     * @param array{
     *   ticket_id?: int|null,
     *   amount: float,
     *   paid_to: string,
     *   purpose: string,
     *   expense_id?: int|null,
     *   financial_account_id: int,
     *   department_id?: int|null,
     *   authorized_by_user_id?: int|null,
     *   authorized_by_name?: string|null,
     *   authorized_by_title?: string|null,
     *   notes?: string|null,
     * } $data
     */
    public function disburse(array $data, User $actor): CashDisbursement
    {
        $amount = (float) $data['amount'];

        if ($amount <= 0) {
            throw new HmsException('Disbursement amount must be greater than zero.');
        }

        // Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §21): "same
        // goes for cash disbursement" — required, and `method` is derived
        // from it rather than accepted separately.
        // Correction round (audited defect #31): see
        // PaymentController::store()'s matching comment — never trust the
        // submitted id alone; a deactivated account must not be
        // disbursable from.
        $financialAccount = FinancialAccount::where('is_active', true)->findOrFail($data['financial_account_id']);

        return DB::transaction(function () use ($data, $amount, $actor, $financialAccount) {
            $ticket = null;
            $departmentId = $data['department_id'] ?? null;

            // "Reference ... the expense ... done": locked and re-verified
            // here, inside the same transaction as the ledger post below,
            // so a concurrent second disbursement (or a concurrent
            // ExpenseController::pay()) against the same expense can't
            // both succeed — the loser sees isApproved() already false and
            // fails cleanly, exactly like markPaid()'s own concurrency
            // guard.
            $expense = null;
            if (! empty($data['expense_id'])) {
                /** @var Expense $expense */
                $expense = Expense::query()->whereKey($data['expense_id'])->lockForUpdate()->firstOrFail();

                if (! $expense->isApproved()) {
                    throw new HmsException("Only an approved expense can be disbursed against (current status: {$expense->status}).");
                }

                // Correction round (code-review pass, Nov 2026): this used
                // to compare with `abs(float diff) > 0.005` — a tolerance
                // band bolted on specifically because plain float equality
                // on currency is unreliable. bccomp does an exact decimal
                // comparison instead, so the tolerance hack (and the small
                // risk of it letting a genuinely-off amount through) is no
                // longer needed.
                if (bccomp((string) $expense->amount, (string) $amount, 2) !== 0) {
                    throw new HmsException("This disbursement ({$amount}) does not match the expense's approved amount ({$expense->amount}) — they must be equal so the ledger and the expense agree on what was paid.");
                }

                $departmentId ??= $expense->department_id;
            }

            if (! empty($data['ticket_id'])) {
                /** @var DisbursementTicket $ticket */
                $ticket = DisbursementTicket::query()->whereKey($data['ticket_id'])->lockForUpdate()->firstOrFail();

                if (! $ticket->isOpen()) {
                    throw new HmsException("This ticket is not open (current status: {$ticket->status}) — it cannot be disbursed against.");
                }

                if (bccomp((string) $amount, (string) $ticket->amount, 2) > 0) {
                    throw new HmsException("This disbursement ({$amount}) exceeds the ticket's authorized amount ({$ticket->amount}).");
                }

                $departmentId ??= $ticket->department_id;
            } else {
                // Ticket-less path — the authorizing superior's details
                // must be clear, per the user's explicit instruction. Not
                // optional, not left to the form to nudge — enforced here
                // so it can never be skipped regardless of which screen
                // calls this service.
                $hasNamedAuthorizer = ! empty($data['authorized_by_user_id']) || ! empty($data['authorized_by_name']);

                if (! $hasNamedAuthorizer) {
                    throw new HmsException('A disbursement with no ticket must record who authorized it — either pick the authorizing manager, or enter their name.');
                }
            }

            $disbursement = CashDisbursement::create([
                'disbursement_number' => 'CD-'.now()->format('Ymd').'-'.strtoupper(Str::random(5)),
                'ticket_id' => $ticket?->id,
                'department_id' => $departmentId,
                'amount' => $amount,
                'paid_to' => $data['paid_to'],
                'purpose' => $data['purpose'],
                'expense_id' => $expense?->id,
                // Fourth post-9.8 fix round: $financialAccount->type is
                // 'bank' for the Bank account, but the `method` column's
                // enum doesn't contain 'bank' — only 'bank_transfer'.
                // See FinancialAccount::paymentMethod()'s docblock.
                'method' => $financialAccount->paymentMethod(),
                'financial_account_id' => $financialAccount->id,
                'authorized_by_user_id' => $ticket ? null : ($data['authorized_by_user_id'] ?? null),
                'authorized_by_name' => $ticket ? null : ($data['authorized_by_name'] ?? null),
                'authorized_by_title' => $ticket ? null : ($data['authorized_by_title'] ?? null),
                'recorded_by' => $actor->id,
                'disbursed_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            $this->ledger->record(
                amount: $amount,
                method: $disbursement->method,
                sourceModule: 'cash_disbursement',
                recordedBy: $actor,
                sourceReference: $disbursement->disbursement_number,
                reference: $disbursement,
                direction: 'out',
                departmentId: $departmentId,
                financialAccountId: $financialAccount->id,
                notes: $disbursement->purpose,
            );

            $ticket?->update(['status' => 'used']);

            // The ledger post above IS this expense's payment — never call
            // ExpenseService::markPaid() here, since that would post a
            // second, duplicate Payment for the same money out. See
            // ExpenseService::markPaidViaDisbursement()'s docblock.
            if ($expense) {
                $this->expenses->markPaidViaDisbursement($expense, $actor, $financialAccount);
            }

            $this->audit->log('finance.cash_disbursed', 'finance', $disbursement, null, $disbursement->fresh()->toArray(), actor: $actor);

            return $disbursement->fresh();
        });
    }
}
