from pathlib import Path
from zipfile import ZipFile


ROOT = Path(__file__).resolve().parents[1]


def test_legacy_driver_login_fallback_is_unambiguous():
    source = (ROOT / "site/patches/9.0/api/mobile_login.php").read_text(encoding="utf-8")
    assert "function av_mobile_legacy_worker_for_user" in source
    assert "linkedUserId!==''&&$linkedUserId!==$userId" in source
    assert "count($matches)===1?$matches[0]:null" in source
    assert "$login,'executor',(string)$w['id']" in source


def test_own_fleet_feed_has_no_radius_filter_and_keeps_distance_sorting():
    source = (ROOT / "site/patches/9.0/partner_orders_helpers.php").read_text(encoding="utf-8")
    assert "function av_partner_distribution_radius_km" in source
    radius_body = source.split("function av_partner_distribution_radius_km", 1)[1].split("function av_partner_distribution_stage", 1)[0]
    assert "return null;" in radius_body
    assert "return 'own_fleet_unlimited';" in source
    assert "$withinRadius=true;" in source
    assert "if($ad!==null&&$bd!==null&&$ad!=$bd)return $ad<=>$bd;" in source


def test_driver_240_archive_contains_required_ux_and_auth_contract():
    archive = ROOT / "android/AVR_Driver_Source_2.4.0.zip"
    assert archive.is_file()
    with ZipFile(archive) as zipped:
        dashboard = zipped.read(
            "AVRescueAndroid/app/src/main/java/ru/avrescue/app/DashboardActivity.java"
        ).decode("utf-8")
        login = zipped.read(
            "AVRescueAndroid/app/src/main/java/ru/avrescue/app/LoginActivity.java"
        ).decode("utf-8")
        detail = zipped.read(
            "AVRescueAndroid/app/src/main/java/ru/avrescue/app/OrderDetailActivity.java"
        ).decode("utf-8")
        ui = zipped.read(
            "AVRescueAndroid/app/src/main/java/ru/avrescue/app/Ui.java"
        ).decode("utf-8")
        build = zipped.read("AVRescueAndroid/app/build.gradle").decode("utf-8")

    for label in ("Свободные", "Назначенные", "Закрытые"):
        assert label in dashboard
    assert 'put("app_mode","driver")' in login
    assert "ApiClient.errorMessage" in login
    assert "← Назад к заявкам" in detail
    assert "Маршрут к точке А" in detail
    assert "Я на месте" in detail
    assert "Маршрут к точке Б" in detail
    assert "openDestinationMap(destinationLat,destinationLng,destination)" in dashboard
    assert "if(toDestination)openDestinationMap(lat,lng,navAddress)" in detail
    assert '"yandexmaps://maps.yandex.ru/?rtext=~"+Uri.encode(exact)' in dashboard
    assert '"yandexmaps://maps.yandex.ru/?rtext=~"+Uri.encode(exact)' in detail
    assert "fixed bottom" not in detail.lower()
    assert "setPrimaryAction" in detail
    assert "Ui.workProgress" in detail
    assert "Ui.routePoint" in detail
    assert "primaryButton" in ui
    assert "workProgress" in ui
    assert "routePoint" in ui
    assert "Завершить заявку" in detail
    assert "versionName '2.4.0'" in build


def test_workflow_builds_driver_240():
    workflow = (ROOT / ".github/workflows/build-android.yml").read_text(encoding="utf-8")
    assert "android/AVR_Driver_Source_2.4.0.zip" in workflow
    assert "AVR_Driver_2.4.0.apk" in workflow
