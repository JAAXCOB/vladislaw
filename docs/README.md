# AV Rescue материалы для ChatGPT Work

Переносимый пакет аудита и безопасного внедрения от 10 октября 2026. Начать с AVR_SAFE_IMPLEMENTATION_2026-10-10.md и DIAGNOSTICS.md. Файлы доступны в репозитории JAAXCOB/vladislaw; локальные абсолютные пути в старых отчётах — история предыдущей среды, а не обязательные адреса текущего Work.

- AVR_AI_AUDIT_2026-10-10.md — аудит исходного main cf4667b с примечанием о найденном Excel-боте.
- AVR_SAFE_IMPLEMENTATION_2026-10-10.md — доступы, сверка, restore, ротация, транзакции, финансы, ИИ и почта.
- AVR_WORK_STATE.md, AVR_ADMIN_FUNCTIONAL_SPEC.md — состояние и требования к админке.
- DIAGNOSTICS.md, diagnostics/ — инструкции, безопасные скрипты и mock tests.
- CHECKPOINT.json, SHA256SUMS.txt — исходные SHAs и контрольные суммы пакета, НЕ production.
- patches/ — архивные кандидаты исправлений, не активный код и не готовый релиз.
- TEST_RESULTS.json — проверки предыдущего локального кандидата; настоящие серверные integration/restore tests пока не выполнены.

## Проверка пакета в другой среде

```bash
cd docs
sha256sum -c SHA256SUMS.txt
```

При ZIP распаковать в НОВЫЙ каталог; внутри корень docs/. Не копировать на production. Диагностику тестировать отдельно по DIAGNOSTICS.md.

## Patch-кандидаты

Для публикации экспортированы без контекстных строк и без повторных docs. Исходные локальные patch-файлы сохранены вне репозитория; их хэши для происхождения записаны в CHECKPOINT.json. Отсутствие контекста убирает ненужные примерные телефоны/адреса из старых тестовых fixtures; содержимое новых изменений сохранено. Обязательны exact base SHA и --unidiff-zero. Не применять к рабочему серверу или ветке бота с автоматическим deploy.

Сделать отдельные detached worktrees из указанных SHAs, затем только проверить применимость:

```bash
git worktree add --detach /workspace/avr-main-candidate cf4667bec55494320032740c3fca937e1905c3a8
git -C /workspace/avr-main-candidate apply --check --unidiff-zero /absolute/path/docs/patches/main-candidate.patch
git worktree add --detach /workspace/avr-excel-candidate beaa88360a1e58764d60b4de006c1e5dac03a9ed
git -C /workspace/avr-excel-candidate apply --check --unidiff-zero /absolute/path/docs/patches/excel-bot-candidate.patch
```

Результат --check ничего не устанавливает. Для Excel base может потребоваться read-only fetch существующей ветки claude/max-excel-evacuation-automation-zxuvb4. После отдельного применения на стенде запускать все тесты; PHP-кандидат требует полного production code reconciliation и транзакционного адаптера до финансовых операций.

## Ограничения

Этот документационный коммит не меняет PHP/webhook/CI production-код; patch-файлы — данные для review, не применённые исправления. Никаких паролей, действующих токенов, .env, исходных баз, XLSX, журналов или данных клиентов в пакет не включено. Фиктивные credential markers присутствуют только в mock tests. Новые ветки и deploy не требуются. Старые записи «не опубликовано» в отчётах относятся к коду кандидата, а не к доступности этого документационного пакета.
