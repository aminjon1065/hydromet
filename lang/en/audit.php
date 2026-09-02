<?php

return [
    'navigation_group' => 'Security',
    'event' => 'Audit event',
    'events' => 'Audit log',
    'sections' => ['event' => 'Event', 'changes' => 'Recorded changes'],
    'fields' => [
        'occurred_at' => 'Occurred at',
        'actor' => 'Actor',
        'action' => 'Action',
        'subject_type' => 'Subject type',
        'subject_id' => 'Subject ID',
        'subject' => 'Subject',
        'changed_fields' => 'Changed fields',
        'before' => 'Before',
        'after' => 'After',
    ],
    'actions' => [
        'audit_exported' => 'Audit log exported',
        'content_created' => 'Content created',
        'content_updated' => 'Content updated',
    ],
    'subject_types' => ['audit_log' => 'Audit log', 'content_item' => 'Content item'],
    'export' => ['action' => 'Download CSV'],
    'system_actor' => 'System / not supplied',
    'not_supplied' => 'Not supplied',
];
