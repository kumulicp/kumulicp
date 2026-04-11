describe('Registration', () => {
    it('register', () => {
        cy.on('uncaught:exception', err => !err.message.includes('ResizeObserver loop limit exceeded'))
        cy.on("uncaught:exception", (err, runnable) => {
            return (!err.message.includes('ResizeObserver loop limit exceeded') && !err.message.includes('ResizeObserver loop completed with undelivered notifications.'));
        });
        cy.visit('/');
        cy.get('#register').click();

        cy.url().should('include', '/register');
        cy.get('#username').type('demo2')
        cy.get('#contactEmail').type('demo2@example.com');
        cy.get('#password').type('Demouser1!&#');
        cy.get('#passwordConfirmation').type('Demouser1!&#');
        cy.get('#contactFirstName').type('Demo');
        cy.get('#contactLastName').type('User2');
        cy.get('#contactPhoneNumber').type('1234567890');
        cy.get('#name').type('Demo2');
        cy.get('#description').type('Demo2');
        cy.get('#email').type('demo2@example.com');
        cy.get('#phoneNumber').type('1234567890');
        cy.get('#type').click();
        cy.get('.va-select-option').contains('Business').click();
        cy.get('#street').type('Demo St');
        cy.get('#zipcode').type('123456');
        cy.get('#city').type('Demo City');
        cy.get('#country').click();
        cy.get('.va-select-option').contains('Canada').click();
        cy.wait(1000);
        cy.get('#state').click();
        cy.get('.va-select-option').contains('Alberta').click();
        cy.get('#subdomain').type('demo2');
        cy.get('#termsOfUse .va-checkbox').click();


        cy.get('#submit').click()

        cy.url().should('include', '/email/verify');
        cy.contains('You\'re email hasn\'t been verified yet');

    });
});
