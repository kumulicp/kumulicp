<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketStatus;
use App\Services\Tickets\TicketExporter;
use App\Services\Tickets\TicketReports;
use App\Ticket;
use App\TicketCategory;
use App\TicketTimeEntry;
use App\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

class TicketReportsTest extends TicketsTestCase
{
    private function at(string $time): Carbon
    {
        return Carbon::parse($time, 'UTC');
    }

    private function period(): array
    {
        return [CarbonImmutable::parse('2026-12-01', 'UTC'), CarbonImmutable::parse('2026-12-10', 'UTC')];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Three tickets in the period (two of them finished) and one outside it.
     *
     * @return array<string, Ticket>
     */
    private function scenario(): array
    {
        Carbon::setTestNow($this->at('2026-12-10 12:00'));
        $billing = TicketCategory::create(['name' => 'Billing', 'slug' => 'billing', 'active' => true]);
        $sam = $this->staffMember('sam');

        $make = function (User $requester, array $state) {
            $ticket = $this->ticketFor($requester);
            $ticket->forceFill($state)->save();

            return $ticket->fresh();
        };

        $first = $make($this->alice, [
            'created_at' => $this->at('2026-12-08 09:00'), 'first_response_at' => $this->at('2026-12-08 09:30'), 'resolved_at' => $this->at('2026-12-08 13:00'),
            'status' => TicketStatus::RESOLVED, 'support_assignee_id' => $sam->id, 'category_id' => $billing->id, 'csat_score' => 5,
            'sla_first_response_due_at' => $this->at('2026-12-08 10:00'), 'sla_resolution_due_at' => $this->at('2026-12-08 17:00'),
        ]);
        $second = $make($this->bob, [
            'created_at' => $this->at('2026-12-08 10:00'), 'first_response_at' => $this->at('2026-12-08 12:00'), 'resolved_at' => $this->at('2026-12-09 10:00'),
            'status' => TicketStatus::CLOSED, 'support_assignee_id' => $sam->id, 'csat_score' => 3,
            'sla_first_response_due_at' => $this->at('2026-12-08 11:00'), 'sla_resolution_due_at' => $this->at('2026-12-09 18:00'),
        ]);
        $third = $make($this->carol, ['created_at' => $this->at('2026-12-09 15:00'), 'sla_first_response_due_at' => $this->at('2026-12-09 16:00')]);
        $outside = $make($this->alice, ['created_at' => $this->at('2026-11-01 15:00')]);

        foreach ([[$first, 60, true], [$first, 30, false], [$second, 120, true]] as [$ticket, $minutes, $billable]) {
            $entry = new TicketTimeEntry;
            $entry->ticket_id = $ticket->id;
            $entry->user_id = $sam->id;
            $entry->minutes = $minutes;
            $entry->billable = $billable;
            $entry->worked_at = '2026-12-09';
            $entry->save();
        }

        return compact('first', 'second', 'third', 'outside');
    }

    public function test_only_staff_see_reports_and_exports(): void
    {
        $this->actingAs($this->alice)->get('/admin/support/reports')->assertForbidden();
        $this->actingAs($this->alice)->get('/admin/support/reports/export.csv')->assertForbidden();
        $this->actingAs($this->alice)->get('/admin/support/people/'.$this->bob->id.'/export')->assertForbidden();
    }

    public function test_the_numbers(): void
    {
        $this->scenario();
        [$from, $to] = $this->period();

        $report = app(TicketReports::class)->summary($from, $to);

        $this->assertSame(['created' => 3, 'resolved' => 2, 'open_now' => 2, 'unassigned_now' => 2], $report['totals']);
        // 30 and 120 minutes
        $this->assertSame([2, 75, 75], [$report['first_response']['count'], $report['first_response']['average'], $report['first_response']['median']]);
        // 240 minutes and 1440 minutes
        $this->assertSame([2, 840, 840], [$report['resolution']['count'], $report['resolution']['average'], $report['resolution']['median']]);
        // One answered in time; one late; one still unanswered past its deadline
        $this->assertSame(['measured' => 3, 'met' => 1, 'percent' => 33.3], $report['sla']['first_response']);
        $this->assertSame(['measured' => 2, 'met' => 2, 'percent' => 100.0], $report['sla']['resolution']);
        $this->assertSame([2, 4.0, 1, 1], [$report['csat']['count'], $report['csat']['average'], $report['csat']['distribution'][5], $report['csat']['distribution'][3]]);

        // Every day of the period is listed, even empty ones
        $this->assertCount(10, $report['per_day']);
        $this->assertSame(['date' => '2026-12-08', 'created' => 2, 'resolved' => 1], collect($report['per_day'])->firstWhere('date', '2026-12-08'));

        $this->assertSame(2, collect($report['by_assignee'])->firstWhere('name', 'Sam')['tickets']);
        $this->assertSame(1, collect($report['by_category'])->firstWhere('name', 'Billing')['tickets']);
        $this->assertSame(['Acme' => 2, 'Globex' => 1], collect($report['by_organization'])->pluck('tickets', 'name')->all());

        $this->assertSame([210, 180], [$report['time']['total'], $report['time']['billable']]);
    }

    public function test_a_period_with_nothing_in_it_is_empty_not_an_error(): void
    {
        $report = app(TicketReports::class)->summary(CarbonImmutable::parse('2020-01-01'), CarbonImmutable::parse('2020-01-03'));

        $this->assertSame(['count' => 0, 'average' => null, 'median' => null], $report['first_response']);
    }

    public function test_the_report_page_loads_and_limits_the_period(): void
    {
        $this->scenario();

        $this->actingAs($this->admin)->get('/admin/support/reports?days=30')
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Tickets/TicketReports')->has('report.totals')->where('range', 30));

        $this->actingAs($this->admin)->get('/admin/support/reports?days=5000')->assertSessionHasErrors('days');
        $this->actingAs($this->admin)->get('/admin/support/reports?from=not-a-date')->assertSessionHasErrors('from');
    }

    public function test_the_csv_has_a_row_per_ticket_in_the_period_and_defuses_formulas(): void
    {
        ['outside' => $outside] = $this->scenario();
        $injection = $this->ticketFor($this->alice, ['subject' => '=HYPERLINK("http://evil","x")']);
        $injection->forceFill(['created_at' => $this->at('2026-12-09 10:00')])->save();

        $response = $this->actingAs($this->admin)->get('/admin/support/reports/export.csv?from=2026-12-01&to=2026-12-10');
        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        // 3 tickets + the one added + the header
        $this->assertSame(5, count(array_filter(explode("\n", trim($csv)))));
        $this->assertStringNotContainsString($outside->reference.',', $csv);
        // A spreadsheet would run this as a formula; it is made plain text
        $this->assertStringContainsString("\"'=HYPERLINK(", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
    }

    public function test_cells_that_spreadsheets_would_execute_are_neutralised(): void
    {
        $exporter = app(TicketExporter::class);

        $this->assertSame("'+1 555", $exporter->cell('+1 555'));
        $this->assertSame("'-2", $exporter->cell('-2'));
        $this->assertSame("'@SUM(1)", $exporter->cell('@SUM(1)'));
        $this->assertSame('plain', $exporter->cell('plain'));
        $this->assertSame(5, $exporter->cell(5));
        $this->assertNull($exporter->cell(null));
    }

    public function test_one_persons_data_can_be_exported(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $this->reply($ticket, $this->alice, '<p>Hello from Alice</p>');
        $this->reply($ticket, $this->bob, '<p>Hello from Bob</p>');

        $response = $this->actingAs($this->admin)->get('/admin/support/people/'.$this->alice->id.'/export');

        $response->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="support-data-user-'.$this->alice->id.'.json"');
        $this->assertSame('alice@example.com', $response->json('person.email'));
        $this->assertSame([$ticket->reference], array_column($response->json('tickets_opened'), 'reference'));
        // Their own words only
        $this->assertCount(2, $response->json('messages_written'));
        $this->assertStringNotContainsString('Hello from Bob', $response->getContent());
    }
}
