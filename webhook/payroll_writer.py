"""
Writes each closed job into the payroll Excel file, placing the amount
into the correct employee's column.

Sheet layout observed in the real file:
  Дата | VIN/Гос.номер ТС | Услуга | <Сотрудник 1> | <Сотрудник 2> | ... | 750

Only ONE employee column is filled per row (the one who did the job);
all others stay blank. The last column's header is a plain number (750)
rather than a name — per explicit instruction, this column is never
touched by automation. To stay robust if more such columns are added
later, any header that isn't a string is treated as protected and
excluded from employee matching.
"""
from __future__ import annotations

import logging
from copy import copy
from datetime import datetime, timezone, timedelta
from pathlib import Path
from typing import Optional

import openpyxl
from openpyxl.styles import Alignment, Font, PatternFill
from openpyxl.worksheet.worksheet import Worksheet

from webhook.excel_writer import _date_value, _first_empty_row, _format_services
from webhook.schema import ExtractedJob

log = logging.getLogger("max_webhook.payroll")

MOSCOW_TZ = timezone(timedelta(hours=3))

# Each month has one shared payroll sheet. Employees are columns inside that
# sheet — never separate worksheets.
MONTH_NAMES = {
    1: "Январь", 2: "Февраль", 3: "Март", 4: "Апрель",
    5: "Май", 6: "Июнь", 7: "Июль", 8: "Август",
    9: "Сентябрь", 10: "Октябрь", 11: "Ноябрь", 12: "Декабрь",
}

FIXED_COLUMNS = {"Дата", "VIN/Гос.номер ТС", "Услуга", "Сумма"}

DEFAULT_FONT = Font(name="Calibri", size=11)
CENTER_ALIGN = Alignment(horizontal="center")
DATE_FORMAT = "mm-dd-yy"

# Light fill for rows where the employee couldn't be confidently matched
REVIEW_FILL = PatternFill(start_color="FFF2CC", end_color="FFF2CC", fill_type="solid")


class AmbiguousEmployeeError(Exception):
    """Raised when the sender name matches zero or multiple employee columns."""


def _sheet_name(dt: datetime) -> str:
    return MONTH_NAMES[dt.month]


def _get_or_create_sheet(wb: openpyxl.Workbook, sheet_name: str, path: Path) -> Worksheet:
    """
    Return one shared sheet for the requested month. If September does not
    exist yet, create exactly one "Сентябрь" sheet using August as its
    structure: same employee columns, header styles and column widths.
    """
    # Excel treats sheet names as case-insensitive. Match that behaviour so
    # an existing "сентябрь" sheet is reused instead of creating
    # "Сентябрь1", "Сентябрь2", etc. on repeated runs.
    existing_name = next(
        (name for name in wb.sheetnames if name.casefold() == sheet_name.casefold()),
        None,
    )
    if existing_name is not None:
        return wb[existing_name]

    month_number = next(number for number, name in MONTH_NAMES.items() if name == sheet_name)
    if month_number == 1:
        raise ValueError(f"Cannot create January payroll sheet automatically in {path.name}")

    previous_month_name = MONTH_NAMES[month_number - 1]
    previous_existing_name = next(
        (
            name
            for name in wb.sheetnames
            if name.casefold() == previous_month_name.casefold()
        ),
        None,
    )
    if previous_existing_name is None:
        raise ValueError(
            f"Previous payroll sheet '{previous_month_name}' not found in {path.name}"
        )

    template_ws = wb[previous_existing_name]
    ws = wb.create_sheet(sheet_name)

    for col_idx in range(1, template_ws.max_column + 1):
        src = template_ws.cell(row=1, column=col_idx)
        dst = ws.cell(row=1, column=col_idx)
        dst.value = src.value
        if src.has_style:
            dst.font = copy(src.font)
            dst.alignment = copy(src.alignment)
            dst.fill = copy(src.fill)
            dst.border = copy(src.border)
            dst.number_format = src.number_format

    for col_letter, col_dim in template_ws.column_dimensions.items():
        ws.column_dimensions[col_letter].width = col_dim.width

    log.info(
        "Created shared payroll sheet '%s' from '%s' in %s",
        sheet_name,
        previous_month_name,
        path.name,
    )
    return ws


def _employee_columns(ws: Worksheet) -> dict[int, str]:
    """
    Maps column index -> employee display name, for header cells that are
    text (skips Дата/VIN/Услуга and any numeric-header column like 750).
    """
    columns: dict[int, str] = {}
    for col_idx in range(1, ws.max_column + 1):
        header = ws.cell(row=1, column=col_idx).value
        if isinstance(header, str) and header.strip() and header.strip() not in FIXED_COLUMNS:
            columns[col_idx] = header.strip()
    return columns


def _match_employee_column(ws: Worksheet, employee_name: str) -> Optional[int]:
    """
    Finds the single column whose header matches employee_name.
    Returns None if there is no match or more than one — callers must
    never guess when it's ambiguous, this is payroll data.
    """
    if not employee_name:
        return None

    name_lower = " ".join(employee_name.strip().casefold().split())
    candidates = _employee_columns(ws)

    # Prefer the most specific complete header found in the MAX display name.
    # This separates employees who share a first name.
    full_name_matches = [
        (col, header)
        for col, header in candidates.items()
        if " ".join(header.casefold().split()) == name_lower
        or " ".join(header.casefold().split()) in name_lower
    ]
    if full_name_matches:
        longest = max(len(header.split()) for _, header in full_name_matches)
        most_specific = [
            col for col, header in full_name_matches if len(header.split()) == longest
        ]
        if len(most_specific) == 1:
            return most_specific[0]
        return None

    # Short aliases are accepted only when they identify one column.
    # A shared bare first name such as "Николай" remains ambiguous.
    exact_matches = [
        col for col, header in candidates.items()
        if name_lower in [w.casefold() for w in header.split()]
    ]
    if len(exact_matches) == 1:
        return exact_matches[0]

    if not exact_matches:
        substring_matches = [
            col for col, header in candidates.items()
            if name_lower in header.casefold() or header.casefold() in name_lower
        ]
        if len(substring_matches) == 1:
            return substring_matches[0]

    return None


def ensure_employee_column(
    payroll_path: str | Path,
    employee_name: str,
    at: datetime | None = None,
) -> tuple[str, int, bool]:
    """Ensure that the shared current-month sheet has one employee column.

    The new column is appended at the end so existing cells, formulas and
    historical payroll rows are never shifted. Repeated calls are safe.
    Returns (sheet_name, column_index, created).
    """
    path = Path(payroll_path)
    if not path.exists():
        raise FileNotFoundError(f"Payroll file not found: {path}")

    normalized_name = (employee_name or "").strip()
    if not normalized_name:
        raise ValueError("Employee name cannot be empty")

    dt = at.astimezone(MOSCOW_TZ) if at is not None else datetime.now(MOSCOW_TZ)
    sheet_name = _sheet_name(dt)
    wb = openpyxl.load_workbook(path)
    ws = _get_or_create_sheet(wb, sheet_name, path)

    matches = [
        col
        for col, header in _employee_columns(ws).items()
        if header.casefold() == normalized_name.casefold()
    ]
    if len(matches) > 1:
        raise ValueError(
            f"Duplicate employee columns for '{normalized_name}' in sheet '{sheet_name}'"
        )
    if matches:
        return sheet_name, matches[0], False

    employee_columns = _employee_columns(ws)
    if not employee_columns:
        raise ValueError(f"Wrong workbook configured as payroll report: {path.name}")

    source_col = max(employee_columns)
    target_col = ws.max_column + 1
    source = ws.cell(row=1, column=source_col)
    target = ws.cell(row=1, column=target_col)
    target.value = normalized_name
    if source.has_style:
        target.font = copy(source.font)
        target.alignment = copy(source.alignment)
        target.fill = copy(source.fill)
        target.border = copy(source.border)
        target.number_format = source.number_format

    source_letter = source.column_letter
    target_letter = target.column_letter
    ws.column_dimensions[target_letter].width = ws.column_dimensions[source_letter].width
    wb.save(path)

    # Verify the persisted workbook before reporting success.
    check_wb = openpyxl.load_workbook(path, read_only=True, data_only=False)
    check_ws = check_wb[sheet_name]
    saved_matches = [
        col
        for col in range(1, check_ws.max_column + 1)
        if isinstance(check_ws.cell(row=1, column=col).value, str)
        and check_ws.cell(row=1, column=col).value.strip().casefold()
        == normalized_name.casefold()
    ]
    check_wb.close()
    if saved_matches != [target_col]:
        raise RuntimeError(
            f"Employee column verification failed for '{normalized_name}' in '{sheet_name}'"
        )

    log.info(
        "Added payroll employee column | sheet='%s' | column=%d | employee=%s",
        sheet_name,
        target_col,
        normalized_name,
    )
    return sheet_name, target_col, True


def _find_duplicate_row(
    ws: Worksheet,
    job_date,
    plate: str,
    service_text: str,
    matched_col: Optional[int],
    amount: Optional[int],
) -> int | None:
    """Find an existing payroll row before a recovery import writes it again."""
    for row in range(2, ws.max_row + 1):
        same_date_and_plate = (
            _date_value(ws.cell(row=row, column=1).value) == job_date
            and str(ws.cell(row=row, column=2).value or "").strip().upper() == plate.strip().upper()
        )
        if not same_date_and_plate:
            continue
        if matched_col is not None and ws.cell(row=row, column=matched_col).value == amount:
            return row
        if (
            matched_col is None
            and str(ws.cell(row=row, column=3).value or "").strip().lower()
            == service_text.strip().lower()
        ):
            return row
    return None


def append_salary_row(
    payroll_path: str | Path,
    job: ExtractedJob,
    message_timestamp_ms: int,
    employee_name: str,
    original_text: str = "",
) -> tuple[str, bool, bool]:
    """
    Appends one row to the shared monthly payroll sheet: Дата/VIN/Услуга
    are always filled, the amount is placed under the matched employee's
    column only if the match is unambiguous. All employees stay on the same
    worksheet as separate columns.

    Returns (sheet_name, matched, inserted) — matched=False means the row was
    written but no employee column could be confidently identified, so
    the amount cell was left blank for manual entry. inserted=False means
    the same row already existed and was not written again.
    """
    path = Path(payroll_path)
    if not path.exists():
        raise FileNotFoundError(f"Payroll file not found: {path}")

    dt = datetime.fromtimestamp(message_timestamp_ms / 1000, tz=MOSCOW_TZ)
    sheet_name = _sheet_name(dt)

    wb = openpyxl.load_workbook(path)
    ws = _get_or_create_sheet(wb, sheet_name, path)

    if not _employee_columns(ws):
        raise ValueError(f"Wrong workbook configured as payroll report: {path.name}")

    plate = job.license_plate or f"[НЕТ НОМЕРА] {original_text[:30]}"
    service_text = _format_services(job) or original_text[:60]
    amount = job.total_amount_rub

    matched_col = _match_employee_column(ws, employee_name) if amount is not None else None

    duplicate_row = _find_duplicate_row(
        ws, dt.date(), plate, service_text, matched_col, amount
    )
    if duplicate_row is not None:
        matched = matched_col is not None
        log.info(
            "PAYROLL DUPLICATE SKIPPED | sheet='%s' | row=%d | plate=%s | employee=%s",
            sheet_name,
            duplicate_row,
            plate,
            employee_name,
        )
        return sheet_name, matched, False

    row_values = [None] * ws.max_column
    row_values[0] = dt.date()
    row_values[1] = plate
    row_values[2] = service_text
    if matched_col is not None:
        row_values[matched_col - 1] = amount

    target_row = _first_empty_row(ws)
    for col_idx, value in enumerate(row_values, 1):
        ws.cell(row=target_row, column=col_idx).value = value

    matched = matched_col is not None

    for col in range(1, ws.max_column + 1):
        cell = ws.cell(row=target_row, column=col)
        cell.font = DEFAULT_FONT
        cell.alignment = CENTER_ALIGN
        if not matched:
            cell.fill = REVIEW_FILL
    ws.cell(row=target_row, column=1).number_format = DATE_FORMAT

    wb.save(path)

    log.info(
        "PAYROLL | sheet='%s' | row=%d | plate=%s | employee=%s | amount=%s | matched=%s",
        sheet_name, target_row, plate, employee_name, amount, matched,
    )

    return sheet_name, matched, True
