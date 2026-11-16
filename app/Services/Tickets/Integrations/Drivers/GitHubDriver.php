<?php

namespace App\Services\Tickets\Integrations\Drivers;

use App\Contracts\Tickets\ReceivesInbound;
use App\Services\Tickets\Integrations\Capability;
use App\Services\Tickets\Integrations\DeliveryResult;
use App\Services\Tickets\Integrations\ExternalIssue;
use App\Services\Tickets\Integrations\InboundEvent;
use App\Services\Tickets\Integrations\IntegrationConfig;
use App\Services\Tickets\Integrations\IntegrationException;
use App\Services\Tickets\Integrations\TestResult;

/**
 * GitHub Issues (github.com or GitHub Enterprise Server). Needs a token that can
 * create and edit issues in the repository (fine-grained: Issues read/write).
 * GitHub has no time tracking, so time is posted as a comment.
 */
class GitHubDriver extends AbstractTracker implements ReceivesInbound
{
    public static function key(): string
    {
        return 'github';
    }

    public function label(): string
    {
        return 'GitHub Issues';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'token', 'type' => 'secret', 'label' => 'Access token', 'help' => 'A fine-grained token with Issues read and write on the repository.', 'required' => true],
            ['key' => 'repository', 'type' => 'text', 'label' => 'Repository', 'help' => 'owner/name, for example acme/platform', 'required' => true, 'pattern' => '^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$'],
            ['key' => 'api_url', 'type' => 'url', 'label' => 'API address', 'help' => 'Leave as it is for github.com. For GitHub Enterprise use https://your-host/api/v3', 'default' => 'https://api.github.com'],
            ['key' => 'labels', 'type' => 'text', 'label' => 'Labels', 'help' => 'Comma separated, added to every new issue.', 'default' => 'support'],
            ['key' => 'webhook_secret', 'type' => 'secret', 'label' => 'Webhook secret', 'help' => 'Generated for you. Enter it as the secret when adding the webhook in GitHub so we can check calls really come from it.', 'generated' => true],
        ];
    }

    public function testConnection(IntegrationConfig $config): TestResult
    {
        try {
            $response = $this->request($config, 'GET', '');

            return TestResult::ok('Connected to '.($response->json('full_name') ?? $config->string('repository')).'.');
        } catch (IntegrationException $e) {
            return TestResult::failed($e->status === 404 ? 'The repository was not found, or the token cannot see it.' : $e->getMessage());
        }
    }

    public function createIssue(IntegrationConfig $config, array $payload): DeliveryResult
    {
        $response = $this->request($config, 'POST', '/issues', [
            'title' => $this->title($payload),
            'body' => $this->issueBody($payload),
            'labels' => $this->labels($config->string('labels', 'support')),
        ]);

        $number = $this->value($response, 'number');

        return new DeliveryResult($response->status(), 'Created issue #'.$number, new ExternalIssue(
            id: $number,
            key: '#'.$number,
            url: $response->json('html_url'),
            state: 'open',
        ));
    }

    public function addComment(IntegrationConfig $config, ExternalIssue $issue, array $payload): DeliveryResult
    {
        $response = $this->request($config, 'POST', "/issues/{$issue->id}/comments", ['body' => $this->commentBody($payload)]);

        return new DeliveryResult($response->status(), $this->summary($response));
    }

    public function updateState(IntegrationConfig $config, ExternalIssue $issue, string $state): DeliveryResult
    {
        $body = $state === 'closed' ? ['state' => 'closed', 'state_reason' => 'completed'] : ['state' => 'open'];
        $response = $this->request($config, 'PATCH', "/issues/{$issue->id}", $body);

        return new DeliveryResult($response->status(), "Issue {$state}.");
    }

    public function logTime(IntegrationConfig $config, ExternalIssue $issue, array $payload): DeliveryResult
    {
        // GitHub has no time tracking: leave a comment people can read
        $response = $this->request($config, 'POST', "/issues/{$issue->id}/comments", ['body' => $this->timeComment($payload)]);

        return new DeliveryResult($response->status(), 'Time noted in a comment.');
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    private function request(IntegrationConfig $config, string $method, string $path, ?array $json = null)
    {
        $base = rtrim($config->string('api_url', 'https://api.github.com'), '/');
        $options = [
            'headers' => [
                'Authorization' => 'Bearer '.$config->string('token'),
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ],
        ];

        if ($json !== null) {
            $options['json'] = $json;
        }

        return $this->http->send($method, $base.'/repos/'.$config->string('repository').$path, $options, $config->allowInternal());
    }

    public function verifyInbound(string $body, array $headers, IntegrationConfig $config): bool
    {
        $secret = $config->string('webhook_secret');
        $given = $headers['x-hub-signature-256'] ?? '';

        return $secret !== '' && str_starts_with($given, 'sha256=') && hash_equals('sha256='.hash_hmac('sha256', $body, $secret), $given);
    }

    public function parseInbound(string $body, array $headers, IntegrationConfig $config): ?InboundEvent
    {
        $data = json_decode($body, true);

        if (! is_array($data) || strcasecmp((string) ($data['repository']['full_name'] ?? ''), $config->string('repository')) !== 0) {
            return null;
        }

        $number = (string) ($data['issue']['number'] ?? '');
        $key = $headers['x-github-delivery'] ?? sha1($body);

        return match ($headers['x-github-event'] ?? '') {
            'issues' => match ($data['action'] ?? '') {
                'closed' => new InboundEvent(InboundEvent::STATE, $number, $key, 'closed'),
                'reopened' => new InboundEvent(InboundEvent::STATE, $number, $key, 'open'),
                default => null,
            },
            // Pull requests are issues too in GitHub's eyes; they are not what we track
            'issue_comment' => ($data['action'] ?? '') === 'created' && $number !== '' && ! isset($data['issue']['pull_request'])
                ? new InboundEvent(InboundEvent::COMMENT, $number, $key, null, (string) ($data['comment']['user']['login'] ?? ''), (string) ($data['comment']['body'] ?? ''))
                : null,
            default => null,
        };
    }

    public function capabilities(): array
    {
        // Time is only a comment, so TIME_TRACKING is deliberately not claimed
        return [Capability::CREATE_ISSUE, Capability::COMMENT, Capability::STATE_SYNC];
    }
}
