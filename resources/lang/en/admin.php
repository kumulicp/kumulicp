<?php

return [
    'servers' => [
        'authentik' => [
            'host' => 'Set the domain name here: authentik.example.com. This will override the default hostname for the app instance. (Requires manual app upgrade)',
            'address' => 'Address to connect to Authentik (eg. https://authentik.example.com)',
            'api_key' => 'Must login to Authentik: (1) go to Users, (2) create a service account (3) make an API Token (4) give correct permissions (5) make this spot "Bearer"',
            'api_secret' => 'Use the Secret key that comes with the API key created above',
            'ip' => 'IP address of the domain used in the address',
            'internal_address' => 'Internal address isn\'t used',
            'settings' => 'Use this template. **The signing_key, encryption_key, invalidation_flow, property_mappings, and authentication_flow require manual editing.**
        { "sub_mode": "hashed_user_id", "client_type": "confidential", "issuer_mode": "global", "signing_key": "d53b2317-4b85-417c-a690-f080cc97eab9", "encryption_key": null, "invalidation_flow": "9954b8c0-3ee2-40fb-888c-00994e39ace7", "property_mappings": [ "d3982c85-94ab-4350-96d9-bdae20f678d6", "7a620954-645e-47f5-b4e5-b01f1e4fe70f", "be0200ee-1a71-4f6d-9a87-99c3a362a1d5", "bc42db72-bf9a-45df-a296-0d2f6866b9ba" ], "authorization_flow": "922c7b62-a50d-4848-8dad-baa8b3791747", "authentication_flow": null, "access_code_validity": "minutes=1", "access_token_validity": "minutes=5", "jwt_federation_sources": [], "refresh_token_validity": "days=30", "jwt_federation_providers": [], "include_claims_in_id_token": false }',
            'general_1' => '0. If activating up Authentik through the control panel, first you must go here to set your first user: https://<example.com>/if/flow/initial-setup/',
            'general_2' => '1. Next, collect all the ID\'s for the various keys & flows: signing_key, encryption_key, invalidation_flow, property_mappings, and authentication_flow',
            'general_3' => '2. Add a serviceaccount for API access',
        ],
        'mailserver' => [
            'host' => 'Address that users will connect to from their email clients (eg. mail.example.com)',
            'address' => 'Address that users will connect to from their email clients (eg. mail.example.com)',
            'api_key' => 'Not needed',
            'api_secret' => 'Not need',
            'ip' => 'IP address of the email server',
            'internal_address' => 'Not needed',
            'settings' => '{"namespace":"","rancher_server":1}',
        ],
        'rancher' => [
            'host' => 'Address to connect to Rancher (eg. https://rancher.example.com)',
            'address' => 'Address to connect to Racher (eg. https://rancher.example.com)',
            'api_key' => 'Must login to Rancher, go to "Accounts and API Keys", then create a new API with No scope',
            'api_secret' => 'Use the Secret key that comes with the API key created above',
            'ip' => 'IP address of the domain used in the address',
            'internal_address' => 'This is used for apps that need to add a proxy server as a trusted IP',
            'settings' => 'Requires creating a new project for organizations to be stored. Add the settings: project_id',
        ],
        'app_database' => [
            'host' => 'IP or domain used by the Control Panel to connect to this database',
            'address' => 'Not used',
            'api_key' => 'The database username',
            'api_secret' => 'The database password',
            'ip' => 'Not used',
            'internal_address' => 'IP or domain used by apps to connect to this database (may be the same as the host)',
            'settings' => 'Not used',
        ],
        'erpnext' => [
            'recommendations' => [
                'other' => 'Other Instructions',
                'instructions' => 'Install ERPNext as a database. This will allow you to share one Frappe server with all the instances.',
            ],
        ],
    ],
    'denied' => 'You must be an administrator to continue',
    'applications' => [
        'plans' => [
            'added' => 'Plan added',
            'updated' => ':plan plan updated',
            'deleted' => ':plan plan deleted',
            'order_updated' => 'Plan order updated',
            'archived' => 'Plan archived',
            'unarchived' => 'Plan unarchived',
            'first_plan_name' => 'First Plan',
            'first_plan_description' => 'First plan to be changed',
            'configurations_updated' => ':plan plan configurations updated!',
            'features_updated' => ':plan plan features updated!',
            'denied' => [
                'delete' => "Plan can't be deleted as organizations are currently subscribed to it. Please consider archiving for now.",
            ],
        ],
    ],
];
