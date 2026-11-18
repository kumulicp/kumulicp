<?php

namespace App\Services\Organization;

use App\Application;
use App\AppPlan;
use App\Enums\AccessType;
use App\Enums\PlanEntity;
use App\Organization as OrganizationModel;
use App\Plan;
use App\Services\OrganizationService;
use App\Support\AccountManager\UserManager;
use App\Support\Facades\Application as AppFacade;
use App\Support\Facades\FastCache;
use App\Support\Facades\Organization;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Proxies undeclared property/method access to the underlying Plan model via __get/__call.
 *
 * @property int $id
 * @property array|null $app_plans
 *
 * @method mixed appPlansList(Application $app)
 * @method mixed setting(string $setting)
 */
class BasePlanService
{
    protected $plan;

    protected $org_service;

    public $pricing_options = [PlanEntity::BASE, PlanEntity::ADDITIONAL_STORAGE, PlanEntity::STANDARD_USER, PlanEntity::BASIC_USER, PlanEntity::EMAIL, PlanEntity::APPLICATION];

    public function __construct(public OrganizationModel $organization, ?Plan $plan = null)
    {
        $this->plan = $plan ?? $organization->plan;
        $this->org_service = Organization::account()?->is($organization) ? Organization::getThis() : new OrganizationService($organization);
    }

    public function itemName()
    {
        return 'Base';
    }

    public function itemLabel()
    {
        return 'Base Plan';
    }

    public function model()
    {
        return $this->organization;
    }

    public function get()
    {
        return $this->plan;
    }

    /**
     * Every plan this organization could be offered, including the ones whose limits its
     * current usage would break (see isAnyMaxBroken()) so they can be shown as unavailable.
     */
    public function allListed()
    {
        return collect(Plan::where('archive', 0)->where('org_type', $this->organization->type)->orderBy('display_order')->get()->all())
            ->map(fn (Plan $plan) => new self($this->organization, $plan));
    }

    public function allAvailable()
    {
        return $this->allListed()
            ->filter(fn (self $plan) => ! $plan->isAnyMaxBroken() || $plan->id == $this->id)
            ->values();
    }

    public function enabledApps()
    {
        $app_plans = $this->app_plans ?? [];

        $enabled_slugs = collect($app_plans)
            ->filter(fn ($settings) => ! empty(Arr::get($settings, 'plans')))
            ->keys();

        return Application::available()
            ->orderBy('slug')
            ->where('enabled', 1)
            ->whereIn('slug', $enabled_slugs)
            ->get();
    }

    public function appEnabled(Application $app)
    {
        if (! $this->plan) {
            return false;
        }

        return ! empty(Arr::get($this->app_plans, "{$app->slug}.plans"));
    }

    public function appPlans(Application $app, $archived = true, $display_order = false)
    {
        $app_plans = $this->appPlansList($app);
        $plans = AppPlan::whereIn('id', is_array($app_plans) ? $app_plans : []);

        if ($archived === false) {
            $plans->where('archive', false);
        }

        if ($display_order) {
            $plans->orderBy('display_order');
        }

        return $plans->get();
    }

    public function appPlanEnabled(AppPlan $plan)
    {
        return in_array($plan->id, $this->appPlansList($plan->application));
    }

    /**
     * Whether an organization on this plan may pick the app plan when it activates an app.
     */
    public function appPlanSelectable(AppPlan $plan): bool
    {
        $app_plans = Arr::get($this->app_plans, $plan->application->slug.'.plans');

        return is_array($app_plans)
            && in_array($plan->id, $app_plans)
            && ! $plan->archive
            && $this->appEnabled($plan->application);
    }

    /**
     * Pay per App plans bill everything through the app plans, except the org-level
     * services that no single app owns (email). Packaged plans bill every entity.
     *
     * @return array<int, PlanEntity>
     */
    public function billableEntities(): array
    {
        return $this->plan->type === 'app' ? [PlanEntity::EMAIL] : $this->pricing_options;
    }

    public function pricingOptions()
    {
        $pricing = [];
        $options = [];

        $entities = $this->billableEntities();

        $currency = $this->organization->billingCurrency();

        foreach ($entities as $entity) {
            $priceData = $this->plan->priceFor($entity->value, $currency);

            if ($priceData['price_id']) {
                $option = [
                    'name' => $entity,
                    'price_id' => $priceData['price_id'],
                    'price' => $priceData['amount'],
                    'quantity' => $this->org_service->countEntity($entity->value),
                ];

                $options[$entity->value] = $option;
            }
        }

        return $options;
    }

    public function status()
    {
        return $this->organization->status;
    }

    public function stats()
    {
        $base = $this;

        return FastCache::retrieve('base_plan_stats_'.$this->organization->id.'_'.$this->plan->id, function () use ($base) {
            $stats = [];
            foreach ($base->billableEntities() as $option) {
                $option_stats = $base->optionStats($option->value);
                if ($option_stats['price'] > 0) {
                    $stats[$option->value] = $option_stats;
                }
            }

            return $stats;
        });
    }

    public function optionStats($type)
    {
        $stats = $type.'Stats';

        return $this->$stats();
    }

    public function baseStats()
    {
        $price = $this->price('base');

        return [
            'label' => __('labels.base'),
            'quantity' => $price ? 1 : 0,
            'price' => $price,
            'total_price' => $price ? number_format($price, 2, '.', ', ') : null,
        ];
    }

    public function standardStats()
    {
        return [
            'label' => __('labels.standard_users'),
            'calculation' => '$'.$this->price('standard').' per user',
            'quantity' => $this->org_service->countEntity('standard'),
            'price' => $this->price('standard'),
            'total_price' => number_format($this->standardPriceTotal(), 2, '.', ', '),
            'unit' => 'user',
        ];
    }

    public function basicStats()
    {
        return [
            'label' => $this->accessTypeLabel(AccessType::BASIC).' User Price',
            'calculation' => '$'.$this->price('basic').' for every '.$this->plan->setting('basic.amount').' '.Str::plural(strtolower($this->accessTypeLabel(AccessType::BASIC))),
            'quantity' => $this->org_service->countEntity('basic'),
            'price' => $this->price('basic'),
            'total_price' => number_format($this->basicPriceTotal(), 2, '.', ', '),
            'unit' => $this->plan->setting('basic.amount').' '.Str::plural(Str::lower($this->accessTypeLabel(AccessType::BASIC)), (int) $this->plan->setting('basic.amount')),
        ];
    }

    public function storageStats()
    {
        return [
            'label' => __('labels.additional_storage'),
            'calculation' => '$'.$this->price('storage').' for every '.$this->plan->setting('storage.amount').'GB',
            'storage' => $this->additionalStorageTotal(),
            'quantity' => $this->org_service->countEntity('storage'),
            'price' => $this->price('storage'),
            'amount' => $this->plan->setting('storage.amount'),
            'total_price' => number_format($this->additionalStoragePriceTotal(), 2, '.', ', '),
            'unit' => $this->plan->setting('storage.amount').'GB',
        ];
    }

    public function emailStats()
    {
        return [
            'label' => __('labels.email'),
            'calculation' => '$'.$this->price('email').' per email',
            'quantity' => $this->org_service->countEntity('email'),
            'price' => $this->price('email'),
            'total_price' => number_format($this->emailPriceTotal(), 2, '.', ', '),
            'unit' => 'email accounts',
        ];
    }

    public function applicationStats()
    {
        return [
            'label' => __('labels.app'),
            'calculation' => $this->org_service->countEntity('application').' x $'.$this->price('application'),
            'quantity' => $this->org_service->countEntity('application'),
            'price' => $this->price('application'),
            'total_price' => number_format($this->applicationPriceTotal(), 2, '.', ', '),
            'unit' => 'application',
        ];
    }

    /**
     * The price of an entity in the organization's billing currency, falling back like billing does
     * (default currency, then the legacy flat price). Plans save per-currency prices, so reading the
     * flat setting directly gives nothing and the summaries come up empty.
     */
    public function price($entity)
    {
        return $this->plan->amountFor($entity, $this->organization->billingCurrency());
    }

    public function refresh()
    {
        $this->organization->refresh();
        $this->plan = $this->organization->plan;
    }

    /**
     * What an access type is called to the organization. The names are the organization plan's, whatever
     * app they are for, and fall back to defaults when the plan doesn't name them.
     */
    public function accessTypeLabel(AccessType $access_type): string
    {
        return match ($access_type) {
            AccessType::STANDARD => $this->setting('base.standard_label') ?: __('labels.access_standard'),
            AccessType::BASIC => $this->setting('basic.name') ?: __('labels.access_basic'),
            AccessType::MINIMAL => $this->setting('base.minimal_label') ?: __('labels.access_minimal'),
            AccessType::NONE => __('labels.access_none'),
        };
    }

    // Whether the plan has basic users. Plans saved before it could be set apart from the name are on when named
    public function isBasicUsersEnabled(): bool
    {
        return (bool) ($this->setting('basic.enabled') ?? filled($this->setting('basic.name')));
    }

    // Whether the plan has minimal users. Plans saved before it could be set apart from the label are on when labelled
    public function isMinimalUsersEnabled(): bool
    {
        return (bool) ($this->setting('base.minimal_enabled') ?? filled($this->setting('base.minimal_label')));
    }

    public function availableAccessTypes()
    {
        $access_types = [
            [
                'value' => 'standard',
                'text' => $this->accessTypeLabel(AccessType::STANDARD),
            ],
        ];

        if ($this->isBasicUsersEnabled()) {
            $access_types[] = [
                'value' => 'basic',
                'text' => $this->accessTypeLabel(AccessType::BASIC),
            ];
        }

        if ($this->isMinimalUsersEnabled()) {
            $access_types[] = [
                'value' => 'minimal',
                'text' => $this->accessTypeLabel(AccessType::MINIMAL),
            ];
        }

        $access_types[] = [
            'value' => 'none',
            'text' => $this->accessTypeLabel(AccessType::NONE),
        ];

        return $access_types;
    }

    public function availableAccessTypesForUser(UserManager $user)
    {
        $access_types = $this->availableAccessTypes();
        $filtered_access_types = [];
        foreach ($access_types as $type) {
            switch ($type['value']) {
                case 'standard':
                    $type['disabled'] = ! Gate::allows('update-standard-user', $user);
                    break;
                case 'basic':
                    $type['disabled'] = ! Gate::allows('update-basic-user', $user);
                    break;
                default:
                    $type['disabled'] = false;
            }
            $filtered_access_types[] = $type;
        }

        return $filtered_access_types;
    }

    public function availableAccessTypesList()
    {
        $access_types = [];
        foreach ($this->availableAccessTypes() as $access_type) {
            $access_types[] = $access_type['value'];
        }

        return $access_types;
    }

    public function domainsEnabled()
    {
        return $this->plan->domain_enabled;
    }

    public function emailEnabled()
    {
        return $this->plan->email_enabled == 1;
    }

    /**
     * Whether the organization's current usage is above any limit of this plan. Deliberately
     * not cached: it depends on live counts, and every plan has its own answer.
     */
    public function isAnyMaxBroken()
    {
        return $this->brokenLimits() !== [];
    }

    /**
     * The limits of this plan the organization's current usage is above.
     *
     * Pay per App plans null the org-wide limits, so for them only the per-app instance
     * caps and email can show up here.
     *
     * @return array<int, array{name: string, used: int, max: int}>
     */
    public function brokenLimits(): array
    {
        return array_merge($this->brokenAppLimits(), $this->brokenEntityLimits());
    }

    /**
     * Apps the organization has more instances of than this plan allows.
     *
     * @return array<int, array{name: string, used: int, max: int}>
     */
    private function brokenAppLimits(): array
    {
        $broken = [];

        // Only apps with instances can be over their cap, and one query beats one per app
        $instances = $this->organization->app_instances()
            ->selectRaw('application_id, count(*) as total')
            ->groupBy('application_id')
            ->pluck('total', 'application_id');

        foreach (Application::whereIn('id', $instances->keys())->get() as $app) {
            $max = Arr::get($this->plan->app_plans, "{$app->slug}.max");

            if ($max < $instances[$app->id]) {
                $broken[] = ['name' => $app->name, 'used' => (int) $instances[$app->id], 'max' => (int) $max];
            }
        }

        return $broken;
    }

    /**
     * @return array<int, array{name: string, used: int, max: int}>
     */
    private function brokenEntityLimits(): array
    {
        $broken = [];

        foreach ($this->pricing_options as $entity) {
            if ($this->isMaxBroken($entity)) {
                $broken[] = [
                    'name' => __("organization.plan.denied.limits.{$entity->value}"),
                    'used' => (int) $this->org_service->countEntity($entity->value),
                    'max' => (int) $this->plan->setting("{$entity->value}.max"),
                ];
            }
        }

        return $broken;
    }

    /**
     * Why the organization can't select this plan, naming each limit it is over.
     */
    public function limitsMessage(): string
    {
        $limits = collect($this->brokenLimits())
            ->map(fn (array $limit) => __('organization.plan.denied.limit_item', $limit))
            ->implode('; ');

        return __('organization.plan.denied.limit', ['limits' => $limits]);
    }

    public function isMaxApps(Application $app)
    {
        $app_instances = AppFacade::instances($this->organization, $app->slug);
        $max_name = "{$app->slug}.max";
        $max_apps = (int) Arr::get($this->app_plans, $max_name, 1);

        return $this->isMax('application') || count($app_instances) >= $max_apps;
    }

    public function totalPrice()
    {
        if ($this->plan->type === 'app') {
            return $this->emailPriceTotal();
        }

        $base = $this;

        return FastCache::retrieve('base_plan_total_price_'.$this->organization->id.'_'.$this->plan->id, function () use ($base) {
            return $base->price('base')
            + $base->standardPriceTotal()
            + $base->basicPriceTotal()
            + $base->additionalStoragePriceTotal()
            + $base->applicationPriceTotal()
            + $base->emailPriceTotal();
        });
    }

    public function isMaxAppsBroken()
    {
        return $this->brokenAppLimits() !== [];
    }

    public function isDomainMax()
    {
        return ! is_null($this->plan->domain_max) && $this->organization->main_domains()->where('status', '!=', 'pending_registration')->count() >= $this->plan->domain_max;
    }

    public function isMax($entity)
    {
        return $this->plan->setting("$entity.max") && $this->org_service->countEntity($entity) >= (int) $this->plan->setting("$entity.max");
    }

    public function isMaxBroken(PlanEntity $entity)
    {
        return (! $this->plan->setting("{$entity->value}.max") || $this->org_service->countEntity($entity->value) <= $this->plan->setting("{$entity->value}.max")) ? false : true;
    }

    public function standardPriceTotal()
    {
        return $this->price('standard') ? $this->org_service->countEntity('standard') * $this->price('standard') : 0;
    }

    public function standardStorageTotal()
    {
        return ($this->price('standard')) ? $this->org_service->countEntity('standard') * $this->plan->setting('standard.storage') : 0;
    }

    public function basicPriceTotal()
    {
        return $this->price('basic') ? $this->basicBlocks() * $this->price('basic') : 0;
    }

    /**
     * Number of billable blocks of basic users. Users are billed in blocks of
     * `basic.amount`; a missing or zero block size means one user per block.
     */
    public function basicBlocks(): int
    {
        $block_size = max(1, (int) $this->plan->setting('basic.amount'));

        return (int) ceil($this->org_service->countEntity('basic') / $block_size);
    }

    public function basicAmountTotal()
    {
        return ($this->plan->setting('basic.amount')) ? $this->org_service->countEntity('basic') * $this->plan->setting('basic.amount') : 0;
    }

    public function additionalStoragePriceTotal()
    {
        return $this->price('storage') ? $this->org_service->countEntity('storage') * $this->price('storage') : 0;
    }

    public function additionalStorageTotal()
    {
        return $this->plan->setting('storage.amount') ? $this->org_service->countEntity('storage') * $this->plan->setting('storage.amount') : 0;
    }

    public function applicationPriceTotal()
    {
        return $this->price('application') ? $this->org_service->countEntity('application') * $this->price('application') : 0;
    }

    public function emailPriceTotal()
    {
        return $this->price('email') ? $this->org_service->countEntity('email') * $this->price('email') : 0;
    }

    public function accessTypeName(?AccessType $access_type = null): ?string
    {
        switch ($access_type) {
            case AccessType::STANDARD:
            case AccessType::BASIC:
            case AccessType::MINIMAL:
                return $this->accessTypeLabel($access_type);
            case AccessType::NONE:
                return __('labels.none_user');
            default:
                return __('labels.unknown');
        }
    }

    public function save()
    {
        $this->plan->save();
    }

    public function __get($property)
    {
        return $this->plan->$property;
    }

    public function __call($method, $args)
    {
        return $this->plan->$method(...$args);
    }
}
