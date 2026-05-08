<?php

use App\Organization;
use App\User;
use Spatie\Permission\Models\Role;

// ---------------------------------------------------------------------------
// Helper: log in as the demo admin and return the browser chain positioned
// on the given path.
// ---------------------------------------------------------------------------
function loginAndVisit(string $path): mixed
{
    return visit('/login')
        ->fill('input[type=email]', 'demo@example.com')
        ->fill('input[type=password]', 'demouser')
        ->click('#submit')
        ->visit($path);
}

// ---------------------------------------------------------------------------
// Helper: open the permissions modal for the row whose app name contains
// $appName by using JavaScript so we don't depend on link ordering.
// ---------------------------------------------------------------------------
function openPermissionsModal(string $appName): string
{
    return "
        (function () {
            const rows = document.querySelectorAll('table tbody tr');
            for (const row of rows) {
                if (row.textContent.includes('{$appName}')) {
                    const link = row.querySelector('a[href=\"#\"]');
                    if (link) { link.click(); return; }
                }
            }
        })();
    ";
}

// ---------------------------------------------------------------------------
// Shared setup: each test gets a clean database (RefreshDatabase + DemoSeeder
// already applied by global beforeEach in Pest.php), plus the three Spatie
// roles, a demo user promoted to admin, and a testing1 user to edit.
// ---------------------------------------------------------------------------
describe('User Permissions Update', function () {
    beforeEach(function () {
        Role::create(['name' => 'control_panel_admin']);
        Role::create(['name' => 'organization_admin']);
        Role::create(['name' => 'billing_manager']);

        $demoUser = User::find(1);
        $demoUser->assignRole('control_panel_admin');
        $demoUser->assignRole('organization_admin');
        $demoUser->is_allowed = true;
        $demoUser->save();

        User::factory()->create([
            'username'        => 'testing1',
            'organization_id' => 1,
            'is_allowed'      => false,
            'email'           => 'testing1@example.com',
            'name'            => 'Test User One',
            'first_name'      => 'Test',
            'last_name'       => 'User One',
        ]);
    });

    // -----------------------------------------------------------------------
    // 1. Page loads
    // -----------------------------------------------------------------------
    it('loads the permissions page with the user name and app rows', function () {
        loginAndVisit('/users/testing1/permissions')
            ->assertSee('Test User One')
            ->assertSee('Wordpress')
            ->assertSee('Update Permissions');
    });

    // -----------------------------------------------------------------------
    // 2. App permissions modal – open and verify content
    // -----------------------------------------------------------------------
    it('opens the app permissions modal for the Wordpress row', function () {
        loginAndVisit('/users/testing1/permissions')
            ->script(openPermissionsModal('Wordpress'))
            ->assertSee('Update App')
            ->assertSee('Access Type for Wordpress');
    });

    // -----------------------------------------------------------------------
    // 3. App permissions – assigning a standard role
    //
    // Flow:
    //   a) Open modal → access-type select appears (Wordpress has multiple
    //      standard AND basic roles so full=true).
    //   b) Choose "Active User" (standard) access type – role categories
    //      are then rendered by updateRoleOptions().
    //   c) Choose "Admin" (Administrator) from the Content category.
    //   d) Click OK in the modal.
    //   e) Verify the "Content Admin" chip appears in the table row.
    //   f) Submit the form.
    //   g) The "Confirm Permission Changes" modal appears showing that
    //      access changed to Active User for Wordpress.
    //   h) Accept → redirect to the user show page.
    // -----------------------------------------------------------------------
    it('assigns a standard role, shows the access-type chip, and presents the confirm modal', function () {
        loginAndVisit('/users/testing1/permissions')
            // Open Wordpress modal
            ->script(openPermissionsModal('Wordpress'))
            ->assertSee('Update App')

            // Step (b): select 'Active User' from the access-type filter select.
            // The va-select id="roles-{appId}" (Wordpress id = 2 from seeder).
            // Clicking the wrapper opens the Vuestic dropdown portal.
            ->click('#roles-2')
            ->click('Active User')

            // Step (c): the Content category roles are now visible.
            // The second va-select with id="roles-2" is the category role select.
            ->script("document.querySelectorAll('[id=\"roles-2\"]')[1].click()")
            ->click('Admin')

            // Step (d): confirm in the modal
            ->click('#submit')

            // Step (e): chip is visible in the table
            ->assertSee('Content Admin')

            // Step (f–g): submit triggers access-type check; change modal appears
            ->click('#submit')
            ->assertSee('Confirm Permission Changes')
            ->assertSee('Access changed')
            ->assertSee('Active User')

            // Step (h): accept and verify redirect
            ->click('Yes, Update Permissions')
            ->assertPathIs('/users/testing1');
    });

    // -----------------------------------------------------------------------
    // 4. App permissions – assigning a basic role shows "Access changed"
    //    for a lower access tier
    // -----------------------------------------------------------------------
    it('assigns a basic role and the confirm modal lists the correct access change', function () {
        loginAndVisit('/users/testing1/permissions')
            ->script(openPermissionsModal('Wordpress'))
            ->click('#roles-2')
            ->click('Active User')         // unlock all roles first

            // Select Author (basic)
            ->script("document.querySelectorAll('[id=\"roles-2\"]')[1].click()")
            ->click('Author')
            ->click('#submit')

            ->assertSee('Content Author')  // chip in table

            ->click('#submit')
            ->assertSee('Confirm Permission Changes')
            ->assertSee('Access changed')
            ->click('Yes, Update Permissions')
            ->assertPathIs('/users/testing1');
    });

    // -----------------------------------------------------------------------
    // 5. App permissions – changing access type via the filter select
    //    When the user switches from "Active User" to a lower tier the
    //    previously selected role is cleared and an error message appears.
    // -----------------------------------------------------------------------
    it('displays an error chip when the selected role is unavailable after downgrading the access type', function () {
        loginAndVisit('/users/testing1/permissions')
            ->script(openPermissionsModal('Wordpress'))

            // Start at Standard, pick Administrator
            ->click('#roles-2')
            ->click('Active User')
            ->script("document.querySelectorAll('[id=\"roles-2\"]')[1].click()")
            ->click('Admin')

            // Now downgrade to Basic – Administrator (standard) is no longer
            // available, so the role field is cleared and an error is shown.
            ->click('#roles-2')
            ->click('Basic')        // select the second access_type option if available,
                                    // or 'Disabled' if the plan only has standard/none
            ->assertSee('Error')    // accessDowngradedError or accessChangedError

            ->click('#submit');     // close modal
    });

    // -----------------------------------------------------------------------
    // 6. Organization / control-panel access
    //
    // The "Control Panel" row appears at the top of the permissions table.
    // Granting it associates the user with the Demo organization and assigns
    // them the organization_admin role.
    // -----------------------------------------------------------------------
    it('grants control panel access and shows the org chip', function () {
        $org = Organization::find(1);

        loginAndVisit('/users/testing1/permissions')
            ->script(openPermissionsModal('Control Panel'))
            ->assertSee('Update App')

            // The Organization category lists the Demo org as a role option.
            // Clicking it and clicking the role with the org name grants access.
            ->script("document.querySelectorAll('[id=\"roles-control_panel\"]')[0].click()")
            ->click('Demo')         // org name from DemoSeeder

            ->click('#submit')      // close modal

            // The chip for the Control Panel row now shows the org name
            ->assertSee('Organization Demo')

            ->click('#submit')
            ->assertSee('Confirm Permission Changes')
            ->click('Yes, Update Permissions')
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
        loginAndVisit('/users/testing1/permissions')
            ->assertSee('Control Panel')   // both CP and CP Admin rows shown

            ->script(openPermissionsModal('Control Panel Admin'))

            // The category has two options: "No access" and "Allowed".
            ->script("document.querySelectorAll('[id=\"roles-control_panel_admin\"]')[0].click()")
            ->click('Allowed')

            ->click('#submit')   // close modal
            ->assertSee('Control Panel Allowed')

            ->click('#submit')
            ->assertSee('Confirm Permission Changes')
            ->click('Yes, Update Permissions')
            ->assertPathIs('/users/testing1');
    });

    // -----------------------------------------------------------------------
    // 8. Removing access clears the chip and shows "No Access"
    // -----------------------------------------------------------------------
    it('removes an existing role and shows No Access chip', function () {
        loginAndVisit('/users/testing1/permissions')
            ->script(openPermissionsModal('Wordpress'))
            ->click('#roles-2')
            ->click('Active User')
            ->script("document.querySelectorAll('[id=\"roles-2\"]')[1].click()")
            ->click('Admin')
            ->click('#submit')

            // Now open the modal again and set the role back to none
            ->script(openPermissionsModal('Wordpress'))
            ->script("document.querySelectorAll('[id=\"roles-2\"]')[1].click()")
            ->click('None')
            ->click('#submit')

            ->assertSee('No Access')

            ->click('#submit')
            ->assertSee('Confirm Permission Changes')
            ->click('Yes, Update Permissions')
            ->assertPathIs('/users/testing1');
    });
});
