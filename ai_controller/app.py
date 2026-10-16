from __future__ import annotations

import logging
from contextlib import asynccontextmanager

from fastapi import FastAPI, Header, HTTPException, Request, Response, status

from .config import ControllerSettings
from .service import ControllerService


def create_app(settings: ControllerSettings | None = None) -> FastAPI:
    resolved = settings or ControllerSettings()
    logging.basicConfig(level=getattr(logging, resolved.log_level.upper(), logging.INFO))
    service = ControllerService(resolved)

    @asynccontextmanager
    async def lifespan(_: FastAPI):
        await service.start()
        try:
            yield
        finally:
            await service.stop()

    application = FastAPI(title="AVR AI Controller", version="0.2.0", lifespan=lifespan)
    application.state.controller = service

    @application.get("/health")
    async def health(response: Response) -> dict[str, object]:
        snapshot = service.snapshot
        if snapshot.cycle == 0:
            response.status_code = status.HTTP_503_SERVICE_UNAVAILABLE
        return {
            "status": snapshot.overall.value,
            "cycle": snapshot.cycle,
            "paid_api_enabled": resolved.paid_api_enabled,
        }

    @application.get("/status")
    async def controller_status() -> dict[str, object]:
        return service.snapshot.model_dump(mode="json")

    @application.get("/budget")
    async def budget() -> dict[str, int | bool]:
        return service.budget.totals()

    @application.get("/audit/verify")
    async def verify_audit(response: Response) -> dict[str, object]:
        valid, records = service.audit.verify()
        if not valid:
            response.status_code = status.HTTP_503_SERVICE_UNAVAILABLE
        return {"valid": valid, "records": records}

    @application.post("/max-control/webhook")
    async def max_control_webhook(
        request: Request,
        x_max_bot_api_secret: str = Header(default=""),
    ) -> dict[str, bool]:
        if not resolved.max_control_enabled or resolved.max_control_transport != "webhook":
            raise HTTPException(status_code=status.HTTP_404_NOT_FOUND)
        if not service.max_control.accepts_webhook_secret(x_max_bot_api_secret):
            service.audit.append(
                "max_control_webhook", "unauthorized", "denied", {"secret_match": False}
            )
            raise HTTPException(status_code=status.HTTP_403_FORBIDDEN)
        try:
            update = await request.json()
        except ValueError as exc:
            raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="invalid JSON") from exc
        if not isinstance(update, dict):
            raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="invalid update")
        processed = await service.max_control.process_update(update)
        return {"ok": True, "processed": processed}

    return application
