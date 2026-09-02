<?php

/*
 * Сатрҳои огоҳиҳо.
 *
 * Ибораҳо пешакӣ мебошанд. Гидромет каталоги миллии рамзҳои ҳодисаҳо, тарзи
 * намоиши дараҷаи хатар ва қоидаҳои нашрро тасдиқ накардааст
 * (docs/08-hydromet-input-checklist.md, бахши 3), бинобар ин луғати зерин аз
 * CAP гирифта шудааст ва тавсифӣ аст, на расмӣ.
 */

return [
    'navigation_group' => 'Огоҳиҳо',
    'message' => 'Паёми огоҳӣ',
    'messages' => 'Паёмҳои огоҳӣ',
    'read_only_notice' => 'Паёмҳои воридотии огоҳӣ. Таърихи паёмҳо тавассути воридот аз манбаъ нигоҳ дошта мешавад ва дар ин ҷо таҳрир намешавад.',
    'not_supplied' => 'Пешниҳод нашудааст',

    'fields' => [
        'identifier' => 'Рақами шиносоӣ',
        'source' => 'Манбаъ',
        'sender' => 'Фиристанда',
        'status' => 'Ҳолат',
        'message_type' => 'Навъи паём',
        'scope' => 'Доираи паҳншавӣ',
        'event_code' => 'Рамзи ҳодиса',
        'severity' => 'Дараҷаи хатар',
        'urgency' => 'Таъҷилият',
        'certainty' => 'Эътимоднокӣ',
        'categories' => 'Категорияҳо',
        'references' => 'Иршод ба паёмҳо',
        'parameters' => 'Параметрҳои манбаъ',
        'sent_at' => 'Фиристода шуд',
        'effective_at' => 'Эътибор аз',
        'onset_at' => 'Оғози интизорӣ',
        'expires_at' => 'Эътибор то',
        'headline' => 'Сарлавҳа',
        'description' => 'Тавсиф',
        'instruction' => 'Дастури манбаъ',
        'areas' => 'Минтақаҳои фарогирифта',
        'area_count' => 'Минтақаҳо',
        'geocodes' => 'Геокодҳо',
        'geometry' => 'Геометрия',
        'superseded_at' => 'Иваз карда шуд',
        'superseded_by' => 'Иваз карда шуд бо паём',
        'imported_at' => 'Ворид карда шуд',
        'lifecycle' => 'Ҳолати амал',
    ],

    'sections' => [
        'identity' => 'Шиносоӣ',
        'classification' => 'Таснифот',
        'validity' => 'Мӯҳлати амал',
        'content' => 'Матни оммавӣ',
        'areas' => 'Минтақаҳои фарогирифта',
        'provenance' => 'Сарчашма',
    ],

    'lifecycle' => [
        'active' => 'Амал мекунад',
        'scheduled' => 'Ба нақша гирифта шудааст',
        'superseded' => 'Иваз шуд',
        'expired' => 'Мӯҳлаташ гузашт',
        'withheld' => 'Нашр намешавад',
    ],

    'statuses' => [
        'Actual' => 'Амалкунанда',
        'Exercise' => 'Машқ',
        'System' => 'Системавӣ',
        'Test' => 'Озмоишӣ',
        'Draft' => 'Лоиҳа',
    ],

    'message_types' => [
        'Alert' => 'Огоҳӣ',
        'Update' => 'Навсозӣ',
        'Cancel' => 'Бекоркунӣ',
        'Ack' => 'Тасдиқ',
        'Error' => 'Хато',
    ],

    'scopes' => [
        'Public' => 'Оммавӣ',
        'Restricted' => 'Маҳдуд',
        'Private' => 'Хусусӣ',
    ],

    'severities' => [
        'Extreme' => 'Фавқулодда',
        'Severe' => 'Баланд',
        'Moderate' => 'Мӯътадил',
        'Minor' => 'Паст',
        'Unknown' => 'Номаълум',
    ],

    'urgencies' => [
        'Immediate' => 'Фаврӣ',
        'Expected' => 'Интизорӣ',
        'Future' => 'Ояндадор',
        'Past' => 'Гузашта',
        'Unknown' => 'Номаълум',
    ],

    'certainties' => [
        'Observed' => 'Мушоҳида шудааст',
        'Likely' => 'Эҳтимол',
        'Possible' => 'Имконпазир',
        'Unlikely' => 'Кам эҳтимол',
        'Unknown' => 'Номаълум',
    ],
];
