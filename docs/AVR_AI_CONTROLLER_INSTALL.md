# AVR AI Controller: installation on IHC 130.49.173.150

This is a read-only observation pilot. It does not contain tools for Excel, payroll,
orders, driver assignment, finance, Caddy, deployment, or the existing MAX webhook.
Do not install it on production until the reviewed release containing
`ai_controller/` has passed the safe workflow in verify-only mode.
Every command that changes the IHC server requires a separate approval immediately
before it is run. This document is preparation, not authorization to connect or apply.

## What the service does

- polls the public site health URL, local MAX webhook health, and an OSRM nearest
  endpoint with GET requests;
- writes a privacy-safe, HMAC-chained action journal under
  `/opt/vladislaw/ai-controller-data`;
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

The safe release Python version must match the server (currently Python 3.10 on
IHC). Leave the existing `.env`,
`data/excel`, Excel workbooks, MAX token, Caddy configuration, and
`max-webhook.service` unchanged.

## 1. Prepare identities and directories

Run once as an administrator during an approved maintenance window:

```bash
useradd --system --home-dir /nonexistent --shell /usr/sbin/nologin avrai
install -d -o avrai -g avrai -m 0700 /opt/vladislaw/ai-controller-data
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
The example placeholder is rejected by the application, so it cannot silently become
the production audit key.
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

For the current IHC layout use `AVR_AI_SITE_HEALTH_URL=https://av-rescue.ru/`,
`AVR_AI_MAX_HEALTH_URL=http://127.0.0.1:8000/health`, and
`AVR_AI_OSRM_BASE_URL=http://127.0.0.1:5000`. The public site does not expose a
working `/health` route, while OSRM is intentionally reached over its local port.

## 3. Build a controller-only release off the server

Run the manual `Verify AVR AI controller` workflow for a full 40-character commit
SHA. It runs the controller tests with a real asyncio event loop on Linux, checks the
systemd unit, downloads an offline wheelhouse, and creates a controller-only artifact.
It has no deployment job, SSH key, server secret, or production environment.

Download both artifact files and independently record the SHA-256 value. Do not use
the MAX release, `/opt/vladislaw/runtime/current`, its wheelhouse, or its deploy key.
The controller artifact must contain only `ai_controller/`, its dedicated requirements,
the unit, the example environment, and this installation guide.

## 4. Stage and validate the isolated release on IHC

Only after a separate server-change approval, transfer the verified artifact and its
`.sha256` file to a new temporary location. Replace `<commit>` with the exact commit
used by the workflow:

```bash
cd /var/tmp
sha256sum -c "avr-ai-controller-<commit>.tar.gz.sha256"
release=/opt/vladislaw/ai-controller/releases/<commit>
install -d -o root -g avrai -m 0750 "$release"
tar --extract --gzip --file "avr-ai-controller-<commit>.tar.gz" \
  --directory "$release" --no-same-owner
python3 -m venv "$release/.venv"
"$release/.venv/bin/pip" install --disable-pip-version-check --no-index \
  --find-links "$release/wheelhouse" \
  -r "$release/requirements-ai-controller.txt"
chown -R root:avrai "$release"
chmod -R u=rwX,g=rX,o= "$release"

systemd-run --wait --pipe --collect --uid=avrai \
  --working-directory="$release" \
  --property=EnvironmentFile=/opt/vladislaw/.env.ai-controller \
  "$release/.venv/bin/python" -c \
  "from ai_controller.config import ControllerSettings; ControllerSettings(); print('config ok')"
```

Stop if the checksum, offline installation, or configuration validation fails. At
this stage the existing MAX service has not been restarted or changed, and the new
controller is not enabled. The controller has its own virtual environment and never
needs read access to the MAX release.

## 5. Activate only the isolated controller

Activation is a second server-changing phase and requires a fresh approval. Preserve
the previous `current` link, if one exists, before switching only the controller:

```bash
release=/opt/vladislaw/ai-controller/releases/<commit>
test "$(cat "$release/RELEASE_ID")" = "<commit>"
readlink -f /opt/vladislaw/ai-controller/current || true
ln -sfn "$release" /opt/vladislaw/ai-controller/current.next
mv -Tf /opt/vladislaw/ai-controller/current.next /opt/vladislaw/ai-controller/current
install -o root -g root -m 0644 "$release/ops/systemd/avr-ai-controller.service" \
  /etc/systemd/system/avr-ai-controller.service
systemd-analyze verify /etc/systemd/system/avr-ai-controller.service
systemctl daemon-reload
systemctl enable --now avr-ai-controller.service
```

The unit binds only to `127.0.0.1:8010`, runs as `avrai`, has no capabilities or
sudo, and is limited to 384 MiB RAM and 50% of one CPU. Its only writable application
path is the separately owned mode-0700 `/opt/vladislaw/ai-controller-data` directory.
The IHC host runs inside Virtuozzo, whose container policy rejects systemd mount and
PID namespace hardening such as `ProtectSystem`, `PrivateTmp`, `ProtectProc`, and
`RestrictNamespaces`. Those directives are intentionally omitted; isolation is
provided by the dedicated no-login account, empty capability sets, `NoNewPrivileges`,
filesystem ownership, and resource/address-family restrictions.

Verify:

```bash
systemctl show avr-ai-controller.service -p User -p Group -p MainPID -p MemoryCurrent
curl -fsS http://127.0.0.1:8010/health
curl -fsS http://127.0.0.1:8010/status
curl -fsS http://127.0.0.1:8010/budget
curl -fsS http://127.0.0.1:8010/audit/verify
journalctl -u avr-ai-controller.service --since today --no-pager
```

Expected health output has `status=healthy`. Expected budget output has
`paid_api_enabled=false` and zero usage/limits. Audit verification must return
`valid=true`. Confirm that `max-webhook.service` is still
active and compare hashes of both Excel files before and after starting the new
service; they must be identical.

## 6. Enable optional integrations one at a time

### Closed MAX controller

Use a separate controller bot and a separate closed chat. Never reuse `MAX_BOT_TOKEN`.
Keep `AVR_AI_MAX_CONTROL_TRANSPORT=webhook`; polling is retained only for an isolated
development check. Generate a dedicated random webhook secret of at least 32
characters and configure the controller bot to deliver only `message_created` events
to a dedicated HTTPS path which the reverse proxy forwards to
`http://127.0.0.1:8010/max-control/webhook`.

The public route must be unique to this controller. Do not replace or redirect the
existing MAX webhook route. Before enabling it, verify all of the following in a test
chat: a missing or wrong `X-Max-Bot-Api-Secret` gets HTTP 403; the correct secret gets
HTTP 200; a different chat or user is ignored; a repeated nonce is ignored; and only
`/status`, `/budget`, `/mail`, and `/help` are accepted. Then set:

```text
AVR_AI_MAX_CONTROL_ENABLED=true
AVR_AI_MAX_CONTROL_TRANSPORT=webhook
AVR_AI_MAX_CONTROL_TOKEN=<separate controller bot token>
AVR_AI_MAX_CONTROL_WEBHOOK_SECRET=<dedicated random secret>
AVR_AI_MAX_CONTROL_CHAT_ID=<closed numeric chat id>
AVR_AI_MAX_CONTROL_USER_IDS=<comma-separated numeric user ids>
```

Changing the reverse proxy and registering the webhook are separate production
changes and require their own reviewed patch, backup and approval. Do not enable the
flag until the HTTPS route has been verified end to end.

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

Keep `/opt/vladislaw/ai-controller-data/audit.jsonl` for investigation and retention.
Removing the unit does not require restoring Excel, reverting orders, changing the
MAX webhook, or rolling back the website. Delete the controller state only under a
separate approved retention decision.

