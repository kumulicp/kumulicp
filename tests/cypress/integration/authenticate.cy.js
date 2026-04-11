describe('Login', () => {
    it('login', () => {
        cy.on('uncaught:exception', err => !err.message.includes('ResizeObserver loop limit exceeded'))
        cy.on("uncaught:exception", (err, runnable) => {
            return (!err.message.includes('ResizeObserver loop limit exceeded') && !err.message.includes('ResizeObserver loop completed with undelivered notifications.'));
        });
        cy.visit('/');

        cy.url().should('include', '/login');
        cy.get('#email').type('demo@example.com');
        cy.get('#password').type('demouser');
        cy.get('#submit').click()

        cy.url().should('include', '/');
        cy.contains('Welcome to the Control Panel.')

    });
});
