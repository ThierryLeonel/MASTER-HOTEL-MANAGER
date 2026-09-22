<?php

namespace Tests\Feature\FrontOffice;

use App\Models\DiscountApproval;
use App\Models\Guest;
use App\Models\Room;
use App\Models\Stay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Feature request ("also include ... a place where the front desk or
 * receptionist selects to identify who approved the discount" on the
 * Walk-in Check-in form): DiscountApprovalService's "Walk-in exception"
 * (see its class docblock). Unlike a reservation — which is approved (or
 * sent for approval) days before check-in, so blocking check-in costs
 * nothing — a walk-in's discount decision happens at the exact moment
 * the guest is standing at the desk. This lets a non-eligible
 * receptionist route the decision to a specific eligible approver and
 * check the guest in immediately, with a PENDING DiscountApproval
 * attached that the named approver must still separately confirm from
 * their own authenticated session — the product's explicit choice
 * (confirmed via AskUserQuestion) over either a bare unverified name
 * picker (which would have silently reopened the exact gap
 * DiscountApprovalService's docblock says it closes: "selecting another
 * employee's name is NOT proof of approval") or requiring inline
 * PIN/password re-authentication.
 */
class WalkInDiscountApprovalRoutingTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
        $this->openShiftFor('FO');
    }

    public function test_walk_in_check_in_with_a_discounted_rate_and_no_approver_selected_is_still_blocked(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $guest = Guest::factory()->create();
        $room = Room::factory()->create(['status' => 'available']); // base_rate 10000 by factory default

        $response = $this->actingAs($frontDesk)->post(route('checkin.walk-in.store'), [
            'guest_id' => $guest->id,
            'room_id' => $room->id,
            'expected_check_out_date' => now()->addDays(2)->toDateString(),
            'agreed_rate' => 5000, // below the 10000 standard rate
        ]);

        $response->assertSessionHasErrors('discount_approver_id');
        $this->assertDatabaseCount('stays', 0);
        $this->assertSame('available', $room->fresh()->status);
    }

    public function test_walk_in_check_in_can_route_a_discount_to_a_named_eligible_approver_and_proceed_immediately(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $dutyManager = $this->userWithRole('duty_manager', ['auth_type' => 'password_only']);
        $guest = Guest::factory()->create();
        $room = Room::factory()->create(['status' => 'available']);

        $response = $this->actingAs($frontDesk)->post(route('checkin.walk-in.store'), [
            'guest_id' => $guest->id,
            'room_id' => $room->id,
            'expected_check_out_date' => now()->addDays(2)->toDateString(),
            'agreed_rate' => 6000,
            'discount_approver_id' => $dutyManager->id,
            'discount_reason' => 'Repeat guest, negotiated at the desk.',
        ]);

        $stay = Stay::first();
        $response->assertRedirect(route('stays.show', $stay));
        $response->assertSessionHas('status', fn ($status) => str_contains($status, 'pending approval'));

        // The guest is genuinely checked in right away — this is the
        // whole point of the walk-in exception.
        $this->assertSame('active', $stay->status);
        $this->assertSame('occupied', $room->fresh()->status);
        $this->assertNotNull($stay->folio);

        $approval = DiscountApproval::first();
        $this->assertSame('pending', $approval->status);
        $this->assertSame($frontDesk->id, $approval->requested_by);
        $this->assertSame($dutyManager->id, $approval->requested_approver_id);
        $this->assertNull($approval->approver_id);
        $this->assertSame('6000.00', $approval->agreed_rate);
        $this->assertSame('10000.00', $approval->standard_rate);
        $this->assertSame('Repeat guest, negotiated at the desk.', $approval->reason);
    }

    public function test_the_named_approvers_pending_queue_shows_the_walk_in_request_and_they_can_approve_it(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $dutyManager = $this->userWithRole('duty_manager', ['auth_type' => 'password_only']);
        $guest = Guest::factory()->create();
        $room = Room::factory()->create(['status' => 'available']);

        $this->actingAs($frontDesk)->post(route('checkin.walk-in.store'), [
            'guest_id' => $guest->id,
            'room_id' => $room->id,
            'expected_check_out_date' => now()->addDays(2)->toDateString(),
            'agreed_rate' => 6000,
            'discount_approver_id' => $dutyManager->id,
            'discount_reason' => 'Repeat guest, negotiated at the desk.',
        ]);

        $approval = DiscountApproval::first();

        // Same "My Discount Approvals" queue the reservation-based
        // request flow already uses — no new screen needed.
        $this->actingAs($dutyManager)
            ->get(route('discount-approvals.index'))
            ->assertOk()
            ->assertSee($guest->full_name)
            ->assertSee('Repeat guest, negotiated at the desk.');

        $this->actingAs($dutyManager)->post(route('discount-approvals.approve', $approval))
            ->assertRedirect();

        $approval->refresh();
        $this->assertSame('approved', $approval->status);
        $this->assertSame($dutyManager->id, $approval->approver_id);
    }

    /**
     * The safety net still holds inside the new pathway: a client that
     * bypasses the UI (or a compromised/buggy request) and names a user
     * who does NOT actually hold 'approve-discount' must fail loudly, not
     * silently let the guest check in "approved" by someone with no
     * authority to approve anything. requestApproval() enforces this
     * independently of what the controller sent — proven here by picking
     * another ordinary front_desk user's id.
     */
    public function test_walk_in_check_in_refuses_to_route_a_discount_to_a_user_who_is_not_an_eligible_approver(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $anotherFrontDeskUser = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $guest = Guest::factory()->create();
        $room = Room::factory()->create(['status' => 'available']);

        $response = $this->actingAs($frontDesk)->post(route('checkin.walk-in.store'), [
            'guest_id' => $guest->id,
            'room_id' => $room->id,
            'expected_check_out_date' => now()->addDays(2)->toDateString(),
            'agreed_rate' => 6000,
            'discount_approver_id' => $anotherFrontDeskUser->id,
            'discount_reason' => 'Attempted bypass.',
        ]);

        $response->assertSessionHasErrors('error');
        // The whole DB transaction rolled back — no half-checked-in
        // walk-in, no room silently flipped to occupied, no dangling
        // discount approval left behind.
        $this->assertDatabaseCount('stays', 0);
        $this->assertDatabaseCount('discount_approvals', 0);
        $this->assertSame('available', $room->fresh()->status);
    }

    /**
     * Regression: when the person doing the walk-in check-in is
     * themselves an eligible approver, nothing changes from before this
     * feature — it self-approves inline and no "discount_approver_id" is
     * ever needed.
     */
    public function test_an_eligible_approver_doing_their_own_walk_in_check_in_self_approves_without_naming_anyone(): void
    {
        $dutyManager = $this->userWithRole('duty_manager', ['auth_type' => 'password_only']);
        $guest = Guest::factory()->create();
        $room = Room::factory()->create(['status' => 'available']);

        $response = $this->actingAs($dutyManager)->post(route('checkin.walk-in.store'), [
            'guest_id' => $guest->id,
            'room_id' => $room->id,
            'expected_check_out_date' => now()->addDays(2)->toDateString(),
            'agreed_rate' => 6000,
        ]);

        $stay = Stay::first();
        $response->assertRedirect(route('stays.show', $stay));
        $response->assertSessionHas('status', 'Walk-in guest checked in.');

        $approval = DiscountApproval::first();
        $this->assertSame('approved', $approval->status);
        $this->assertSame($dutyManager->id, $approval->approver_id);
    }
}
