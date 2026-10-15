from __future__ import annotations

import os
import subprocess
from pathlib import Path

import openpyxl
import pytest

from scripts.create_excel_backup import create_backup
from scripts.restore_excel_backup import restore
from scripts.verify_excel_backup import newest_complete_backup


ROOT = Path(__file__).resolve().parents[1]


def make_workbook(path: Path, value: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    workbook = openpyxl.Workbook()
    workbook.active["A1"] = value
    workbook.save(path)
    workbook.close()


def read_a1(path: Path) -> str:
    workbook = openpyxl.load_workbook(path, read_only=True, data_only=False)
    try:
        return str(workbook.active["A1"].value)
    finally:
        workbook.close()


def test_deploy_workflow_has_no_password_or_business_data_mutation() -> None:
    workflow = (ROOT / ".github/workflows/deploy-bot.yml").read_text(encoding="utf-8")
    forbidden = (
        "sshpass",
        "SERVER_PASSWORD",
        "clear_open_job.py",
        "restore_latest_valid_excel.py",
        "reconcile_closed_jobs.py",
        "git stash",
        "git checkout",
        "git push",
        "apt-get",
        "systemctl",
        "caddy",
    )
    for token in forbidden:
        assert token not in workflow
    assert "workflow_dispatch:" in workflow
    assert "push:" not in workflow
    assert "contents: read" in workflow
    assert "StrictHostKeyChecking=yes" in workflow
    assert "MAX_DEPLOY_SSH_PRIVATE_KEY" in workflow
    assert 'printf \'%s\' "$SSH_PRIVATE_KEY" | base64 --decode' in workflow
    assert "environment: production-max" in workflow
    assert "Full 40-character commit SHA" in workflow
    assert "deploy_to_production" in workflow
    assert "default: false" in workflow
    assert "if: ${{ inputs.deploy_to_production }}" in workflow
    assert "actions/checkout@v4" not in workflow
    assert "actions/setup-python@v5" not in workflow
    assert "actions/upload-artifact@v4" not in workflow
    assert "actions/download-artifact@v4" not in workflow


def test_excel_restore_is_separate_and_manual_only() -> None:
    workflow = (ROOT / ".github/workflows/restore-excel.yml").read_text(encoding="utf-8")
    assert "workflow_dispatch:" in workflow
    assert "push:" not in workflow
    assert "RESTORE_EXCEL" in workflow
    assert "production-max-excel-recovery" in workflow
    assert "MAX_RECOVERY_SSH_PRIVATE_KEY" in workflow
    assert "MAX_DEPLOY_SSH_PRIVATE_KEY" not in workflow


def test_no_workflow_keeps_legacy_password_deployment() -> None:
    for path in (ROOT / ".github/workflows").glob("*.yml"):
        workflow = path.read_text(encoding="utf-8")
        assert "sshpass" not in workflow, path
        assert "SERVER_PASSWORD" not in workflow, path
        assert "contents: write" not in workflow, path


def test_deployer_cannot_read_application_secrets() -> None:
    deployer = (ROOT / "ops/bin/avr-max-deploy").read_text(encoding="utf-8")
    assert "BACKUP_ENV=/etc/avr-max-deploy.env" in deployer
    assert '--env-file "$BACKUP_ENV"' in deployer
    assert '--env-file "$APP_ROOT/.env"' not in deployer
    assert 'exec 9>"$INCOMING_DIR/deploy.lock"' in deployer
    assert 'exec 9>"$APP_ROOT/.deploy/deploy.lock"' not in deployer


@pytest.mark.skipif(os.name == "nt", reason="bash validation runs on Linux and GitHub Actions")
def test_operations_shell_scripts_parse() -> None:
    for script in (
        ROOT / "ops/bin/avr-max-deploy",
        ROOT / "ops/sbin/avr-max-restart",
        ROOT / "ops/sbin/avr-max-excel-restore",
    ):
        subprocess.run(["bash", "-n", str(script)], check=True)


def test_newest_backup_must_be_complete_and_fresh(tmp_path: Path) -> None:
    old = tmp_path / "20261011T120000Z"
    fresh = tmp_path / "20261013T120000Z"
    make_workbook(old / "report.xlsx", "old report")
    make_workbook(old / "payroll.xlsx", "old payroll")
    make_workbook(fresh / "report.xlsx", "fresh report")
    make_workbook(fresh / "payroll.xlsx", "fresh payroll")

    selected = newest_complete_backup(tmp_path, {"report.xlsx", "payroll.xlsx"}, 72)
    assert selected == fresh


def test_backup_creation_copies_and_validates_both_workbooks(tmp_path: Path) -> None:
    live = tmp_path / "live"
    report = live / "report.xlsx"
    payroll = live / "payroll.xlsx"
    make_workbook(report, "report")
    make_workbook(payroll, "payroll")
    env_file = tmp_path / ".env"
    env_file.write_text(
        f"EXCEL_FILE_PATH={report}\nPAYROLL_FILE_PATH={payroll}\n",
        encoding="utf-8",
    )

    created = create_backup(env_file, tmp_path / "backups")

    assert read_a1(created / report.name) == "report"
    assert read_a1(created / payroll.name) == "payroll"


def test_stale_backup_blocks_deployment(tmp_path: Path) -> None:
    backup = tmp_path / "20261001T120000Z"
    make_workbook(backup / "report.xlsx", "report")
    make_workbook(backup / "payroll.xlsx", "payroll")
    stale = 100 * 3600
    os.utime(backup, (backup.stat().st_atime - stale, backup.stat().st_mtime - stale))

    with pytest.raises(RuntimeError, match="maximum allowed age"):
        newest_complete_backup(tmp_path, {"report.xlsx", "payroll.xlsx"}, 72)


def test_manual_restore_uses_selected_backup_and_keeps_safety_copy(tmp_path: Path) -> None:
    live = tmp_path / "live"
    report = live / "report.xlsx"
    payroll = live / "payroll.xlsx"
    make_workbook(report, "current report")
    make_workbook(payroll, "current payroll")

    selected = tmp_path / "backups/20261013T120000Z"
    make_workbook(selected / report.name, "restored report")
    make_workbook(selected / payroll.name, "restored payroll")
    env_file = tmp_path / ".env"
    env_file.write_text(
        f"EXCEL_FILE_PATH={report}\nPAYROLL_FILE_PATH={payroll}\n",
        encoding="utf-8",
    )

    safety = restore(selected, env_file, live, selected.parent)

    assert read_a1(report) == "restored report"
    assert read_a1(payroll) == "restored payroll"
    assert read_a1(safety / report.name) == "current report"
    assert read_a1(safety / payroll.name) == "current payroll"
