describe('Wordpress', () => {
    it('add permissions', () => {
        cy.on('uncaught:exception', err => !err.message.includes('ResizeObserver loop limit exceeded'))
        cy.on("uncaught:exception", (err, runnable) => {
            return (!err.message.includes('ResizeObserver loop limit exceeded') && !err.message.includes('ResizeObserver loop completed with undelivered notifications.'));
        });
        cy.login({ email: 'demo@example.com'});

        cy.visit('/users/demo/permissions');
        cy.get('#roles-2').click();
        cy.get('.va-select-option').contains('Editor').click();

        cy.get('#submit').click();
        cy.wait(5000);

        cy.contains('permissions updated!');
    });
});
