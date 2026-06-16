<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Support\Facades\AccountManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BillingManagers extends Controller
{
    public function store(Request $request)
    {
        $user = AccountManager::users()->find($request->user_id);

        Gate::authorize('edit-user', $user);

        $request->validate([
            'user_id' => ['required', 'string'],
        ]);

        $user->permissions()->addBillingManagerAccess();

        return redirect('/subscription/payment')->with('success', __('organization.billing_manager.added', ['user' => $user->attribute('name')]));
    }

    public function destroy($id)
    {
        $user = AccountManager::users()->find($id);

        Gate::authorize('edit-user', $user);

        $user_name = $user->attribute('name');
        $user->permissions()->removeBillingManagerAccess();

        return redirect('/subscription/payment')->with('success', __('organization.billing_manager.removed', ['user' => $user_name]));
    }
}
