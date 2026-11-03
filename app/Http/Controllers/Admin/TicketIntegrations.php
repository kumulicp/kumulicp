<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Tickets\ReceivesEvents;
use App\Contracts\Tickets\ReceivesInbound;
use App\Contracts\Tickets\TicketIntegrationDriver;
use App\Contracts\Tickets\TracksIssues;
use App\Enums\TicketPriority;
use App\Enums\TicketType;
use App\Http\Controllers\Controller;
use App\Jobs\Tickets\DeliverTicketIntegration;
use App\Services\Tickets\Integrations\IntegrationConfig;
use App\Services\Tickets\Integrations\TicketIntegrationRegistry;
use App\Ticket;
use App\TicketCategory;
use App\TicketDelivery;
use App\TicketIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Staff manage the connections tickets are sent through: webhooks and issue
 * trackers. The settings form is built from each driver's own schema, so a new
 * driver needs nothing here. Secrets are encrypted, never sent back to the
 * browser, and only replaced when a new value is typed.
 */
class TicketIntegrations extends Controller
{
    /** Marks the secret shown once, after it is created or rotated. */
    private const REVEALED = 'revealed_secret';

    public function __construct(private TicketIntegrationRegistry $registry) {}

    public function index()
    {
        $this->authorizeStaff();

        return inertia('Admin/Tickets/IntegrationsList', [
            'integrations' => TicketIntegration::orderBy('name')->get()->map(fn (TicketIntegration $integration) => [
                'id' => $integration->id,
                'name' => $integration->name,
                'driver' => $this->registry->driver($integration->driver)?->label() ?? $integration->driver,
                'installed' => $this->registry->has($integration->driver),
                'enabled' => $integration->enabled,
                'disabled_reason' => $integration->disabled_reason,
                'failures' => $integration->consecutive_failures,
                'last_success_at' => $integration->last_success_at?->toIso8601String(),
                'pending' => $integration->deliveries()->where('status', TicketDelivery::PENDING)->count(),
                'failed' => $integration->deliveries()->where('status', TicketDelivery::FAILED)->count(),
            ])->values()->all(),
            'drivers' => $this->driverChoices(),
            'breadcrumbs' => [
                ['label' => __('tickets.admin.title'), 'url' => '/admin/support/tickets'],
                ['label' => __('tickets.integrations.title')],
            ],
        ]);
    }

    public function create(Request $request)
    {
        $this->authorizeStaff();

        $driver = $this->registry->driver((string) $request->query('driver'));
        abort_if($driver === null, 404);

        return $this->form(null, $driver);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeStaff();

        $driver = $this->registry->driver((string) $request->input('driver'));
        abort_if($driver === null, 404);

        $data = $request->validate($this->rules($driver, creating: true));

        $integration = new TicketIntegration(['driver' => $driver::key(), 'name' => $data['name']]);
        $secrets = [];
        $integration->config = $this->buildConfig($driver, $data['config'] ?? [], [], $secrets);
        $integration->enabled = (bool) ($data['enabled'] ?? true);
        $this->fill($integration, $driver, $data);
        $integration->save();

        return redirect('/admin/support/integrations/'.$integration->id.'/edit')
            ->with('success', __('tickets.integrations.created'))
            ->with(self::REVEALED, $secrets ?: null);
    }

    public function edit(TicketIntegration $integration)
    {
        $this->authorizeStaff();

        $driver = $this->registry->driver($integration->driver);
        abort_if($driver === null, 404);

        return $this->form($integration, $driver);
    }

    public function update(Request $request, TicketIntegration $integration): RedirectResponse
    {
        $this->authorizeStaff();

        $driver = $this->registry->driver($integration->driver);
        abort_if($driver === null, 404);

        $data = $request->validate($this->rules($driver, creating: false));

        $secrets = [];
        $integration->name = $data['name'];
        $integration->config = $this->buildConfig($driver, $data['config'] ?? [], $integration->config ?? [], $secrets);
        $this->fill($integration, $driver, $data);
        $integration->save();

        return back()->with('success', __('tickets.integrations.saved'));
    }

    public function destroy(TicketIntegration $integration): RedirectResponse
    {
        $this->authorizeStaff();

        $integration->deliveries()->delete();
        $integration->links()->delete();
        $integration->delete();

        return redirect('/admin/support/integrations')->with('success', __('tickets.integrations.deleted'));
    }

    /**
     * Switch an integration on or off. Switching on clears the failure count
     * so one more failure does not immediately switch it off again.
     */
    public function setEnabled(Request $request, TicketIntegration $integration): RedirectResponse
    {
        $this->authorizeStaff();

        $enabled = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        $integration->enabled = (bool) $enabled;

        if ($enabled) {
            $integration->consecutive_failures = 0;
            $integration->disabled_reason = null;
        } else {
            $integration->disabled_reason = null;
        }

        $integration->save();

        return back()->with('success', $enabled ? __('tickets.integrations.enabled') : __('tickets.integrations.disabled'));
    }

    /**
     * Check the saved settings work. Changes nothing outside.
     */
    public function test(TicketIntegration $integration): JsonResponse
    {
        $this->authorizeStaff();

        $driver = $this->registry->driver($integration->driver);
        abort_if($driver === null, 404);

        $result = $driver->testConnection(new IntegrationConfig($integration->config ?? [], (bool) $integration->option('allow_internal', false)));

        return response()->json(['ok' => $result->ok, 'message' => $result->message]);
    }

    /**
     * Start a fresh signing secret. The old one keeps being sent alongside it
     * for a week, so the receiver can be updated without missing anything.
     */
    public function rotateSecret(TicketIntegration $integration): RedirectResponse
    {
        $this->authorizeStaff();

        $config = $integration->config ?? [];
        abort_unless($this->hasGeneratedSecret($integration), 404);

        $config['previous_secret'] = $config['secret'] ?? null;
        $config['previous_secret_until'] = now()->addDays(7)->timestamp;
        $config['secret'] = Str::random(40);
        $integration->config = $config;
        $integration->save();

        return back()
            ->with('success', __('tickets.integrations.rotated'))
            ->with(self::REVEALED, ['secret' => $config['secret']]);
    }

    /**
     * The generated secrets (signing secret, webhook secret), on request.
     */
    public function revealSecret(TicketIntegration $integration): JsonResponse
    {
        $this->authorizeStaff();

        $driver = $this->registry->driver($integration->driver);
        $generated = collect($driver?->configSchema() ?? [])->filter(fn (array $field) => ($field['generated'] ?? false))->pluck('key');
        abort_if($generated->isEmpty(), 404);

        $config = $integration->config ?? [];

        return response()->json($generated->mapWithKeys(fn (string $key) => [$key => $config[$key] ?? null])->all());
    }

    public function deliveries(Request $request, TicketIntegration $integration)
    {
        $this->authorizeStaff();

        $status = in_array($request->query('status'), ['pending', 'delivered', 'failed'], true) ? $request->query('status') : null;

        $deliveries = $integration->deliveries()
            ->with('ticket:id,number')
            ->when($status, fn ($query) => $query->where('status', $status))
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (TicketDelivery $delivery) => [
                'id' => $delivery->id,
                'event' => $delivery->event,
                'status' => $delivery->status,
                'attempts' => $delivery->attempts,
                'response_code' => $delivery->response_code,
                'error' => $delivery->error,
                'response' => $delivery->response,
                'ticket' => $delivery->ticket ? ['reference' => config('tickets.prefix').'-'.$delivery->ticket->number, 'url' => '/admin/support/tickets/'.$delivery->ticket->number] : null,
                'created_at' => $delivery->created_at?->toIso8601String(),
                'delivered_at' => $delivery->delivered_at?->toIso8601String(),
            ]);

        return inertia('Admin/Tickets/IntegrationDeliveries', [
            'integration' => ['id' => $integration->id, 'name' => $integration->name],
            'deliveries' => $deliveries,
            'status' => $status,
            'breadcrumbs' => [
                ['label' => __('tickets.admin.title'), 'url' => '/admin/support/tickets'],
                ['label' => __('tickets.integrations.title'), 'url' => '/admin/support/integrations'],
                ['label' => $integration->name, 'url' => '/admin/support/integrations/'.$integration->id.'/edit'],
                ['label' => __('tickets.integrations.deliveries')],
            ],
        ]);
    }

    /**
     * What exactly was (or would be) sent.
     */
    public function delivery(TicketIntegration $integration, TicketDelivery $delivery): JsonResponse
    {
        $this->authorizeStaff();
        abort_unless($delivery->integration_id === $integration->id, 404);

        return response()->json(['payload' => $delivery->payload]);
    }

    /**
     * Try a failed delivery again, for example after fixing the settings.
     */
    public function redeliver(TicketIntegration $integration, TicketDelivery $delivery): RedirectResponse
    {
        $this->authorizeStaff();
        abort_unless($delivery->integration_id === $integration->id, 404);
        abort_unless($delivery->status === TicketDelivery::FAILED, 422);

        $delivery->status = TicketDelivery::PENDING;
        $delivery->attempts = 0;
        $delivery->error = null;
        $delivery->save();

        DeliverTicketIntegration::dispatch($delivery->id);

        return back()->with('success', __('tickets.integrations.redelivering'));
    }

    private function authorizeStaff(): void
    {
        Gate::authorize('manage', Ticket::class);
    }

    /**
     * @return list<array{key: string, label: string, kind: string, capabilities: list<string>}>
     */
    private function driverChoices(): array
    {
        return collect($this->registry->all())->keys()
            ->map(fn (string $key) => $this->registry->driver($key))
            ->filter()
            ->map(fn (TicketIntegrationDriver $driver) => [
                'key' => $driver::key(),
                'label' => $driver->label(),
                'kind' => $driver instanceof TracksIssues ? 'tracker' : 'webhook',
                'capabilities' => $driver->capabilities(),
            ])
            ->values()
            ->all();
    }

    private function form(?TicketIntegration $integration, TicketIntegrationDriver $driver)
    {
        $config = $integration?->config ?? [];

        return inertia('Admin/Tickets/IntegrationEdit', [
            'integration' => $integration ? [
                'id' => $integration->id,
                'name' => $integration->name,
                'enabled' => $integration->enabled,
                'disabled_reason' => $integration->disabled_reason,
                'failures' => $integration->consecutive_failures,
                'events' => $integration->events ?? [],
                'filters' => array_merge(['types' => [], 'priorities' => [], 'categories' => []], $integration->filters ?? []),
                'options' => $integration->options ?? [],
                // Secrets are never sent to the browser, only whether one is set
                'config' => collect($driver->configSchema())->mapWithKeys(fn (array $field) => [
                    $field['key'] => $field['type'] === 'secret' ? '' : ($config[$field['key']] ?? ($field['default'] ?? '')),
                ])->all(),
                'secrets_set' => collect($driver->configSchema())->filter(fn (array $field) => $field['type'] === 'secret')
                    ->mapWithKeys(fn (array $field) => [$field['key'] => ! empty($config[$field['key']])])->all(),
                'has_generated_secret' => $this->hasGeneratedSecret($integration),
                // Where the tracker should send its notifications
                'inbound_url' => $driver instanceof ReceivesInbound && ! empty($config['inbound_token'])
                    ? url('/webhooks/tickets/'.$integration->id.'/'.$config['inbound_token'])
                    : null,
            ] : null,
            'driver' => [
                'key' => $driver::key(),
                'label' => $driver->label(),
                'kind' => $driver instanceof TracksIssues ? 'tracker' : 'webhook',
                'capabilities' => $driver->capabilities(),
                'schema' => $driver->configSchema(),
                'events' => $driver instanceof ReceivesEvents ? $driver->events() : [],
                'inbound' => $driver instanceof ReceivesInbound,
            ],
            'choices' => [
                'types' => collect(TicketType::cases())->map(fn ($case) => ['value' => $case->value, 'text' => $case->label()])->all(),
                'priorities' => collect(TicketPriority::cases())->map(fn ($case) => ['value' => $case->value, 'text' => $case->label()])->all(),
                'categories' => TicketCategory::where('active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($category) => ['value' => $category->id, 'text' => $category->name])->all(),
            ],
            'revealed' => session(self::REVEALED),
            'breadcrumbs' => [
                ['label' => __('tickets.admin.title'), 'url' => '/admin/support/tickets'],
                ['label' => __('tickets.integrations.title'), 'url' => '/admin/support/integrations'],
                ['label' => $integration?->name ?? $driver->label()],
            ],
        ]);
    }

    /**
     * Validation: the shared fields plus whatever the driver's schema asks for.
     *
     * @return array<string, mixed>
     */
    private function rules(TicketIntegrationDriver $driver, bool $creating): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'enabled' => ['sometimes', 'boolean'],
            'options' => ['sometimes', 'array'],
            'options.auto_create' => ['sometimes', 'boolean'],
            'options.sync_state' => ['sometimes', 'boolean'],
            'options.sync_time' => ['sometimes', 'boolean'],
            'options.include_requester' => ['sometimes', 'boolean'],
            'options.allow_internal' => ['sometimes', 'boolean'],
            'options.inbound_comments' => ['sometimes', 'boolean'],
            'options.inbound_resolve' => ['sometimes', 'boolean'],
            'filters' => ['sometimes', 'array'],
            'filters.types' => ['sometimes', 'array'],
            'filters.types.*' => [Rule::enum(TicketType::class)],
            'filters.priorities' => ['sometimes', 'array'],
            'filters.priorities.*' => [Rule::enum(TicketPriority::class)],
            'filters.categories' => ['sometimes', 'array'],
            'filters.categories.*' => ['integer', Rule::exists('ticket_categories', 'id')],
            'config' => ['sometimes', 'array'],
        ];

        if ($driver instanceof ReceivesEvents) {
            $rules['events'] = ['required', 'array', 'min:1'];
            $rules['events.*'] = [Rule::in($driver->events())];
        }

        $protocols = config('tickets.integrations.allow_http') ? 'http,https' : 'https';

        foreach ($driver->configSchema() as $field) {
            $required = ($field['required'] ?? false) && ! ($field['generated'] ?? false);
            // A secret that is already saved does not have to be typed again
            $mustBePresent = $required && ($field['type'] !== 'secret' || $creating);

            $fieldRules = [$mustBePresent ? 'required' : 'nullable'];

            $fieldRules = array_merge($fieldRules, match ($field['type']) {
                'url' => ['string', 'max:255', 'url:'.$protocols],
                'secret' => ['string', 'max:500'],
                'number' => ['integer'],
                'toggle' => ['boolean'],
                'select' => [Rule::in(array_column($field['options'] ?? [], 'value'))],
                default => ['string', 'max:255'],
            });

            if (isset($field['pattern'])) {
                $fieldRules[] = 'regex:/'.str_replace('/', '\/', $field['pattern']).'/';
            }

            $rules['config.'.$field['key']] = $fieldRules;
        }

        return $rules;
    }

    /**
     * The settings to store: submitted values over what was there, a blank
     * secret keeps the existing one, a generated secret is made when missing.
     * Keys the schema does not know (rotation bookkeeping) are left alone.
     *
     * @param  array<string, mixed>  $submitted
     * @param  array<string, mixed>  $existing
     * @param  array<string, string>  $revealed  Receives any secret generated now, to show once
     * @return array<string, mixed>
     */
    private function buildConfig(TicketIntegrationDriver $driver, array $submitted, array $existing, array &$revealed): array
    {
        $config = $existing;

        // The address the tracker calls back on contains its own secret token
        if ($driver instanceof ReceivesInbound && empty($config['inbound_token'])) {
            $config['inbound_token'] = Str::random(40);
        }

        foreach ($driver->configSchema() as $field) {
            $key = $field['key'];
            $value = $submitted[$key] ?? null;
            $blank = $value === null || $value === '';

            if ($field['type'] === 'secret') {
                if ($blank && empty($existing[$key]) && ($field['generated'] ?? false)) {
                    $config[$key] = Str::random(40);
                    $revealed[$key] = $config[$key];
                } elseif (! $blank) {
                    $config[$key] = $value;
                }

                continue;
            }

            $config[$key] = $blank ? ($field['default'] ?? null) : (is_string($value) ? trim($value) : $value);
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fill(TicketIntegration $integration, TicketIntegrationDriver $driver, array $data): void
    {
        $options = Arr::only($data['options'] ?? [], ['auto_create', 'sync_state', 'sync_time', 'include_requester', 'allow_internal', 'inbound_comments', 'inbound_resolve']);

        $integration->options = array_map('boolval', $options);
        $integration->filters = collect($data['filters'] ?? [])->only(['types', 'priorities', 'categories'])->map(fn ($values) => array_values($values))->all();
        $integration->events = $driver instanceof ReceivesEvents ? array_values($data['events'] ?? []) : null;

        if (array_key_exists('enabled', $data)) {
            $integration->enabled = (bool) $data['enabled'];
        }
    }

    private function hasGeneratedSecret(TicketIntegration $integration): bool
    {
        $driver = $this->registry->driver($integration->driver);

        return $driver !== null && collect($driver->configSchema())->contains(fn (array $field) => $field['key'] === 'secret' && ($field['generated'] ?? false));
    }
}
