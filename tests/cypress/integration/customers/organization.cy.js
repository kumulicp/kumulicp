describe('Organization', () => {
    it('update', () => {
        cy.on('uncaught:exception', err => !err.message.includes('ResizeObserver loop limit exceeded'))
        cy.on("uncaught:exception", (err, runnable) => {
            return (!err.message.includes('ResizeObserver loop limit exceeded') && !err.message.includes('ResizeObserver loop completed with undelivered notifications.'));
        });
        cy.login({ email: 'demo@example.com'});
        cy.visit('/settings/organization');

        cy.get('#name').clear().type('Demo1');
        cy.get('#description').clear().type('Demo1');
        cy.get('#orgEmail').clear().type('demo1@example.com');
        cy.get('#orgPhoneNumber').clear().type('1234567891');
        cy.get('#street').clear().type('1234 Demo St');
        cy.get('#zipcode').clear().type('Z1Z 1Z1');
        cy.get('#city').clear().type('Demo Town');
        cy.get('#country').click();
        cy.get('#country').click();
        cy.get('#country').click();
        cy.get('.va-select-option').contains('Canada').click();
        cy.get('#province').click();
        cy.get('.va-select-option').contains('British Columbia').click();
        cy.get('#mainContact').click();
        cy.get('.va-select-option').contains('Demo User').click();
        cy.wait(2);
        cy.get('#submit').click()

        cy.contains('Organization Settings Updated')

    });
});
