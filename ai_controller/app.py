from __future__ import annotations

import logging
from contextlib import asynccontextmanager

from fastapi import FastAPI, Response, status

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

    application = FastAPI(title="AVR AI Controller", version="0.1.0", lifespan=lifespan)
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

    return application
