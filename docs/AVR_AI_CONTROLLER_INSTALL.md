# AVR AI Controller: installation on IHC

This is a read-only observation pilot. It does not contain tools for Excel, payroll,
orders, driver assignment, finance, Caddy, deployment, or the existing MAX webhook.
Do not install it on production until the reviewed release containing
`ai_controller/` has passed the safe workflow in verify-only mode.

## What the service does

- polls the public site health URL, local MAX webhook health, and an OSRM nearest
  endpoint with GET requests;
- writes a privacy-safe, HMAC-chained action journal under
  `/opt/vladislaw/data/ai-controller`;
- optionally polls a separate MAX controller bot and accepts only `/status`,
  `/budget`, `/mail`, and `/help` from one closed chat and allowlisted numeric user
  IDs; every command needs a fresh timestamp and a unique nonce;
- optionally reads Gmail metadata using OAuth authorized only for
  `gmail.readonly`;
- optionally reads Yandex Mail with IMAP `readonly=True` and `BODY.PEEK`, which does
  not set the Seen flag;
- produces deterministic recommendations. Paid model calls are blocked before any
  HTTP request unless all three explicit budget limits are positive.

## Preconditions

Before changing the server, keep an administrator/IHC console session open and
record:

```bash
systemctl is-active max-webhook.service
curl -fsS http://127.0.0.1:8000/health
python3 --version
free -h
df -h /opt/vladislaw
```

The safe release Python version must match the server. Leave the existing `.env`,
`data/excel`, Excel workbooks, MAX token, Caddy configuration, and
`max-webhook.service` unchanged.

## 1. Prepare identities and directories

Run once as an administrator during an approved maintenance window:

```bash
useradd --system --home-dir /nonexistent --shell /usr/sbin/nologin avrai
install -d -o avrai -g avrai -m 0700 /opt/vladislaw/data/ai-controller
install -d -o root -g avrai -m 0750 /opt/vladislaw/ai-controller
```

Do not add `avrai` to `sudo`, `maxbot`, deploy, mail, or Excel groups. The account
must not have an SSH key or interactive shell. Confirm `sudo -u avrai sudo -n -l`
does not grant commands.

## 2. Create the isolated configuration

Copy `.env.ai-controller.example` to `/opt/vladislaw/.env.ai-controller`, replace
only the required values, then install it as `root:avrai` mode `0640`:

```bash
install -o root -g avrai -m 0640 .env.ai-controller.example /opt/vladislaw/.env.ai-controller
openssl rand -hex 32
```

Put the generated value into `AVR_AI_AUDIT_HMAC_KEY` without printing it again.
Initially keep these settings exactly as follows:

```text
AVR_AI_PAID_API_ENABLED=false
AVR_AI_DAILY_BUDGET_MINOR=0
AVR_AI_MONTHLY_BUDGET_MINOR=0
AVR_AI_PER_REQUEST_BUDGET_MINOR=0
AVR_AI_MAX_CONTROL_ENABLED=false
AVR_AI_YANDEX_MAIL_ENABLED=false
AVR_AI_GMAIL_ENABLED=false
```

Set `AVR_AI_SITE_HEALTH_URL` to an existing GET-only site health endpoint,
`AVR_AI_MAX_HEALTH_URL=http://127.0.0.1:8000/health`, and
`AVR_AI_OSRM_BASE_URL` to the actual HTTPS OSRM base. Do not guess an OSRM URL.

## 3. Install and validate an isolated controller release

After the reviewed safe release has been verified, copy only the controller from
that immutable release into a separate root-owned directory. Replace `<commit>` with
the full release commit. This does not switch `runtime/current` and does not restart
the existing MAX bot:

```bash
release=/opt/vladislaw/ai-controller/releases/<commit>
install -d -o root -g avrai -m 0750 "$release"
cp -a /opt/vladislaw/runtime/current/ai_controller "$release/"
cp -a /opt/vladislaw/runtime/current/requirements-ai-controller.txt "$release/"
python3 -m venv "$release/.venv"
"$release/.venv/bin/pip" install --disable-pip-version-check --no-index \
  --find-links /opt/vladislaw/runtime/current/wheelhouse \
  -r "$release/requirements-ai-controller.txt"
chown -R root:avrai "$release"
chmod -R u=rwX,g=rX,o= "$release"
ln -sfn "$release" /opt/vladislaw/ai-controller/current.next
mv -Tf /opt/vladislaw/ai-controller/current.next /opt/vladislaw/ai-controller/current

systemd-run --wait --pipe --collect --uid=avrai \
  --working-directory=/opt/vladislaw/ai-controller/current \
  --property=EnvironmentFile=/opt/vladislaw/.env.ai-controller \
  /opt/vladislaw/ai-controller/current/.venv/bin/python -c \
  "from ai_controller.config import ControllerSettings; ControllerSettings(); print('config ok')"
```

The controller has its own virtual environment and does not need read access to the
MAX release after installation. Run `tests/test_ai_controller.py` in CI before this
copy; tests are deliberately not installed into the service directory.

## 4. Install and start the isolated unit

```bash
install -o root -g root -m 0644 ops/systemd/avr-ai-controller.service \
  /etc/systemd/system/avr-ai-controller.service
systemd-analyze verify /etc/systemd/system/avr-ai-controller.service
systemctl daemon-reload
systemctl enable --now avr-ai-controller.service
```

The unit binds only to `127.0.0.1:8010`, runs as `avrai`, has no capabilities or
sudo, can write only its own state directory, and is limited to 384 MiB RAM and 50%
of one CPU.

Verify:

```bash
systemctl show avr-ai-controller.service -p User -p Group -p MainPID -p MemoryCurrent
curl -fsS http://127.0.0.1:8010/health
curl -fsS http://127.0.0.1:8010/status
curl -fsS http://127.0.0.1:8010/budget
curl -fsS http://127.0.0.1:8010/audit/verify
journalctl -u avr-ai-controller.service --since today --no-pager
```

Expected budget output has `paid_api_enabled=false` and zero usage/limits. Audit
verification must return `valid=true`. Confirm that `max-webhook.service` is still
active and compare hashes of both Excel files before and after starting the new
service; they must be identical.

## 5. Enable integrations one at a time

### Closed MAX controller

Create a separate bot token; never reuse `MAX_BOT_TOKEN`. Add that bot only to a
closed control chat, record the numeric chat ID and numeric owner/operator user IDs,
and set the four `AVR_AI_MAX_CONTROL_*` values. The controller uses long polling and
does not register or replace the existing webhook.

After restart, test with a fresh random nonce, for example `/status 8f2d91ab`. A
second message with the same nonce must be rejected. A message from another chat or
user must produce no response. Controller responses contain status and counts only,
not message bodies, addresses, phones, tokens, or Excel data.

### Yandex Mail

Use a dedicated technical mailbox and an app password with IMAP enabled. The adapter
selects INBOX read-only and requests only headers with `BODY.PEEK`. Verify in the
mail UI that sampled messages remain unread. SMTP settings are intentionally absent.

### Gmail

Authorize a dedicated mailbox with exactly
`https://www.googleapis.com/auth/gmail.readonly`. Store its client secret and refresh
token only in `.env.ai-controller`. The adapter calls list/get metadata endpoints;
it cannot modify labels, delete, forward, draft, or send mail.

Restart and verify after each single integration. If a check fails, disable only that
adapter and restart the controller; do not change the existing MAX bot.

## Rollback

The controller owns no authoritative business state. To remove it safely:

```bash
systemctl disable --now avr-ai-controller.service
```

Keep `/opt/vladislaw/data/ai-controller/audit.jsonl` for investigation and retention.
Removing the unit does not require restoring Excel, reverting orders, changing the
MAX webhook, or rolling back the website. Delete the controller state only under a
separate approved retention decision.
