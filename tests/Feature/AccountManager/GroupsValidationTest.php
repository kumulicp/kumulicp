<?php

use App\Support\Facades\AccountManager;
use App\User;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSupports;

// Create, update and delete of a group are in GroupsTest. These are the pages and rules around them

beforeEach(function () {
    Queue::fake();
    $this->actAsDemoAdmin = function (string $driver) {
        setupAccountManagerDriver($driver);
        $support = new TestSupports;
        $support->seed();
        $support->addUsers();
        $this->actingAs(User::where('username', 'demo')->firstOrFail());
    };
});

it('lists the organization groups', function (string $driver) {
    ($this->actAsDemoAdmin)($driver);

    $this->get('/groups')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Organization/Groups/GroupsList')
            ->has('categories')
        );
})->with('account_manager_drivers');

it('validates the group on create', function (string $driver, array $payload, string $error_field) {
    ($this->actAsDemoAdmin)($driver);

    $this->post('/groups', $payload)->assertSessionHasErrors($error_field);
})->with('account_manager_drivers')->with([
    'name is required' => [['category' => 'others'], 'name'],
    'name cannot contain a slash' => [['name' => 'a/b', 'category' => 'others'], 'name'],
    'category is required' => [['name' => 'No Category'], 'category'],
    'category must be a known one' => [['name' => 'Bad Category', 'category' => 'unknown'], 'category'],
]);

it('cannot create a group whose name is already used', function (string $driver) {
    ($this->actAsDemoAdmin)($driver);

    $this->post('/groups', ['name' => 'Duplicate Team', 'category' => 'others']);
    $this->post('/groups', ['name' => 'Duplicate Team', 'category' => 'others'])
        ->assertSessionHasErrors('name');
})->with('account_manager_drivers');

it('shows the group edit page', function (string $driver) {
    ($this->actAsDemoAdmin)($driver);
    $this->post('/groups', ['name' => 'Edit Page Team', 'category' => 'others']);

    $this->get('/groups/Edit Page Team/edit')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Organization/Groups/GroupEdit')
            ->where('group.name', 'Edit Page Team')
        );
})->with('account_manager_drivers');

it('redirects to the groups list when the group does not exist', function (string $driver) {
    ($this->actAsDemoAdmin)($driver);

    $this->get('/groups/Nobody Home/edit')
        ->assertRedirect('/groups')
        ->assertSessionHas('error');
})->with('account_manager_drivers');

it('cannot rename a group to a name that is already used', function (string $driver) {
    ($this->actAsDemoAdmin)($driver);
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
