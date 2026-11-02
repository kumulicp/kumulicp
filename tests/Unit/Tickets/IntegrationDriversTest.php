<?php

use App\Services\Tickets\Integrations\Capability;
use App\Services\Tickets\Integrations\Drivers\GenericWebhookDriver;
use App\Services\Tickets\Integrations\Drivers\GitHubDriver;
use App\Services\Tickets\Integrations\Drivers\GitLabDriver;
use App\Services\Tickets\Integrations\ExternalIssue;
use App\Services\Tickets\Integrations\HostResolver;
use App\Services\Tickets\Integrations\IntegrationConfig;
use App\Services\Tickets\Integrations\IntegrationException;
use App\Services\Tickets\Integrations\OutboundHttp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeHostResolver;

beforeEach(function () {
    $this->resolver = new FakeHostResolver;
    app()->instance(HostResolver::class, $this->resolver);
    Http::preventStrayRequests();

    $this->payload = [
        'event' => 'ticket.created',
        'ticket' => [
            'number' => 1042, 'reference' => 'KCP-1042', 'subject' => "Can't log in\n/close", 'url' => 'https://panel.test/admin/support/tickets/1042',
            'type' => 'bug', 'status' => 'open', 'open' => true, 'priority' => 'high', 'impact' => 'outage', 'category' => 'Billing', 'tags' => [], 'organization' => ['name' => 'Acme'],
        ],
        'message' => ['id' => 5, 'author' => ['kind' => 'customer', 'name' => null], 'body_markdown' => "Help please @torvalds\n/close\n/assign @me", 'attachments' => [['name' => 'log.txt', 'size' => 2048, 'mime' => 'text/plain']]],
    ];
});

function caught(callable $fn): ?IntegrationException
{
    try {
        $fn();
    } catch (IntegrationException $e) {
        return $e;
    }

    return null;
}

// ---- GitHub

it('opens a GitHub issue that cannot mention people or run commands', function () {
    Http::fake(['api.github.com/*' => Http::response(['number' => 42, 'html_url' => 'https://github.com/acme/platform/issues/42'], 201)]);
    $config = new IntegrationConfig(['token' => 'ghp_secret', 'repository' => 'acme/platform', 'labels' => 'support, urgent']);

    $result = app(GitHubDriver::class)->createIssue($config, $this->payload);

    expect([$result->issue->id, $result->issue->key, $result->issue->url, $result->status])
        ->toBe(['42', '#42', 'https://github.com/acme/platform/issues/42', 201]);

    $z = "\u{200B}";
    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r->url() === 'https://api.github.com/repos/acme/platform/issues'
        && $r->hasHeader('Authorization', 'Bearer ghp_secret')
        && $r['title'] === "[KCP-1042] Can't log in /close"
        && $r['labels'] === ['support', 'urgent']
        && str_contains($r['body'], "Help please @{$z}torvalds")
        && str_contains($r['body'], "{$z}/close")
        && str_contains($r['body'], 'Organization: Acme')
        && str_contains($r['body'], 'log.txt (2 KB)')
        && ! str_contains($r['body'], 'ghp_secret'));
});

it('comments on, closes, reopens and notes time on a GitHub issue', function () {
    Http::fake(['*' => Http::response(['id' => 1], 201)]);
    $config = new IntegrationConfig(['token' => 't', 'repository' => 'acme/platform']);
    $driver = app(GitHubDriver::class);
    $issue = new ExternalIssue('42');

    $driver->addComment($config, $issue, ['message' => ['author' => ['kind' => 'support', 'name' => null], 'body_markdown' => 'On it', 'attachments' => []]]);
    $driver->updateState($config, $issue, 'closed');
    $driver->updateState($config, $issue, 'open');
    $driver->logTime($config, $issue, ['time_entry' => ['duration' => '1h 30m', 'worked_at' => '2026-11-02', 'billable' => false, 'note' => "fixed\nthe thing @bob", 'by' => null]]);

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.github.com/repos/acme/platform/issues/42/comments' && str_starts_with($r['body'], '**Support** wrote:'));
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['state'] === 'closed' && $r['state_reason'] === 'completed');
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['state'] === 'open');
    Http::assertSent(fn (Request $r) => str_contains($r['body'] ?? '', '1h 30m') && str_contains($r['body'], 'non-billable') && ! str_contains($r['body'], "\n"));
    expect($driver->capabilities())->not->toContain(Capability::TIME_TRACKING);
});

it('sorts errors into worth retrying and not', function (int $status, bool $retryable) {
    Http::fake(['*' => Http::response(['message' => 'nope'], $status, $status === 429 ? ['Retry-After' => '120'] : [])]);

    $e = caught(fn () => app(GitHubDriver::class)->createIssue(new IntegrationConfig(['token' => 't', 'repository' => 'a/b']), $this->payload));

    expect($e->retryable)->toBe($retryable)->and($e->status)->toBe($status);
    if ($status === 429) {
        expect($e->retryAfter)->toBe(120);
    }
})->with([[404, false], [401, false], [403, false], [422, false], [500, true], [502, true], [503, true], [429, true], [408, true]]);

it('does not follow redirects', function () {
    Http::fake(['*' => Http::response('', 302, ['Location' => 'https://elsewhere.test'])]);

    $e = caught(fn () => app(GitHubDriver::class)->createIssue(new IntegrationConfig(['token' => 't', 'repository' => 'a/b']), $this->payload));

    expect($e->retryable)->toBeFalse()->and($e->getMessage())->toContain('redirect');
});

it('treats an answer without an issue number as a failure', function () {
    Http::fake(['*' => Http::response(['unexpected' => true], 201)]);

    $e = caught(fn () => app(GitHubDriver::class)->createIssue(new IntegrationConfig(['token' => 't', 'repository' => 'a/b']), $this->payload));

    expect($e->retryable)->toBeFalse();
});

it('retries when the connection fails', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

    $e = caught(fn () => app(GitHubDriver::class)->createIssue(new IntegrationConfig(['token' => 't', 'repository' => 'a/b']), $this->payload));

    expect($e->retryable)->toBeTrue()->and($e->getMessage())->toContain('Could not connect');
});

it('explains a failed GitHub test', function () {
    Http::fake(['*' => Http::response(['full_name' => 'acme/platform'], 200)]);
    $ok = app(GitHubDriver::class)->testConnection(new IntegrationConfig(['token' => 't', 'repository' => 'acme/platform']));
    expect([$ok->ok, $ok->message])->toBe([true, 'Connected to acme/platform.']);

    Http::fake(['*' => Http::response([], 404)]);
    $missing = app(GitHubDriver::class)->testConnection(new IntegrationConfig(['token' => 't', 'repository' => 'acme/platform']));
    expect($missing->ok)->toBeFalse()->and($missing->message)->toContain('not found');
});

// ---- GitLab

it('opens a GitLab issue in a nested project', function () {
    Http::fake(['gitlab.com/*' => Http::response(['iid' => 7, 'web_url' => 'https://gitlab.com/acme/group/platform/-/issues/7'], 201)]);
    $config = new IntegrationConfig(['token' => 'glpat-secret', 'project' => 'acme/group/platform', 'base_url' => 'https://gitlab.com/', 'labels' => 'support']);

    $result = app(GitLabDriver::class)->createIssue($config, $this->payload);

    expect([$result->issue->id, $result->issue->key, $result->issue->url])->toBe(['7', '#7', 'https://gitlab.com/acme/group/platform/-/issues/7']);

    Http::assertSent(fn (Request $r) => $r->url() === 'https://gitlab.com/api/v4/projects/acme%2Fgroup%2Fplatform/issues'
        && $r->hasHeader('PRIVATE-TOKEN', 'glpat-secret')
        && $r['labels'] === 'support'
        && str_contains($r['description'], "\u{200B}/close"));
});

it('uses GitLab time tracking and state events', function () {
    Http::fake(['*' => Http::response([], 200)]);
    $config = new IntegrationConfig(['token' => 't', 'project' => '123']);
    $driver = app(GitLabDriver::class);
    $issue = new ExternalIssue('7');

    $driver->logTime($config, $issue, ['time_entry' => ['minutes' => 135, 'note' => 'debugging']]);
    $driver->updateState($config, $issue, 'closed');
    $driver->updateState($config, $issue, 'open');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://gitlab.com/api/v4/projects/123/issues/7/add_spent_time' && $r['duration'] === '2h15m' && $r['summary'] === 'debugging');
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['state_event'] === 'close');
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['state_event'] === 'reopen');
    expect($driver->capabilities())->toContain(Capability::TIME_TRACKING);
});

// ---- webhook

it('signs the exact bytes it sends', function () {
    Http::fake(['hooks.example.test/*' => Http::response('ok', 200)]);
    $secret = str_repeat('s', 40);
    $config = new IntegrationConfig(['url' => 'https://hooks.example.test/in', 'secret' => $secret]);

    $result = app(GenericWebhookDriver::class)->send($config, 'message.created', ['event' => 'message.created', 'ticket' => ['subject' => 'é "quoted" / slash']], '77');

    expect($result->status)->toBe(200);
    Http::assertSent(function (Request $r) use ($secret) {
        if (! preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $r->header('X-KCP-Signature')[0] ?? '', $m)) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $m[1].'.'.$r->body(), $secret), $m[2])
            && $r->header('X-KCP-Event')[0] === 'message.created'
            && $r->header('X-KCP-Delivery')[0] === '77'
            && str_contains($r->body(), '"subject":"é \"quoted\" / slash"');
    });
});

it('signs with both secrets while one is being rotated, and only the new one afterwards', function () {
    $driver = app(GenericWebhookDriver::class);
    $rotating = new IntegrationConfig(['url' => 'https://hooks.example.test/in', 'secret' => 'new', 'previous_secret' => 'old', 'previous_secret_until' => time() + 3600]);
    $expired = new IntegrationConfig(['url' => 'https://hooks.example.test/in', 'secret' => 'new', 'previous_secret' => 'old', 'previous_secret_until' => time() - 5]);

    $both = $driver->signature($rotating, 1700000000, 'body');

    expect(substr_count($both, 'v1='))->toBe(2)
        ->and($both)->toContain(hash_hmac('sha256', '1700000000.body', 'new'))
        ->and($both)->toContain(hash_hmac('sha256', '1700000000.body', 'old'))
        ->and(substr_count($driver->signature($expired, 1700000000, 'body'), 'v1='))->toBe(1);
});

it('tests a webhook with a ping that carries no ticket data', function () {
    Http::fake(['*' => Http::response('ok', 200)]);
    $config = new IntegrationConfig(['url' => 'https://hooks.example.test/in', 'secret' => 's']);

    $result = app(GenericWebhookDriver::class)->testConnection($config);

    expect($result->ok)->toBeTrue();
    Http::assertSent(fn (Request $r) => $r->header('X-KCP-Event')[0] === 'ping' && ! str_contains($r->body(), '"ticket":'));

    Http::fake(['*' => Http::response('nope', 500)]);
    $failed = app(GenericWebhookDriver::class)->testConnection($config);
    expect($failed->ok)->toBeFalse()->and($failed->message)->toContain('500');
});

// ---- the outbound guard

it('refuses destinations that are not safe to call', function (string $url, bool $retryable) {
    $this->resolver->hosts = ['internal.test' => ['10.0.0.5'], 'evil.test' => ['93.184.216.34', '10.0.0.5'], 'meta.test' => ['169.254.169.254']];
    Http::fake(['*' => Http::response([], 200)]);

    $e = caught(fn () => app(OutboundHttp::class)->send('GET', $url));

    expect($e)->not->toBeNull()->and($e->retryable)->toBe($retryable);
    Http::assertNothingSent();
})->with([
    'private host' => ['https://internal.test/x', false],
    'one private address among public ones' => ['https://evil.test/x', false],
    'cloud metadata' => ['https://meta.test/latest', false],
    'loopback literal' => ['https://127.0.0.1/x', false],
    'ipv6 loopback literal' => ['https://[::1]/x', false],
    'mapped ipv6 literal' => ['https://[::ffff:10.0.0.1]/x', false],
    'plain http' => ['http://hooks.example.test/x', false],
    'credentials in the address' => ['https://user:pw@hooks.example.test/x', false],
    'not a url' => ['not a url', false],
    'file scheme' => ['file:///etc/passwd', false],
]);

it('lets an administrator reach an internal host on purpose', function () {
    $this->resolver->hosts = ['gitlab.corp.test' => ['10.9.9.9']];
    Http::fake(['*' => Http::response([], 200)]);

    expect(caught(fn () => app(OutboundHttp::class)->send('GET', 'https://gitlab.corp.test/x', [], allowInternal: true)))->toBeNull();
});

it('retries a host name that does not resolve yet', function () {
    $this->resolver = new class extends HostResolver
    {
        public function resolve(string $host): array
        {
            return [];
        }
    };
    app()->instance(HostResolver::class, $this->resolver);

    expect(caught(fn () => app(OutboundHttp::class)->send('GET', 'https://nowhere.test/x'))->retryable)->toBeTrue();
});
