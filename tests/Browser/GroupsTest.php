<?php

describe('Groups', function () {
    it('can add a new group', function () {
        visit('/login')
            ->fill('input[type=email]', 'demo@example.com')
            ->fill('input[type=password]', 'demouser')
            ->click('#submit')
            ->assertPathIs('/')
            ->visit('/groups')
            ->assertSee('Create Group')
            ->click('#addGroup')
            ->assertSee('Add Group')
            ->fill('#name input', 'Engineering Team')
            ->click('#category')
            ->click('text=Other')
            ->click('#submit')
            ->assertSee('Engineering Team');
    });

    it('can update a group name', function () {
        visit('/login')
            ->fill('input[type=email]', 'demo@example.com')
            ->fill('input[type=password]', 'demouser')
            ->click('#submit')
            ->assertPathIs('/')
            ->visit('/groups')
            ->click('#addGroup')
            ->fill('#name input', 'Original Name')
            ->click('#category')
            ->click('text=Other')
            ->click('#submit')
            ->assertSee('Edit Original Name Group')
            ->fill('#name input', 'Updated Name')
            ->click('#submit')
            ->assertSee('Updated Name');
    });

    it('can delete a group', function () {
        visit('/login')
            ->fill('input[type=email]', 'demo@example.com')
            ->fill('input[type=password]', 'demouser')
            ->click('#submit')
            ->assertPathIs('/')
            ->visit('/groups')
            ->click('#addGroup')
            ->fill('#name input', 'Group To Delete')
            ->click('#category')
            ->click('text=Other')
            ->click('#submit')
            ->visit('/groups')
            ->assertSee('Group To Delete')
            ->click('.table-row button')
            ->assertSee('Remove Group To Delete?')
            ->click('#delete')
            ->assertSee('No Groups Available');
    });
});
