"""
Phase 3 — write extracted job data into the existing Excel file.

Finds the correct monthly sheet by message date, appends one row:
  Дата | VIN/Гос.номер ТС | Услуга | Сумма
"""
from __future__ import annotations

import logging
from datetime import datetime, timezone, timedelta
from pathlib import Path

import openpyxl
from openpyxl.styles import PatternFill

from webhook.schema import ExtractedJob

log = logging.getLogger("max_webhook.excel")

MOSCOW_TZ = timezone(timedelta(hours=3))

# Matches sheet names in the real file (note: "Сентябро" is a typo in the original)
MONTH_NAMES = {
    1: "Январь",
    2: "Февраль",
    3: "Март",
    4: "Апрель",
    5: "Май",
    6: "Июнь",
    7: "Июль",
    8: "Август",
    9: "Сентябро",
    10: "Октябрь",
    11: "Ноябрь",
    12: "Декабрь",
}

# Yellow fill for rows that need human review
REVIEW_FILL = PatternFill(start_color="FFFF00", end_color="FFFF00", fill_type="solid")


def _sheet_name(dt: datetime) -> str:
    """Return sheet name for a given date, e.g. 'Август 26'."""
    month = MONTH_NAMES[dt.month]
    year = str(dt.year)[2:]
    return f"{month} {year}"


def _format_services(job: ExtractedJob) -> str:
    """Format services list into a single string like 'Эвакуация + подкаты'."""
    if not job.services:
        return ""
    parts = []
    for svc in job.services:
        name = svc.name.capitalize()
        if svc.price_rub is not None:
            parts.append(f"{name} {svc.price_rub}р")
        else:
            parts.append(name)
    return " + ".join(parts)


def append_job(
    excel_path: str | Path,
    job: ExtractedJob,
    message_timestamp_ms: int,
    original_text: str = "",
) -> str:
    """
    Append one row to the appropriate monthly sheet.

    Returns the sheet name where the row was written.
    Rows with needs_review=True are highlighted yellow.
    """
    path = Path(excel_path)
    if not path.exists():
        raise FileNotFoundError(f"Excel file not found: {path}")

    dt = datetime.fromtimestamp(message_timestamp_ms / 1000, tz=MOSCOW_TZ)
    sheet_name = _sheet_name(dt)

    wb = openpyxl.load_workbook(path)

    if sheet_name not in wb.sheetnames:
        log.warning("Sheet '%s' not found in %s — available: %s", sheet_name, path.name, wb.sheetnames)
        raise ValueError(f"Sheet '{sheet_name}' not found in Excel file")

    ws = wb[sheet_name]

    plate = job.license_plate or f"[НЕТ НОМЕРА] {original_text[:30]}"
    service_text = _format_services(job) or original_text[:60]
    amount = job.total_amount_rub

    row = [dt.date(), plate, service_text, amount]
    ws.append(row)

    # Highlight rows that need human review
    last_row = ws.max_row
    if job.needs_review:
        for col in range(1, 5):
            ws.cell(row=last_row, column=col).fill = REVIEW_FILL

    wb.save(path)

    log.info(
        "EXCEL | sheet='%s' | row=%d | plate=%s | amount=%s | review=%s",
        sheet_name,
        last_row,
        plate,
        amount,
        job.needs_review,
    )

    return sheet_name
