<?php

namespace App\Services\Tickets;

use App\Enums\TicketImpact;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Services\Tickets\Integrations\IntegrationDispatcher;
use App\Ticket;
use App\TicketAttachment;
use App\TicketCategory;
use App\TicketDelivery;
use App\TicketEvent;
use App\TicketIntegration;
use App\TicketMessage;
use App\TicketMessageRevision;
use App\TicketSubscription;
use App\TicketTag;
use App\TicketTimeEntry;
use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * All ticket writes go through here: one transaction each, one timeline entry
 * each, and notifications only after the transaction has committed.
 */
class TicketService
{
    public const CUSTOMER = 'customer';

    public const SUPPORT = 'support';

    public function __construct(
        private TicketWorkflow $workflow,
        private TicketNotifier $notifier,
        private TicketHtml $html,
        private TicketStaff $staff,
        private AttachmentService $attachments,
        private TicketVersions $versions,
        private TicketUnread $unread,
        private TicketSubscriptions $subscriptions,
        private IntegrationDispatcher $integrations,
        private TicketSla $sla,
        private TicketAutomation $automation,
    ) {}

    /**
     * @param  array{subject: string, body: string, type?: string, impact?: string, category_id?: int|null, app_instance_id?: int|null, customer_assignee_id?: int|null, attachment_ids?: list<int>}  $data
     */
    public function create(User $requester, array $data, string $source = 'web'): Ticket
    {
        $drafts = $this->attachments->drafts(Arr::get($data, 'attachment_ids', []), $requester);
        $body = $this->html->prepare($data['body'], 'body', $drafts->pluck('id')->all(), $drafts->isNotEmpty());

        $ticket = DB::transaction(function () use ($requester, $data, $body, $source, $drafts) {
            $ticket = new Ticket([
                'subject' => $data['subject'],
                'type' => $data['type'] ?? TicketType::QUESTION->value,
                'impact' => $data['impact'] ?? TicketImpact::MINOR->value,
            ]);
            $ticket->organization_id = $requester->organization_id;
            $ticket->requester_id = $requester->id;
            $ticket->customer_assignee_id = $this->resolveCustomerAssignee($requester, Arr::get($data, 'customer_assignee_id'))->id;
            $ticket->app_instance_id = Arr::get($data, 'app_instance_id');
            $ticket->source = $source;
            $ticket->status = TicketStatus::OPEN;
            $ticket->priority = TicketPriority::NORMAL;
            $ticket->last_activity_at = now();
            $ticket->last_requester_reply_at = now();
            $ticket->sla_paused_minutes = 0;

            if ($category = $this->activeCategory(Arr::get($data, 'category_id'))) {
                $ticket->category_id = $category->id;
                $ticket->support_assignee_id = $this->defaultAssigneeFor($category);
            }

            // Nobody chosen by the category: the automatic rules may pick someone
            $autoAssigned = false;
            if (! $ticket->support_assignee_id && ($picked = $this->automation->pickAssignee())) {
                $ticket->support_assignee_id = $picked->id;
                $autoAssigned = true;
            }

            $this->sla->apply($ticket);
            $this->saveWithNumber($ticket);

            $message = $this->addMessage($ticket, $requester, TicketMessage::KIND_REQUESTER, TicketMessage::PUBLIC, $body, $source);
            $this->attachments->bind($drafts, $ticket, $message, $body['images']);
            $this->record($ticket, 'created', $requester);

            if ($ticket->support_assignee_id) {
                $this->record($ticket, 'assigned', $autoAssigned ? null : $requester, ['side' => self::SUPPORT, 'to' => $ticket->support_assignee_id] + ($autoAssigned ? ['auto' => true] : []));
            }

            return $ticket;
        });

        $this->afterCommit(function () use ($ticket) {
            $ticket->load(['requester', 'customerAssignee', 'supportAssignee', 'organization']);
            $this->notifier->created($ticket);
            $this->integrate('ticket.created', $ticket, [], $ticket->id);
        });

        return $ticket;
    }

    /**
     * @param  list<int>  $attachmentIds  Ids of the author's uploaded drafts to send with the message
     */
    public function reply(Ticket $ticket, User $author, string $body, bool $asStaff, bool $internal = false, array $attachmentIds = []): TicketMessage
    {
        if ($internal && ! $asStaff) {
            throw new AuthorizationException(__('tickets.errors.internal_staff_only'));
        }

        if (! $asStaff && $ticket->status === TicketStatus::CLOSED) {
            throw ValidationException::withMessages(['body' => __('tickets.errors.closed')]);
        }

        $drafts = $this->attachments->drafts($attachmentIds, $author);
        $prepared = $this->html->prepare($body, 'body', $drafts->pluck('id')->all(), $drafts->isNotEmpty());

        $message = DB::transaction(function () use ($ticket, $author, $asStaff, $internal, $prepared, $drafts) {
            $message = $this->addMessage(
                $ticket,
                $author,
                $asStaff ? TicketMessage::KIND_STAFF : TicketMessage::KIND_REQUESTER,
                $internal ? TicketMessage::INTERNAL : TicketMessage::PUBLIC,
                $prepared,
            );
            $this->attachments->bind($drafts, $ticket, $message, $prepared['images']);
            // Writing a reply means having read what came before it
            $this->unread->markReadUpTo($ticket, $author, $message->id);

            if (! $internal) {
                $ticket->last_activity_at = now();

                if ($asStaff) {
                    $ticket->last_staff_reply_at = now();
                    $ticket->first_response_at ??= now();
                } else {
                    $ticket->last_requester_reply_at = now();
                }

                if ($next = $this->workflow->statusAfterReply($ticket, $asStaff, false)) {
                    $this->workflow->apply($ticket, $next);
                    $this->afterStatusChange($ticket);
                }
            }

            $ticket->save();

            $this->record($ticket, $internal ? 'note' : 'reply', $author, ['message_id' => $message->id], $internal);

            return $message;
        });

        // The status moving because of a reply is not worth a second email: the
        // reply notification already says what happened.
        $this->afterCommit(function () use ($ticket, $message, $author) {
            $ticket->load(['requester', 'customerAssignee', 'supportAssignee', 'organization']);
            $message->setRelation('author', $author);
            $this->notifier->messageAdded($ticket, $message);

            // Internal notes never leave the system
            if (! $message->isInternal()) {
                $this->integrate('message.created', $ticket, ['message' => $message], $message->id);
            }
        });

        return $message;
    }

    /**
     * An author may edit their own message until staff reply after it, and for a
     * limited time. Staff may always edit their own messages. Redacted messages
     * are never editable.
     */
    public function canEditMessage(TicketMessage $message, User $user, bool $asStaff): bool
    {
        if ($message->author_id !== $user->id || $message->redacted_at !== null) {
            return false;
        }

        if ($asStaff) {
            return true;
        }

        if ($message->isInternal() || $message->ticket->status === TicketStatus::CLOSED) {
            return false;
        }

        if ($message->created_at->lt(now()->subMinutes(config('tickets.edit_window_minutes')))) {
            return false;
        }

        return ! $message->ticket->messages()
            ->where('id', '>', $message->id)
            ->where('author_kind', TicketMessage::KIND_STAFF)
            ->where('visibility', TicketMessage::PUBLIC)
            ->exists();
    }

    public function editMessage(TicketMessage $message, User $editor, string $body, bool $asStaff): TicketMessage
    {
        if (! $this->canEditMessage($message, $editor, $asStaff)) {
            throw new AuthorizationException(__('tickets.errors.cannot_edit'));
        }

        // An edit can keep showing the message's own images but cannot add files
        $own = $message->attachments()->pluck('id')->all();
        $prepared = $this->html->prepare($body, 'body', $own, $own !== []);

        if ($prepared['html'] === $message->body_html) {
            return $message;
        }

        return DB::transaction(function () use ($message, $editor, $prepared) {
            TicketMessageRevision::create([
                'ticket_message_id' => $message->id,
                'body_html' => $message->body_html,
                'edited_by' => $editor->id,
            ]);

            $message->body_html = $prepared['html'];
            $message->body_text = $prepared['text'];
            $message->edited_at = now();
            $message->save();

            $this->record($message->ticket, 'message_edited', $editor, ['message_id' => $message->id], $message->isInternal());

            return $message;
        });
    }

    /**
     * Subject and impact. Members may change them until staff first respond.
     *
     * @param  array{subject?: string, impact?: string}  $data
     */
    public function updateDetails(Ticket $ticket, User $actor, array $data, bool $asStaff): Ticket
    {
        if (! $asStaff && $ticket->first_response_at !== null) {
            throw new AuthorizationException(__('tickets.errors.cannot_edit'));
        }

        return DB::transaction(function () use ($ticket, $actor, $data) {
            foreach (['subject', 'impact'] as $field) {
                if (! Arr::has($data, $field)) {
                    continue;
                }

                $value = $field === 'impact' ? TicketImpact::from($data[$field]) : $data[$field];
                $old = $ticket->{$field};

                if ($old === $value) {
                    continue;
                }

                $ticket->{$field} = $value;
                $this->record($ticket, $field.'_changed', $actor, [
                    'from' => $old instanceof \BackedEnum ? $old->value : $old,
                    'to' => $value instanceof \BackedEnum ? $value->value : $value,
                ]);
            }

            $ticket->save();

            return $ticket;
        });
    }

    public function changeStatus(Ticket $ticket, ?User $actor, TicketStatus $to, bool $asStaff): Ticket
    {
        $from = $ticket->status;

        if ($ticket->isMerged()) {
            throw ValidationException::withMessages(['status' => __('tickets.errors.merged')]);
        }

        if (! $this->workflow->canChange($from, $to, $asStaff)) {
            throw ValidationException::withMessages(['status' => __('tickets.errors.invalid_transition')]);
        }

        $event = null;

        DB::transaction(function () use ($ticket, $actor, $from, $to, &$event) {
            $this->workflow->apply($ticket, $to);
            $this->afterStatusChange($ticket);
            $ticket->last_activity_at = now();
            $ticket->save();

            $event = $this->record($ticket, 'status_changed', $actor, ['from' => $from->value, 'to' => $to->value] + ($actor === null ? ['auto' => true] : []));
        });

        $this->afterCommit(function () use ($ticket, $from, $actor, &$event) {
            $ticket->load(['requester', 'customerAssignee', 'supportAssignee', 'organization']);
            $this->notifier->statusChanged($ticket, $from, $actor);
            $this->integrate('status.changed', $ticket, ['from' => $from->value], $event->id);
        });

        return $ticket;
    }

    public function changePriority(Ticket $ticket, User $actor, TicketPriority $priority): Ticket
    {
        if ($ticket->priority === $priority) {
            return $ticket;
        }

        $from = $ticket->priority;

        DB::transaction(function () use ($ticket, $actor, $priority, $from) {
            $ticket->priority = $priority;
            $this->sla->apply($ticket);
            $ticket->save();
            $this->record($ticket, 'priority_changed', $actor, ['from' => $from->value, 'to' => $priority->value], true);
        });

        return $ticket;
    }

    public function changeCategory(Ticket $ticket, User $actor, ?int $categoryId): Ticket
    {
        $category = $this->activeCategory($categoryId);

        if ($ticket->category_id === $category?->id) {
            return $ticket;
        }

        DB::transaction(function () use ($ticket, $actor, $category) {
            $from = $ticket->category_id;
            $ticket->category_id = $category?->id;
            $this->sla->apply($ticket);
            $ticket->save();
            $this->record($ticket, 'category_changed', $actor, ['from' => $from, 'to' => $category?->id], true);
        });

        return $ticket;
    }

    /**
     * Set or clear one side's assignee.
     *
     * Customer side: any active member of the ticket's own organization.
     * Support side: a staff member.
     *
     * @param  string  $side  self::CUSTOMER|self::SUPPORT
     */
    public function assign(Ticket $ticket, string $side, ?User $assignee, ?User $actor): Ticket
    {
        $column = $side === self::SUPPORT ? 'support_assignee_id' : 'customer_assignee_id';

        if ($assignee) {
            $this->assertCanBeAssigned($ticket, $side, $assignee);
        }

        if ($ticket->{$column} === $assignee?->id) {
            return $ticket;
        }

        $event = null;

        DB::transaction(function () use ($ticket, $side, $assignee, $actor, $column, &$event) {
            $from = $ticket->{$column};
            $ticket->{$column} = $assignee?->id;
            $ticket->save();

            $event = $this->record($ticket, 'assigned', $actor, ['side' => $side, 'from' => $from, 'to' => $assignee?->id]);
        });

        $this->afterCommit(function () use ($ticket, $side, $assignee, $actor, &$event) {
            $this->notifier->assigned($ticket, $side, $assignee, $actor);
            $this->integrate('assigned', $ticket, ['side' => $side, 'assignee' => $assignee?->name], $event->id);
        });

        return $ticket;
    }

    /**
     * Replace the ticket's tags. Staff only, and never shown to customers.
     *
     * @param  list<string>  $names
     */
    public function syncTags(Ticket $ticket, array $names, User $actor): Ticket
    {
        $names = collect($names)->map(fn ($name) => mb_strtolower(trim((string) $name)))->filter()->unique()->take(20)->values();

        DB::transaction(function () use ($ticket, $names, $actor) {
            $ids = $names->map(fn (string $name) => TicketTag::firstOrCreate(['name' => mb_substr($name, 0, 50)])->id)->all();
            $changes = $ticket->tags()->sync($ids);

            if ($changes['attached'] || $changes['detached']) {
                $this->record($ticket, 'tags_changed', $actor, ['tags' => $names->all()], true);
            }
        });

        return $ticket;
    }

    /**
     * Record time a staff member spent on the ticket. It is internal (never
     * shown to the customer) but is sent on to trackers that follow the ticket.
     */
    public function logTime(Ticket $ticket, User $actor, int $minutes, ?string $note = null, bool $billable = true, ?string $workedAt = null): TicketTimeEntry
    {
        if ($minutes < 1 || $minutes > 1440) {
            throw ValidationException::withMessages(['duration' => __('tickets.errors.invalid_duration')]);
        }

        $entry = DB::transaction(function () use ($ticket, $actor, $minutes, $note, $billable, $workedAt) {
            $entry = new TicketTimeEntry;
            $entry->ticket_id = $ticket->id;
            $entry->user_id = $actor->id;
            $entry->minutes = $minutes;
            $entry->note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null;
            $entry->billable = $billable;
            $entry->worked_at = $workedAt ?: now()->toDateString();
            $entry->save();

            $this->record($ticket, 'time_logged', $actor, ['minutes' => $minutes, 'billable' => $billable], true);

            return $entry;
        });

        $this->afterCommit(function () use ($ticket, $entry) {
            $this->integrate('time.logged', $ticket, ['time' => $entry], $entry->id);
        });

        return $entry;
    }

    /**
     * Remove an entry made by mistake. Trackers that already heard about it are
     * not told: time cannot be taken back out of a comment or a tracker's total.
     */
    public function deleteTimeEntry(Ticket $ticket, TicketTimeEntry $entry, User $actor): void
    {
        if ($entry->ticket_id !== $ticket->id) {
            throw new AuthorizationException(__('tickets.errors.cannot_edit'));
        }

        DB::transaction(function () use ($ticket, $entry, $actor) {
            $minutes = $entry->minutes;
            $entry->delete();
            $this->record($ticket, 'time_deleted', $actor, ['minutes' => $minutes], true);
        });
    }

    /**
     * Ask a tracker integration to open this ticket's issue now.
     */
    public function createIssue(Ticket $ticket, TicketIntegration $integration, User $actor): bool
    {
        $delivery = $this->integrations->createIssue($integration, $ticket);

        if ($delivery) {
            DB::transaction(fn () => $this->record($ticket, 'issue_requested', $actor, ['integration' => $integration->name], true));
        }

        return $delivery !== null;
    }

    /**
     * Fold one ticket into another (a duplicate, or the same problem reported
     * twice). The conversation, files and time move to the target so there is
     * one thread; the source is closed with a pointer to where it went.
     * Only tickets of the same organization can be merged.
     */
    public function merge(Ticket $source, Ticket $target, User $actor): Ticket
    {
        $invalid = match (true) {
            $source->is($target) => __('tickets.errors.merge_self'),
            $source->organization_id !== $target->organization_id => __('tickets.errors.merge_organization'),
            $source->isMerged() || $target->isMerged() => __('tickets.errors.merged'),
            ! $target->status->isOpen() => __('tickets.errors.merge_target_closed'),
            default => null,
        };

        if ($invalid) {
            throw ValidationException::withMessages(['target' => $invalid]);
        }

        $moved = 0;

        DB::transaction(function () use ($source, $target, $actor, &$moved) {
            $moved = TicketMessage::where('ticket_id', $source->id)->update(['ticket_id' => $target->id]);
            TicketAttachment::where('ticket_id', $source->id)->update(['ticket_id' => $target->id]);
            TicketTimeEntry::where('ticket_id', $source->id)->update(['ticket_id' => $target->id]);

            // The person who opened the source stays in the loop on the surviving ticket
            if ($source->requester_id && $source->requester_id !== $target->requester_id && $source->requester_id !== $target->customer_assignee_id) {
                $this->subscriptions->addWatcher($target, $source->requester_id, TicketSubscription::CUSTOMER);
            }

            foreach ($source->tags as $tag) {
                $target->tags()->syncWithoutDetaching([$tag->id]);
            }

            $target->first_response_at = collect([$target->first_response_at, $source->first_response_at])->filter()->min();
            $target->last_activity_at = now();
            $target->save();

            $this->workflow->apply($source, TicketStatus::CLOSED);
            $source->merged_into_id = $target->id;
            $source->last_activity_at = now();
            $source->save();

            // So the closed ticket does not look empty to the people who opened it
            $note = new TicketMessage;
            $note->ticket_id = $source->id;
            $note->author_kind = TicketMessage::KIND_SYSTEM;
            $note->visibility = TicketMessage::PUBLIC;
            $note->body_html = '<p>'.e(__('tickets.merged_note', ['target' => $target->reference])).'</p>';
            $note->body_text = __('tickets.merged_note', ['target' => $target->reference]);
            $note->save();

            $this->record($source, 'merged_into', $actor, ['target' => $target->reference, 'target_number' => $target->number]);
            $this->record($target, 'merged', $actor, ['source' => $source->reference, 'messages' => $moved]);
        });

        $this->afterCommit(function () use ($source, $target) {
            $source->load(['requester', 'customerAssignee', 'supportAssignee', 'organization']);
            $this->notifier->merged($source, $target);
        });

        return $target;
    }

    /**
     * A customer's rating of how the ticket was handled (1 to 5). Only once
     * the ticket is resolved or closed; the latest rating stands.
     */
    public function rate(Ticket $ticket, User $by, int $score, ?string $comment = null): Ticket
    {
        if ($ticket->status->isOpen() || $ticket->isMerged()) {
            throw ValidationException::withMessages(['score' => __('tickets.errors.cannot_rate')]);
        }

        if ($score < 1 || $score > 5) {
            throw ValidationException::withMessages(['score' => __('tickets.errors.invalid_score')]);
        }

        DB::transaction(function () use ($ticket, $by, $score, $comment) {
            $ticket->csat_score = $score;
            $ticket->csat_comment = $comment !== null && trim($comment) !== '' ? mb_substr(trim($comment), 0, 1000) : null;
            $ticket->csat_at = now();
            $ticket->save();

            $this->record($ticket, 'rated', $by, ['score' => $score], true);
        });

        $this->afterCommit(function () use ($ticket, $by, $score) {
            if ($score <= 2) {
                $ticket->load(['requester', 'customerAssignee', 'supportAssignee', 'organization']);
                $this->notifier->lowRating($ticket, $by, $score);
            }
        });

        return $ticket;
    }

    /**
     * Take a message out for good (a password pasted by mistake, personal data).
     * The text, its edit history and its files are deleted; a placeholder stays
     * so the conversation still reads in order. Copies already sent to
     * integrations are scrubbed too.
     */
    public function redactMessage(Ticket $ticket, TicketMessage $message, User $actor): TicketMessage
    {
        if ($message->ticket_id !== $ticket->id) {
            throw new AuthorizationException(__('tickets.errors.cannot_edit'));
        }

        DB::transaction(function () use ($ticket, $message, $actor) {
            TicketAttachment::where('ticket_message_id', $message->id)->each(fn (TicketAttachment $attachment) => $attachment->delete());
            TicketMessageRevision::where('ticket_message_id', $message->id)->delete();

            $message->body_html = '';
            $message->body_text = '';
            $message->redacted_at = now();
            $message->save();

            TicketDelivery::where('ticket_id', $ticket->id)->each(function (TicketDelivery $delivery) use ($message) {
                $payload = $delivery->payload;

                if (($payload['message']['id'] ?? null) === $message->id) {
                    foreach (['body_markdown', 'body_text', 'body_html'] as $field) {
                        $payload['message'][$field] = '';
                    }
                    $payload['message']['attachments'] = [];
                    $delivery->payload = $payload;
                    $delivery->save();
                }
            });

            $this->record($ticket, 'message_redacted', $actor, ['message_id' => $message->id], true);
        });

        return $message;
    }

    /**
     * Add a note written by the system itself (for example "the linked issue
     * was closed"). Internal by default, so customers never see it.
     */
    public function addSystemNote(Ticket $ticket, string $text, bool $internal = true): TicketMessage
    {
        $prepared = $this->html->prepare('<p>'.e($text).'</p>');

        $message = DB::transaction(function () use ($ticket, $prepared, $internal) {
            $message = new TicketMessage;
            $message->ticket_id = $ticket->id;
            $message->author_kind = TicketMessage::KIND_SYSTEM;
            $message->visibility = $internal ? TicketMessage::INTERNAL : TicketMessage::PUBLIC;
            $message->body_html = $prepared['html'];
            $message->body_text = $prepared['text'];
            $message->source = 'system';
            $message->save();
            $message->setRelation('ticket', $ticket);

            $this->record($ticket, $internal ? 'note' : 'reply', null, ['message_id' => $message->id], $internal);

            return $message;
        });

        $this->afterCommit(function () use ($ticket, $message) {
            $ticket->load(['requester', 'customerAssignee', 'supportAssignee', 'organization']);
            $this->notifier->messageAdded($ticket, $message);
        });

        return $message;
    }

    /**
     * Tell the customer a ticket has been waiting on them. Once per wait.
     */
    public function sendReminder(Ticket $ticket): void
    {
        DB::transaction(function () use ($ticket) {
            $ticket->reminder_sent_at = now();
            $ticket->save();
            $this->record($ticket, 'reminder_sent', null, [], false);
        });

        $this->afterCommit(function () use ($ticket) {
            $ticket->load(['requester', 'customerAssignee', 'supportAssignee', 'organization']);
            $this->notifier->reminder($ticket);
        });
    }

    /**
     * Mark tickets whose service targets have run out, and warn about those
     * about to. Run on a schedule; each ticket is flagged once per clock.
     *
     * @return array{warned: int, breached: int}
     */
    public function sweepSla(): array
    {
        $counts = ['warned' => 0, 'breached' => 0];

        $candidates = Ticket::query()
            ->whereIn('status', TicketStatus::openValues())
            ->whereNull('merged_into_id')
            ->whereNotNull('sla_policy_id')
            ->where(function ($query) {
                $query->where(fn ($q) => $q->whereNull('first_response_at')->whereNotNull('sla_first_response_due_at')->whereNull('sla_first_response_breached_at'))
                    ->orWhere(fn ($q) => $q->whereNotNull('sla_resolution_due_at')->whereNull('sla_resolution_breached_at')->whereNull('sla_paused_at'));
            })
            ->get();

        foreach ($candidates as $ticket) {
            $state = $this->sla->state($ticket);

            foreach (['first_response' => 'sla_first_response', 'resolution' => 'sla_resolution'] as $clock => $column) {
                if ($ticket->{$column.'_breached_at'} !== null) {
                    continue;
                }

                if ($state[$clock] === 'breached') {
                    $this->flagSla($ticket, $clock, $column, 'breached');
                    $counts['breached']++;
                } elseif ($state[$clock] === 'warning' && $ticket->{$column.'_warned_at'} === null) {
                    $this->flagSla($ticket, $clock, $column, 'warning');
                    $counts['warned']++;
                }
            }
        }

        return $counts;
    }

    private function flagSla(Ticket $ticket, string $clock, string $column, string $kind): void
    {
        DB::transaction(function () use ($ticket, $clock, $column, $kind) {
            $ticket->{$column.($kind === 'breached' ? '_breached_at' : '_warned_at')} = now();
            $ticket->save();
            $this->record($ticket, 'sla_'.$kind, null, ['clock' => $clock], true);
        });

        $this->afterCommit(function () use ($ticket, $clock, $kind) {
            $ticket->load(['requester', 'customerAssignee', 'supportAssignee', 'organization']);
            $this->notifier->slaAlert($ticket, $kind, $clock);
        });
    }

    /**
     * After any status change: keep the SLA clock and the reminder in step.
     */
    private function afterStatusChange(Ticket $ticket): void
    {
        $this->sla->statusChanged($ticket);

        // A new wait on the customer gets its own reminder
        if ($ticket->status !== TicketStatus::PENDING_CUSTOMER) {
            $ticket->reminder_sent_at = null;
        }
    }

    /**
     * Make exactly $userIds the watchers (CC) of the ticket.
     *
     * Customers can CC active members of their own organization. Staff can CC
     * those too, and other staff. A customer changing the list never touches
     * the staff watchers.
     *
     * @param  array<int, mixed>  $userIds
     */
    public function syncWatchers(Ticket $ticket, array $userIds, User $actor, bool $asStaff): Ticket
    {
        $ids = collect($userIds)->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id)->unique()->values();
        $users = User::whereIn('id', $ids)->get()->keyBy('id');

        if ($users->count() !== $ids->count()) {
            throw ValidationException::withMessages(['user_ids' => __('tickets.errors.invalid_watcher')]);
        }

        $desired = [];

        foreach ($users as $user) {
            $side = match (true) {
                ! $this->staff->isActive($user) => null,
                $asStaff && $this->staff->isStaff($user) => TicketSubscription::SUPPORT,
                $user->organization_id === $ticket->organization_id => TicketSubscription::CUSTOMER,
                default => null,
            };

            if ($side === null) {
                throw ValidationException::withMessages(['user_ids' => __('tickets.errors.invalid_watcher')]);
            }

            $desired[$user->id] = $side;
        }

        DB::transaction(function () use ($ticket, $desired, $actor, $asStaff) {
            $changes = $this->subscriptions->sync(
                $ticket,
                $desired,
                $asStaff ? [TicketSubscription::CUSTOMER, TicketSubscription::SUPPORT] : [TicketSubscription::CUSTOMER],
            );

            if ($changes['added'] || $changes['removed']) {
                $this->record($ticket, 'watchers_changed', $actor, $changes, true);
            }

            return $changes;
        });

        return $ticket;
    }

    /**
     * Mute or unmute the ticket for one person. Muting silences everything
     * except being assigned something.
     */
    public function setMuted(Ticket $ticket, User $user, bool $muted, bool $asStaff): void
    {
        $this->subscriptions->setMuted($ticket, $user, $muted, $asStaff ? TicketSubscription::SUPPORT : TicketSubscription::CUSTOMER);
    }

    /**
     * Fix tickets whose customer assignee (or support assignee) was deleted or
     * deactivated. Safe to run repeatedly; only touches tickets that need it.
     *
     * Customer side: reassign to the requester if they can still use the panel,
     * otherwise leave it unassigned. Organization owners hear about it only
     * while the ticket is still open. Support side: simply unassigned.
     *
     * @return int number of tickets changed
     */
    public function sweepRemovedAssignees(?int $organizationId = null): int
    {
        $inactive = User::withTrashed()
            ->where(fn ($query) => $query->whereNotNull('deleted_at')->orWhere('is_allowed', false))
            ->select('id');

        $changed = 0;

        $customerSide = Ticket::query()
            ->when($organizationId, fn ($query) => $query->where('organization_id', $organizationId))
            ->whereIn('customer_assignee_id', $inactive)
            ->with(['requester', 'organization'])
            ->get();

        foreach ($customerSide as $ticket) {
            $requester = $ticket->requester;
            $replacement = $this->staff->isActive($requester) && $requester->id !== $ticket->customer_assignee_id ? $requester : null;
            $removed = $ticket->customer_assignee_id;

            DB::transaction(function () use ($ticket, $replacement, $removed) {
                $ticket->customer_assignee_id = $replacement?->id;
                $ticket->save();
                $this->record($ticket, 'assignee_removed', null, ['side' => self::CUSTOMER, 'from' => $removed, 'to' => $replacement?->id]);
            });

            if ($ticket->status->isOpen()) {
                $this->afterCommit(function () use ($ticket, $replacement) {
                    $this->notifier->assigned($ticket, self::CUSTOMER, $replacement, null);
                    $this->notifier->assigneeRemoved($ticket);
                });
            }

            $changed++;
        }

        $supportSide = Ticket::query()
            ->when($organizationId, fn ($query) => $query->where('organization_id', $organizationId))
            ->whereIn('support_assignee_id', $inactive)
            ->get();

        foreach ($supportSide as $ticket) {
            $removed = $ticket->support_assignee_id;
            $ticket->support_assignee_id = null;
            $ticket->save();
            $this->record($ticket, 'assignee_removed', null, ['side' => self::SUPPORT, 'from' => $removed, 'to' => null], true);
            $changed++;
        }

        return $changed;
    }

    private function resolveCustomerAssignee(User $requester, mixed $assigneeId): User
    {
        if (! $assigneeId || (int) $assigneeId === $requester->id) {
            return $requester;
        }

        $assignee = User::find($assigneeId);

        if (! $assignee) {
            throw ValidationException::withMessages(['customer_assignee_id' => __('tickets.errors.invalid_assignee')]);
        }

        // Validated against the requester's organization before the ticket exists
        if ($assignee->organization_id !== $requester->organization_id || ! $this->staff->isActive($assignee)) {
            throw ValidationException::withMessages(['customer_assignee_id' => __('tickets.errors.invalid_assignee')]);
        }

        return $assignee;
    }

    private function assertCanBeAssigned(Ticket $ticket, string $side, User $assignee): void
    {
        $valid = $this->staff->isActive($assignee) && match ($side) {
            self::SUPPORT => $this->staff->isStaff($assignee),
            default => $assignee->organization_id === $ticket->organization_id,
        };

        if (! $valid) {
            throw ValidationException::withMessages([
                $side === self::SUPPORT ? 'support_assignee_id' : 'customer_assignee_id' => __('tickets.errors.invalid_assignee'),
            ]);
        }
    }

    private function activeCategory(mixed $categoryId): ?TicketCategory
    {
        return $categoryId ? TicketCategory::where('active', true)->find($categoryId) : null;
    }

    private function defaultAssigneeFor(TicketCategory $category): ?int
    {
        $assignee = $category->default_support_assignee_id ? User::find($category->default_support_assignee_id) : null;

        return $this->staff->isActive($assignee) ? $assignee->id : null;
    }

    /**
     * @param  array{html: string, text: string, images?: list<int>}  $body
     */
    private function addMessage(Ticket $ticket, User $author, string $kind, string $visibility, array $body, string $source = 'web'): TicketMessage
    {
        $message = new TicketMessage;
        $message->ticket_id = $ticket->id;
        $message->author_id = $author->id;
        $message->author_kind = $kind;
        $message->visibility = $visibility;
        $message->body_html = $body['html'];
        $message->body_text = $body['text'];
        $message->source = $source;
        $message->save();
        $message->setRelation('ticket', $ticket);

        return $message;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(Ticket $ticket, string $type, ?User $actor, array $context = [], bool $internal = false): TicketEvent
    {
        $event = new TicketEvent;
        $event->ticket_id = $ticket->id;
        $event->actor_id = $actor?->id;
        $event->type = $type;
        $event->context = $context ?: null;
        $event->internal = $internal;
        $event->save();

        // Open pages poll with this token: change it once the write is visible
        $this->afterCommit(fn () => $this->versions->bump($ticket));

        return $event;
    }

    /**
     * Numbers are sequential. Two requests can pick the same one, so the unique
     * index decides and the loser simply tries the next number.
     */
    private function saveWithNumber(Ticket $ticket): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                DB::transaction(function () use ($ticket) {
                    $ticket->number = (int) Ticket::withTrashed()->max('number') + 1;
                    $ticket->save();
                });

                return;
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 5) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Tell integrations about something. A broken integration must never get in
     * the way of the ticket itself, so any failure is reported and swallowed.
     *
     * @param  array<string, mixed>  $subject
     */
    private function integrate(string $event, Ticket $ticket, array $subject, string|int $key): void
    {
        try {
            $this->integrations->dispatch($event, $ticket, $subject, $key);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function afterCommit(callable $callback): void
    {
        DB::afterCommit($callback);
    }
}
