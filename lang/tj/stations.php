<?php

/*
 * Сатрҳои феҳристи истгоҳҳо ва каталоги параметрҳо.
 *
 * Ибораҳо пешакӣ мебошанд. Истилоҳоти ниҳоӣ бо Гидромет мувофиқа мешавад
 * (docs/08-hydromet-input-checklist.md, бахши 5).
 */

return [
    'navigation_group' => 'Маълумотномаҳо',

    'station' => 'Истгоҳ',
    'stations' => 'Истгоҳҳо',
    'parameter' => 'Параметр',
    'parameters' => 'Параметрҳо',

    'fields' => [
        'code' => 'Рамз',
        'name' => 'Ном',
        'source' => 'Манбаъ',
        'external_id' => 'Рақами манбаъ',
        'region_code' => 'Минтақа',
        'district_code' => 'Ноҳия',
        'status' => 'Ҳолат',
        'station_type' => 'Навъ',
        'parameters_count' => 'Шумораи параметрҳо',
        'source_updated_at' => 'Дар манбаъ навсозӣ шуд',
        'latitude' => 'Арз',
        'longitude' => 'Тӯл',
        'elevation_m' => 'Баландӣ, м',
        'timezone' => 'Минтақаи вақт',
        'owner' => 'Соҳиб',
        'installed_at' => 'Ба кор андохта шуд',
        'imported_at' => 'Воридоти аввал',
        'updated_at' => 'Тағйироти охирин',
        'kind' => 'Гурӯҳ',
        'canonical_unit' => 'Воҳиди каноникӣ',
        'precision' => 'Рақамҳо баъди вергул',
        'default_averaging_period' => 'Давраи миёнагирии пешфарз',
        'plausible_min' => 'Ҳадди ақали эҳтимолӣ',
        'plausible_max' => 'Ҳадди аксари эҳтимолӣ',
        'active' => 'Нашр мешавад',
    ],

    'sections' => [
        'identity' => 'Шиносоӣ',
        'location' => 'Ҷойгиршавӣ',
        'lifecycle' => 'Давраи хизмат',
        'catalogue' => 'Каталог',
        'quality_control' => 'Назорати сифат',
        'provenance' => 'Сарчашма',
    ],

    'statuses' => [
        'active' => 'Фаъол',
        'maintenance' => 'Хизматрасонӣ',
        'offline' => 'Бе алоқа',
        'decommissioned' => 'Аз кор бароварда шуд',
    ],

    'types' => [
        'air_quality' => 'Сифати ҳаво',
        'meteorological' => 'Метеорологӣ',
        'combined' => 'Омехта',
    ],

    'parameter_kinds' => [
        'pollutant' => 'Моддаи ифлоскунанда',
        'meteorological' => 'Метеорологӣ',
        'derived' => 'Ҳисобшуда',
    ],

    'read_only_notice' => 'Маълумоти воридотии маълумотнома. Сабтҳо тавассути воридот аз манбаъ нигоҳ дошта мешаванд ва дар ин ҷо таҳрир намешаванд.',
    'not_supplied' => 'Пешниҳод нашудааст',
];
