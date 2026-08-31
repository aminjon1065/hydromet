<?php

/*
 * Строки реестра станций и каталога параметров.
 *
 * Формулировки предварительные. Окончательная терминология на таджикском,
 * русском и английском согласуется с Гидрометом
 * (docs/08-hydromet-input-checklist.md, раздел 5).
 */

return [
    'navigation_group' => 'Справочные данные',

    'station' => 'Станция',
    'stations' => 'Станции',
    'parameter' => 'Параметр',
    'parameters' => 'Параметры',

    'fields' => [
        'code' => 'Код',
        'name' => 'Название',
        'source' => 'Источник',
        'external_id' => 'Идентификатор источника',
        'region_code' => 'Регион',
        'district_code' => 'Район',
        'status' => 'Статус',
        'station_type' => 'Тип',
        'parameters_count' => 'Параметров',
        'source_updated_at' => 'Обновлено в источнике',
        'latitude' => 'Широта',
        'longitude' => 'Долгота',
        'elevation_m' => 'Высота, м',
        'timezone' => 'Часовой пояс',
        'owner' => 'Владелец',
        'installed_at' => 'Введена в эксплуатацию',
        'imported_at' => 'Первый импорт',
        'updated_at' => 'Последнее изменение',
        'kind' => 'Вид',
        'canonical_unit' => 'Каноническая единица',
        'precision' => 'Знаков после запятой',
        'default_averaging_period' => 'Период осреднения по умолчанию',
        'plausible_min' => 'Правдоподобный минимум',
        'plausible_max' => 'Правдоподобный максимум',
        'active' => 'Публикуется',
    ],

    'sections' => [
        'identity' => 'Идентификация',
        'location' => 'Расположение',
        'lifecycle' => 'Жизненный цикл',
        'catalogue' => 'Каталог',
        'quality_control' => 'Контроль качества',
        'provenance' => 'Происхождение',
    ],

    'statuses' => [
        'active' => 'Активна',
        'maintenance' => 'Обслуживание',
        'offline' => 'Не на связи',
        'decommissioned' => 'Выведена из эксплуатации',
    ],

    'types' => [
        'air_quality' => 'Качество воздуха',
        'meteorological' => 'Метеорологическая',
        'combined' => 'Комбинированная',
    ],

    'parameter_kinds' => [
        'pollutant' => 'Загрязняющее вещество',
        'meteorological' => 'Метеорологический',
        'derived' => 'Расчётный',
    ],

    'read_only_notice' => 'Импортированные справочные данные. Записи ведутся импортом из источника и не редактируются здесь.',
    'not_supplied' => 'Не передано',
];
