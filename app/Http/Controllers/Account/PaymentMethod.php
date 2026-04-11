<?php

namespace App\Http\Controllers\Account;

use App\Actions\Organizations\SubscriptionUpdate;
use App\Http\Controllers\Controller;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Action;
use App\Support\Facades\Billing;
use App\Support\Facades\Organization;
use App\Support\Facades\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class PaymentMethod extends Controller
{
    public function show()
    {
        $organization = Organization::account();

        $data = [
            'hasDefaultPaymentMethod' => Billing::hasDefaultPaymentMethod(),
            'intent' => Billing::intent(),
            'defaultPaymentMethod' => Billing::defaultPaymentMethod(),
            'brand_image' => Billing::defaultPaymentMethodBrandImage(),
            'stripe_key' => env('STRIPE_KEY'),
        ];

        return response()->json($data, 200);
    }

    public function edit()
    {
        $this->authorize('has-billing-account');
        $organization = Organization::account();
        $managers = AccountManager::users()->billingManagers();
        $users = AccountManager::users()->collect()->map(function ($user) {
            return [
                'value' => $user->attribute('username'),
                'text' => $user->attribute('name'),
            ];
        });

        return inertia('Organization/Subscription/PaymentMethod', [
            'hasDefaultPaymentMethod' => Billing::hasDefaultPaymentMethod(),
            'managers' => $managers,
            'users' => $users,
            'driver' => 'StripePaymentMethod',
        ]);
    }

    public function update(Request $request)
    {
        $organization = Organization::account();
        $new_payment_method = $request->input('paymentMethod');

        if (Billing::hasDefaultPaymentMethod()) {
            Billing::defaultPaymentMethod();

            Billing::deleteDefaultPaymentMethod();
        }
        Billing::updateDefaultPaymentMethod($new_payment_method);

        $payment_method = Billing::defaultPaymentMethod();

        Action::execute(new SubscriptionUpdate($organization, Subscription::all()), background: true);

        $info = [
            'success' => 'success',
            'text' => __('organization.payment_method.updated'),
            'defaultPaymentMethod' => $payment_method,
            'card_brand' => Arr::get($payment_method, 'card.brand'),
            'card_last_four' => Arr::get($payment_method, 'card.last4'),
            'expiry_month' => Arr::get($payment_method, 'card.exp_month'),
            'expiry_year' => Arr::get($payment_method, 'card.exp_year'),
            'brand_image' => Billing::defaultPaymentMethodBrandImage(),
        ];

        return response()->json($info, 200);
    }

    public function delete()
    {
        $organization = Organization::account();

        if (Billing::hasDefaultPaymentMethod()) {
            Billing::deleteDefaultPaymentMethod();
            Action::execute(new SubscriptionUpdate($organization, Subscription::all()), background: true);

            $subscription = Subscription::all();
            $plans = $subscription->get();
            $base_plan = $subscription->base();
            $paid_plans = $subscription->paidSubscriptions();

            if ($base_plan->payment_enabled) {
                foreach ($plans as $plan) {
                    $model = $plan->model();
                    $model->status = 'deactivating';
                    $model->save();
                }
            } else {
                foreach ($paid_plans as $plan) {
                    $model = $plan->model();
                    $model->status = 'deactivating';
                    $model->save();
                }
            }
        }

        return back()->with('success', __('organization.payment_method.removed'));
    }
}
