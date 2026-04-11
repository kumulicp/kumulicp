describe('Profile', () => {
    it('updates', () => {
        cy.on('uncaught:exception', err => !err.message.includes('ResizeObserver loop limit exceeded'))
        cy.on("uncaught:exception", (err, runnable) => {
            return (!err.message.includes('ResizeObserver loop limit exceeded') && !err.message.includes('ResizeObserver loop completed with undelivered notifications.'));
        });
        cy.login({ email: 'demo@example.com'});
        cy.visit('/profile');

        cy.get('#firstName').clear().type('Demo1');
        cy.get('#lastName').clear().type('User1');
        cy.get('#personalEmail').clear().type('demo@example.com');
        cy.get('#phoneNumber').clear().type('1234567891');
        cy.get('#submit').click()

        cy.contains('Profile was updated!')

    });
    it('failed updates password', () => {
        cy.on('uncaught:exception', err => !err.message.includes('ResizeObserver loop limit exceeded'))
        cy.on("uncaught:exception", (err, runnable) => {
            return (!err.message.includes('ResizeObserver loop limit exceeded') && !err.message.includes('ResizeObserver loop completed with undelivered notifications.'));
        });
        cy.login({ email: 'demo@example.com'});
        cy.visit('/profile');

        cy.get('#changePassword').click();
        cy.get('#currentPassword').type('demouser');
        cy.get('#password').type('demouser1');
        cy.get('#passwordConfirmation').type('demouser2');
        cy.get('#updatePassword').click()

        cy.contains('The password confirmation does not match.')

    });
    it('updates password', () => {
        cy.on('uncaught:exception', err => !err.message.includes('ResizeObserver loop limit exceeded'))
        cy.on("uncaught:exception", (err, runnable) => {
            return (!err.message.includes('ResizeObserver loop limit exceeded') && !err.message.includes('ResizeObserver loop completed with undelivered notifications.'));
        });
        cy.login({ email: 'demo@example.com'});
        cy.visit('/profile');

        cy.get('#changePassword').click();
        cy.get('#currentPassword').type('demouser');
        cy.get('#password').type('demouser1');
        cy.get('#passwordConfirmation').type('demouser1');
        cy.get('#updatePassword').click()

        cy.contains('Password updated!')

    });
    it('reset updates password', () => {
        cy.on('uncaught:exception', err => !err.message.includes('ResizeObserver loop limit exceeded'))
        cy.on("uncaught:exception", (err, runnable) => {
            return (!err.message.includes('ResizeObserver loop limit exceeded') && !err.message.includes('ResizeObserver loop completed with undelivered notifications.'));
        });
        cy.login({ email: 'demo@example.com'});
        cy.visit('/profile');

        cy.get('#changePassword').click();
        cy.get('#currentPassword').type('demouser1');
        cy.get('#password').type('demouser');
        cy.get('#passwordConfirmation').type('demouser');
        cy.get('#updatePassword').click()

        cy.contains('Password updated!')

    });
});
