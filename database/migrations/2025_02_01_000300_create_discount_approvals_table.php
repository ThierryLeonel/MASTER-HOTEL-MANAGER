<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Correction round (audited defect #6, new requirement "Approved
     * discounts on check-in"): a real, auditable authorization record for
     * any lodging rate below a room category's standard rate — including a
     * full (zero-rate) complimentary stay, which is just the 100% case of
     * the same control, not a separate mechanism.
     *
     * Bound to either a Reservation or a Stay (`approvable_type`/
     * `approvable_id`) because a walk-in check-in has no Reservation row
     * at all, but still needs the identical control. `standard_rate` is
     * snapshotted from RoomCategory at approval time specifically so a
     * later category price change never alters what a historical discount
     * looked like (spec requirement: "Snapshot the standard rate used for
     * approval so later category-price changes do not alter historical
     * discount calculations").
     *
     * `status`: an eligible approver acting on their own reservation/stay
     * self-approves immediately (`approved`, `approver_id` = themselves,
     * bound server-side — never a client-supplied id). A non-eligible
     * staff member submitting on someone else's behalf creates a
     * `pending` row addressed to a specific `requested_approver_id`, who
     * must separately log in and approve it themselves
     * (DiscountApprovalService::approveRequest()) — "selecting another
     * employee's name is not proof of approval."
     *
     * `superseded_at`: sourceRow material terms (room/category/dates/rate)
     * changing requires a fresh approval; the old one is marked superseded
     * rather than edited, so the audit trail is never rewritten.
     */
    public function up(): void
    {
        Schema::create('discount_approvals', function (Blueprint $table) {
            $table->id();
            $table->morphs('approvable'); // approvable_type, approvable_id — Reservation or Stay

            $table->foreignId('room_category_id')->constrained('room_categories')->restrictOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->date('check_in_date');
            $table->date('check_out_date');

            $table->decimal('standard_rate', 12, 2)->comment('RoomCategory.base_rate snapshotted at approval time.');
            $table->decimal('agreed_rate', 12, 2);
            $table->decimal('discount_amount', 12, 2)->comment('standard_rate - agreed_rate, per night.');
            $table->boolean('is_complimentary')->default(false)->comment('true when agreed_rate = 0 (100% discount).');

            $table->text('reason');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('approved')->index();

            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('requested_approver_id')->nullable()->constrained('users')->nullOnDelete()
                ->comment('Set only when status started pending — the specific approver this request was addressed to.');

            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approver_role_snapshot')->nullable()->comment('The approving user\'s primary role name at approval time.');
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamp('superseded_at')->nullable()->comment('Set when a material change required a fresh approval.');

            $table->timestamps();

            $table->index(['approvable_type', 'approvable_id', 'superseded_at'], 'discount_approvals_approvable_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_approvals');
    }
};
