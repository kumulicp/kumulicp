<?php

namespace App;

use App\Support\Facades\Settings as SettingsFacade;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * @property int $id
 * @property string $name
 * @property int|null $email_server_id
 * @property array|null $app_plans
 * @property array|null $settings
 * @property array|null $features
 * @property bool $is_default
 * @property bool $payment_enabled
 * @property bool $domain_enabled
 * @property bool $email_enabled
 * @property bool $archive
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Organization> $subscribers
 * @property-read \App\Server|null $email_server
 */
class Plan extends Model
{
    use HasFactory;

    protected $table = 'plans';

    private $organization;

    protected $fillable = [
        'name', 'app_plans', 'settings', 'is_default', 'payment_enabled', 'domain_enabled', 'email_enabled', 'archive', 'features',
    ];

    protected $casts = [
        'app_plans' => 'array',
        'settings' => 'array',
        'is_default' => 'boolean',
        'payment_enabled' => 'boolean',
        'domain_enabled' => 'boolean',
        'email_enabled' => 'boolean',
        'archive' => 'boolean',
        'features' => 'array',
    ];

    public function subscribers()
    {
        return $this->hasMany('App\Organization', 'plan_id');
    }

    public function email_server()
    {
        return $this->belongsTo('App\Server', 'email_server_id');
    }

    public function displayFeatures()
    {
        return is_array($this->features) ? $this->features : [];
    }

    public function hasValue($value)
    {
        return $this->$value ? true : false;
    }

    public function appPlansList(Application $app)
    {
        $plan = "{$app->slug}.plans";
        if (Arr::has($this->app_plans, $plan)) {
            return Arr::get($this->app_plans, $plan, []);
        }

        // Will return null if app not found
        return Arr::get($this->app_plans, $app->slug, []);
    }

    public function appMax(Application $app)
    {
        $max = "{$app->slug}.max";

        return Arr::get($this->app_plans, $max);
    }

    public function setting($setting)
    {
        return is_array($this->settings) ? Arr::get($this->settings, $setting, null) : null;
    }

    /**
     * Returns ['amount' => ..., 'price_id' => ...] for the given component and currency,
     * falling back to the default currency then to the legacy flat price/price_id keys.
     */
    public function priceFor(string $component, string $currency): array
    {
        $amount = $this->setting("{$component}.prices.{$currency}.amount");
        $priceId = $this->setting("{$component}.prices.{$currency}.price_id");

        if ($priceId) {
            return ['amount' => $amount, 'price_id' => $priceId];
        }

        $defaultCurrency = SettingsFacade::get('default_currency', 'USD');
        if ($currency !== $defaultCurrency) {
            $amount = $this->setting("{$component}.prices.{$defaultCurrency}.amount");
            $priceId = $this->setting("{$component}.prices.{$defaultCurrency}.price_id");
            if ($priceId) {
                return ['amount' => $amount, 'price_id' => $priceId];
            }
        }

        // Legacy flat format (pre-currency migration)
        return [
            'amount' => $this->setting("{$component}.price"),
            'price_id' => $this->setting("{$component}.price_id"),
        ];
    }

    public function updateSettings(array $settings)
    {
        $current_settings = $this->settings;
        foreach ($settings as $setting => $value) {
            Arr::set($current_settings, $setting, $value);
        }

        $this->settings = $current_settings;
    }
}
