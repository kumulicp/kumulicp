<?php

use App\Organization;
use App\Support\Facades\AccountManager;
use App\User;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestSupports;



// ---------------------------------------------------------------------------
// Shared setup: each test gets a clean database (RefreshDatabase + DemoSeeder
// already applied by global beforeEach in Pest.php), plus the three Spatie
// roles, a demo user promoted to admin, and a testing1 user to edit.
// actingAs() sets up the session for the in-process browser server.
//
// NOTE: The DemoSeeder assigns Wordpress app_plan_id = 3 (basic plan), which
// has basic.name = null. With no basic label, basic and minimal roles are
// bumped to standard. Tests that target basic roles must switch Wordpress to
// app_plan_id = 4 (paid plan, basic.name = "Volunteer") in their own setup.
// ---------------------------------------------------------------------------
describe('User Permissions Update', function () {
    beforeEach(function () {
        $this->actingAs(User::find(1));
        (new TestSupports())->addUsers();
    });

    // -----------------------------------------------------------------------
    // 1. Page loads
    // -----------------------------------------------------------------------
    it('loads the permissions page with the user name and app rows', function () {
        visit('/users/testing1/permissions')
            ->assertPathIs('/users/testing1/permissions')
            ->assertSee('test user1')
            ->assertSee('Wordpress')
            ->assertSee('Update Permissions');
    });

    // -----------------------------------------------------------------------
    // 2. App permissions modal – roles are grouped under access type headers.
    //    Default plan (app_plan_id = 3, no basic label) means all roles are
    //    standard, so only the "Active User" section header appears.
    // -----------------------------------------------------------------------
    it('opens the app permissions modal for the Wordpress row', function () {
        $page = visit('/users/testing1/permissions')
            ->assertPathIs('/users/testing1/permissions');

        $page->click('#open-permissions-2');

        $page->assertSee('Update App')
            ->assertSee('Active User');
    });

    // -----------------------------------------------------------------------
    // 3. App permissions – assigning a standard role (Administrator).
    //    Default plan (app_plan_id = 3) — Administrator is standard; it
    //    appears under the "Active User" section header.
    // -----------------------------------------------------------------------
    it('assigns a standard role, shows the access-type chip, and presents the confirm modal', function () {
        $page = visit('/users/testing1/permissions')
            ->assertPathIs('/users/testing1/permissions');

        $page->click('#open-permissions-2');
        $page->assertSee('Update App');

        $page->click('#switch-0-administrator');

        $page->click('#modal-ok');

        $page->assertSee('Content Admin');

        $page->click('#submit')
            ->assertSee('Confirm Permission Changes')
            ->assertSee('Access changed')
            ->assertSee('Active User');

        $page->click('#modal-confirm')
            ->assertPathIs('/users/testing1');
    });

    // -----------------------------------------------------------------------
    // 4. App permissions – assigning a basic role (Author).
    //    Requires app_plan_id = 4 (basic.name = "Volunteer") so that basic
    //    roles are not bumped to standard. Author and Contributor appear under
    //    "Volunteer"; Administrator and Editor remain under "Active User".
    // -----------------------------------------------------------------------
    it('assigns a basic role and the confirm modal lists the correct access change', function () {
        // Enable basic access type label for Wordpress (paid plan has basic.name = "Volunteer")
        DB::table('app_instances')->where('name', 'wordpress')->update(['plan_id' => 4]);

        $page = visit('/users/testing1/permissions')
            ->assertPathIs('/users/testing1/permissions');

        $page->click('#open-permissions-2');

        // "Content Author" now appears under the "Volunteer" (basic) section
        $page->assertSee('Volunteer');
        $page->click('#switch-0-author');

        $page->click('#modal-ok');

        $page->assertSee('Content Author')
            ->click('#submit');

        $page->assertSee('Confirm Permission Changes')
            ->assertSee('Access changed')
            ->click('#modal-confirm')
            ->assertPathIs('/users/testing1');
    });

    // -----------------------------------------------------------------------
    // 5. Selecting a role from a different access type section replaces the
    //    current selection for that category.
    //    Requires app_plan_id = 4 so that standard and basic have separate
    //    section headers — a meaningful cross-tier switch can be tested.
    // -----------------------------------------------------------------------
    it('selecting a role from a different access type section replaces the previous role', function () {
        // Enable basic label so Administrator (Active User) and Author (Volunteer) are in different sections
        DB::table('app_instances')->where('name', 'wordpress')->update(['plan_id' => 4]);

        $page = visit('/users/testing1/permissions')
            ->assertPathIs('/users/testing1/permissions');

        $page->click('#open-permissions-2');

        // Select standard role first (under "Active User")
        $page->click('#switch-0-administrator');
        // Then select basic role for the same category (under "Volunteer") — Administrator clears
        $page->click('#switch-0-author');

        $page->click('#modal-ok');

        // Only Content Author chip should appear
        $page->assertSee('Content Author')
            ->assertDontSee('Content Administrator');
    });

    // -----------------------------------------------------------------------
    // 6. Organization / control-panel access
    //
    // The "Control Panel" row appears at the top of the permissions table.
    // Granting it associates the user with the Demo organization and assigns
    // them the organization_admin role.
    // -----------------------------------------------------------------------
    it('grants control panel access and shows the org chip', function () {
        $page = visit('/users/testing1/permissions')
            ->assertPathIs('/users/testing1/permissions');

        $page->click('#open-permissions-control_panel');

        $page->assertSee('Update App');

        $page->click('#switch-0-1');

        $page->click('#modal-ok')
            ->assertSee('Organization Demo')
            ->click('#submit')
            ->assertSee('Confirm Permission Changes')
            ->click('#modal-confirm')
            ->assertPathIs('/users/testing1');
    });

    // -----------------------------------------------------------------------
    // 7. Control-panel admin access
    //
    // The "Control Panel Admin" row is only visible when the logged-in user
    // is themselves a control_panel_admin (Gate::allows('admin')).
    // Selecting "Allowed" grants the target user the control_panel_admin role.
    // -----------------------------------------------------------------------
    it('grants control panel admin access and shows the Allowed chip', function () {
        $page = visit('/users/testing1/permissions')
            ->assertPathIs('/users/testing1/permissions')
            ->assertSee('Control Panel');

        $page->click('#open-permissions-control_panel_admin');

        $page->click('#switch-0-control_panel_standard');

        $page->click('#modal-ok')
            ->assertSee('Control Panel Allowed')
            ->click('#submit')
            ->assertSee('Confirm Permission Changes')
            ->click('#modal-confirm')
            ->assertPathIs('/users/testing1');
    });

    // -----------------------------------------------------------------------
    // 8. Removing access clears the chip and shows "No Access"
    // -----------------------------------------------------------------------
    it('removes an existing role and shows No Access chip', function () {
        $page = visit('/users/testing1/permissions')
            ->assertPathIs('/users/testing1/permissions');

        // First assign a role
        $page->click('#open-permissions-2');
        $page->click('#switch-0-administrator');
        $page->click('#modal-ok');

        // Reopen the modal and toggle the switch off
        $page->click('#open-permissions-2');
        $page->click('#switch-0-administrator');  // toggles off → 'none'

        $page->click('#modal-ok');
        $page->assertSee('No Access')
            ->click('#submit');
        $page->assertSee('Confirm Permission Changes')
            ->click('#modal-confirm')
            ->assertPathIs('/users/testing1');
    });
});
