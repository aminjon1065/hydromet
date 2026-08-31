import type { SharedProps } from '@/types';

/**
 * Mirrors the props shared by App\Http\Middleware\HandleInertiaRequests.
 */
export const sharedProps: SharedProps = {
    locale: {
        current: 'ru',
        bcp47: 'ru',
        fallback: 'ru',
        available: [
            { value: 'tj', label: 'Тоҷикӣ' },
            { value: 'ru', label: 'Русский' },
            { value: 'en', label: 'English' },
        ],
    },
    displayTimezone: 'Asia/Dushanbe',
    translations: {
        brand_name: 'Портал экологического мониторинга',
        brand_tagline: 'Официальные гидрометеорологические данные и качество воздуха',
        language_label: 'Язык',
        home_heading: 'Портал экологического мониторинга Таджикистана',
        home_intro: 'Портал публикует наблюдения станций.',
        home_foundation_badge: 'Фаза 1 — основание приложения',
        home_roadmap_heading: 'Разделы портала',
        home_roadmap_intro: 'Эти разделы подключаются позже.',
        roadmap_map: 'Карта станций и текущие значения',
        roadmap_charts: 'Графики и экспорт CSV',
        roadmap_alerts: 'Официальные предупреждения',
        roadmap_silam: 'Прогноз качества воздуха SILAM',
        roadmap_status: 'Планируется',
        time_timezone_notice: 'Время отображается в часовом поясе :timezone.',
        time_generated_at: 'Страница сформирована',
        footer_source: 'Источник данных: Гидромет',
        footer_note: 'Портал не эксплуатирует измерительное оборудование.',
    },
};
