<?php

namespace App\Services\Application;

use App\Application;
use App\AppPlan;
use App\Server;
use Illuminate\Support\Collection;

/**
 * What an app still needs before it can be installed, for the admin who has to
 * fix it: each issue says what is wrong and links to the admin page that fixes it.
 *
 * An issue is either blocking (the app can't be installed until it is fixed) or
 * only a warning. Nothing here is specific to an organization.
 *
 * Whether an app is ready is also what its saved status says: in a single
 * organization install `enabled` is kept in step with isReady() (see sync()),
 * so it is the result of these checks and never one of them.
 *
 * Each issue is an array:
 *  - key: which check found it
 *  - blocking: whether it stops the app being installed
 *  - message: what is wrong
 *  - actions: the ways to fix it, each with a label, a url and a method (get or post)
 */
class ApplicationReadiness
{
    /** @var list<array{key: string, blocking: bool, message: string, actions: list<array{label: string, url: string, method: string}>}>|null */
    private ?array $issues = null;

    /** @var Collection<int, AppPlan>|null */
    private ?Collection $plans = null;

    public function __construct(private Application $app) {}

    /**
     * @return list<array{key: string, blocking: bool, message: string, actions: list<array{label: string, url: string, method: string}>}>
     */
    public function issues(): array
    {
        return $this->issues ??= array_values(array_filter([
            $this->version(),
            $this->plan(),
            $this->planConfigured(),
            $this->servers(),
        ]));
    }

    public function isReady(): bool
    {
        return collect($this->issues())->every(fn (array $issue) => ! $issue['blocking']);
    }

    /**
     * ready: nothing to fix. warning: installable, but worth a look.
     * blocked: can't be installed yet.
     */
    public function status(): string
    {
        if (! $this->isReady()) {
            return 'blocked';
        }

        return count($this->issues()) > 0 ? 'warning' : 'ready';
    }

    /**
     * Keep the app's saved status (its `enabled` column) in step with whether it
     * is ready, so that nobody has to turn an app on or off by hand. Returns
     * whether the status changed.
     */
    public function sync(): bool
    {
        $ready = $this->isReady();

        if ((bool) $this->app->enabled === $ready) {
            return false;
        }

        // Quietly: nothing else needs to hear about it, and it would loop back here
        $this->app->enabled = $ready;
        $this->app->saveQuietly();

        return true;
    }

    /**
     * Sync every app, for the ones whose inputs changed without a model event.
     * Returns how many apps changed status.
     */
    public static function syncAll(): int
    {
        return Application::all()->filter(fn (Application $app) => (new self($app))->sync())->count();
    }

    private function version(): ?array
    {
        if ($this->app->active_version()) {
            return null;
        }

        $latest = $this->app->versions()->orderByDesc('name')->first();

        if (! $latest) {
            return $this->issue('version', true, __('organization.readiness.version_missing.message', ['app' => $this->app->name]), [
                $this->action(__('organization.readiness.version_missing.action'), "/admin/apps/{$this->app->slug}/versions/create"),
            ]);
        }

        return $this->issue('version', true, __('organization.readiness.version_inactive.message', ['app' => $this->app->name, 'version' => $latest->name]), [
            $this->action(__('organization.readiness.version_inactive.action', ['version' => $latest->name]), "/admin/apps/{$this->app->slug}/versions/{$latest->name}"),
        ]);
    }

    private function plan(): ?array
    {
        if ($this->plans()->isNotEmpty()) {
            return null;
        }

        return $this->issue('plan', true, __('organization.readiness.plan_missing.message', ['app' => $this->app->name]), [
            $this->action(__('organization.readiness.plan_missing.create_default'), "/admin/apps/{$this->app->slug}/plans/default", 'post'),
            $this->action(__('organization.readiness.plan_missing.action'), "/admin/apps/{$this->app->slug}/plans/create"),
        ]);
    }

    /**
     * A plan almost always needs a change or two to work on a given server, and
     * a brand new one has identical created and updated times, so a plan nobody
     * has ever saved after creating it hasn't been set up yet. Only a warning:
     * saving the plan as it is clears it.
     *
     * A plan that connects to a shared app is set up on that app, so it is not
     * counted here.
     */
    private function planConfigured(): ?array
    {
        $plans = $this->plans()->reject(fn (AppPlan $plan) => $plan->usesSharedApp());

        if ($plans->isEmpty() || $plans->contains(fn (AppPlan $plan) => $this->wasEdited($plan))) {
            return null;
        }

        $plan = $plans->first();

        return $this->issue('plan_configured', false, __('organization.readiness.plan_unedited.message', ['plan' => $plan->name]), [
            $this->action(__('organization.readiness.plan_unedited.action'), "/admin/apps/{$this->app->slug}/plans/{$plan->id}/edit"),
        ]);
    }

    private function servers(): ?array
    {
        $has_server = Server::where('default_web_server', 1)->exists()
            || $this->app->plans()->whereNotNull('web_server_id')->exists();

        if ($has_server) {
            return null;
        }

        return $this->issue('servers', true, __('organization.readiness.servers.message'), [
            $this->action(__('organization.readiness.servers.action'), '/admin/server/servers'),
        ]);
    }

    /**
     * The plans an organization can pick: not archived and not hidden.
     *
     * @return Collection<int, AppPlan>
     */
    private function plans(): Collection
    {
        return $this->plans ??= $this->app->plans()->visible()->where('archive', false)->orderBy('display_order')->orderBy('id')->get();
    }

    private function wasEdited(AppPlan $plan): bool
    {
        return $plan->updated_at && $plan->created_at && ! $plan->updated_at->equalTo($plan->created_at);
    }

    /**
     * @param  list<array{label: string, url: string, method: string}>  $actions
     */
    private function issue(string $key, bool $blocking, string $message, array $actions): array
    {
        return ['key' => $key, 'blocking' => $blocking, 'message' => $message, 'actions' => $actions];
    }

    private function action(string $label, string $url, string $method = 'get'): array
    {
        return ['label' => $label, 'url' => $url, 'method' => $method];
    }
}
