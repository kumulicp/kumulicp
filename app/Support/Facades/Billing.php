<?php

namespace App\Support\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool isBillable()
 * @method static array intent()
 * @method static void update()
 * @method static void cancel()
 * @method static void sendInvoices()
 * @method static void sendInvoice(float $price, string $description)
 * @method static \Carbon\Carbon|null periodEnds()
 * @method static \Illuminate\Support\Collection invoices()
 * @method static array upcomingInvoice()
 * @method static string status(string $type = 'label')
 * @method static array discount()
 * @method static bool hasDefaultPaymentMethod()
 * @method static void updateDefaultPaymentMethod(string $payment_method)
 * @method static void deleteDefaultPaymentMethod()
 * @method static array defaultPaymentMethod()
 * @method static string|null defaultPaymentMethodBrandImage()
 * @method static static organization(\App\Organization $organization)
 */
class Billing extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'billing';
    }
}
