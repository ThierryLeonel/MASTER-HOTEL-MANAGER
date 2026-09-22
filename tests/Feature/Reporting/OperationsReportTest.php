<?php

namespace Tests\Feature\Reporting;

use App\Models\Room;
use App\Models\Stay;
use App\Services\FrontOffice\FolioService;
use App\Services\Reporting\OperationsReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class OperationsReportTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    public function test_occupancy_counts_only_rooms_actually_occupied_on_each_day(): void
    {
        Room::factory()->count(4)->create(['is_active' => true]);
        $occupiedRoom = Room::factory()->create(['is_active' => true]);

        $stay = Stay::factory()->for($occupiedRoom)->create([
            'status' => 'active',
            'check_in_at' => Carbon::today()->subDays(1),
            'actual_check_out_at' => null,
        ]);

        $report = app(OperationsReportService::class)->occupancy(Carbon::today()->subDays(1), Carbon::today());

        $this->assertSame(5, $report['total_rooms']);
        $this->assertSame(2, $report['days']);
        $this->assertSame(10, $report['room_nights_available']);
        $this->assertSame(2, $report['room_nights_occupied'], 'The one active stay should occupy its room for both days of the range.');
        $this->assertSame(20.0, $report['occupancy_rate']);
    }

    public function test_a_checked_out_stay_stops_counting_as_occupied(): void
    {
        $room = Room::factory()->create(['is_active' => true]);
        Stay::factory()->for($room)->create([
            'status' => 'closed',
            'check_in_at' => Carbon::today()->subDays(3),
            'actual_check_out_at' => Carbon::today()->subDays(2),
        ]);

        $report = app(OperationsReportService::class)->occupancy(Carbon::today(), Carbon::today());

        $this->assertSame(0, $report['room_nights_occupied']);
    }

    public function test_adr_is_room_revenue_divided_by_room_nights_sold(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $room = Room::factory()->create(['is_active' => true]);
        $stay = Stay::factory()->for($room)->create([
            'status' => 'active',
            'check_in_at' => Carbon::today(),
            'actual_check_out_at' => null,
        ]);
        $folio = \App\Models\Folio::factory()->for($stay)->create(['status' => 'open', 'balance' => 0]);
        app(FolioService::class)->postCharge($folio, 'room_charge', 30000, 'Room', $frontDesk);

        $report = app(OperationsReportService::class)->occupancy(Carbon::today(), Carbon::today());

        $this->assertSame(1, $report['room_nights_occupied']);
        $this->assertSame(30000.0, $report['adr']);
    }

    public function test_arrivals_and_departures_are_scoped_to_the_date_range(): void
    {
        $room = Room::factory()->create();
        $inRange = Stay::factory()->for($room)->create(['check_in_at' => Carbon::today()]);
        Stay::factory()->for(Room::factory()->create())->create(['check_in_at' => Carbon::today()->subDays(10)]);

        $result = app(OperationsReportService::class)->arrivalsAndDepartures(Carbon::today(), Carbon::today());

        $this->assertCount(1, $result['arrivals']);
        $this->assertSame($inRange->id, $result['arrivals']->first()->id);
    }

    public function test_guest_stay_history_lists_only_closed_stays_checked_out_in_range_with_real_totals(): void
    {
        // Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §21): a
        // folio payment (default sourceModule 'front_office') now also
        // requires an open Front Office shift.
        $this->openShiftFor('FO');

        $frontDesk = $this->userWithRole('front_desk');
        $room = Room::factory()->create();

        $closedInRange = Stay::factory()->for($room)->create([
            'status' => 'closed',
            'check_in_at' => Carbon::today()->subDays(2),
            'actual_check_out_at' => Carbon::today(),
        ]);
        $folio = \App\Models\Folio::factory()->for($closedInRange)->create(['status' => 'closed', 'balance' => 0]);
        app(FolioService::class)->postCharge($folio, 'room_charge', 60000, 'Room', $frontDesk);
        app(FolioService::class)->postPayment($folio, 60000, 'cash', $frontDesk);

        // Still-active stay must not appear in history at all.
        Stay::factory()->for(Room::factory()->create())->create(['status' => 'active']);
        // Closed stay outside the requested range must be excluded.
        Stay::factory()->for(Room::factory()->create())->create([
            'status' => 'closed',
            'actual_check_out_at' => Carbon::today()->subDays(10),
        ]);

        $rows = app(OperationsReportService::class)->guestStayHistory(Carbon::today()->subDays(1), Carbon::today());

        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame($closedInRange->id, $row['stay']->id);
        $this->assertSame(2, $row['nights']);
        $this->assertSame(60000.0, $row['total_charged']);
        $this->assertSame(60000.0, $row['total_paid']);
        $this->assertSame(0.0, $row['balance']);
        $this->assertFalse($row['sent_to_receivables']);
    }

    public function test_guest_stay_history_flags_a_stay_that_was_sent_to_accounts_receivable(): void
    {
        $room = Room::factory()->create();
        $stay = Stay::factory()->for($room)->create([
            'status' => 'closed',
            'actual_check_out_at' => Carbon::today(),
        ]);
        $folio = \App\Models\Folio::factory()->for($stay)->create(['status' => 'closed', 'balance' => 25000]);
        \App\Models\AccountsReceivable::create([
            'folio_id' => $folio->id,
            'guest_id' => $stay->guest_id,
            'original_amount' => 25000,
            'amount_outstanding' => 25000,
            'status' => 'open',
            'created_by' => $this->userWithRole('front_desk')->id,
        ]);

        $rows = app(OperationsReportService::class)->guestStayHistory(Carbon::today(), Carbon::today());

        $this->assertTrue($rows->first()['sent_to_receivables']);
    }

    public function test_bar_staff_cannot_view_operational_reports(): void
    {
        $bar = $this->pinUser('bar_staff');

        $this->actingAs($bar)->get(route('reports.occupancy'))->assertForbidden();
    }

    public function test_duty_manager_can_view_operational_reports(): void
    {
        $dutyManager = $this->userWithRole('duty_manager');

        $this->actingAs($dutyManager)->get(route('reports.occupancy'))->assertOk();
        $this->actingAs($dutyManager)->get(route('reports.arrivals-departures'))->assertOk();
        $this->actingAs($dutyManager)->get(route('reports.guest-stay-history'))->assertOk();
    }

    /**
     * Phase 9.5 (PHASE9_AUDIT_AND_PLAN.md §3.6): CSV export of the same
     * guest stay history data — same permission gate, real row content.
     */
    public function test_guest_stay_history_csv_downloads_with_the_correct_content_type_and_real_rows(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $dutyManager = $this->userWithRole('duty_manager');
        $room = Room::factory()->create();
        $stay = Stay::factory()->for($room)->create([
            'status' => 'closed',
            'check_in_at' => Carbon::today()->subDays(2),
            'actual_check_out_at' => Carbon::today(),
        ]);
        $folio = \App\Models\Folio::factory()->for($stay)->create(['status' => 'closed', 'balance' => 0]);
        app(FolioService::class)->postCharge($folio, 'room_charge', 60000, 'Room', $frontDesk);

        $response = $this->actingAs($dutyManager)->get(route('reports.guest-stay-history.csv'));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();
        $this->assertStringContainsString($stay->guest->full_name, $content);
        $this->assertStringContainsString('60000', $content);
    }

    public function test_bar_staff_cannot_download_guest_stay_history_csv(): void
    {
        $bar = $this->pinUser('bar_staff');

        $this->actingAs($bar)->get(route('reports.guest-stay-history.csv'))->assertForbidden();
    }
}
