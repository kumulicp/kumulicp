<?php

use App\Enums\TicketStatus;
use App\Services\Tickets\TicketWorkflow;
use App\Ticket;

function ticketWithStatus(TicketStatus $status): Ticket
{
    $ticket = new Ticket;
    $ticket->status = $status;

    return $ticket;
}

it('lets staff move a ticket to any other status', function () {
    $workflow = new TicketWorkflow;

    foreach (TicketStatus::cases() as $from) {
        foreach (TicketStatus::cases() as $to) {
            expect($workflow->canChange($from, $to, true))->toBe($from !== $to);
        }
    }
});

it('lets members only close, and reopen a finished ticket', function () {
    $workflow = new TicketWorkflow;

    expect($workflow->canChange(TicketStatus::OPEN, TicketStatus::CLOSED, false))->toBeTrue();
    expect($workflow->canChange(TicketStatus::RESOLVED, TicketStatus::OPEN, false))->toBeTrue();
    expect($workflow->canChange(TicketStatus::CLOSED, TicketStatus::OPEN, false))->toBeTrue();

    expect($workflow->canChange(TicketStatus::OPEN, TicketStatus::RESOLVED, false))->toBeFalse();
    expect($workflow->canChange(TicketStatus::OPEN, TicketStatus::ON_HOLD, false))->toBeFalse();
    expect($workflow->canChange(TicketStatus::PENDING_CUSTOMER, TicketStatus::OPEN, false))->toBeFalse();
    expect($workflow->canChange(TicketStatus::OPEN, TicketStatus::OPEN, false))->toBeFalse();
});

it('moves the status after a reply', function (TicketStatus $from, bool $byStaff, bool $internal, ?TicketStatus $expected) {
    $next = (new TicketWorkflow)->statusAfterReply(ticketWithStatus($from), $byStaff, $internal);

    expect($next)->toBe($expected);
})->with([
    'staff answers a new ticket' => [TicketStatus::OPEN, true, false, TicketStatus::PENDING_CUSTOMER],
    'staff answers a waiting ticket' => [TicketStatus::PENDING_SUPPORT, true, false, TicketStatus::PENDING_CUSTOMER],
    'staff reply on hold keeps it on hold' => [TicketStatus::ON_HOLD, true, false, null],
    'staff reply on resolved keeps it resolved' => [TicketStatus::RESOLVED, true, false, null],
    'customer answers' => [TicketStatus::PENDING_CUSTOMER, false, false, TicketStatus::PENDING_SUPPORT],
    'customer reopens a resolved ticket' => [TicketStatus::RESOLVED, false, false, TicketStatus::PENDING_SUPPORT],
    'customer follow-up on a new ticket' => [TicketStatus::OPEN, false, false, null],
    'internal note by staff' => [TicketStatus::OPEN, true, true, null],
    'internal note on a pending ticket' => [TicketStatus::PENDING_CUSTOMER, true, true, null],
]);

it('keeps the resolved and closed timestamps in step with the status', function () {
    $workflow = new TicketWorkflow;
    $ticket = ticketWithStatus(TicketStatus::OPEN);

    $workflow->apply($ticket, TicketStatus::RESOLVED);
    expect($ticket->resolved_at)->not->toBeNull()->and($ticket->closed_at)->toBeNull();

    $workflow->apply($ticket, TicketStatus::CLOSED);
    expect($ticket->resolved_at)->not->toBeNull()->and($ticket->closed_at)->not->toBeNull();

    $workflow->apply($ticket, TicketStatus::OPEN);
    expect($ticket->resolved_at)->toBeNull()->and($ticket->closed_at)->toBeNull();
});

it('knows which statuses are still open', function () {
    expect(TicketStatus::openValues())->toBe(['open', 'pending_support', 'pending_customer', 'on_hold']);
});
