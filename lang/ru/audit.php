<?php

return [
    'navigation_group' => 'Безопасность',
    'event' => 'Событие аудита',
    'events' => 'Журнал аудита',
    'sections' => ['event' => 'Событие', 'changes' => 'Зафиксированные изменения'],
    'fields' => [
        'occurred_at' => 'Время события',
        'actor' => 'Пользователь',
        'action' => 'Действие',
        'subject_type' => 'Тип объекта',
        'subject_id' => 'ID объекта',
        'subject' => 'Объект',
        'changed_fields' => 'Изменённые поля',
        'before' => 'До',
        'after' => 'После',
    ],
    'actions' => [
        'audit_exported' => 'Журнал аудита выгружен',
        'content_created' => 'Материал создан',
        'content_updated' => 'Материал изменён',
    ],
    'subject_types' => ['audit_log' => 'Журнал аудита', 'content_item' => 'Материал CMS'],
    'export' => ['action' => 'Скачать CSV'],
    'system_actor' => 'Система / не указан',
    'not_supplied' => 'Не указано',
];
