// describe('Billing Manager', () => {
//     it('add', () => {
//         cy.on('uncaught:exception', err => !err.message.includes('ResizeObserver loop limit exceeded'))
//         cy.on("uncaught:exception", (err, runnable) => {
//             return (!err.message.includes('ResizeObserver loop limit exceeded') && !err.message.includes('ResizeObserver loop completed with undelivered notifications.'));
//         });
//         cy.login({ email: 'demo@example.com'});
//         cy.visit('/subscription/payment');
//
//         cy.get('#addBillingManager').click();
//         cy.get('#billingManager').click();
//         cy.get('.va-select-option').contains('Demo1 User1').click();
//         cy.get('#add').click()
//
//         cy.contains('Demo1 User1')
//
//     });
//     it('remove', () => {
//         cy.on('uncaught:exception', err => !err.message.includes('ResizeObserver loop limit exceeded'))
//         cy.on("uncaught:exception", (err, runnable) => {
//             return (!err.message.includes('ResizeObserver loop limit exceeded') && !err.message.includes('ResizeObserver loop completed with undelivered notifications.'));
//         });
//         cy.login({ email: 'demo@example.com'});
//         cy.visit('/subscription/payment');
//
//         cy.get('#removedemo').click();
//         cy.get('#remove').click()
//
//         cy.get('#removedemo').should('not.exist')
//     });
// });

describe('Change base plan', () => {
    it('change', () => {
        cy.on('uncaught:exception', err => !err.message.includes('ResizeObserver loop limit exceeded'))
        cy.on("uncaught:exception", (err, runnable) => {
            return (!err.message.includes('ResizeObserver loop limit exceeded') && !err.message.includes('ResizeObserver loop completed with undelivered notifications.'));
        });
        cy.login({ email: 'demo@example.com'});
        cy.visit('/subscription/2/overview');

        cy.get('#basePlan').click();
        cy.get('#select4').click();
cy.wait(10000)
        cy.get('#card-element iframe').then((iframe) => {
            const doc = iframe.contents();
            doc.get('input[name="cardnumber"]').type(6011000990139424);
        })
        /*cy.get('input[name=exp-date]').type('1230');
        cy.get('input[name=cvc]').type('1234');*/
        // cy.get('#updateCreditCard').click();

        // cy.contains('Demo1 User1')

    });/*
    it('remove', () => {
        cy.on('uncaught:exception', err => !err.message.includes('ResizeObserver loop limit exceeded'))
        cy.on("uncaught:exception", (err, runnable) => {
            return (!err.message.includes('ResizeObserver loop limit exceeded') && !err.message.includes('ResizeObserver loop completed with undelivered notifications.'));
        });
        cy.login({ email: 'demo@example.com'});
        cy.visit('/subscription/payment');

        cy.get('#removedemo').click();
        cy.get('#remove').click()

        cy.get('#removedemo').should('not.exist')
    });*/
});
