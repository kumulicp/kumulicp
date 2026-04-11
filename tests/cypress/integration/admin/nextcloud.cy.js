describe('Nextcloud', () => {
    it('update', () => {
        cy.on('uncaught:exception', err => !err.message.includes('ResizeObserver loop limit exceeded'))
        cy.on("uncaught:exception", (err, runnable) => {
            return (!err.message.includes('ResizeObserver loop limit exceeded') && !err.message.includes('ResizeObserver loop completed with undelivered notifications.'));
        });
        cy.login({ email: 'demo@example.com'});
        cy.visit('/admin/apps/nextcloud/edit');

        cy.get('#name').clear().type('Nextcloud');
        cy.get('#category').clear().type('Nextcloud');
        cy.get('#permissionType').click();
        cy.get('.va-select-option').contains('Login').click();
        cy.get('#accessType').click();
        cy.get('.va-select-option').contains('Standard').click();
        cy.get('#submit').click();

        cy.contains('has been updated!');
    });
});

describe('Nextcloud Version', () => {
    it('add', () => {
        cy.on('uncaught:exception', err => !err.message.includes('ResizeObserver loop limit exceeded'))
        cy.on("uncaught:exception", (err, runnable) => {
            return (!err.message.includes('ResizeObserver loop limit exceeded') && !err.message.includes('ResizeObserver loop completed with undelivered notifications.'));
        });
        cy.login({ email: 'demo@example.com'});
        cy.visit('/admin/apps/nextcloud/versions');

        cy.get('#addVersion').click();
        cy.get('#name').type('1.0-cypress');
        cy.get('#submit').click();
        cy.url().should('include', '1.0-cypress');
    });

    it('update', () => {
        cy.on('uncaught:exception', err => !err.message.includes('ResizeObserver loop limit exceeded'))
        cy.on("uncaught:exception", (err, runnable) => {
            return (!err.message.includes('ResizeObserver loop limit exceeded') && !err.message.includes('ResizeObserver loop completed with undelivered notifications.'));
        });
        cy.login({ email: 'demo@example.com'});
        cy.visit('/admin/apps/nextcloud/versions/1.0-cypress');

        cy.get('#version').clear().type('1.1-cypress');
        cy.get('#chartVersion').type('1.0');
        cy.get('#helmRepoName').clear().type('cypress');
        cy.get('#imageRepoName').clear().type('cypress');
        cy.get('#announcementLocation').click();
        cy.get('.va-select-option').contains('Remote').click();
        cy.get('#announcementUrl').clear().type('http://exampmle.com');
        cy.get('#submit').click();
        cy.url().should('include', '1.1-cypress');
    });

    it('enable', () => {
        cy.on('uncaught:exception', err => !err.message.includes('ResizeObserver loop limit exceeded'))
        cy.on("uncaught:exception", (err, runnable) => {
            return (!err.message.includes('ResizeObserver loop limit exceeded') && !err.message.includes('ResizeObserver loop completed with undelivered notifications.'));
        });
        cy.login({ email: 'demo@example.com'});
        cy.visit('/admin/apps/nextcloud/versions/1.1-cypress');

        cy.get('#showEnableDisable').click()
        cy.get('#enableDisable').click();
        cy.get('#showEnableDisable').contains('Disable');
    });

    it('disable', () => {
        cy.on('uncaught:exception', err => !err.message.includes('ResizeObserver loop limit exceeded'))
        cy.on("uncaught:exception", (err, runnable) => {
            return (!err.message.includes('ResizeObserver loop limit exceeded') && !err.message.includes('ResizeObserver loop completed with undelivered notifications.'));
        });
        cy.login({ email: 'demo@example.com'});
        cy.visit('/admin/apps/nextcloud/versions/1.1-cypress');

        cy.get('#showEnableDisable').click()
        cy.get('#enableDisable').click();
        cy.get('#showEnableDisable').contains('Enable');
    });
});


describe('Nextcloud Plan', () => {
    it('add', () => {
        cy.on('uncaught:exception', err => !err.message.includes('ResizeObserver loop limit exceeded'))
        cy.on("uncaught:exception", (err, runnable) => {
            return (!err.message.includes('ResizeObserver loop limit exceeded') && !err.message.includes('ResizeObserver loop completed with undelivered notifications.'));
        });
        cy.login({ email: 'demo@example.com'});
        cy.visit('/admin/apps/nextcloud/plans');

        cy.get('#addPlan').click();
        cy.get('#name').type('Cypress Plan');
        cy.get('#description').type('This is a test cypress plan')
        cy.get('#submit').click();
        cy.wait(1000)
        cy.url().should('include', '/admin/apps/nextcloud/plans');

        cy.get('#name').clear().type('Cypress Plan 1');
        cy.get('#default .va-checkbox').click();
        cy.get('#description').clear().type('This is a test cypress plan');
        cy.get('#displayedFeatures').clear().type('[{"name":"Feature 1","description":"This is feature 1"},{"name":"Feature 2","description":"This is feature 2"}]', { parseSpecialCharSequences: false });
        cy.get('#paymentEnabled .va-checkbox').click();
        cy.get('#domainEnabled .va-checkbox').click();
        cy.get('#domainMax').clear().type(10);
        cy.get('#basePrice').clear().type(10);
        cy.get('#baseStripeId').clear().type('base_price_id');
        cy.get('#baseStorage').clear().type(10);
        cy.get('#standardPrice').clear().type(10);
        cy.get('#standardMax').clear().type(10);
        cy.get('#standardStripeId').clear().type('standard_price_id');
        cy.get('#standardStorage').clear().type(10);
        cy.get('#basicName').clear().type('Demo users');
        cy.get('#basicPrice').clear().type(10);
        cy.get('#basicMax').clear().type(10);
        cy.get('#basicStripeId').clear().type('basic_price_id');
        cy.get('#basicStorage').clear().type(10);
        cy.get('#basicAmount').clear().type(10);
        cy.get('#storagePrice').clear().type(10);
        cy.get('#storageMax').clear().type(10);
        cy.get('#storageStripeId').clear().type('storage_price_id');
        cy.get('#storageAmount').clear().type(10);
        cy.get('#submit').click();
        cy.contains('Plan: Cypress Plan 1 updated!');
    });
});
