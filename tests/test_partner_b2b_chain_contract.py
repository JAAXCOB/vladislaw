import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
HELPERS = (ROOT / "site/patches/9.0/partner_orders_helpers.php").read_text(encoding="utf-8")
WORKER = (ROOT / "site/patches/9.0/worker.php").read_text(encoding="utf-8")


class PartnerB2BChainContractTests(unittest.TestCase):
    def test_max_b2b_sla_is_twelve_hours(self):
        self.assertIn("define('AV_PARTNER_PRIORITY_AGE_SECONDS', 12*60*60);", HELPERS)
        self.assertIn("function av_partner_order_deadline_timestamp", HELPERS)
        self.assertIn("'deadline_seconds_left'", HELPERS)

    def test_priority_order_does_not_globally_block_chain(self):
        self.assertNotIn("$priorityAllowed", HELPERS)
        self.assertIn("'claim_blocked'=>false", HELPERS)
        self.assertIn("if($status==='available'&&$withinRadius)$canClaim=true;", HELPERS)

    def test_chain_prefers_deadline_then_route_progress(self):
        self.assertIn("function av_partner_chain_target", HELPERS)
        self.assertIn("'chain_progress_km'", HELPERS)
        self.assertIn("'chain_rank'", HELPERS)
        self.assertLess(
            HELPERS.index("$as=$a['deadline_seconds_left']"),
            HELPERS.index("$ap=$a['chain_progress_km']"),
        )

    def test_feed_remains_own_fleet_only(self):
        self.assertIn("!av_worker_is_own_fleet($worker)", HELPERS)
        self.assertIn("Доступно только нашему парку", WORKER)

    def test_driver_sees_deadline_and_chain_progress(self):
        self.assertIn("До предельного срока", WORKER)
        self.assertIn("Приоритет до 12 часов", WORKER)
        self.assertIn("приблизит", WORKER)


if __name__ == "__main__":
    unittest.main()
