<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used during authentication for various
    | messages that we need to display to the user. You are free to modify
    | these language lines according to your application's requirements.
    |
    */

    'tech_error' => 'There were technical difficulties and your last action was unable to be completed. Our experts have been notified of the error and will fix it ASAP.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',
    'expired' => 'The page expired, please try again.',
    'failed' => 'Failed',
    'action' => [
        'waiting_for' => 'Waiting for other tasks to complete',
        'domain_wrong_ip' => 'Domain pointing to :domainip instead of :serverip',
        'need_subscription' => 'Need subscription',
        'parent_app' => ':app needs to be installed first',
        'time_range' => 'This will run between :start and :end',
    ],
    'exception' => [
        'database_failed' => "Database couldn't be created",
        'sso_failed' => "SSO couldn't be created",
        'account_manager_driver_fail' => "Account manager driver doesn't exist",
        'action_not_subclass' => ':action is not a subclass of Action',
        'no_configuration' => 'Could not obtain :app configuration :configuration',
        'no_backup_driver' => ':driver doesn\'t exist',
        'no_domain_driver' => ':name driver not found',
        'no_server_interface' => ':server interface doesn\'t exist',
        'no_connection' => 'Unable to connect to :server',
        'sso_not_ready' => 'SSO not ready: :reason',
        'http_error' => 'HTTP error',
        'curl_error' => 'CURL error',
        'test_connection_error' => 'Test connection error',
        'server_not_set' => 'No :server_type server is set for :app',
        'api_error' => 'Description: :description. Response: [:code] :message',
        'action_failed' => '',
        'no_billing_driver' => 'Billing driver doesn\'t exist',
    ],
    'sso' => [
        'denied' => [
            'groups_missing' => 'Some groups don\'t exist',
        ],
    ],
    'rule' => [
        'account_email_checks' => "Account emails aren't enabled. Must be a personal email",
        'account_not_exists' => 'This organization subdomain is unavailable. Please choose a new one',
        'app_exists' => 'App doesn\'t exist',
        'application_enabled' => 'The application you selected has been disabled',
        'confirm_old_password' => 'Current password does not match our records',
        'domain_available' => 'Your domain format is incorrect. (Example: example.com)',
        'domain_name' => 'Must be standard domain (example.com). If you believe your domain should work, click the question mark at the top',
        'domain_name_required' => 'Domain name required',
        'email_address_exists' => 'This email is already being used by another user',
        'group_name_not_used' => 'Group name already exists',
        'main_contact' => 'Phone number required for main contacts',
        'ldap_email_not_exists' => 'This email already exists. Please choose another :attribute',
        'new_user_account_email_check' => 'This must be a personal email account. An email will be sent to the user so they can login',
        'no_symbols' => 'Aliases cannot contain any symbols',
        'org_domain_name' => "This domain can't be used to create email accounts",
        'user_exists' => 'User already exists. Choose a new :attribute',
        'organization' => 'Organization doesn\'t exist',
        'org_suborganization' => 'This suborganization doesn\'t exist',
        'org_app_instance' => 'This app doesn\'t exist',
        'subdomain_exists' => 'Domain already exists. Choose a new :attribute',
        'org_subdomain_message' => 'Subdomain is already used',
    ],
    'email' => [
        'create_dkim_key' => 'We are creating your DKIM key as you read this. This will take a few moments',
    ],
    'notification' => [
        'subscription_cancelled' => 'Subscription Cancelled',
        'cancellation_notice' => "You're subscription has been cancelled. You're account will be deactivated on :date. If this was a mistake, you can resubscribe this anytime before this date.",
        'cancellation_notice_short' => "You're account will be deactivated on :date",
        'review_plan' => 'Review Plan',
        'account_deactivated' => 'Account Deactivated',
        'deactivation_notice' => "You're organization's account has been deactivated. You can still login to choose a new plan, but functionality is limited.",
        'app_deactivated' => ':app Deactivated',
        'app_deactivation_notice' => ':app has been deactivated and can no longer be used.',
        'welcome' => 'Welcome :name !',
        'welcome_notice' => 'Welcome to :controlpanelname ! You\'re life will get a lot easier with our great library of apps.',
        'action_done' => 'Your action has been complete',
        'app_activated' => ':app activated!',
        'app_activated_notice' => ':app Activated!',
        'thank_you' => 'Thank you for using our application!',
        'use_app' => 'Start using :app now',
        'app_upgraded' => ':app Upgraded!',
        'app_upgraded_notice' => ':app has been upgraded!',
        'read_announcement' => "Find out what's new!",
        'domain_transferred' => ':domain Transferred!',
        'domain_transferred_notice' => ':domain has been successfully transferred to :appname !',
        'manage_domains' => 'Manage Domains',
        'domain_name_registered' => ':domain has been successfully registered!',
        'timed_app_upgrade' => ':app will upgrade at :time',
        'app_upgrading' => ':app upgrading',
        'temporary_downtime' => 'You may experience temporary downtime for just a few minutes.',
        'account' => [
            'created' => ':name has created your account on :panel_name',
            'app_access' => 'You have been given access to these apps on the :panel_name platform: **:app_list**',
            'username' => 'Your username is: :username',
        ],
        'domain' => [
            'updated' => 'Domain name updated!',
            'changed' => 'Your domain has been successfully changed to :domain',
        ],
        'dummy' => [
            'completed' => 'Your action has been complete',
            'failed' => 'Your action failed',
        ],
        'organization' => [
            'subscribed' => ':organization just subscribed!',
            'cancelled' => ':organization cancelled!',
        ],
        'permissions' => [
            'updated' => 'Your Permissions Have Updated',
        ],
        'password' => [
            'reset_link' => 'You\'ve received a link to reset your password from :organization',
            'reset_action' => 'Reset your password!',
            'set' => 'Set your password',
        ],
        'subscription' => [
            'updated_title' => 'Subscription Updated!',
            'updated' => 'Your organization\'s subscription has been updated to: :plan',
            'update_failed' => 'You\'re subscription failed to update. We\'re looking into immediately.',
        ],
    ],
    'api' => [
        'nextcloud' => [
            'team_folders' => [
                'get' => 'Get team folder :name',
                'all' => 'Get all team folders',
                'add' => 'Add team folder',
                'enable_acl' => 'Enable team folder acl',
                'update_quota' => 'Set team folder quota',
                'update_name' => 'Update team folder name',
                'add_group' => 'Add Group to Team Folder',
                'add_manager' => 'Add manager to team folder',
                'remove_manager' => 'Remove manager from team folder',
                'delete' => 'Delete team folder',
            ],
            'users' => [
                'add_to_group' => 'Add user to :group group',
                'remove_from_group' => 'Remove user from :group group',
            ],
        ],
        'wordpress' => [
            'update_user_roles' => 'Update User Roles (:roles)',
        ],
        'rancher' => [
            'log' => [
                'app_created' => ':app created for :organization',
                'app_updated' => ':app updated for :organization',
                'app_retrieved' => ':app retrieved for :organization',
                'app_deleted' => ':app deleted for :organization',
                'ingress_created' => 'Ingress created for :organization',
                'ingress_updated' => 'Ingress updated for :organization',
                'ingress_deleted' => 'Ingress deleted for :organization',
                'job_created' => 'Job created for :organization',
                'job_updated' => 'Job updated for :organization',
                'job_deleted' => 'Job deleted for :organization',
                'namespace_created' => 'Namespace created for :organization',
                'namespace_updated' => 'Namespace updated for :organization',
                'namespace_deleted' => 'Namespace deleted for :organization',
                'middleware_created' => 'Middleware created for :organization',
                'middleware_updated' => 'Middleware updated for :organization',
                'middleware_deleted' => 'Middleware deleted for :organization',
                'persistent_volume_claim_created' => 'Persistent Volume Claim created for :organization',
                'persistent_volume_claim_updated' => 'Persistent Volume Claim updated for :organization',
                'persistent_volume_claim_deleted' => 'Persistent Volume Claim deleted for :organization',
            ],
            'error' => [
                'job' => 'Rancher Job - :job - :message',
            ],
        ],
        'authentik' => [
            'log' => [
                'policy_binding' => [
                    'listed' => 'Listed policy bindings for :app',
                    'created' => 'Created policy binding :policy_binding for :app',
                    'removed' => 'Removed policy binding :policy_binding for :app',
                ],
            ],
        ],
    ],
    'extensions' => [
        'nextcloud' => [
            'add_team_folder' => 'Add Team Folder to Nextcloud',
            'connection_error' => 'Connection to Nextcloud is broken. Please report this so we can resolve it ASAP',
            'max_reached' => "You've reached the max additional storage for your plan. If you would like to create a Nextcloud group, you'll have to upgrade your plan",
            'subscription_affected' => 'Changing this team folder storage may affect your subscription price',
            'team_folder_storage' => 'Nextcloud Team Folder Storage',
            'storage_quota_limited' => 'Nextcloud Storage Quota Limited!',
        ],
    ],
];
