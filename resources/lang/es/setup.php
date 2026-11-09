<?php

return [
    'extension' => 'Configuración de PHP: Extensión cargada :extension',
    'function' => 'Configuración de PHP: Función habilitada: :function',
    'stripe_connection' => 'Conexión con Stripe',
    'plan' => [
        'name' => 'El Plan Definitivo',
        'description' => '¡Aplicaciones, almacenamiento y tráfico ilimitados de forma gratuita!',
    ],
    'complete' => 'El panel de control se ha configurado correctamente',
    'first_org' => 'Cuenta de súper administrador',
    'tenancy' => [
        'modes' => [
            'single' => [
                'label' => 'Una sola organización',
                'description' => 'Una organización gestiona sus propias aplicaciones. No hay planes, suscripciones, facturación ni registro público.',
            ],
            'multi' => [
                'label' => 'Multiinquilino',
                'description' => 'Un proveedor de alojamiento atiende a muchas organizaciones, cada una suscrita a un plan.',
            ],
        ],
    ],
];
