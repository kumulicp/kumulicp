<?php

namespace App\Services\Tickets;

use App\Enums\TicketImpact;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Ticket;
use App\TicketCategory;
use App\TicketEvent;
use App\TicketMessage;
use App\TicketMessageRevision;
use App\TicketSubscription;
use App\TicketTag;
use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

            if ($category = $this->activeCategory(Arr::get($data, 'category_id'))) {
                $ticket->category_id = $category->id;
                $ticket->support_assignee_id = $this->defaultAssigneeFor($category);
            }

            $this->saveWithNumber($ticket);

            $message = $this->addMessage($ticket, $requester, TicketMessage::KIND_REQUESTER, TicketMessage::PUBLIC, $body, $source);
            $this->attachments->bind($drafts, $ticket, $message, $body['images']);
            $this->record($ticket, 'created', $requester);

            if ($ticket->support_assignee_id) {
                $this->record($ticket, 'assigned', $requester, ['side' => self::SUPPORT, 'to' => $ticket->support_assignee_id]);
            }

            return $ticket;
        });

        $this->afterCommit(function () use ($ticket) {
            $ticket->load(['requester', 'customerAssignee', 'supportAssignee', 'organization']);
            $this->notifier->created($ticket);
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

    public function changeStatus(Ticket $ticket, User $actor, TicketStatus $to, bool $asStaff): Ticket
    {
        $from = $ticket->status;

        if (! $this->workflow->canChange($from, $to, $asStaff)) {
            throw ValidationException::withMessages(['status' => __('tickets.errors.invalid_transition')]);
        }

        DB::transaction(function () use ($ticket, $actor, $from, $to) {
            $this->workflow->apply($ticket, $to);
            $ticket->last_activity_at = now();
            $ticket->save();

            $this->record($ticket, 'status_changed', $actor, ['from' => $from->value, 'to' => $to->value]);
        });

        $this->afterCommit(function () use ($ticket, $from, $actor) {
            $ticket->load(['requester', 'customerAssignee', 'supportAssignee', 'organization']);
            $this->notifier->statusChanged($ticket, $from, $actor);
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

        DB::transaction(function () use ($ticket, $side, $assignee, $actor, $column) {
            $from = $ticket->{$column};
            $ticket->{$column} = $assignee?->id;
            $ticket->save();

            $this->record($ticket, 'assigned', $actor, ['side' => $side, 'from' => $from, 'to' => $assignee?->id]);
        });

        $this->afterCommit(function () use ($ticket, $side, $assignee, $actor) {
            $this->notifier->assigned($ticket, $side, $assignee, $actor);
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

    private function afterCommit(callable $callback): void
    {
        DB::afterCommit($callback);
    }
}
