# Vladislaw — MAX → Excel Evacuation Automation

Automated pipeline: MAX group chat → AI extraction → Excel/database.

**Current phase: Phase 1 PoC** — receive raw MAX webhook events and log the JSON payload.

---

## Project structure

```
vladislaw/
├── webhook/
│   ├── config.py        # env-var settings
│   ├── models.py        # Pydantic models (Update, Message, User, …)
│   └── main.py          # FastAPI webhook endpoint
├── scripts/
│   ├── poll.py          # long-poll loop (no public URL needed)
│   └── register_webhook.py  # one-shot: POST /subscriptions to MAX
├── tests/
│   └── test_webhook.py  # mock-payload tests (no token needed)
├── .env.example
├── requirements.txt
└── README.md
```

---

## Setup

### 1. Clone and install

```bash
git clone https://github.com/jaaxcob/vladislaw.git
cd vladislaw
python -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
```

### 2. Configure secrets

```bash
cp .env.example .env
# Fill in MAX_BOT_TOKEN, MAX_WEBHOOK_SECRET, MAX_WEBHOOK_URL
```

`MAX_WEBHOOK_SECRET` — any string of `A-Z a-z 0-9 -`, 5–256 chars. You invent it; MAX echoes it back in the `X-Max-Bot-Api-Secret` header so you can verify requests.

### 3. Run tests (no token needed)

```bash
pytest
```

All tests use mock MAX payloads — no network connection required.

---

## Development without a public URL (long polling)

When you have a token but no HTTPS domain yet, use the poll script.  
MAX delivers updates directly without needing a webhook.

```bash
python scripts/poll.py
```

Send a message in the MAX test group → it prints the full JSON.

---

## Production: webhook mode

### Requirements
- Public domain with valid TLS cert (Let's Encrypt or Минцифры) on **port 443**
- OR: ngrok tunnel (`ngrok http 8000`) for quick testing

### Start server

```bash
uvicorn webhook.main:app --host 0.0.0.0 --port 8000
```

### Register webhook with MAX (run once)

```bash
python scripts/register_webhook.py
```

MAX will start POSTing events to your `/webhook` endpoint.  
Check your server logs — you should see the full JSON of every group message.

---

## Webhook endpoint

| | |
|---|---|
| Path | `POST /webhook` |
| Auth | `X-Max-Bot-Api-Secret` header must match `MAX_WEBHOOK_SECRET` |
| Response | Always `200 {"ok": "true"}` (so MAX doesn't retry) |
| Error | `403` on wrong secret, `400` on invalid JSON |

---

## Roadmap

- **Phase 1 (current):** receive raw JSON → log it
- **Phase 2:** parse into typed internal objects
- **Phase 3:** AI extraction → structured JSON
- **Phase 4:** validation + duplicate detection
- **Phase 5:** database (PostgreSQL)
- **Phase 6:** Excel sync
- **Phase 7:** human-review workflow
- **Phase 8:** production deployment
