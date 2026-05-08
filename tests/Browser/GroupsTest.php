<?php

describe('Groups', function () {
    beforeEach(function () {
        visit('/login')
            ->fill('input[type=email]', 'demo@example.com')
            ->fill('input[type=password]', 'demouser')
            ->click('#submit')
            ->assertPathIs('/');
    });

    it('can add a new group', function () {
        visit('/groups')
            ->click('#addGroup')
            ->fill('#name input', 'Engineering Team')
            ->click('#category')
            ->click('text=Other')
            ->click('#submit')
            ->assertSee('Engineering Team');
    });

    it('can update a group name', function () {
        visit('/groups')
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
        visit('/groups')
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

    it('shows an error when submitting without a group name', function () {
        visit('/groups')
            ->click('#addGroup')
            ->click('#category')
            ->click('text=Other')
            ->click('#submit')
            ->assertSee('name field is required');
    });

    it('shows an error when submitting without a category', function () {
        visit('/groups')
            ->click('#addGroup')
            ->fill('#name input', 'Valid Name')
            ->click('#submit')
            ->assertSee('category field is required');
    });

    it('shows an error when the group name exceeds 100 characters', function () {
        visit('/groups')
            ->click('#addGroup')
            ->fill('#name input', str_repeat('a', 101))
            ->click('#category')
            ->click('text=Other')
            ->click('#submit')
            ->assertSee('100 characters');
    });

    it('shows an error when creating a group with a duplicate name', function () {
        visit('/groups')
            ->click('#addGroup')
            ->fill('#name input', 'Duplicate Group')
            ->click('#category')
            ->click('text=Other')
            ->click('#submit')
            ->visit('/groups')
            ->click('#addGroup')
            ->fill('#name input', 'Duplicate Group')
            ->click('#category')
            ->click('text=Other')
            ->click('#submit')
            ->assertSee('Group name already exists');
    });

    it('shows an error when updating a group with an empty name', function () {
        visit('/groups')
            ->click('#addGroup')
            ->fill('#name input', 'Test Group')
            ->click('#category')
            ->click('text=Other')
            ->click('#submit')
            ->fill('#name input', '')
            ->click('#submit')
            ->assertSee('name field is required');
    });
});
