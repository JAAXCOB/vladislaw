import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
WORKER = (ROOT / "site/patches/9.0/worker.php").read_text(encoding="utf-8")
ROLE_CSS = (ROOT / "site/patches/9.0/assets/avr-role-ui.css").read_text(encoding="utf-8")


class DriverWebUiContractTests(unittest.TestCase):
    def test_same_order_tabs_are_available_on_web_and_iphone_pwa(self):
        for label in ("Свободные", "Назначенные", "Закрытые"):
            self.assertIn(label, WORKER)
        self.assertIn("@media(display-mode:standalone)", WORKER)
        self.assertIn("env(safe-area-inset-bottom)", WORKER)

    def test_driver_workflow_uses_one_clear_primary_action(self):
        for label in (
            "Начать маршрут к точке А",
            "Я на месте",
            "Фото автомобиля и погрузка",
            "Погрузка завершена — к точке Б",
            "Завершить заявку",
        ):
            self.assertIn(label, WORKER)
        self.assertIn("workPrimary", WORKER)

    def test_yandex_route_prefers_saved_coordinates(self):
        self.assertIn("?rtext=~${encodeURIComponent(lat+','+lng)}&rtt=auto", WORKER)
        self.assertIn("routeStop(o.destination,true)", WORKER)

    def test_shared_role_ui_covers_all_workspaces(self):
        pages = {
            "role-driver": ROOT / "site/patches/9.0/worker.php",
            "role-admin": ROOT / "site/patches/9.0/admin.php",
            "role-dispatcher": ROOT / "site/patches/8.9/dispatcher.php",
            "role-client": ROOT / "site/patches/9.0/account.php",
            "role-b2b": ROOT / "site/patches/9.0/b2b.php",
        }
        for role, path in pages.items():
            source = path.read_text(encoding="utf-8")
            self.assertIn(role, source)
            self.assertIn("/assets/avr-role-ui.css?v=325", source)
        self.assertIn("min-height:48px", ROLE_CSS)
        self.assertIn("prefers-reduced-motion", ROLE_CSS)


if __name__ == "__main__":
    unittest.main()
