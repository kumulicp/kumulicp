<?php

// What a single-organization install calls an app plan: a profile. These are laid over the
// normal strings (see App\Support\SingleOrganizationTerms); a key here must exist there.

return [
    'organization.readiness.plan_missing.message' => ':app has no profile. A profile sets the servers, features and settings it is installed with',
    'organization.readiness.plan_missing.action' => 'Add a profile',
    'organization.readiness.plan_missing.create_default' => 'Create a default profile',
    'organization.readiness.plan_unedited.message' => 'The profile ":plan" has never been changed. A profile almost always needs a change or two, such as its server or settings, to work on your server',
    'organization.readiness.plan_unedited.action' => 'Review the profile',
    'organization.getting_started.app_ready.hint' => 'An app needs an enabled version and a profile before it can be installed.',
    'admin.applications.plans.plans' => 'Profiles',
    'admin.applications.plans.added' => 'Profile added',
    'admin.applications.plans.updated' => ':plan profile updated',
    'admin.applications.plans.deleted' => ':plan profile deleted',
    'admin.applications.plans.order_updated' => 'Profile order updated',
    'admin.applications.plans.archived' => 'Profile archived',
    'admin.applications.plans.unarchived' => 'Profile unarchived',
    'admin.applications.plans.cloned' => ':plan profile created',
    'admin.applications.plans.already_has_plan' => 'A profile for this app already exists',
    'admin.applications.plans.first_plan_name' => 'First Profile',
    'admin.applications.plans.first_plan_description' => 'First profile to be changed',
    'admin.applications.plans.configurations_updated' => ':plan profile configurations updated',
    'admin.applications.plans.features_updated' => ':plan profile features updated',
    'admin.applications.plans.secrets_updated' => ':plan profile secrets updated',
];
