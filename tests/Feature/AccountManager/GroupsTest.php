<?php

use App\Support\Facades\AccountManager;
use App\User;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSupports;

beforeEach(function () {
    Queue::fake();
});

function actAsDemoAdmin(string $driver): void
{
    setupAccountManagerDriver($driver);
    $support = new TestSupports;
    $support->seed();
    $support->addUsers();
    test()->actingAs(User::where('username', 'demo')->firstOrFail());
}

it('lists the organization groups', function (string $driver) {
    actAsDemoAdmin($driver);

    $this->get('/groups')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Organization/Groups/GroupsList')
            ->has('categories')
        );
})->with('account_manager_drivers');

it('can create a group', function (string $driver) {
    actAsDemoAdmin($driver);

    $this->post('/groups', ['name' => 'Engineering Team', 'category' => 'others'])
        ->assertRedirect(route('groups.edit', ['group' => 'Engineering Team']));

    expect(AccountManager::groups()->find('Engineering Team'))->not->toBeNull();
})->with('account_manager_drivers');

it('validates the group on create', function (string $driver, array $payload, string $error_field) {
    actAsDemoAdmin($driver);

    $this->post('/groups', $payload)->assertSessionHasErrors($error_field);
})->with('account_manager_drivers')->with([
    'name is required' => [['category' => 'others'], 'name'],
    'name cannot contain a slash' => [['name' => 'a/b', 'category' => 'others'], 'name'],
    'category is required' => [['name' => 'No Category'], 'category'],
    'category must be a known one' => [['name' => 'Bad Category', 'category' => 'unknown'], 'category'],
]);

it('cannot create a group whose name is already used', function (string $driver) {
    actAsDemoAdmin($driver);

    $this->post('/groups', ['name' => 'Duplicate Team', 'category' => 'others']);
    $this->post('/groups', ['name' => 'Duplicate Team', 'category' => 'others'])
        ->assertSessionHasErrors('name');
})->with('account_manager_drivers');

it('can show the group edit page', function (string $driver) {
    actAsDemoAdmin($driver);
    $this->post('/groups', ['name' => 'Edit Page Team', 'category' => 'others']);

    $this->get('/groups/Edit Page Team/edit')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Organization/Groups/GroupEdit')
            ->where('group.name', 'Edit Page Team')
        );
})->with('account_manager_drivers');

it('redirects to the groups list when the group does not exist', function (string $driver) {
    actAsDemoAdmin($driver);

    $this->get('/groups/Nobody Home/edit')
        ->assertRedirect('/groups')
        ->assertSessionHas('error');
})->with('account_manager_drivers');

it('can rename a group', function (string $driver) {
    actAsDemoAdmin($driver);
    $this->post('/groups', ['name' => 'Original Name', 'category' => 'others']);

    $response = $this->put('/groups/Original Name', [
        'original_name' => 'Original Name',
        'name' => 'Updated Name',
        'category' => 'others',
        'managers' => [],
        'members' => [],
    ])->assertRedirect()->assertSessionHas('success');

    // What the group is looked up by after a rename depends on the driver (the db driver keeps its slug),
    // so check the page the user lands on instead
    $this->get($response->headers->get('Location'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('group.name', 'Updated Name'));
})->with('account_manager_drivers');

it('cannot rename a group to a name that is already used', function (string $driver) {
    actAsDemoAdmin($driver);
    $this->post('/groups', ['name' => 'First Team', 'category' => 'others']);
    $this->post('/groups', ['name' => 'Second Team', 'category' => 'others']);

    $this->put('/groups/Second Team', [
        'original_name' => 'Second Team',
        'name' => 'First Team',
        'category' => 'others',
        'managers' => [],
        'members' => [],
    ])->assertSessionHasErrors('name');

    expect(AccountManager::groups()->find('Second Team'))->not->toBeNull();
})->with('account_manager_drivers');

it('can delete a group', function (string $driver) {
    actAsDemoAdmin($driver);
    $this->post('/groups', ['name' => 'Doomed Team', 'category' => 'others']);

    $this->delete('/groups/Doomed Team')
        ->assertRedirect('/groups')
        ->assertSessionHas('success');

    expect(AccountManager::groups()->find('Doomed Team'))->toBeNull();
})->with('account_manager_drivers');
