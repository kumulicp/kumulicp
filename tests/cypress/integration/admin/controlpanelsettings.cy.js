describe('Control Panel Settings', () => {
    it('update', () => {
        cy.on('uncaught:exception', err => !err.message.includes('ResizeObserver loop limit exceeded'))
        cy.on("uncaught:exception", (err, runnable) => {
            return (!err.message.includes('ResizeObserver loop limit exceeded') && !err.message.includes('ResizeObserver loop completed with undelivered notifications.'));
        });
        cy.login({ email: 'demo@example.com'});
        cy.visit('/admin/server/settings');

        cy.get('#baseDomain').clear().type('example1.com');
        cy.get('#termsUrl').clear().type('This is a test cypress plan');
        cy.get('#docsUrl').clear().type('https://example.com');
        cy.get('#defaultStandardPrice').clear().type(10);
        cy.get('#invoiceVendorName').clear().type('example 1');
        cy.get('#invoiceVendorProduct').clear().type('example product');
        cy.get('#invoiceVendorStreet').clear().type('example st');
        cy.get('#invoiceVendorLocation').clear().type('example location');
        cy.get('#invoiceVendorPhoneNumber').clear().type('1234567890');
        cy.get('#invoiceVendorEmail').clear().type('examle@example.com');
        cy.get('#invoiceVendorUrl').clear().type('https://example.com');
        cy.get('#invoiceVendorVat').clear().type('vat');
        cy.get('#submit').click();
        cy.contains('Settings have been updated!');
    });
});
