describe('Rancher', () => {
    it('add', () => {
        cy.on('uncaught:exception', err => !err.message.includes('ResizeObserver loop limit exceeded'))
        cy.on("uncaught:exception", (err, runnable) => {
            return (!err.message.includes('ResizeObserver loop limit exceeded') && !err.message.includes('ResizeObserver loop completed with undelivered notifications.'));
        });
        cy.login({ email: 'demo@example.com'});
        cy.visit('/admin/server/servers');

        cy.get('#addServer').click();
        cy.get('#name').type('Rancher1');
        cy.get('#type').click();
        cy.get('.va-select-option').contains('Web').click();
        cy.get('#interface').click();
        cy.get('.va-select-option').contains('rancher').click();
        cy.get('#submit').click();

        cy.url().should('include', '/admin/server/servers');

        cy.get('#host').clear().type('https://localhost');
        cy.get('#address').clear().type('https://localhost');
        cy.get('#apiKey').clear().type('api_key');
        cy.get('#apiSecret').clear().type('api_secret');
        cy.get('#ip').clear().type('0.0.0.0');
        cy.get('#internalAddress').clear().type('0.0.0.0');
        cy.get('#settings').clear().type('{"project_id":"project_id","traefik_middleware":false}', { parseSpecialCharSequences: false })
        cy.get('#submit').click();
        cy.contains('Server has been updated!');
    });

    // Can't delete currently
    // it('delete', () => {
    //     cy.on('uncaught:exception', err => !err.message.includes('ResizeObserver loop limit exceeded'))
    //     cy.on("uncaught:exception", (err, runnable) => {
    //         return (!err.message.includes('ResizeObserver loop limit exceeded') && !err.message.includes('ResizeObserver loop completed with undelivered notifications.'));
    //     });
    //     cy.login({ email: 'demo@example.com'});
    //     cy.visit('/admin/server/servers');
    //     cy.get('.delete-server').last().click();
    //     cy.get('#delete').click();
    //
    //     cy.contains('Server deleted!');
    //
    // });
});
