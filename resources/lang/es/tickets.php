<?php

return [
    'title' => 'Tickets de soporte',
    'new_ticket' => 'Nuevo ticket',
    'preferences_saved' => 'Ajustes de notificaciones guardados.',
    'support_label' => 'soporte',
    'system_label' => 'Servicio de soporte',
    'merged_note' => 'Este ticket se fusionó con :target. La conversación continúa allí.',
    'sla' => [
        'clocks' => ['first_response' => 'primera respuesta', 'resolution' => 'resolución'],
    ],
    'rated' => 'Gracias por sus comentarios.',
    'merged_done' => 'Los tickets se fusionaron.',
    'redacted' => 'El mensaje fue eliminado.',
    'deleted_forever' => 'El ticket fue eliminado.',
    'deleted_user' => 'Usuario eliminado',

    'created' => 'Su ticket ha sido creado.',
    'reply_sent' => 'Su respuesta ha sido enviada.',
    'note_added' => 'Nota interna añadida.',
    'message_updated' => 'Su mensaje ha sido actualizado.',
    'updated' => 'Ticket actualizado.',
    'closed' => 'El ticket ha sido cerrado.',
    'reopened' => 'El ticket ha sido reabierto.',

    'status' => [
        'open' => 'Abierto',
        'pending_support' => 'Esperando a soporte',
        'pending_customer' => 'Esperando su respuesta',
        'on_hold' => 'En espera',
        'resolved' => 'Resuelto',
        'closed' => 'Cerrado',
    ],

    'priority' => [
        'low' => 'Baja',
        'normal' => 'Normal',
        'high' => 'Alta',
        'urgent' => 'Urgente',
    ],

    'type' => [
        'question' => 'Pregunta',
        'bug' => 'Problema',
        'feature' => 'Solicitud de función',
    ],

    'impact' => [
        'minor' => 'Menor: una molestia pequeña',
        'degraded' => 'Degradado: algo no funciona correctamente',
        'outage' => 'Interrupción: algo no funciona',
    ],

    'validation' => [
        'body_required' => 'Escriba un mensaje.',
        'body_too_long' => 'El mensaje es demasiado largo (máximo :max caracteres).',
    ],

    'integrations' => [
        'disabled_title' => 'La integración :name se desactivó',
        'disabled_line' => 'La integración :name falló demasiadas veces seguidas, por lo que se desactivó para no sobrecargar al otro lado.',
        'open' => 'Abrir integraciones',
            'title' => 'Integraciones',
        'created' => 'Integración creada.',
        'saved' => 'Integración guardada.',
        'deleted' => 'Integración eliminada.',
        'enabled' => 'Integración activada.',
        'disabled' => 'Integración desactivada.',
        'rotated' => 'Se generó un nuevo secreto de firma. El anterior seguirá funcionando 7 días.',
        'redelivering' => 'Reintentando.',
        'not_a_tracker' => 'Elija un gestor de incidencias.',
        'issue_requested' => 'Abriendo la incidencia. Aparecerá aquí en un momento.',
        'issue_exists' => 'Este ticket ya tiene una incidencia allí, o hay una en camino.',
        'deliveries' => 'Envíos',
],

    'time' => [
        'logged' => 'Tiempo registrado.',
        'deleted' => 'Entrada de tiempo eliminada.',
    ],

    'inbound' => [
        'comment' => ':author comentó en :issue de :integration: :text',
        'closed' => 'La incidencia vinculada :issue se cerró en :integration.',
        'reopened' => 'La incidencia vinculada :issue se reabrió en :integration.',
        'someone' => 'Alguien',
    ],

    'attachments' => [
        'upload_failed' => 'No se pudo subir el archivo: :reason',
        'not_received' => 'No se recibió ningún archivo. Puede ser mayor de lo que permite el servidor.',
        'not_saved' => 'no se pudo guardar',
        'empty' => 'Ese archivo está vacío.',
        'too_large' => 'Ese archivo es demasiado grande (máximo :max MB).',
        'too_many_waiting' => 'Tiene demasiados archivos pendientes de enviar. Envíe o elimine algunos primero.',
        'type_not_allowed' => 'Ese tipo de archivo no está permitido. Permitidos: :types.',
        'rejected' => 'Ese archivo fue rechazado por el análisis de seguridad.',
        'too_many' => 'Puede adjuntar como máximo :max archivos a un mensaje.',
        'unavailable' => 'Uno de los archivos adjuntos ya no está disponible. Vuelva a subirlo.',
        'message_too_large' => 'Los archivos adjuntos son demasiado grandes en conjunto (máximo :max MB por mensaje).',
    ],

    'errors' => [
        'internal_staff_only' => 'Solo el personal de soporte puede añadir notas internas.',
        'closed' => 'Este ticket está cerrado. Reábralo para responder.',
        'cannot_edit' => 'Esto ya no se puede editar.',
        'invalid_transition' => 'El ticket no se puede mover a ese estado.',
        'invalid_duration' => 'Introduzca una duración entre 1 minuto y 24 horas, como 45m, 1h30 o 1.5h.',
        'merged' => 'Este ticket se fusionó con otro.',
        'merge_not_found' => 'No hay ningún ticket con ese número.',
        'merge_self' => 'Un ticket no se puede fusionar consigo mismo.',
        'merge_organization' => 'Solo se pueden fusionar tickets de la misma organización.',
        'merge_target_closed' => 'Elija un ticket abierto con el que fusionar.',
        'cannot_rate' => 'Puede valorar un ticket una vez resuelto.',
        'invalid_score' => 'Elija una valoración de 1 a 5.',
        'invalid_watcher' => 'Esa persona no se puede añadir a este ticket.',
        'invalid_assignee' => 'Esa persona no puede ser asignada a este ticket.',
        'stale' => 'Otra persona modificó este ticket. Recargue la página e inténtelo de nuevo.',
    ],

    'admin' => [
        'title' => 'Tickets',
        'category_saved' => 'Categoría guardada.',
        'category_archived' => 'Categoría archivada.',
    ],

    'notifications' => [
        'view' => 'Ver ticket',
        'rate_prompt' => 'Abra el ticket para decirnos qué tal lo hicimos. Lleva unos segundos y nos ayuda a mejorar.',
        'reply_in_panel' => 'Responda en el panel de control. Las respuestas a este correo no se añaden al ticket.',
        'created' => [
            'title' => 'Nuevo ticket :reference',
            'line' => ':name abrió el ticket ":subject".',
        ],
        'reply' => [
            'title' => 'Nueva respuesta en :reference',
            'line' => ':name respondió al ticket ":subject".',
        ],
        'note' => [
            'title' => 'Nueva nota interna en :reference',
            'line' => ':name añadió una nota interna a ":subject".',
        ],
        'status' => [
            'title' => ':reference ahora está :status',
            'line' => 'El estado de ":subject" cambió a :status.',
        ],
        'assigned' => [
            'title' => ':reference le fue asignado',
            'line' => ':name le asignó el ticket ":subject".',
        ],
        'reminder' => [
            'title' => ':reference espera su respuesta',
            'line' => 'Estamos esperando noticias suyas sobre ":subject". Responda cuando pueda o avísenos si ya no es necesario.',
        ],
        'merged' => [
            'title' => ':reference se fusionó con :target',
            'line' => 'El ticket ":subject" era un duplicado y se ha fusionado con :target. La conversación continúa allí.',
        ],
        'sla_warning' => [
            'title' => ':reference está cerca de su objetivo de :clock',
            'line' => 'El objetivo de :clock de ":subject" se agotará pronto.',
        ],
        'sla_breached' => [
            'title' => ':reference incumplió su objetivo de :clock',
            'line' => 'Se agotó el objetivo de :clock de ":subject".',
        ],
        'rating' => [
            'title' => ':reference fue valorado con :score de 5',
            'line' => ':name no quedó satisfecho con cómo se gestionó ":subject".',
        ],
        'assignee_removed' => [
            'title' => ':reference necesita un responsable',
            'line' => 'La persona responsable de ":subject" ya no está disponible. Asigne a otra persona de su lado.',
        ],
    ],
];
