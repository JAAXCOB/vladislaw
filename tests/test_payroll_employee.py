from datetime import datetime, timezone

import openpyxl

from webhook.payroll_writer import ensure_employee_column


def test_ensure_employee_column_is_idempotent_and_preserves_existing_data(tmp_path):
    payroll_path = tmp_path / "payroll.xlsx"
    workbook = openpyxl.Workbook()
    sheet = workbook.active
    sheet.title = "Сентябрь"
    sheet.append(["Дата", "VIN/Гос.номер ТС", "Услуга", "Макунин Павел", 750])
    sheet.append(["2026-09-10", "Е523ТТ797", "Эвакуация", 4500, None])
    workbook.save(payroll_path)

    at = datetime(2026, 9, 10, tzinfo=timezone.utc)
    first = ensure_employee_column(payroll_path, "Буревич Антон", at=at)
    second = ensure_employee_column(payroll_path, "Буревич Антон", at=at)

    assert first == ("Сентябрь", 6, True)
    assert second == ("Сентябрь", 6, False)

    saved = openpyxl.load_workbook(payroll_path)
    saved_sheet = saved["Сентябрь"]
    assert saved_sheet.cell(row=1, column=6).value == "Буревич Антон"
    assert saved_sheet.cell(row=2, column=2).value == "Е523ТТ797"
    assert saved_sheet.cell(row=2, column=4).value == 4500
    assert saved_sheet.max_column == 6
