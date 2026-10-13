from __future__ import annotations

import asyncio
import time

import httpx

from .config import ControllerSettings
from .models import HealthState, ProbeResult


class ServiceMonitors:
    def __init__(self, settings: ControllerSettings, client: httpx.AsyncClient) -> None:
        self.settings = settings
        self.client = client

    async def _http_probe(self, name: str, url: str) -> ProbeResult:
        started = time.perf_counter()
        try:
            response = await self.client.get(url)
            latency = round((time.perf_counter() - started) * 1000)
            if response.status_code != 200:
                return ProbeResult(
                    name=name,
                    state=HealthState.unavailable,
                    latency_ms=latency,
                    detail=f"HTTP {response.status_code}",
                )
            return ProbeResult(name=name, state=HealthState.healthy, latency_ms=latency, detail="HTTP 200")
        except httpx.TimeoutException:
            return ProbeResult(name=name, state=HealthState.unavailable, detail="timeout")
        except httpx.RequestError as exc:
            return ProbeResult(name=name, state=HealthState.unavailable, detail=type(exc).__name__)

    async def site(self) -> ProbeResult:
        return await self._http_probe("site", self.settings.site_health_url)

    async def max_webhook(self) -> ProbeResult:
        return await self._http_probe("max", self.settings.max_health_url)

    async def osrm(self) -> ProbeResult:
        base = self.settings.osrm_base_url.rstrip("/")
        url = (
            f"{base}/nearest/v1/driving/"
            f"{self.settings.osrm_probe_lon},{self.settings.osrm_probe_lat}"
        )
        started = time.perf_counter()
        try:
            response = await self.client.get(url, params={"number": 1})
            latency = round((time.perf_counter() - started) * 1000)
            if response.status_code != 200:
                return ProbeResult(
                    name="osrm",
                    state=HealthState.unavailable,
                    latency_ms=latency,
                    detail=f"HTTP {response.status_code}",
                )
            payload = response.json()
            if payload.get("code") != "Ok":
                return ProbeResult(
                    name="osrm",
                    state=HealthState.degraded,
                    latency_ms=latency,
                    detail="unexpected OSRM response",
                )
            return ProbeResult(
                name="osrm",
                state=HealthState.healthy,
                latency_ms=latency,
                detail="nearest route point available",
            )
        except (httpx.RequestError, ValueError) as exc:
            return ProbeResult(name="osrm", state=HealthState.unavailable, detail=type(exc).__name__)

    async def run_all(self) -> dict[str, ProbeResult]:
        results = await asyncio.gather(self.site(), self.max_webhook(), self.osrm())
        return {item.name: item for item in results}
