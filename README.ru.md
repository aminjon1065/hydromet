# Портал экологического мониторинга Гидромета

Репозиторий содержит спецификацию нового самостоятельного портала и реализованное основание приложения (Фаза 1).

## Зафиксированные решения

- Портал создаётся как новая кодовая база; работы с существующим `meteo.tj` не влияют на основную архитектуру и оценку.
- Гидромет предоставляет реестр станций, текущие и исторические наблюдения, SmartMet endpoints и данные MeteoAlert.
- SILAM подключается через адаптивный iframe `https://silam.fmi.fi/roux/TAJ/`; локальная обработка NetCDF/GRIB, COG и GeoServer не выполняется.
- Портал приводит внешние форматы к собственной канонической модели, хранит локальную копию нужных данных и показывает состояние/давность источников.
- Рекомендуемый стек: Laravel 13, Inertia.js + React + TypeScript, shadcn/ui, Filament 5, PostgreSQL/PostGIS, React-Leaflet, ECharts, Redis и Docker Compose.
- Архитектура — модульный монолит с адаптерами внешних источников, без NestJS, микросервисов, Kafka и Kubernetes.
- Первый production-год портал работает на VPS подрядчика; резервные копии должны храниться вне этого VPS.

## Основной вывод по `smartmet-alert-client`

Репозиторий FMI — качественный Vue-компонент для пятидневной SVG-карты предупреждений, но не готовый движок нашего портала. В нём жёстко заданы финские WFS-слои, геометрии регионов, языки `fi/sv/en`, часовой пояс Helsinki и специфический формат flood warnings.

Основная рекомендация: хранить предупреждения в канонической CAP-совместимой модели и показывать их полигоны на общей Leaflet-карте портала. `smartmet-alert-client` использовать как референс интерфейса, иконок, уровней опасности и accessibility. Если заказчик потребует именно пятидневную FMI-карту, её адаптация оформляется отдельным пакетом работ.

## Документация

- [Состав продукта и границы](docs/01-product-scope.md)
- [Архитектура](docs/02-architecture.md)
- [Поля и контракты данных](docs/03-data-contracts.md)
- [Анализ SmartMet и MeteoAlert](docs/04-smartmet-and-alerts.md)
- [Контракт API портала](docs/05-api-contract.md)
- [Тестирование и приёмка](docs/06-testing-and-acceptance.md)
- [Сроки и план поставки](docs/07-delivery-plan.md)
- [Что получить от Гидромета](docs/08-hydromet-input-checklist.md)

## Оценка

- Основной MVP: 6–8 недель одному разработчику либо 4–6 недель двум.
- Полный приёмочный объём с AQI, аудитом исправлений, CMS, тремя языками, безопасностью, тестами, документацией и обучением: 9–12 недель одному либо 7–9 недель двум с part-time QA/переводчиком.
- Риск по срокам: ещё 1–3 недели, если история загрязнена, форматы источников меняются, нет геометрий регионов или задерживается утверждение переводов/AQI.

## Что нужно до интеграции реальных данных

Минимальный стартовый пакет от Гидромета:

1. пример реестра станций;
2. пример текущих измерений;
3. часть исторических данных;
4. перечень параметров, единиц и quality flags;
5. пример MeteoAlert `Alert`, `Update` и `Cancel`;
6. полигоны предупреждений либо официальный GeoJSON административных границ;
7. SmartMet URL, producer, параметры и тестовый доступ;
8. утверждённая формула AQI либо согласие временно отключить AQI.

После получения этих данных либо утверждения mock-контрактов можно создавать Laravel-приложение, миграции и первые адаптеры без архитектурных догадок.

## Статус разработки

Фаза 1 (основание приложения) выполнена: модульный монолит Laravel, публичная
оболочка Inertia + React + TypeScript с shadcn/ui, административная панель
Filament, Docker Compose (Nginx, PHP-FPM, очередь, планировщик,
PostgreSQL/PostGIS, Redis), health-эндпоинт и инструменты контроля качества
(Pint, PHPStan/Larastan, ESLint, TypeScript, PHPUnit, Vitest).

Фаза 2A (каталог станций и параметров) выполнена на **mock-данных**: таблицы
`stations`, `parameters`, `station_parameter`, канонические записи из
`docs/03-data-contracts.md`, граница интеграции `StationRegistryProvider` с
fixture-адаптером, идемпотентный сервис импорта реестра и read-only ресурсы
Filament. Реальные данные Гидромета не получены.

Фаза 2B (хранение измерений и правки источника) также выполнена на
**mock-данных**: таблицы `measurements` и `measurement_revisions`, каноническая
запись измерения, граница `MeasurementProvider` с fixture-адаптерами (базовый
пакет и пакет коррекции) и идемпотентный сервис импорта, который применяет
правки источника, сохраняя исходно переданное значение.

AQI, SmartMet, MeteoAlert, SILAM, ручная коррекция, инкрементальное расписание,
публичная карта, графики и публичный API ещё не реализованы.

## Разработка

Полный список команд — в [README.md](README.md#local-development). Кратко:

```bash
cp .env.example .env
docker compose build
docker compose run --rm --no-deps app composer install
docker compose run --rm --no-deps app php artisan key:generate
npm install && npm run build

docker compose up -d                              # запуск
docker compose exec app php artisan migrate       # миграции
docker compose exec app php artisan make:filament-user   # первый администратор

docker compose exec app php artisan stations:import-fixture-registry  # mock-реестр
docker compose exec app php artisan measurements:import-fixture-batch --scenario=base
docker compose exec app php artisan measurements:import-fixture-batch --scenario=correction

docker compose exec app php artisan test          # backend-тесты (SQLite)
npm test                                          # frontend-тесты
composer check                                    # Pint + PHPStan + тесты
npm run lint && npm run types:check               # ESLint + typecheck

docker compose down                               # остановка
```

Портал доступен на `http://localhost:8080`, административная панель — на
`http://localhost:8080/admin`.

Команда `stations:import-fixture-registry` загружает искусственный
fixture-реестр под ключом источника `fixture`. Она идемпотентна, содержит одну
намеренно некорректную строку (частичный результат — это ожидаемое поведение) и
запрещена в окружении `production`.

`measurements:import-fixture-batch --scenario=base|correction` загружает
искусственные измерения того же источника; реестр станций должен быть
импортирован первым. Обе сцены идемпотентны: `correction` создаёт одну запись
в `measurement_revisions` и не меняет исходное значение. Опция `--scenario`
обязательна.

Ограничения `CHECK` и PostGIS проверяются только на PostgreSQL —
см. [README.md](README.md#test).

Языковые ключи приложения — `tj`, `ru`, `en`, запасной — `ru`. Внутренний ключ
`tj` приводится к стандартному тегу `tg-TJ` только на внешних границах (HTML
`lang`, `Content-Language`, в дальнейшем CAP). Метки времени хранятся в UTC и
отображаются в часовом поясе `Asia/Dushanbe`.

## Развёртывание

`compose.yaml` — **только среда разработки**. Он монтирует рабочую копию в
контейнеры через bind mounts, не содержит собранных ассетов и не подходит для
VPS как есть.

До первого развёртывания на VPS дополнительно требуется (в Фазу 1 не входит):

- production-образ, который копирует приложение внутрь, выполняет
  `composer install --no-dev --optimize-autoloader` и включает результат
  `npm run build`, вместо монтирования исходников;
- override `compose.prod.yaml` без bind mounts и без публикации портов
  PostgreSQL/Redis, с закреплёнными тегами образов и политиками перезапуска;
- терминация TLS, `APP_DEBUG=false`, кэш конфигурации/маршрутов/представлений;
- внешнее хранилище резервных копий и репетиция восстановления
  (`docs/01-product-scope.md`, раздел 3.4).

Порты PostgreSQL и Redis публикуются только на `127.0.0.1`. Контейнеры `app`,
`queue` и `scheduler` обращаются к ним по внутренней сети Compose как
`postgres:5432` и `redis:6379`.
