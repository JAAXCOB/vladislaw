from webhook.reporting_rules import employee_header, parse_explicit_closed_report, report_is_writable


def test_structured_close_sums_all_components():
    job = parse_explicit_closed_report(
        "Заявка закрыта х879тк797 эвакуация 4500+ бустер 5000+ "
        "2 блока 1300+ 1км до МКАД и 1от МКАД 180"
    )
    assert job is not None
    assert job.license_plate == "Х879ТК797"
    assert job.total_amount_rub == 10980
    assert report_is_writable(job)


def test_missing_block_price_uses_partner_rate():
    job = parse_explicit_closed_report("Заявка закрыта о467нс797 эвакуация 4500 + 1блок")
    assert job is not None
    assert job.total_amount_rub == 5150


def test_distance_only_close_includes_base_rate():
    job = parse_explicit_closed_report("Заявка закрыта Geely | Н957ОР797 | От МКАД 1 км. 90 руб.")
    assert job is not None
    assert job.total_amount_rub == 4590


def test_bot_only_and_bare_close_do_not_enter_excel():
    assert parse_explicit_closed_report("Закрыта") is None
    assert parse_explicit_closed_report("Заявка закрыта О948РК797 (для бота)") is None


def test_employee_aliases_match_payroll_headers():
    assert employee_header("Валера") == "Баранов Валерий Валера"
    assert employee_header("G") == "Баширов Гоша G"
    assert employee_header("Шмэкс") == "Максим Шмэкс"
    assert employee_header("Антон") == "Буревич Антон"
    assert employee_header("Буревич Антон") == "Буревич Антон"
    assert employee_header("Anton") == "Буревич Антон"
