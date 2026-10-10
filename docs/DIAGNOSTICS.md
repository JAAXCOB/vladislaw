# Безопасная диагностика AV Rescue

Запускать из новой локальной копии docs/diagnostics, не из production checkout. В пакете нет секретов; тестовые PRIVATE_TOKEN/PRIVATE_SECRET — фиктивные маркеры redaction tests, не действующие credentials.

## Подготовка и тесты без сети

```bash
cd docs/diagnostics
python3 -m venv .venv
.venv/bin/python -m pip install -r requirements.txt
.venv/bin/python -m pytest -q tests
```

Загрузка зависимостей требует HTTPS к PyPI; сам тестовый прогон использует только mocks и временные каталоги. Не нужны MAX, банк, почта или SSH.

## MAX только для чтения

Секрет MAX_BOT_TOKEN предоставляет защищённое окружение; не указывать значение в командной строке. MAX_B2B_CHAT_ID, MAX_WEBHOOK_URL и опциональный expected bot ID позволяют сверить нужного бота. Скрипт умеет читать локальную .env, но этот файл в пакет не включён и не должен загружаться в Work/GitHub.

```bash
.venv/bin/python -m scripts.diagnose_max
```

Вызовы: GET /me, /chats/{id}, /chats/{id}/members/me, /subscriptions на фиксированном официальном HTTPS-host; redirect отключён. Вывод содержит только разрешённые статусы и счётчики, не raw response/имена/тексты/URL/ключи. Успешные GET не доказывают возможность отправки; пустой список subscriptions допустим при polling. Никаких POST, подписок, сообщений или deploy. На этом этапе live вызовы не выполнялись.

Для проблемы «заявка принята» после безопасного получения полного серверного кода проверить partner_order_sync.php: ok/reply_required/reply_chat_id/reply_text, source ID и повторный upsert; driver claim в partner_order_action.php — отдельное событие. Проверить deployed SHA, ack/outbox pending/dead и разрешённый чат, не выводя строки заявок и полные журналы. Существующие deploy/recovery workflows не запускать для диагностики.

## Сверка файлов

Согласовать JSON-массив явных относительных путей только к коду. Копии получать read-only, сохранить одинаковую относительную структуру. Не включать .env, data, XLSX, uploads и секретные настройки; config.php при необходимости сверять только хэшем.

```bash
.venv/bin/python -m scripts.safe_inventory scan --root /workspace/readonly-reference --paths /workspace/code-paths.json > /workspace/reference-manifest.json
.venv/bin/python -m scripts.safe_inventory scan --root /workspace/readonly-server-export --paths /workspace/code-paths.json > /workspace/server-manifest.json
.venv/bin/python -m scripts.safe_inventory compare /workspace/reference-manifest.json /workspace/server-manifest.json
```

Symlinks и выход за корень отклоняются. Неизвестные серверные файлы не удалять. Хэши реальных серверных версий ещё не получены; SHA256SUMS.txt описывает только этот пакет.

## Восстановление только на отдельном стенде

```bash
.venv/bin/python -m scripts.verify_restore --archive /workspace/backup-fixture.zip --manifest /workspace/trusted-manifest.json --target /workspace/new-restore-drill
```

Target должен отсутствовать. Внешний доверенный manifest: version=1, files, для каждого файла bytes/sha256. Отвергаются corruption, traversal, symlinks, duplicates, unexpected files и объём >256 MiB. ZIP verification проверяет байты, не финансовую согласованность. Консистентный backup, обезличивание, исходящий firewall и проверка JSON/XLSX/балансов/очередей описаны в AVR_SAFE_IMPLEMENTATION_2026-10-10.md. Production-копия пока не восстановлена.
