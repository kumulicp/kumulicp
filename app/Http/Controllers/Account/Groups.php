<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Rules\GroupNameNotUsed;
use App\Support\AccountManager\GroupCatalog;
use App\Support\AccountManager\GroupManager;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Action;
use App\Support\Facades\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class Groups extends Controller
{
    public function index()
    {
        Gate::authorize('active');

        $organization = Organization::account();

        // A parent sees, and manages, its suborganizations' groups along with its own
        $categories = GroupCatalog::tag(AccountManager::groups()->all(), $organization);
        foreach ($organization->suborganizations as $suborganization) {
            $categories = GroupCatalog::merge(
                $categories,
                GroupCatalog::tag(AccountManager::account($suborganization)->groups()->all(), $suborganization)
            );
        }

        return inertia('Organization/Groups/GroupsList', [
            'categories' => $categories,
            'multiple_orgs' => $organization->suborganizations->isNotEmpty(),
        ]);
    }

    public function show(Request $request, $group)
    {
        return to_route('groups.edit', ['group' => $group] + $request->only('organization'));
    }

    /**
     * The group a request is about and the organization it belongs to.
     *
     * A group is found in the active organization first. A parent can also reach its suborganizations'
     * groups: one with the same name in the parent wins, and `organization` picks a suborg's explicitly.
     * Only the organization itself or one of its suborganizations can be picked.
     *
     * @return array{0: \App\Organization, 1: GroupManager}|null
     */
    private function resolve(Request $request, string $slug): ?array
    {
        $organization = Organization::account();

        if ($request->filled('organization')) {
            $target = \App\Organization::find($request->input('organization'));

            if (! $target || ! ($target->is($organization) || $target->parent_organization_id === $organization->id)) {
                return null;
            }

            $group = AccountManager::account($target)->groups()->find($slug);

            return $group ? [$target, $group] : null;
        }

        if ($group = AccountManager::groups()->find($slug)) {
            return [$organization, $group];
        }

        $matches = [];
        foreach ($organization->suborganizations as $suborganization) {
            if ($group = AccountManager::account($suborganization)->groups()->find($slug)) {
                $matches[] = [$suborganization, $group];
            }
        }

        // The same name in several suborganizations needs the organization to be named
        return count($matches) === 1 ? $matches[0] : null;
    }

    /** What a redirect to a group needs to find it again. */
    private function parameters(\App\Organization $group_organization, string $slug): array
    {
        return $group_organization->is(Organization::account())
            ? ['group' => $slug]
            : ['group' => $slug, 'organization' => $group_organization->id];
    }

    public function store(Request $request)
    {
        Gate::authorize('active');
        /* Validate */
        $validatedData = $request->validate([
            'name' => ['required', 'max:100', 'not_regex:/[\/\\\\]/', new GroupNameNotUsed],
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

    public function edit(Request $request, $group)
    {
        Gate::authorize('active');

        $resolved = $this->resolve($request, $group);
        if (! $resolved) {
            return redirect('/groups')->with('error', 'Group not found');
        }
        [$organization, $group] = $resolved;

        $managers = $group->managerNames();
        $members = $group->members();
        $category = $group->categoryName($group);

        $users = AccountManager::account($organization)->users()->collect()->map(function ($user) {
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
                'organization_id' => $organization->id,
                'organization' => $organization->name,
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
        Gate::authorize('active');

        $resolved = $this->resolve($request, $group_name);
        if (! $resolved) {
            return redirect('/groups')->with('error', 'Group not found');
        }
        [$organization, $group] = $resolved;
        $groups = AccountManager::account($organization)->groups();

        /* Validate */
        $validator = Validator::make($request->all(), [
            'original_name' => 'required', // TODO: Check if original name exists
            'name' => ['required', 'max:100', 'not_regex:/[\/\\\\]/'],
            'category' => 'required|in:departments,teams,projects,ministries,others',
            'managers' => 'array',
            'members' => 'array',
            'extensions' => 'array|nullable',
        ]);

        $validator->after(function ($validator) use ($groups) {
            $validatedData = $validator->validated();
            if ($validatedData['original_name'] != $validatedData['name'] && $groups->find($validatedData['name'])) {
                $validator->errors()->add(
                    'name', $validatedData['name'].' already exists!'
                );
            }
        });
        $validatedData = $validator->validate();

        // Update Group settings
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

        return to_route('groups.edit', $this->parameters($organization, $group->attribute('slug')))->with('success', __('organization.group.updated', ['group' => $validatedData['name']]));
    }

    public function destroy(Request $request, $group_name)
    {
        Gate::authorize('active');

        $resolved = $this->resolve($request, $group_name);

        if ($resolved) {
            [$organization, $group] = $resolved;

            foreach ($organization->active_apps() as $app) {
                Action::dispatch($app->application->slug, 'process_group_options', [$app, $group, ['original_name' => $group_name, 'group_name' => $group_name, 'action' => 'remove']]);
            }
            $group->delete();
        }

        return redirect('/groups')->with('success', __('organization.group.deleted', ['group' => $group_name]));
    }
}
