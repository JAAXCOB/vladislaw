"""
Phase 3 — write extracted job data into the existing Excel file.

Finds the correct monthly sheet by message date, appends one row:
  Дата | VIN/Гос.номер ТС | Услуга | Сумма
"""
from __future__ import annotations

import logging
from copy import copy
from datetime import datetime, timezone, timedelta
from pathlib import Path

import openpyxl
from openpyxl.styles import Alignment, Font, PatternFill
from openpyxl.worksheet.worksheet import Worksheet

from webhook.schema import ExtractedJob

log = logging.getLogger("max_webhook.excel")

MOSCOW_TZ = timezone(timedelta(hours=3))

# Matches sheet names in the real file.
MONTH_NAMES = {
    1: "Январь",
    2: "Февраль",
    3: "Март",
    4: "Апрель",
    5: "Май",
    6: "Июнь",
    7: "Июль",
    8: "Август",
    9: "Сентябрь",
    10: "Октябрь",
    11: "Ноябрь",
    12: "Декабрь",
}

# Matches the existing sheet's cell style (Calibri 11, centered, mm-dd-yy dates)
DEFAULT_FONT = Font(name="Calibri", size=11)
CENTER_ALIGN = Alignment(horizontal="center")
DATE_FORMAT = "mm-dd-yy"

# Light fill for rows that need human re-checking (needs_review=True)
REVIEW_FILL = PatternFill(start_color="FFF2CC", end_color="FFF2CC", fill_type="solid")


def _first_empty_row(ws: Worksheet, key_column: int = 1) -> int:
    """
    Returns the first row (below the header) whose key_column cell is empty.

    ws.max_row / ws.append() can't be trusted here: openpyxl counts a row
    as "used" if it ever had a value or style applied, even after the
    value was manually cleared in Excel. That leaves stale formatting
    far below the real data and makes append() start writing hundreds of
    rows past the actual empty area. Scanning for a genuinely empty cell
    finds the real gap instead.
    """
    row = 2
    while ws.cell(row=row, column=key_column).value is not None:
        row += 1
    return row


def _sheet_name(dt: datetime) -> str:
    """Return sheet name for a given date, e.g. 'Август 26'."""
    month = MONTH_NAMES[dt.month]
    year = str(dt.year)[2:]
    return f"{month} {year}"


def _get_or_create_sheet(
    wb: openpyxl.Workbook,
    sheet_name: str,
    dt: datetime,
    path: Path,
) -> Worksheet:
    """Reuse the current month or create it from the previous month header."""
    existing = next((name for name in wb.sheetnames if name.casefold() == sheet_name.casefold()), None)
    if existing is not None:
        return wb[existing]

    month_only = MONTH_NAMES[dt.month].casefold()
    existing = next((name for name in wb.sheetnames if name.casefold() == month_only), None)
    if existing is not None:
        return wb[existing]

    previous_month = 12 if dt.month == 1 else dt.month - 1
    previous_year = dt.year - 1 if dt.month == 1 else dt.year
    previous_name = f"{MONTH_NAMES[previous_month]} {str(previous_year)[2:]}"
    template_name = next(
        (name for name in wb.sheetnames if name.casefold() == previous_name.casefold()),
        None,
    )
    if template_name is None:
        raise ValueError(f"Previous monthly sheet '{previous_name}' not found in Excel file")

    template = wb[template_name]
    ws = wb.create_sheet(sheet_name)
    for col_idx in range(1, max(4, template.max_column) + 1):
        source = template.cell(row=1, column=col_idx)
        target = ws.cell(row=1, column=col_idx)
        target.value = source.value
        if source.has_style:
            target.font = copy(source.font)
            target.alignment = copy(source.alignment)
            target.fill = copy(source.fill)
            target.border = copy(source.border)
            target.number_format = source.number_format
            target.protection = copy(source.protection)
    for letter, dimension in template.column_dimensions.items():
        ws.column_dimensions[letter].width = dimension.width
    ws.freeze_panes = template.freeze_panes
    log.info("Created monthly sheet '%s' from '%s' in %s", sheet_name, template_name, path.name)
    return ws



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


def _date_value(value: object):
    """Return a date for Excel date/datetime values, otherwise unchanged."""
    if isinstance(value, datetime):
        return value.date()
    return value


def _find_duplicate_row(
    ws: Worksheet,
    job_date,
    plate: str,
    amount: int | None,
) -> int | None:
    """Find an already written row so a recovery import stays duplicate-safe."""
    for row in range(2, ws.max_row + 1):
        if (
            _date_value(ws.cell(row=row, column=1).value) == job_date
            and str(ws.cell(row=row, column=2).value or "").strip().upper() == plate.strip().upper()
            and ws.cell(row=row, column=4).value == amount
        ):
            return row
    return None


def append_job(
    excel_path: str | Path,
    job: ExtractedJob,
    message_timestamp_ms: int,
    original_text: str = "",
) -> tuple[str, bool]:
    """
    Append one row to the appropriate monthly sheet, chosen automatically
    from the message date (e.g. a message on Sep 1 goes to 'Сентябрь 26'
    even if the previous message was in 'Август 26').

    Returns (sheet_name, inserted). inserted=False means the same row was
    already present and was not written again.
    """
    path = Path(excel_path)
    if not path.exists():
        raise FileNotFoundError(f"Excel file not found: {path}")

    dt = datetime.fromtimestamp(message_timestamp_ms / 1000, tz=MOSCOW_TZ)
    sheet_name = _sheet_name(dt)

    wb = openpyxl.load_workbook(path)
    ws = _get_or_create_sheet(wb, sheet_name, dt, path)

    headers = [str(ws.cell(1, col).value or "").strip().casefold() for col in range(1, 5)]\n    expected = ["дата", "vin/гос.номер тс", "услуга", "сумма"]\n    if headers != expected:\n        raise ValueError(f"Wrong workbook configured as evacuation report: {path.name}")\n\n    plate = job.license_plate or f"[НЕТ НОМЕРА] {original_text[:30]}"
    service_text = _format_services(job) or original_text[:60]
    amount = job.total_amount_rub

    duplicate_row = _find_duplicate_row(ws, dt.date(), plate, amount)
    if duplicate_row is not None:
        log.info(
            "EXCEL DUPLICATE SKIPPED | sheet='%s' | row=%d | plate=%s | amount=%s",
            sheet_name,
            duplicate_row,
            plate,
            amount,
        )
        return sheet_name, False

    target_row = _first_empty_row(ws)
    row = [dt.date(), plate, service_text, amount]
    for col_idx, value in enumerate(row, 1):
        ws.cell(row=target_row, column=col_idx).value = value

    # Apply the same style as existing rows: Calibri 11, centered, mm-dd-yy dates
    for col in range(1, 5):
        cell = ws.cell(row=target_row, column=col)
        cell.font = DEFAULT_FONT
        cell.alignment = CENTER_ALIGN
        if job.needs_review:
            cell.fill = REVIEW_FILL

    ws.cell(row=target_row, column=1).number_format = DATE_FORMAT

    wb.save(path)

    log.info(
        "EXCEL | sheet='%s' | row=%d | plate=%s | amount=%s | review=%s",
        sheet_name,
        target_row,
        plate,
        amount,
        job.needs_review,
    )

    return sheet_name, True
