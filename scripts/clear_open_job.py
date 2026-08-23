"""
Manually remove a stuck plate from open-job tracking.

Use this when a job was actually completed but never got a message
the AI recognized as "заявка закрыта" — e.g. it was closed with an
unusual phrasing, reported verbally, or the closing message fell
outside every 24h fetch window (a missed run) and can never be picked
up automatically.

Usage:
    python scripts/clear_open_job.py                  # list all tracked plates
    python scripts/clear_open_job.py С856ТВ797         # clear one plate
    python scripts/clear_open_job.py --all             # clear everything (asks to confirm)
"""
import sys
from pathlib import Path

from dotenv import load_dotenv

load_dotenv()

sys.path.insert(0, str(Path(__file__).parent.parent))

from webhook.config import settings
from webhook.open_jobs_tracker import OpenJobsTracker


def main() -> None:
    if not settings.max_chat_id:
        sys.exit("ERROR: MAX_CHAT_ID is not set in .env")

    tracker = OpenJobsTracker(settings.max_chat_id)
    open_jobs = tracker.list_open_jobs()

    args = sys.argv[1:]

    if not args:
        if not open_jobs:
            print("Нет отслеживаемых незакрытых заявок.")
            return
        print(f"Сейчас отслеживается {len(open_jobs)} заявок:\n")
        for job in open_jobs:
            print(f"  {job['plate']}  (excerpt: {job.get('excerpt', '')[:60]})")
        print("\nЧтобы снять с отслеживания: python scripts/clear_open_job.py <НОМЕР>")
        return

    if args[0] == "--all":
        if not open_jobs:
            print("Нечего снимать — список пуст.")
            return
        confirm = input(f"Снять с отслеживания ВСЕ {len(open_jobs)} заявок? (yes/нет): ")
        if confirm.strip().lower() not in ("yes", "да"):
            print("Отменено.")
            return
        for job in open_jobs:
            tracker.mark_closed(job["plate"])
        tracker.save()
        print(f"Снято с отслеживания: {len(open_jobs)} заявок.")
        return

    plate = args[0]
    before = len(tracker.list_open_jobs())
    tracker.mark_closed(plate)
    tracker.save()
    after = len(tracker.list_open_jobs())

    if after < before:
        print(f"Заявка {plate} снята с отслеживания. Напоминаний по ней больше не будет.")
    else:
        print(f"Заявка {plate} не найдена в списке отслеживаемых (проверь номер).")


if __name__ == "__main__":
    main()
