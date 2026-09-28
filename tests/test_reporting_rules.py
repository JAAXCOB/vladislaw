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


def test_bare_blocks_amount_is_price_not_thirteen_blocks():
    job = parse_explicit_closed_report(
        "Заявка закрыта о467нс797 эвакуация 4500 + блоки 1300"
    )
    assert job is not None
    assert job.total_amount_rub == 5800
    assert [(item.name, item.price_rub) for item in job.services] == [
        ("Эвакуация", 4500),
        ("Блоки", 1300),
    ]


def test_road_pull_is_standalone_service_with_explicit_amount():
    job = parse_explicit_closed_report(
        "Заявка закрыта А123ВС797 вытаскивание на дорожное полотно 14000"
    )
    assert job is not None
    assert job.total_amount_rub == 14000
    assert job.services[0].name == "Вытаскивание на дорожное полотно"


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
    assert employee_header("Максим Шмэкс") == "Максим Шмэкс"
    assert employee_header("Бодров Максим") == "Бодров Максим"
    # A bare shared first name is intentionally not assigned to either driver.
    assert employee_header("Максим") == "Максим"
    assert employee_header("Антон") == "Буревич Антон"
    assert employee_header("Буревич Антон") == "Буревич Антон"
    assert employee_header("Anton") == "Буревич Антон"
    assert employee_header("Вадим") == "Вадим Водитель"
    assert employee_header("Vadim Novikov") == "Вадим Водитель"
    assert employee_header("Новиков Вадим") == "Вадим Водитель"
