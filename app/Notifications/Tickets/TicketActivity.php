<?php

namespace App\Notifications\Tickets;

use App\Channels\PanelChannel;
use App\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

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

        return $mail
            ->action(__('tickets.notifications.view'), $this->url())
            ->line(__('tickets.notifications.reply_in_panel'));
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
