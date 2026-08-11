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
from openpyxl.styles import Alignment, Font

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

# Matches the existing sheet's cell style (Calibri 11, centered, mm-dd-yy dates)
DEFAULT_FONT = Font(name="Calibri", size=11)
CENTER_ALIGN = Alignment(horizontal="center")
DATE_FORMAT = "mm-dd-yy"


def _sheet_name(dt: datetime) -> str:
    """Return sheet name for a given date, e.g. 'Август 26'."""
    month = MONTH_NAMES[dt.month]
    year = str(dt.year)[2:]
    return f"{month} {year}"


def _format_services(job: ExtractedJob) -> str:
    """
    Format services list to match the existing sheet style, e.g.
    'Эвакуация + 25 км за МКАД' or 'Ложная подача'.
    No prices in this column — the total goes in the Сумма column.
    """
    if not job.services:
        return ""
    parts = [svc.name.strip().capitalize() for svc in job.services if svc.name.strip()]
    return " + ".join(parts)


def append_job(
    excel_path: str | Path,
    job: ExtractedJob,
    message_timestamp_ms: int,
    original_text: str = "",
) -> str:
    """
    Append one row to the appropriate monthly sheet, chosen automatically
    from the message date (e.g. a message on Sep 1 goes to 'Сентябро 26'
    even if the previous message was in 'Август 26').

    Returns the sheet name where the row was written.
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

    # Apply the same style as existing rows: Calibri 11, centered, mm-dd-yy dates
    last_row = ws.max_row
    for col in range(1, 5):
        cell = ws.cell(row=last_row, column=col)
        cell.font = DEFAULT_FONT
        cell.alignment = CENTER_ALIGN

    ws.cell(row=last_row, column=1).number_format = DATE_FORMAT

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
