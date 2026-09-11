from webhook import open_jobs_tracker as tracker_module
from webhook.open_jobs_tracker import OpenJobsTracker


def test_mixed_latin_cyrillic_plate_is_closed(tmp_path, monkeypatch):
    monkeypatch.setattr(tracker_module, "STATE_PATH", tmp_path / "open_jobs_state.json")

    tracker = OpenJobsTracker("chat")
    tracker.start_run()
    tracker.register_new_job("E677TP797", "mid-1", "request")
    tracker.save()

    reloaded = OpenJobsTracker("chat")
    assert len(reloaded.list_open_jobs()) == 1
    reloaded.mark_closed("Е677ТР797")
    reloaded.save()

    assert OpenJobsTracker("chat").list_open_jobs() == []
