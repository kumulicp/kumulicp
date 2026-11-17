<?php

use App\Support\Billing\PlanPriceBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves per-plan prices out of the plans/app_plans settings JSON into their
 * own table, so a price is a row (component, currency, amount) plus a map of
 * billing-driver ids, instead of a Stripe price id buried in JSON.
 *
 * Every id that exists today is a Stripe id, so the backfill files them under
 * the "stripe" driver key.
 */
return new class extends Migration
{
    private const DRIVER = 'stripe';

    /** Morph alias => table */
    private const OWNERS = [
        'plan' => 'plans',
        'app_plan' => 'app_plans',
    ];

    public function up(): void
    {
        Schema::create('plan_prices', function (Blueprint $table) {
            $table->id();
            $table->string('priceable_type', 32);
            $table->unsignedBigInteger('priceable_id');
            // base, standard, basic, storage, application, email, or feature.{name}
            $table->string('component', 100);
            $table->string('currency', 3);
            $table->decimal('amount', 14, 4)->nullable();
            // {driver: id}, e.g. {"stripe": "price_123"}. One row serves every
            // billing driver, so switching engines does not duplicate amounts.
            $table->json('external_ids')->nullable();
            $table->timestamps();

            $table->unique(['priceable_type', 'priceable_id', 'component', 'currency'], 'plan_prices_unique');
        });

        $defaultCurrency = DB::table('server_settings')->where('key', 'default_currency')->value('value') ?: 'USD';

        foreach (self::OWNERS as $type => $tableName) {
            DB::table($tableName)->orderBy('id')->chunkById(100, function ($plans) use ($type, $tableName, $defaultCurrency) {
                foreach ($plans as $plan) {
                    $settings = json_decode($plan->settings ?? '', true);
                    if (! is_array($settings)) {
                        continue;
                    }

                    $extracted = PlanPriceBackfill::extract($settings, $defaultCurrency);

                    foreach ($extracted['rows'] as $row) {
                        DB::table('plan_prices')->insert([
                            'priceable_type' => $type,
                            'priceable_id' => $plan->id,
                            'component' => $row['component'],
                            'currency' => $row['currency'],
                            'amount' => $row['amount'],
                            'external_ids' => $row['external_id'] === null
                                ? null
                                : json_encode([self::DRIVER => $row['external_id']]),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    if ($extracted['settings'] !== $settings) {
                        DB::table($tableName)->where('id', $plan->id)->update([
                            'settings' => json_encode($extracted['settings']),
                        ]);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::OWNERS as $type => $tableName) {
            DB::table($tableName)->orderBy('id')->chunkById(100, function ($plans) use ($type, $tableName) {
                foreach ($plans as $plan) {
                    $settings = json_decode($plan->settings ?? '', true);
                    $settings = is_array($settings) ? $settings : [];

                    $rows = DB::table('plan_prices')
                        ->where('priceable_type', $type)
                        ->where('priceable_id', $plan->id)
                        ->get()
                        ->map(fn ($price) => [
                            'component' => $price->component,
                            'currency' => $price->currency,
                            'amount' => $price->amount,
                            'external_id' => (json_decode($price->external_ids ?? '', true) ?: [])[self::DRIVER] ?? null,
                        ]);

                    if ($rows->isNotEmpty()) {
                        DB::table($tableName)->where('id', $plan->id)->update([
                            'settings' => json_encode(PlanPriceBackfill::restore($settings, $rows)),
                        ]);
                    }
                }
            });
        }

        Schema::dropIfExists('plan_prices');
    }
};
