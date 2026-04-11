<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Rules\GroupNameNotUsed;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Action;
use App\Support\Facades\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class Groups extends Controller
{
    public function index()
    {
        $this->authorize('active');

        $organization = Organization::account();
        $categories = AccountManager::groups()->all();

        return inertia('Organization/Groups/GroupsList', [
            'categories' => $categories,
        ]);
    }

    public function show($group)
    {
        return redirect("/groups/$group/edit");
    }

    public function store(Request $request)
    {
        $this->authorize('active');
        /* Validate */
        $validatedData = $request->validate([
            'name' => ['required', 'max:100', new GroupNameNotUsed],
            'category' => 'required|in:departments,teams,projects,ministries,others',
        ]);

        $organization = Organization::account();

        // Check if groups exists and create if not
        $group = AccountManager::groups()->find($validatedData['name']);

        // Check if groups ou exists and create if not
        $group = AccountManager::groups()->add($validatedData);

        $task = null;
        $active_apps = $organization->active_apps();

        foreach ($active_apps as $app) {
            Action::dispatch($app->application->slug, 'process_group_options', [$app, $group, $request->all()]);
        }

        return to_route('groups.edit', ['group' => $validatedData['name']]);
    }

    public function edit($group)
    {
        $this->authorize('active');
        $organization = Organization::account();

        $group = AccountManager::groups()->find($group);
        if (! $group) {
            return redirect('/groups')->with('error', 'Group not found');
        }

        $managers = $group->managerNames();
        $members = $group->members();
        $category = $group->categoryName($group);

        $users = AccountManager::users()->collect()->map(function ($user) {
            return [
                'text' => $user->attribute('name'),
                'value' => $user->attribute('username'),
            ];
        });

        $extensions = [];
        foreach ($organization->active_apps() as $app) {
            if ($app->extensionExists('groups')) {
                foreach ($app->extension('groups', ['name' => $group->attribute('slug'), 'action' => 'update']) as $option) {
                    $extensions[] = $option;
                }
            }
        }

        return inertia('Organization/Groups/GroupEdit', [
            'group' => [
                'slug' => $group->attribute('slug'),
                'name' => $group->attribute('name'),
            ],
            'managers' => $managers,
            'members' => $members,
            'category' => $category,
            'users' => $users,
            'extensions' => $extensions,
        ]);
    }

    public function update(Request $request, $group_name)
    {
        $this->authorize('active');
        /* Validate */
        $validator = Validator::make($request->all(), [
            'original_name' => 'required', // TODO: Check if original name exists
            'name' => ['required', 'max:100'],
            'category' => 'required|in:departments,teams,projects,ministries,others',
            'managers' => 'array',
            'members' => 'array',
            'extensions' => 'array|nullable',
        ]);

        $organization = Organization::account();

        $validator->after(function ($validator) {
            $validatedData = $validator->validated();
            if ($validatedData['original_name'] != $validatedData['name'] && AccountManager::groups()->find($validatedData['name'])) {
                $validator->errors()->add(
                    'name', $validatedData['name'].' already exists!'
                );
            }
        });
        $validatedData = $validator->validate();

        // Update Group settings
        $group = AccountManager::groups()->find($group_name);
        $group->disableAutoSave();
        $group->updateManagers($validatedData['managers']);
        $group->updateMembers($validatedData['members']);
        $group->updateName($validatedData['name']);
        $group->updateCategory($validatedData['category']);
        $group->save();

        $active_apps = $organization->active_apps();

        $task = null;
        foreach ($active_apps as $app) {
            Action::dispatch($app->application->slug, 'process_group_options', [$app, $group, $request->all()]);
        }

        return to_route('groups.edit', ['group' => $group->attribute('slug')])->with('success', __('organization.group.updated', ['group' => $validatedData['name']]));
    }

    public function destroy($group_name)
    {
        $this->authorize('active');
        $organization = Organization::account();

        $active_apps = $organization->active_apps();

        $group = AccountManager::groups()->find($group_name);

        if ($group) {
            foreach ($active_apps as $app) {
                Action::dispatch($app->application->slug, 'process_group_options', [$app, $group, ['original_name' => $group_name, 'group_name' => $group_name, 'action' => 'remove']]);
            }
            $group->delete();
        }

        return redirect('/groups')->with('success', __('organization.group.deleted', ['group' => $group_name]));
    }
}
