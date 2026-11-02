<?php

return [
    'title' => 'Tickets de soporte',
    'new_ticket' => 'Nuevo ticket',
    'preferences_saved' => 'Ajustes de notificaciones guardados.',
    'support_label' => 'soporte',
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
        'assignee_removed' => [
            'title' => ':reference necesita un responsable',
            'line' => 'La persona responsable de ":subject" ya no está disponible. Asigne a otra persona de su lado.',
        ],
    ],
];
