<?php

namespace App\Notifications\Tickets;

use App\Channels\PanelChannel;
use App\Enums\TicketStatus;
use App\Services\Tickets\TicketPreferences;
use App\Services\Tickets\TicketSubscriptions;
use App\Services\Tickets\TicketUnread;
use App\Ticket;
use App\TicketMessage;
use App\User;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

/**
 * One notification for everything that happens on a ticket. `event` is one of
 * created, reply, note, status, assigned, assignee_removed and picks the wording.
 *
 * Never built from internal content for a customer: the notifier decides who
 * receives which event.
 */
class TicketActivity extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, string>  $context  Plain strings used in the wording (name, status, excerpt, side)
     * @param  list<string>|null  $channels  Override the delivery channels (e.g. panel only)
     */
    public function __construct(
        public Ticket $ticket,
        public string $event,
        public bool $staffView = false,
        public array $context = [],
        public ?array $channels = null,
        public ?int $messageId = null,
    ) {}

    public function via(object $notifiable): array
    {
        if ($this->channels !== null) {
            return $this->channels;
        }

        if ($notifiable instanceof AnonymousNotifiable) {
            return ['mail'];
        }

        return [PanelChannel::class, 'mail'];
    }

    /**
     * Per person and per channel, at the moment of sending:
     *  - their notification preferences for this kind of event
     *  - muting the ticket (still told when something is assigned to them)
     *  - for reply and note emails, coalescing: skipped when they have read the
     *    message in the meantime or a newer one from the same person follows
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        if (! $notifiable instanceof User) {
            return true;
        }

        $kind = $channel === 'mail' ? 'mail' : 'panel';

        if (! app(TicketPreferences::class)->allows($notifiable, $this->preferenceKey(), $kind)) {
            return false;
        }

        $direct = in_array($this->event, ['assigned', 'assignee_removed'], true);

        if (! $direct && app(TicketSubscriptions::class)->isMuted($this->ticket, $notifiable)) {
            return false;
        }

        if ($kind === 'mail' && $this->messageId !== null) {
            $message = TicketMessage::find($this->messageId);

            return $message === null || app(TicketUnread::class)->shouldEmail($message, $notifiable);
        }

        return true;
    }

    /**
     * Reply and note emails wait a little so a burst becomes one email. The
     * panel notification is immediate.
     */
    public function withDelay(object $notifiable, string $channel): ?DateTimeInterface
    {
        $seconds = config('tickets.mail_delay_seconds');

        return $channel === 'mail' && $this->messageId !== null && $seconds > 0
            ? now()->addSeconds($seconds)
            : null;
    }

    /**
     * Which of the person's switches governs this event.
     */
    private function preferenceKey(): string
    {
        return match ($this->event) {
            'sla_warning', 'sla_breached' => 'sla',
            'merged' => 'status',
            default => $this->event,
        };
    }

    public function url(): string
    {
        return url(($this->staffView ? '/admin/support/tickets/' : '/support/tickets/').$this->ticket->number);
    }

    public function title(): string
    {
        return __('tickets.notifications.'.$this->event.'.title', $this->wording());
    }

    public function line(): string
    {
        return __('tickets.notifications.'.$this->event.'.line', $this->wording());
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('['.$this->ticket->reference.'] '.$this->ticket->subject)
            ->greeting($this->title())
            ->line($this->line());

        if (! empty($this->context['excerpt'])) {
            $mail->line('> '.str_replace("\n", "\n> ", $this->context['excerpt']));
        }

        // Once a ticket is resolved the customer is invited to say how it went
        if ($this->event === 'status' && ! $this->staffView && $this->ticket->status === TicketStatus::RESOLVED) {
            $mail->line(__('tickets.notifications.rate_prompt'));
        }

        return $mail
            ->action(__('tickets.notifications.view'), $this->url())
            ->line(__('tickets.notifications.reply_in_panel'))
            ->withSymfonyMessage(function (Email $message) {
                $this->thread($message);
            });
    }

    /**
     * Make a ticket's emails one conversation in mail clients. The first email
     * ("created") carries the root id; every later one points back to it.
     */
    private function thread(Email $message): void
    {
        $root = 'ticket-'.$this->ticket->id.'@'.($this->mailDomain());
        $headers = $message->getHeaders();

        if ($this->event === 'created') {
            $headers->remove('Message-ID');
            $headers->addIdHeader('Message-ID', $root);

            return;
        }

        $headers->addIdHeader('In-Reply-To', $root);
        $headers->addIdHeader('References', $root);
    }

    private function mailDomain(): string
    {
        return parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';
    }

    /**
     * @return array{title: string, message: string, url: string}
     */
    public function toPanel(): array
    {
        return [
            'title' => $this->title(),
            'message' => $this->line(),
            'url' => $this->url(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function wording(): array
    {
        return array_merge([
            'reference' => $this->ticket->reference,
            'subject' => $this->ticket->subject,
        ], $this->context);
    }
}
