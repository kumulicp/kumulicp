<?php

namespace App\Services\Tickets;

use Illuminate\Validation\ValidationException;

/**
 * The single place ticket HTML is cleaned. Client HTML is never trusted: the
 * `ticket` purifier profile is the security boundary, and what it returns is
 * the only thing stored or rendered.
 */
class TicketHtml
{
    public function sanitize(string $html): string
    {
        return trim((string) clean($html, 'ticket'));
    }

    /**
     * Plain text for search, email excerpts and excerpts in notifications.
     */
    public function toText(string $html): string
    {
        $text = preg_replace('#</(p|li|h[1-6]|blockquote|pre|ul|ol)>|<br\s*/?>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }

    /**
     * Clean a submitted body and make sure something is left.
     *
     * @return array{html: string, text: string}
     *
     * @throws ValidationException
     */
    public function prepare(string $html, string $field = 'body'): array
    {
        $clean = $this->sanitize($html);
        $text = $this->toText($clean);

        if ($text === '') {
            throw ValidationException::withMessages([$field => __('tickets.validation.body_required')]);
        }

        if (mb_strlen($text) > config('tickets.max_body_characters')) {
            throw ValidationException::withMessages([$field => __('tickets.validation.body_too_long', ['max' => config('tickets.max_body_characters')])]);
        }

        return ['html' => $clean, 'text' => $text];
    }
}
