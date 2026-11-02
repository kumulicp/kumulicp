<?php

namespace App\Services\Tickets\Integrations\Drivers;

use App\Contracts\Tickets\TracksIssues;
use App\Services\Tickets\Integrations\Capability;
use App\Services\Tickets\Integrations\IntegrationException;
use App\Services\Tickets\Integrations\OutboundHttp;
use App\Services\Tickets\Integrations\TrackerText;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * Shared by tracker drivers: what an issue, a comment and a time note say.
 * Everything built here passes through TrackerText::neutralize(), so a
 * customer's words can neither mention people nor run slash commands with the
 * integration's token. A third-party tracker driver should extend this too.
 */
abstract class AbstractTracker implements TracksIssues
{
    public function __construct(protected OutboundHttp $http) {}

    public function capabilities(): array
    {
        return [Capability::CREATE_ISSUE, Capability::COMMENT, Capability::STATE_SYNC];
    }

    protected function issueBody(array $payload): string
    {
        $ticket = $payload['ticket'];

        $facts = array_filter([
            'Organization: '.($ticket['organization']['name'] ?? ''),
            'Type: '.ucfirst($ticket['type']),
            'Impact: '.ucfirst($ticket['impact']),
            'Priority: '.ucfirst($ticket['priority']),
            ! empty($ticket['category']) ? 'Category: '.$ticket['category'] : null,
        ], fn ($fact) => $fact !== null && ! str_ends_with($fact, ': '));

        $body = '**Support ticket ['.$ticket['reference'].']('.$ticket['url'].')**'."\n\n".implode(' · ', $facts);

        if (! empty($ticket['requester'])) {
            $body .= "\n\nRequester: ".$ticket['requester']['name'].' <'.$ticket['requester']['email'].'>';
        }

        if (isset($payload['message'])) {
            $body .= "\n\n---\n\n".$this->messageBlock($payload['message'], false);
        }

        return TrackerText::neutralize($body);
    }

    protected function commentBody(array $payload): string
    {
        return TrackerText::neutralize($this->messageBlock($payload['message'], true));
    }

    protected function timeComment(array $payload): string
    {
        $entry = $payload['time_entry'];
        $who = $entry['by'] ?: 'support';
        $line = '⏱️ **'.$entry['duration'].'** logged by '.$who.' on '.$entry['worked_at'].($entry['billable'] ? '' : ' (non-billable)');

        if (! empty($entry['note'])) {
            $line .= ': '.preg_replace('/\s+/', ' ', $entry['note']);
        }

        return TrackerText::neutralize($line);
    }

    protected function title(array $payload): string
    {
        return TrackerText::title($payload['ticket']['reference'], $payload['ticket']['subject']);
    }

    /**
     * @return list<string>
     */
    protected function labels(string $csv): array
    {
        return collect(explode(',', $csv))->map(fn ($label) => trim($label))->filter()->unique()->take(10)->values()->all();
    }

    /**
     * The state a ticket should put the issue in.
     */
    protected function desiredState(array $payload): string
    {
        return ($payload['ticket']['open'] ?? true) ? 'open' : 'closed';
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function messageBlock(array $message, bool $withAuthor): string
    {
        $text = trim($message['body_markdown'] ?? '') !== '' ? trim($message['body_markdown']) : '_(no text)_';
        $block = '';

        if ($withAuthor) {
            $author = $message['author'];
            $block .= '**'.($author['name'] ?: ucfirst($author['kind'])).'** wrote:'."\n\n";
        }

        $block .= $text;

        if (! empty($message['attachments'])) {
            $list = collect($message['attachments'])->map(fn ($file) => $file['name'].' ('.Str::of((string) round($file['size'] / 1024))->append(' KB').')')->implode(', ');
            $block .= "\n\n_Attachments (in the support desk): ".$list.'_';
        }

        return $block;
    }

    /**
     * Pull one value out of a JSON answer or fail clearly.
     */
    protected function value(Response $response, string $key): string
    {
        $value = $response->json($key);

        if ($value === null || $value === '') {
            throw IntegrationException::permanent("The tracker's answer did not include {$key}.", $response->status());
        }

        return (string) $value;
    }

    protected function summary(Response $response): string
    {
        return mb_substr(trim($response->body()), 0, 2000);
    }
}
