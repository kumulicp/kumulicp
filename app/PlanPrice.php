<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One price of a plan component in one currency, e.g. Plan #3 / "standard" / CAD.
 *
 * The amount is what the customer sees. external_ids maps a billing driver to
 * that driver's own id for the price ({"stripe": "price_123"}), so the same row
 * can be billed through whichever engine is configured.
 *
 * @property int $id
 * @property string $priceable_type
 * @property int $priceable_id
 * @property string $component
 * @property string $currency
 * @property float|null $amount
 * @property array<string, string>|null $external_ids
 * @property-read Plan|AppPlan $priceable
 */
class PlanPrice extends Model
{
    protected $fillable = ['component', 'currency', 'amount', 'external_ids'];

    protected $casts = [
        'amount' => 'float',
        'external_ids' => 'array',
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function priceable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The driver whose ids are read and written when none is given: the
     * configured billing driver, or "stripe" while billing is not configured
     * (every id that predates drivers is a Stripe id).
     */
    public static function activeDriver(): string
    {
        return config('billing.default') ?: 'stripe';
    }

    public function externalId(?string $driver = null): ?string
    {
        $id = ($this->external_ids ?? [])[$driver ?? self::activeDriver()] ?? null;

        return $id === '' ? null : $id;
    }
}
