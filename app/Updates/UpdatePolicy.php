<?php

namespace App\Updates;

use App\Support\Facades\Settings;
use App\UpdateState;
use Illuminate\Support\Collection;

/**
 * Which of what is out of date is brought up to date on its own, once an admin has
 * turned that on, and which is left for an admin to start.
 *
 * Automatic updates are off to begin with. Once on, an aspect is automatic unless
 * a step that brings it up to date is disruptive (see UpdateStep::disruptive()),
 * or no step is known for it: an app's own aspects stay manual until an admin says
 * otherwise. Either can be changed per aspect.
 */
class UpdatePolicy
{
    public const AUTO = 'auto';

    public const MANUAL = 'manual';

    private const ENABLED_SETTING = 'update_auto_apply';

    public function __construct(private readonly UpdateSteps $steps, private readonly AspectRegistry $aspects) {}

    /** Whether anything is brought up to date on its own */
    public function enabled(): bool
    {
        return Settings::get(self::ENABLED_SETTING, '') === '1';
    }

    public function enable(bool $enabled): void
    {
        Settings::update(self::ENABLED_SETTING, $enabled ? '1' : null);
    }

    /** What an aspect is without an admin having said */
    public function defaultMode(string $aspect): string
    {
        // Bringing an organization's subscription up to date is what SubscriptionUpdate does whenever it is asked to
        if (in_array($aspect, OrganizationDrift::ASPECTS, true)) {
            return self::AUTO;
        }

        $steps = collect($this->steps->registered())->filter(fn (UpdateStep $step) => in_array($aspect, $step->aspects(), true));

        return $steps->isNotEmpty() && $steps->doesntContain(fn (UpdateStep $step) => $step->disruptive())
            ? self::AUTO
            : self::MANUAL;
    }

    public function mode(string $aspect): string
    {
        $mode = Settings::get($this->setting($aspect), '');

        return in_array($mode, [self::AUTO, self::MANUAL], true) ? $mode : $this->defaultMode($aspect);
    }

    /** Whether it is brought up to date on its own: automatic updates are on and it is automatic */
    public function isAuto(string $aspect): bool
    {
        return $this->enabled() && $this->mode($aspect) === self::AUTO;
    }

    public function set(string $aspect, string $mode): void
    {
        // What it would be anyway isn't kept, so a change to the default reaches it
        Settings::update($this->setting($aspect), $mode === $this->defaultMode($aspect) ? null : $mode);
    }

    /**
     * Every aspect an admin can set: the ones every app has, and any that an
     * instance has been found out of date for.
     *
     * `apps` is the apps an aspect was found on, and is empty for the ones every
     * app has (core settings).
     *
     * @return array<int, array{key: string, label: string, apps: array<int, string>, mode: string, default: string, disruptive: bool}>
     */
    public function all(): array
    {
        $registered = array_keys($this->aspects->registered());

        /** @var Collection<string, Collection<int, string>> $apps */
        $apps = UpdateState::query()
            ->join('app_instances', 'app_instances.id', '=', 'update_states.app_instance_id')
            ->join('applications', 'applications.id', '=', 'app_instances.application_id')
            ->distinct()
            ->get(['update_states.aspect', 'applications.name'])
            ->groupBy('aspect')
            ->map(fn (Collection $rows) => $rows->pluck('name')->sort()->values());

        /** @var Collection<int, string> $keys */
        $keys = collect($registered)
            ->merge(OrganizationDrift::ASPECTS)
            ->merge($apps->keys())
            ->unique()
            ->sort()
            ->values();

        return $keys->map(fn (string $key) => [
            'key' => $key,
            'label' => $this->aspects->label($key),
            'apps' => in_array($key, $registered, true) ? [] : ($apps[$key] ?? collect())->all(),
            'mode' => $this->mode($key),
            'default' => $this->defaultMode($key),
            'disruptive' => $this->disruptive($key),
        ])->all();
    }

    /** Whether a step that brings the aspect up to date is disruptive */
    public function disruptive(string $aspect): bool
    {
        return collect($this->steps->registered())->contains(
            fn (UpdateStep $step) => $step->disruptive() && in_array($aspect, $step->aspects(), true)
        );
    }

    // A setting's name can't have dots in it: an aspect's key may
    private function setting(string $aspect): string
    {
        return 'update_policy_'.str_replace('.', '_', $aspect);
    }
}
