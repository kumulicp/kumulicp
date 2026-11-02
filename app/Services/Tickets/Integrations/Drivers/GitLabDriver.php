<?php

namespace App\Services\Tickets\Integrations\Drivers;

use App\Services\Tickets\DurationParser;
use App\Services\Tickets\Integrations\Capability;
use App\Services\Tickets\Integrations\DeliveryResult;
use App\Services\Tickets\Integrations\ExternalIssue;
use App\Services\Tickets\Integrations\IntegrationConfig;
use App\Services\Tickets\Integrations\IntegrationException;
use App\Services\Tickets\Integrations\TestResult;

/**
 * GitLab Issues (gitlab.com or self-managed), including GitLab's own time
 * tracking. Needs a project or personal access token with the "api" scope and
 * at least the Reporter role.
 */
class GitLabDriver extends AbstractTracker
{
    public static function key(): string
    {
        return 'gitlab';
    }

    public function label(): string
    {
        return 'GitLab Issues';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'token', 'type' => 'secret', 'label' => 'Access token', 'help' => 'A project or personal access token with the api scope.', 'required' => true],
            ['key' => 'project', 'type' => 'text', 'label' => 'Project', 'help' => 'The project path (group/project) or its numeric id.', 'required' => true, 'pattern' => '^([0-9]+|[A-Za-z0-9_.\-]+(/[A-Za-z0-9_.\-]+)+)$'],
            ['key' => 'base_url', 'type' => 'url', 'label' => 'GitLab address', 'help' => 'Leave as it is for gitlab.com. For a self-managed server use its address.', 'default' => 'https://gitlab.com'],
            ['key' => 'labels', 'type' => 'text', 'label' => 'Labels', 'help' => 'Comma separated, added to every new issue.', 'default' => 'support'],
        ];
    }

    public function capabilities(): array
    {
        return [Capability::CREATE_ISSUE, Capability::COMMENT, Capability::STATE_SYNC, Capability::TIME_TRACKING];
    }

    public function testConnection(IntegrationConfig $config): TestResult
    {
        try {
            $response = $this->request($config, 'GET', '');

            return TestResult::ok('Connected to '.($response->json('path_with_namespace') ?? $config->string('project')).'.');
        } catch (IntegrationException $e) {
            return TestResult::failed($e->status === 404 ? 'The project was not found, or the token cannot see it.' : $e->getMessage());
        }
    }

    public function createIssue(IntegrationConfig $config, array $payload): DeliveryResult
    {
        $response = $this->request($config, 'POST', '/issues', [
            'title' => $this->title($payload),
            'description' => $this->issueBody($payload),
            'labels' => implode(',', $this->labels($config->string('labels', 'support'))),
        ]);

        $iid = $this->value($response, 'iid');

        return new DeliveryResult($response->status(), 'Created issue #'.$iid, new ExternalIssue(
            id: $iid,
            key: '#'.$iid,
            url: $response->json('web_url'),
            state: 'open',
        ));
    }

    public function addComment(IntegrationConfig $config, ExternalIssue $issue, array $payload): DeliveryResult
    {
        $response = $this->request($config, 'POST', "/issues/{$issue->id}/notes", ['body' => $this->commentBody($payload)]);

        return new DeliveryResult($response->status(), $this->summary($response));
    }

    public function updateState(IntegrationConfig $config, ExternalIssue $issue, string $state): DeliveryResult
    {
        $response = $this->request($config, 'PUT', "/issues/{$issue->id}", ['state_event' => $state === 'closed' ? 'close' : 'reopen']);

        return new DeliveryResult($response->status(), "Issue {$state}.");
    }

    public function logTime(IntegrationConfig $config, ExternalIssue $issue, array $payload): DeliveryResult
    {
        $entry = $payload['time_entry'];

        $response = $this->request($config, 'POST', "/issues/{$issue->id}/add_spent_time", array_filter([
            'duration' => DurationParser::compact($entry['minutes']),
            'summary' => $entry['note'] ?? null,
        ]));

        return new DeliveryResult($response->status(), 'Time logged on the issue.');
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    private function request(IntegrationConfig $config, string $method, string $path, ?array $json = null)
    {
        $base = rtrim($config->string('base_url', 'https://gitlab.com'), '/');
        $project = ctype_digit($config->string('project')) ? $config->string('project') : rawurlencode($config->string('project'));
        $options = ['headers' => ['PRIVATE-TOKEN' => $config->string('token')]];

        if ($json !== null) {
            $options['json'] = $json;
        }

        return $this->http->send($method, $base.'/api/v4/projects/'.$project.$path, $options, $config->allowInternal());
    }
}
