<?php

return [
    'extension' => 'Configuración de PHP: Extensión cargada :extension',
    'function' => 'Configuración de PHP: Función habilitada: :function',
    'stripe_connection' => 'Conexión con Stripe',
    'plan' => [
        'name' => 'El Plan Definitivo',
        'description' => '¡Aplicaciones, almacenamiento y tráfico ilimitados de forma gratuita!',
    ],
    'example_plans' => [
        'basic_name' => 'Voluntario',
        'starter' => [
            'name' => 'Inicial',
            'description' => 'Nivel de ejemplo para equipos pequeños que comienzan. Edítalo o elimínalo.',
        ],
        'standard' => [
            'name' => 'Estándar',
            'description' => 'Nivel de ejemplo para organizaciones en crecimiento. Edítalo o elimínalo.',
        ],
        'plus' => [
            'name' => 'Plus',
            'description' => 'Nivel de ejemplo para organizaciones grandes. Edítalo o elimínalo.',
        ],
        'features' => [
            'users' => 'Usuarios',
            'users_description' => 'Hasta :count usuarios',
            'apps' => 'Aplicaciones',
            'apps_description' => 'Hasta :count aplicaciones',
            'storage' => 'Almacenamiento',
            'storage_description' => ':count GB por usuario',
        ],
    ],
    'complete' =>'El panel de control se ha configurado correctamente',
    'first_org' => 'Cuenta de súper administrador',
];
