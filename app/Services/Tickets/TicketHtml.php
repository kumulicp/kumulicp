<?php

namespace App\Services\Tickets;

use App\TicketAttachment;
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
     * Images are only kept when they point at one of $imageIds, the attachments
     * this message is allowed to show. Remote images, data: images and images
     * of someone else's upload are removed. A message may be only images or only
     * attachments, but not nothing at all.
     *
     * @param  list<int>  $imageIds
     * @return array{html: string, text: string, images: list<int>}
     *
     * @throws ValidationException
     */
    public function prepare(string $html, string $field = 'body', array $imageIds = [], bool $hasAttachments = false): array
    {
        [$clean, $images] = $this->keepOwnImages($this->sanitize($html), $imageIds);
        $text = $this->toText($clean);

        if ($text === '' && $images === [] && ! $hasAttachments) {
            throw ValidationException::withMessages([$field => __('tickets.validation.body_required')]);
        }

        if (mb_strlen($text) > config('tickets.max_body_characters')) {
            throw ValidationException::withMessages([$field => __('tickets.validation.body_too_long', ['max' => config('tickets.max_body_characters')])]);
        }

        return ['html' => $clean, 'text' => $text, 'images' => $images];
    }

    /**
     * Rewrite every <img> in already-sanitized HTML to our canonical form, or
     * drop it. The purifier's output always has double-quoted attributes, so
     * reading src out of it is reliable.
     *
     * @param  list<int>  $allowedIds
     * @return array{0: string, 1: list<int>}
     */
    private function keepOwnImages(string $html, array $allowedIds): array
    {
        $used = [];

        $html = preg_replace_callback('#<img\b[^>]*>#i', function (array $match) use ($allowedIds, &$used) {
            if (! preg_match('#\ssrc="/support/tickets/attachments/(\d+)"#i', $match[0], $src)) {
                return '';
            }

            $id = (int) $src[1];

            if (! in_array($id, $allowedIds, true)) {
                return '';
            }

            $alt = preg_match('#\salt="([^"]*)"#i', $match[0], $alt_match) ? $alt_match[1] : '';
            $used[] = $id;

            return '<img src="'.TicketAttachment::urlFor($id).'" alt="'.$alt.'">';
        }, $html) ?? $html;

        return [$html, array_values(array_unique($used))];
    }
}
