<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Support\Facades\AccountManager;
use Illuminate\Http\Request;

class BillingManagers extends Controller
{
    public function store(Request $request)
    {
        $user = AccountManager::users()->find($request->user_id);

        $validated = $request->validate([
            'user_id' => [
                'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail) use ($user) {
                    if (! $user) {
                        $fail(__('organization.user.denied.exists'));
                    }
                },
            ],
        ]);

        $user->permissions()->addBillingManagerAccess();

        return redirect('/subscription/payment')->with('success', __('organization.billing_manager.added', ['user' => $user->attribute('name')]));
    }

    public function destroy($id)
    {
        $user = AccountManager::users()->find($id);
        $user_name = $user->attribute('name');

        if ($user) {
            $user->permissions()->removeBillingManagerAccess();
        }

        return redirect('/subscription/payment')->with('success', __('organization.billing_manager.removed', ['user' => $user_name]));
    }
}
