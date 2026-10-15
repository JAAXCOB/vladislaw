# AV Rescue automation platform

This repository contains the MAX-to-Excel automation, its AV Rescue integration,
the prepared safe IHC deployment, and the standalone `avr-ai-controller`.

The current project status, decisions, priorities, and acceptance criteria are
maintained in [docs/AVR_PROJECT_REGISTER.md](docs/AVR_PROJECT_REGISTER.md).
Read that file before starting or continuing AV Rescue work.

## Implemented here

- authenticated MAX webhook and durable event inbox;
- message extraction, validation, duplicate protection, and reporting rules;
- Excel and AV Rescue synchronization;
- secure non-root deployment files with backup gate and rollback;
- a separate manual Excel recovery workflow;
- `avr-ai-controller` with site/MAX/OSRM monitoring, an HMAC action journal,
  closed MAX control, read-only mail adapters, and spending limits;
- automated tests for the application, deployment security, Excel recovery,
  and the AI controller.

## Canonical operating documents

- [Project register and work plan](docs/AVR_PROJECT_REGISTER.md)
- [Safe MAX deployment on IHC](docs/SAFE_MAX_DEPLOYMENT.md)
- [AI controller installation](docs/AVR_AI_CONTROLLER_INSTALL.md)
- [Legacy deployment notice](DEPLOY.md)

## Safety boundary

Production is not changed from this checkout without a separate approved
deployment step. Deployment must not mutate orders, Excel, Caddy, system
packages, or Git state on the server. Excel recovery is always a separate,
manual operation.

Do not store passwords, tokens, Android signing keys, or private SSH keys in
Git. Use environment files and secret stores described by the operating docs.

## Local verification

Install the relevant requirements in an isolated environment, then run:

```bash
pytest
```

The asynchronous Linux/systemd path must also pass in CI or on a Linux staging
host before any production installation.
