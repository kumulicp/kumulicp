describe('User', () => {
    it('create', () => {
        cy.on('uncaught:exception', err => !err.message.includes('ResizeObserver loop limit exceeded'))
        cy.on("uncaught:exception", (err, runnable) => {
            return (!err.message.includes('ResizeObserver loop limit exceeded') && !err.message.includes('ResizeObserver loop completed with undelivered notifications.'));
        });
        cy.login({ email: 'demo@example.com'});
        cy.visit('/users');

        cy.get('#createUser').click();
        cy.get('#username').type('newuser');
        cy.get('#firstName').type('New');
        cy.get('#lastName').type('User');
        cy.get('#personalEmail').type('newuser@example.com');
        cy.get('#phoneNumber').type('1234567890');
        cy.get('#submit').click()

        cy.url().should('include', '/users/newuser');
        cy.contains('New User')

    });

    it('updates', () => {
        cy.on('uncaught:exception', err => !err.message.includes('ResizeObserver loop limit exceeded'))
        cy.on("uncaught:exception", (err, runnable) => {
            return (!err.message.includes('ResizeObserver loop limit exceeded') && !err.message.includes('ResizeObserver loop completed with undelivered notifications.'));
        });
        cy.login({ email: 'demo@example.com'});
        cy.visit('/users/newuser/edit');

        cy.get('#firstName').clear().type('New1');
        cy.get('#lastName').clear().type('User1');
        cy.get('#personalEmail').clear().type('newuser1@example.com');
        cy.get('#phoneNumber').clear().type('1234567891');
        cy.get('#submit').click()

        cy.url().should('include', '/users/newuser');
        cy.contains('New1 User1')

    });

    it('change permissions', () => {
        cy.on('uncaught:exception', err => !err.message.includes('ResizeObserver loop limit exceeded'))
        cy.on("uncaught:exception", (err, runnable) => {
            return (!err.message.includes('ResizeObserver loop limit exceeded') && !err.message.includes('ResizeObserver loop completed with undelivered notifications.'));
        });
        cy.login({ email: 'demo@example.com'});
        cy.visit('/users/newuser/permissions');

        cy.get('#login-control_panel .va-switch').click();
        cy.get('#login-control_panel_admin .va-switch').click();
        cy.get('#submit').click();
        cy.wait(5000)

        cy.contains('permissions updated!');

    });

    it('deletes', () => {
        cy.on('uncaught:exception', err => !err.message.includes('ResizeObserver loop limit exceeded'))
        cy.on("uncaught:exception", (err, runnable) => {
            return (!err.message.includes('ResizeObserver loop limit exceeded') && !err.message.includes('ResizeObserver loop completed with undelivered notifications.'));
        });
        cy.login({ email: 'demo@example.com'});
        cy.visit('/users');
        cy.get('#deletenewuser').click();
        cy.get('#delete').click();

        cy.contains('New User').should('not.exist');

    });
});
