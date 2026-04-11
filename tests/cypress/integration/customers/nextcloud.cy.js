describe('Nextcloud', () => {
    it('update app', () => {
        cy.on('uncaught:exception', err => !err.message.includes('ResizeObserver loop limit exceeded'))
        cy.on("uncaught:exception", (err, runnable) => {
            return (!err.message.includes('ResizeObserver loop limit exceeded') && !err.message.includes('ResizeObserver loop completed with undelivered notifications.'));
        });
        cy.login({ email: 'demo@example.com'});
        cy.visit('/apps');

        cy.get('a').contains('Nextcloud').click();
        cy.get('.va-checkbox').click({ multiple: true });
        cy.get('#submit').click()

        cy.wait(10000)
        cy.contains('Nextcloud has been updated!')
    });

    it('add permissions', () => {
        cy.on('uncaught:exception', err => !err.message.includes('ResizeObserver loop limit exceeded'))
        cy.on("uncaught:exception", (err, runnable) => {
            return (!err.message.includes('ResizeObserver loop limit exceeded') && !err.message.includes('ResizeObserver loop completed with undelivered notifications.'));
        });
        cy.login({ email: 'demo@example.com'});

        cy.visit('/users/demo/permissions');
        cy.get('#roles-1').click();
        cy.get('.va-select-option').contains('Standard').click();
        cy.get('#submit').click();
        cy.wait(5000);

        cy.contains('permissions updated!');
    });

    it('add team folder', () => {
        cy.on('uncaught:exception', err => !err.message.includes('ResizeObserver loop limit exceeded'))
        cy.on("uncaught:exception", (err, runnable) => {
            return (!err.message.includes('ResizeObserver loop limit exceeded') && !err.message.includes('ResizeObserver loop completed with undelivered notifications.'));
        });
        cy.login({ email: 'demo@example.com'});

        cy.visit('/groups');
        cy.get('#addGroup').click();
        cy.get('#name').type('Nextcloud');
        cy.get('#category').click();
        cy.get('.va-select-option').contains('Other').click();
        cy.get('#submit').click()

        cy.wait(2000)
        cy.visit('/groups/Nextcloud/edit');
        cy.get('#managers').click();
        cy.get('.va-select-option').contains('Demo User').click();
        cy.get('#managers').click();
        cy.get('#members').click();
        cy.get('.va-select-option').contains('Demo User').click();
        cy.get('#members').click();
        cy.get('.va-checkbox').click({multiple: true});
        cy.get('#nextcloud_additional_storage').click();
        cy.get('.va-select-option').contains('10 GB').click();
        cy.get('#submit').click()

        cy.wait(10000)
        cy.contains('Group Updated!')

        cy.visit('/groups');
        cy.get('#deleteNextcloud').click();
        cy.get('#delete').click();

        cy.wait(5000)
        cy.contains('Nextcloud deleted');
    });
});
