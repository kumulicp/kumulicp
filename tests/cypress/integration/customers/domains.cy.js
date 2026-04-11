describe('Domains', () => {
    it('add connection', () => {
        cy.on('uncaught:exception', err => !err.message.includes('ResizeObserver loop limit exceeded'))
        cy.on("uncaught:exception", (err, runnable) => {
            return (!err.message.includes('ResizeObserver loop limit exceeded') && !err.message.includes('ResizeObserver loop completed with undelivered notifications.'));
        });
        cy.login({ email: 'demo@example.com'});
        cy.visit('/settings/domains');

        cy.get('#addDomain').click();
        cy.get('#connect').click();
        cy.url().should('include', '/settings/domains/connect');
        cy.get('#name').type('example1.com');
        cy.get('#submit').click()

        cy.url().should('include', '/settings/domains');
        cy.contains('example.com')
    });

    // it('updates', () => {
    //     cy.on('uncaught:exception', err => !err.message.includes('ResizeObserver loop limit exceeded'))
    //     cy.on("uncaught:exception", (err, runnable) => {
    //         return (!err.message.includes('ResizeObserver loop limit exceeded') && !err.message.includes('ResizeObserver loop completed with undelivered notifications.'));
    //     });
    //     cy.login({ email: 'demo@example.com'});
    //     cy.visit('/users/newuser/edit');
    //
    //     cy.get('#firstName').clear().type('New1');
    //     cy.get('#lastName').clear().type('User1');
    //     cy.get('#personalEmail').clear().type('newuser1@example.com');
    //     cy.get('#phoneNumber').clear().type('1234567891');
    //     cy.get('#submit').click()
    //
    //     cy.url().should('include', '/users/newuser');
    //     cy.contains('New1 User1')
    // });

    it('deletes', () => {
        cy.on('uncaught:exception', err => !err.message.includes('ResizeObserver loop limit exceeded'))
        cy.on("uncaught:exception", (err, runnable) => {
            return (!err.message.includes('ResizeObserver loop limit exceeded') && !err.message.includes('ResizeObserver loop completed with undelivered notifications.'));
        });
        cy.login({ email: 'demo@example.com'});
        cy.visit('/settings/domains');
        cy.get('#actionsexample1com').click();
        cy.get('#removeDomain').click();
        cy.get('#remove').click();
        cy.artisan('schedule:run');
        cy.wait(500);
        cy.artisan('schedule:run');
        cy.reload();

        cy.contains('example1.com').should('not.exist');

    });
});
