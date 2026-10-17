from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SITE = ROOT / "site" / "patches" / "9.0"


def read(relative: str) -> str:
    return (SITE / relative).read_text(encoding="utf-8")


def test_public_status_is_read_only_and_does_not_require_staff_session() -> None:
    source = read("api/alfa_status.php")
    assert "av_require_staff" not in source
    assert "av_alfa_ready(true)" in source
    assert "alfa_password" not in source
    assert "alfa_token" not in source
    assert "acquiring_connected" in source


def test_live_gateway_is_locked_without_explicit_server_flag() -> None:
    source = read("alfa_acquiring.php")
    assert "AV_ALFA_LIVE_ALLOWED" in source
    assert "Боевой режим заблокирован конфигурацией сервера" in source
    assert "https://alfa.rbsuat.com/payment/rest/" in source
    assert "https://pay.alfabank.ru/payment/rest/" in source


def test_admin_and_api_reject_implicit_live_switch() -> None:
    admin = read("admin.php")
    settings = read("api/payment_settings.php")
    assert "av_alfa_live_allowed()" in admin
    assert "' — заблокирован'" in admin
    assert "av_alfa_live_allowed()" in settings
    assert "Сначала завершите тестирование" in settings


def test_bank_payment_is_verified_before_finance_posting() -> None:
    acquiring = read("alfa_acquiring.php")
    guard = read("finance_payment_guard.php")
    config = read("config.php")
    assert "av_alfa_verification($order,$data)" in acquiring
    assert "payment_verification" in acquiring
    for field in ("bank_order_id", "order_number", "currency", "amount_minor"):
        assert field in guard
    assert "av_verified_cashless_payment($order)" in config
    assert "storage_error" in config


def test_legacy_completion_routes_use_the_same_verified_finance_gate() -> None:
    update_order = read("api/update_order.php")
    settle_order = read("api/settle_order.php")
    assert "av_post_order_finance($orders[$k],$worker)" in update_order
    assert "av_post_order_finance($orders[$idx],$worker)" in settle_order
    assert "av_finance_record_order" not in update_order
    assert "av_ledger_add_once" not in update_order
    assert "av_finance_record_order" not in settle_order
    assert "av_ledger_add_once" not in settle_order
    assert "Безналичная оплата ещё не подтверждена банком" in settle_order


def test_expected_test_payment_operations_are_present() -> None:
    acquiring = read("alfa_acquiring.php")
    for method in (
        "register.do",
        "getOrderStatusExtended.do",
        "refund.do",
        "reverse.do",
    ):
        assert method in acquiring
    for endpoint in (
        "api/alfa_create_payment.php",
        "api/alfa_payment_status.php",
        "api/alfa_callback.php",
        "api/alfa_refund.php",
    ):
        assert (SITE / endpoint).is_file()


def test_sbp_stays_disabled_until_bank_confirmation() -> None:
    config = read("config.php")
    status = read("api/alfa_status.php")
    assert "'alfa_sbp_enabled'=>false" in config
    assert "alfa_sbp_enabled" in status
