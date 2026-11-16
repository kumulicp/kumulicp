<?php

// What a single-organization install calls an app plan: a profile. These are laid over the
// normal strings (see App\Support\SingleOrganizationTerms); a key here must exist there.

return [
    'organization.readiness.plan_missing.message' => ':app no tiene ningún perfil. Un perfil define los servidores, las funciones y los ajustes con los que se instala',
    'organization.readiness.plan_missing.action' => 'Añadir un perfil',
    'organization.readiness.plan_missing.create_default' => 'Crear un perfil predeterminado',
    'organization.readiness.plan_unedited.message' => 'El perfil ":plan" nunca se ha modificado. Casi siempre hace falta un cambio o dos, como su servidor o sus ajustes, para que funcione en tu servidor',
    'organization.readiness.plan_unedited.action' => 'Revisar el perfil',
    'organization.getting_started.app_ready.hint' => 'Una aplicación necesita una versión habilitada y un perfil antes de poder instalarse.',
    'admin.applications.plans.plans' => 'Perfiles',
    'admin.applications.plans.added' => 'Perfil agregado',
    'admin.applications.plans.updated' => 'Perfil :plan actualizado',
    'admin.applications.plans.deleted' => 'Perfil :plan eliminado',
    'admin.applications.plans.order_updated' => 'Orden de perfiles actualizado',
    'admin.applications.plans.archived' => 'Perfil archivado',
    'admin.applications.plans.unarchived' => 'Perfil desarchivado',
    'admin.applications.plans.cloned' => 'Perfil :plan creado',
    'admin.applications.plans.already_has_plan' => 'Esta aplicación ya tiene un perfil',
    'admin.applications.plans.first_plan_name' => 'Primer perfil',
    'admin.applications.plans.first_plan_description' => 'Primer perfil a modificar',
    'admin.applications.plans.configurations_updated' => 'Configuraciones del perfil :plan actualizadas',
    'admin.applications.plans.features_updated' => 'Funciones del perfil :plan actualizadas',
    'admin.applications.plans.secrets_updated' => 'Secretos del perfil :plan actualizados',
];
