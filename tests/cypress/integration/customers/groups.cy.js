describe('Groups', () => {
    it('create', () => {
        cy.on('uncaught:exception', err => !err.message.includes('ResizeObserver loop limit exceeded'))
        cy.on("uncaught:exception", (err, runnable) => {
            return (!err.message.includes('ResizeObserver loop limit exceeded') && !err.message.includes('ResizeObserver loop completed with undelivered notifications.'));
        });
        cy.login({ email: 'demo@example.com'});
        cy.visit('/groups');

        cy.get('#addGroup').click();
        cy.get('#name').type('NewGroup');
        cy.get('#category').click();
        cy.get('.va-select-option').contains('Other').click();
        cy.get('#submit').click()

        cy.url().should('include', '/groups/NewGroup');
        cy.contains('NewGroup')
    });

    it('update', () => {
        cy.on('uncaught:exception', err => !err.message.includes('ResizeObserver loop limit exceeded'))
        cy.on("uncaught:exception", (err, runnable) => {
            return (!err.message.includes('ResizeObserver loop limit exceeded') && !err.message.includes('ResizeObserver loop completed with undelivered notifications.'));
        });
        cy.login({ email: 'demo@example.com'});
        cy.visit('/groups/NewGroup/edit');

        cy.get('#name').clear().type('NewGroup1');
        cy.get('#category').click();
        cy.get('.va-select-option').contains('Ministry').click();
        cy.get('#managers').click();
        cy.get('.va-select-option').contains('Demo User').click();
        cy.get('#managers').click();
        cy.get('#members').click();
        cy.get('.va-select-option').contains('Demo User').click();
        cy.get('#members').click();
        cy.get('#submit').click()

        cy.contains('Group Updated!')
    });

    it('deletes', () => {
        cy.on('uncaught:exception', err => !err.message.includes('ResizeObserver loop limit exceeded'))
        cy.on("uncaught:exception", (err, runnable) => {
            return (!err.message.includes('ResizeObserver loop limit exceeded') && !err.message.includes('ResizeObserver loop completed with undelivered notifications.'));
        });
        cy.login({ email: 'demo@example.com'});
        cy.visit('/groups');
        cy.get('#deleteNewGroup1').click();
        cy.get('#delete').click();

        cy.contains('NewGroup1 deleted');

    });
});
