# Safe MAX deployment migration

This change is preparation only. Do not run either workflow and do not change the
current production service until a maintenance window is approved.

## Resulting trust boundaries

- `maxdeploy` can upload archives only to `/opt/vladislaw/.deploy/incoming`, create
  immutable release directories, switch `/opt/vladislaw/runtime/current`, and call
  one root-owned restart helper.
- `maxbot` runs the application and can write only to `/opt/vladislaw/data`.
- `maxrecovery` has a separate SSH key and can call only the root-owned Excel restore
  helper. It cannot deploy application code.
- GitHub never receives a root password. Host identity is pinned in `known_hosts`.
- Third-party GitHub Actions are pinned to reviewed commit hashes, and a deployment
  accepts only an immutable full commit SHA.
- Deployments never run Git, package-manager, Caddy, Excel repair, job reconciliation,
  or `clear_open_job.py` commands on the server.
- `maxdeploy` reads only `/etc/avr-max-deploy.env`, containing the two Excel file
  paths required by the backup gate. It cannot read the application `.env` or its
  MAX/API secrets.
- Each release is tested in CI and again from its offline wheel bundle before the
  active symlink is changed.

## Files installed once by an administrator

From a reviewed checkout of this exact commit, install these root-owned files:

```text
ops/bin/avr-max-deploy              -> /usr/local/bin/avr-max-deploy       0755 root:root
ops/sbin/avr-max-restart            -> /usr/local/sbin/avr-max-restart     0755 root:root
ops/sbin/avr-max-excel-restore      -> /usr/local/sbin/avr-max-excel-restore 0755 root:root
ops/systemd/max-webhook.service     -> /etc/systemd/system/max-webhook.service 0644 root:root
ops/sudoers.d/avr-max-deploy        -> /etc/sudoers.d/avr-max-deploy       0440 root:root
```

Validate the sudo policy with `visudo -cf /etc/sudoers.d/avr-max-deploy` before
ending the administrator session. The deploy and recovery scripts themselves are
root-owned so neither SSH account can replace the command that sudo permits.

## Server preparation

1. Keep the current root session open. Record the current service status, unit file,
   `/opt/vladislaw` owner/mode, active commit, and local changes. Take the normal IHC
   snapshot first. Record `python3 --version` and `uname -m`; select that Python
   major/minor in the workflow. The supplied GitHub runner package targets x86_64.
2. Create locked-password accounts `maxdeploy`, `maxrecovery`, and `maxbot`. Give each
   account its own group and no reusable password. Only `maxdeploy` and `maxrecovery`
   receive SSH public keys; `maxbot` has no SSH access.
3. Create the following directories without changing the existing `.env`, `data`,
   `.git`, `.venv`, Caddy configuration, or running service:

   ```text
   /opt/vladislaw/.deploy/incoming   maxdeploy:maxdeploy 0700
   /opt/vladislaw/releases           maxdeploy:maxdeploy 0750
   /opt/vladislaw/runtime            maxdeploy:maxdeploy 0750
   /opt/vladislaw/data               maxbot:maxbot       existing contents preserved
   ```

4. Prepare `/opt/vladislaw/releases/bootstrap` as a copy of the currently running
   application, excluding `.git`, `.env`, `data`, `.deploy`, `releases`, and `runtime`.
   Inside the bootstrap release, link `data` to `/opt/vladislaw/data` and `.venv` to
   `/opt/vladislaw/.venv`. Copy the three reviewed `create_excel_backup.py`,
   `verify_excel_backup.py`, and `restore_excel_backup.py` files into its `scripts`
   directory without replacing any existing application module. Point
   `/opt/vladislaw/runtime/current` to the bootstrap release. This makes the
   pre-migration version a valid rollback target.
5. Confirm that `maxbot` can read the bootstrap code and `.env`, execute the existing
   virtual environment, and read/write only the required files under `data`. Do not
   make `.env` readable by `maxdeploy` or `maxrecovery`.
   Create `/etc/avr-max-deploy.env` as `root:maxdeploy` mode `0640`, containing only
   `EXCEL_FILE_PATH` and `PAYROLL_FILE_PATH` copied from the application `.env`.
6. Install the helper scripts and sudo policy. Do not install or restart the new
   systemd unit yet.

## GitHub configuration

Create two protected GitHub Environments and require a reviewer for both:

- `production-max`: `MAX_DEPLOY_HOST`, `MAX_DEPLOY_USER=maxdeploy`,
  `MAX_DEPLOY_SSH_PRIVATE_KEY`, and the exact `ssh-keyscan` line verified against the
  IHC console as `MAX_DEPLOY_KNOWN_HOSTS`.
- `production-max-excel-recovery`: `MAX_RECOVERY_HOST`,
  `MAX_RECOVERY_USER=maxrecovery`, a different private key, and independently verified
  `MAX_RECOVERY_KNOWN_HOSTS`.

Restrict both keys in `authorized_keys` to their source addresses when GitHub runner
network policy permits it. Do not add `SERVER_PASSWORD`, do not reuse the root key,
and do not use `StrictHostKeyChecking=accept-new`.

## Verify new access before disabling root password login

Run these checks while the original administrator session and IHC console access are
still available:

1. Log in as `maxdeploy` with its key and run
   `/usr/local/bin/avr-max-deploy --check-access`. It must report that the three
   directories and restart helper are ready without restarting the service.
2. Run `sudo -n -l` as `maxdeploy`. Only `avr-max-restart` with no argument or
   `--check` may be allowed. The `--check` mode validates the fixed current
   release and latest Excel backup as root without granting `maxdeploy` read
   access to Excel data. `sudo -n bash` and restarting Caddy must fail.
3. Log in as `maxrecovery`; verify that only `avr-max-excel-restore` is permitted.
   Do not execute it during an access test.
4. Verify a wrong host key is rejected and password authentication is not offered to
   either new account.
5. Create a fresh validated backup manually from the active release:

   ```bash
   cd /opt/vladislaw/runtime/current
   .venv/bin/python -m scripts.create_excel_backup \
     --env-file /opt/vladislaw/.env \
     --backup-root /opt/vladislaw/data/excel/backups
   ```

6. Install the reviewed systemd unit, run `systemd-analyze verify` and
   `systemctl daemon-reload`, then restart only `max-webhook.service`. Confirm local
   `/health`, the public webhook health endpoint, MAX message processing, and that
   both Excel workbooks remain byte-for-byte unchanged.
7. Manually start `Deploy MAX bot safely` with an exact commit SHA. Approve the
   `production-max` environment only after the test job is green. The workflow is
   verify-only by default; activation also requires explicitly enabling
   `deploy_to_production`. Confirm the active symlink, service account, local/public
   health, logs, and unchanged Excel hashes.
8. Only after a successful deploy and a tested IHC console login, validate the SSH
   daemon configuration with `sshd -t`, disable root password authentication, reload
   SSH (do not restart it), and open a second key-only session before closing the first.

## Backup gate and rollback

Before activation, `verify_excel_backup.py` requires one complete backup directory
containing both configured workbooks, checks that both open successfully, and rejects
a backup older than 72 hours. It never edits a workbook.

The deployer keeps the old release and changes only the `runtime/current` symlink. If
the protected restart or health check fails, it immediately restores the old symlink
and restarts the old release. For a manual rollback, point `runtime/current` to a
previous reviewed release and call `/usr/local/sbin/avr-max-restart`; never run Git in
the production directory.

## Excel recovery

Excel recovery is not part of deployment. The `Restore MAX Excel from a validated
backup` workflow requires a timestamped backup id, the exact confirmation text
`RESTORE_EXCEL`, a separate protected environment, and the recovery-only SSH key. The
root helper validates the selected pair of workbooks, saves the current pair to a new
`pre-restore-*` directory, performs atomic file replacement, and starts the bot only
afterward. It does not fetch code, change branches, or touch job state.

The two older password-based Excel workflows are retained as manual, failing notices
so they cannot perform server actions accidentally and their replacement remains
visible in GitHub Actions history.
