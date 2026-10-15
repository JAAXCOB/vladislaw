import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[1]
APP = ROOT / "AVRescueAndroid" / "app"
JAVA = APP / "src" / "main" / "java" / "ru" / "avrescue" / "app"


class DriverReleaseContractTest(unittest.TestCase):
    def read(self, path):
        return path.read_text(encoding="utf-8")

    def test_release_identity_advances_rustore_build(self):
        gradle = self.read(APP / "build.gradle")
        self.assertIn("versionCode 324", gradle)
        self.assertIn("versionName '3.24'", gradle)
        self.assertIn("applicationId 'ru.avrescue.app'", gradle)

    def test_driver_login_uses_driver_mode_and_proxy_token_fallback(self):
        login = self.read(JAVA / "LoginActivity.java")
        api = self.read(JAVA / "ApiClient.java")
        self.assertIn('.put("app_mode","driver")', login)
        self.assertIn('setRequestProperty("Authorization","Bearer "+token)', api)
        self.assertIn('setRequestProperty("X-AVR-Token",token)', api)

    def test_driver_workflow_is_visible(self):
        dashboard = self.read(JAVA / "DashboardActivity.java")
        details = self.read(JAVA / "OrderDetailActivity.java")
        for label in ("Свободные", "Назначенные", "Закрытые"):
            self.assertIn(label, dashboard)
        for label in (
            "Начать маршрут к точке А",
            "Я на месте",
            "Погрузка завершена — к точке Б",
            "Завершить заявку",
        ):
            self.assertIn(label, details)

    def test_point_b_route_prefers_exact_address(self):
        dashboard = self.read(JAVA / "DashboardActivity.java")
        details = self.read(JAVA / "OrderDetailActivity.java")
        for source in (dashboard, details):
            self.assertIn("openDestinationMap", source)
            self.assertIn('rtext=~"+Uri.encode(exact)', source)


if __name__ == "__main__":
    unittest.main()
