<?php

namespace App\Http\Controllers\Webhooks;

use App\Contracts\Tickets\ReceivesInbound;
use App\Http\Controllers\Controller;
use App\Services\Tickets\Integrations\InboundHandler;
use App\Services\Tickets\Integrations\IntegrationConfig;
use App\Services\Tickets\Integrations\TicketIntegrationRegistry;
use App\TicketIntegration;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The address GitHub and GitLab call when an issue changes. No session, so it
 * is protected twice over: the address itself contains a secret token, and the
 * call must carry the tracker's own signature or token. Everything that is not
 * exactly right is a plain 404 or 401 with nothing in it.
 */
class TicketInbound extends Controller
{
    /** Trackers send small notifications; anything bigger is not one. */
    private const MAX_BODY = 1_000_000;

    public function __construct(
        private TicketIntegrationRegistry $registry,
        private InboundHandler $handler,
    ) {}

    public function __invoke(Request $request, int $integration, string $token): Response
    {
        $integration = TicketIntegration::where('enabled', true)->find($integration);
        $driver = $integration ? $this->registry->driver($integration->driver) : null;

        abort_unless($driver instanceof ReceivesInbound, 404);

        $expected = (string) (($integration->config ?? [])['inbound_token'] ?? '');
        abort_if($expected === '' || ! hash_equals($expected, $token), 404);

        $body = $request->getContent();
        abort_if(strlen($body) > self::MAX_BODY, 413);

        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower($name)] = (string) ($values[0] ?? '');
        }

        $config = new IntegrationConfig($integration->config ?? [], (bool) $integration->option('allow_internal', false));

        abort_unless($driver->verifyInbound($body, $headers, $config), 401);

        if ($event = $driver->parseInbound($body, $headers, $config)) {
            $this->handler->handle($integration, $event);
        }

        return response()->noContent();
    }
}
