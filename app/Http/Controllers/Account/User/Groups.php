<?php

namespace App\Http\Controllers\Account\User;

use App\Http\Controllers\Controller;
use App\Support\Facades\AccountManager;
use Illuminate\Http\Request;

class Groups extends Controller
{
    public function edit($userid)
    {
        $user = AccountManager::users()->find($userid);
        $this->authorize('edit-user', $user);

        $categories = AccountManager::groups()->all();
        $groups = [];

        foreach ($categories as $category) {
            foreach ($category['groups'] as $group) {
                $groups[] = $group;
            }
        }

        return inertia('Organization/Users/UserGroups', [
            'user' => [
                'id' => $user->attribute('username'),
                'name' => $user->attribute('name'),
                'first_name' => $user->attribute('first_name'),
                'last_name' => $user->attribute('last_name'),
                'url' => [
                    'show' => '/users/'.$user->attribute('username'),
                    'edit' => '/users/'.$user->attribute('username').'/edit',
                    'permissions' => '/users/'.$user->attribute('username').'/permissions',
                ],
                'groups' => $user->listGroups(),
            ],
            'groups' => $groups,
            'breadcrumbs' => [
                [
                    'label' => 'Users',
                    'url' => '/users',
                ],
                [
                    'label' => $user->attribute('name'),
                ],
            ],
        ]);
    }

    public function add(Request $request, $userid, $groupid)
    {
        $user = AccountManager::users()->find($userid);
        $this->authorize('edit-user', $user);

        $group = $user->addToGroup($groupid);

        return redirect("/users/$userid/groups")->with('success', __('organization.user.group.added', ['group' => $group->attribute('name')]));
    }

    public function remove(Request $request, $userid, $groupid)
    {
        $user = AccountManager::users()->find($userid);
        $this->authorize('edit-user', $user);

        $group = $user->removeFromGroup($groupid);

        return redirect("/users/$userid/groups")->with('success', __('organization.user.group.removed', ['group' => $group->attribute('name')]));
    }
}
