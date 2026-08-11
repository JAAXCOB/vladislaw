# Деплой на Яндекс.Облако

Пошаговая инструкция по запуску бота на постоянно работающем сервере вместо локального компьютера.

## Что нужно до начала

1. **Аккаунт в Яндекс.Облако** с привязанной картой
2. **Домен** — MAX требует HTTPS с доверенным сертификатом (порт 443), самоподписные сертификаты не принимаются. Нужен настоящий домен (например `evacuation-bot.ru`), указывающий на IP сервера. Купить можно у любого регистратора (Reg.ru, Timeweb и т.д.), ~200-500₽/год.

## Шаг 1 — создать виртуальную машину

В консоли Яндекс.Облако:
1. **Compute Cloud** → **Создать ВМ**
2. Образ: **Ubuntu 22.04 LTS**
3. Конфигурация: минимальная хватит — 2 vCPU, 2 ГБ RAM
4. Диск: 20 ГБ достаточно
5. Публичный IP: **обязательно** (нужен для домена)
6. Сохрани SSH-ключ или пароль для входа

## Шаг 2 — направить домен на сервер

У регистратора домена добавь **A-запись**:
```
your-domain.com → <публичный IP сервера>
```
Подождите 5-30 минут пока DNS обновится (проверить: `nslookup your-domain.com`).

## Шаг 3 — подключиться к серверу и установить Docker

```bash
ssh <username>@<ip-сервера>

curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER
newgrp docker
```

## Шаг 4 — склонировать проект

```bash
git clone https://github.com/JAAXCOB/vladislaw.git
cd vladislaw
git checkout claude/max-excel-evacuation-automation-zxuvb4
```

## Шаг 5 — настроить .env

```bash
cp .env.example .env
nano .env
```

Заполни:
```
MAX_BOT_TOKEN=...
MAX_WEBHOOK_SECRET=придумай-случайную-строку
MAX_WEBHOOK_URL=https://your-domain.com/webhook
YANDEX_API_KEY=...
YANDEX_FOLDER_ID=...
LOG_LEVEL=INFO
```

`EXCEL_FILE_PATH` в `.env` можно не указывать — в Docker-контейнере путь задаётся отдельно (см. `docker-compose.yml`), он уже прописан как `/data/excel/evacuations.xlsx`.

## Шаг 6 — положить Excel-файл на сервер

```bash
mkdir -p data/excel
```

Загрузи существующий `.xlsx` файл на сервер (с локального компьютера):
```bash
scp путь/к/твоему/файлу.xlsx <username>@<ip-сервера>:~/vladislaw/data/excel/evacuations.xlsx
```

## Шаг 7 — прописать домен в Caddyfile

```bash
nano Caddyfile
```
Замени `your-domain.com` на свой настоящий домен.

## Шаг 8 — запустить

```bash
docker compose up -d --build
```

Caddy автоматически получит бесплатный TLS-сертификат от Let's Encrypt при первом запуске (нужен рабочий домен из шага 2).

Проверка что сервер жив:
```bash
curl https://your-domain.com/health
# Ожидается: {"status":"ok"}
```

## Шаг 9 — зарегистрировать webhook у MAX

```bash
pip install -r requirements.txt --user
python scripts/register_webhook.py
```

Дальше MAX сам будет присылать сообщения на `https://your-domain.com/webhook` — 24/7, без участия локального компьютера.

## Проверка логов

```bash
docker compose logs -f webhook
```

Здесь видно каждое полученное сообщение, результат AI-извлечения и запись в Excel.

## Обновление кода после новых изменений

```bash
git pull origin claude/max-excel-evacuation-automation-zxuvb4
docker compose up -d --build
```

## Как забрать актуальный Excel-файл с сервера

```bash
scp <username>@<ip-сервера>:~/vladislaw/data/excel/evacuations.xlsx ~/Downloads/
```
