describe('Announcements', () => {
    it('add', () => {
        cy.on('uncaught:exception', err => !err.message.includes('ResizeObserver loop limit exceeded'))
        cy.on("uncaught:exception", (err, runnable) => {
            return (!err.message.includes('ResizeObserver loop limit exceeded') && !err.message.includes('ResizeObserver loop completed with undelivered notifications.'));
        });
        cy.login({ email: 'demo@example.com'});
        cy.visit('/admin/service/announcements');

        cy.get('#addAnnouncement').click();
        cy.get('#title').type('New Announcement');
        cy.get('#submit').click();

        cy.get('#apps').click();
        cy.get('.va-select-option').contains('Nextcloud').click();
        cy.get('.va-select-option').contains('Wordpress').click();
        cy.get('#apps').click();
        cy.get('#shortDescription').clear().type("Lorem Ipsum is simply dummy text of the printing and typesetting industry. Lorem Ipsum has been the industry's standard dummy text ever since the 1500s, when an unknown printer took a galley of type and scrambled it to make a type specimen book. It has su");
        cy.get('#submit').click();


    });
/*
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

    });*/
});

