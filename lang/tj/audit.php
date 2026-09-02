<?php

return [
    'navigation_group' => 'Амният',
    'event' => 'Рӯйдоди аудит',
    'events' => 'Журнали аудит',
    'sections' => ['event' => 'Рӯйдод', 'changes' => 'Тағйироти сабтшуда'],
    'fields' => [
        'occurred_at' => 'Вақти рӯйдод',
        'actor' => 'Истифодабаранда',
        'action' => 'Амал',
        'subject_type' => 'Навъи объект',
        'subject_id' => 'ID-и объект',
        'subject' => 'Объект',
        'changed_fields' => 'Майдонҳои тағйирёфта',
        'before' => 'Пеш',
        'after' => 'Баъд',
    ],
    'actions' => [
        'audit_exported' => 'Феҳристи аудит содир карда шуд',
        'content_created' => 'Мавод эҷод шуд',
        'content_updated' => 'Мавод тағйир ёфт',
    ],
    'subject_types' => ['audit_log' => 'Феҳристи аудит', 'content_item' => 'Маводи CMS'],
    'export' => ['action' => 'Боргирии CSV'],
    'system_actor' => 'Система / нишон дода нашудааст',
    'not_supplied' => 'Нишон дода нашудааст',
];
