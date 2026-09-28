"""Crash-safe local persistence primitives used by the webhook service."""
from __future__ import annotations

import json
import os
import inspect
import tempfile
from contextlib import contextmanager
from functools import wraps
from pathlib import Path
from typing import Any, Callable, Iterator, TypeVar

import openpyxl

try:  # pragma: no cover - Windows is supported for local operator scripts.
    import fcntl
except ImportError:  # pragma: no cover
    fcntl = None
    import msvcrt


@contextmanager
def file_lock(target: str | Path) -> Iterator[None]:
    """Hold an exclusive, cross-process lock associated with *target*."""
    path = Path(target)
    lock_path = path if path.suffix == ".lock" else path.with_name(path.name + ".lock")
    lock_path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    with lock_path.open("a+b") as handle:
        try:
            os.chmod(lock_path, 0o600)
        except OSError:
            pass
        if fcntl is not None:
            fcntl.flock(handle.fileno(), fcntl.LOCK_EX)
        else:  # pragma: no cover
            handle.seek(0)
            msvcrt.locking(handle.fileno(), msvcrt.LK_LOCK, 1)
        try:
            yield
        finally:
            if fcntl is not None:
                fcntl.flock(handle.fileno(), fcntl.LOCK_UN)
            else:  # pragma: no cover
                handle.seek(0)
                msvcrt.locking(handle.fileno(), msvcrt.LK_UNLCK, 1)


F = TypeVar("F", bound=Callable[..., Any])


def locked_path_argument(position: int = 0) -> Callable[[F], F]:
    """Serialize a function using the filesystem path at an argument position."""
    def decorate(function: F) -> F:
        parameter_names = list(inspect.signature(function).parameters)
        @wraps(function)
        def wrapped(*args: Any, **kwargs: Any) -> Any:
            bound = inspect.signature(function).bind(*args, **kwargs)
            value = bound.arguments[parameter_names[position]]
            with file_lock(Path(value)):
                return function(*args, **kwargs)
        return wrapped  # type: ignore[return-value]
    return decorate


def locked_target(target: str | Path) -> Callable[[F], F]:
    """Serialize all calls to a function with one fixed lock target."""
    def decorate(function: F) -> F:
        @wraps(function)
        def wrapped(*args: Any, **kwargs: Any) -> Any:
            with file_lock(target):
                return function(*args, **kwargs)
        return wrapped  # type: ignore[return-value]
    return decorate


def atomic_write_json(path: str | Path, value: Any, *, mode: int = 0o600) -> None:
    """Write JSON completely and atomically on the destination filesystem."""
    destination = Path(path)
    destination.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    fd, name = tempfile.mkstemp(prefix=f".{destination.name}.", dir=destination.parent)
    temporary = Path(name)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(value, handle, ensure_ascii=False, indent=2)
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temporary, mode)
        os.replace(temporary, destination)
        fsync_directory(destination.parent)
    finally:
        temporary.unlink(missing_ok=True)


def atomic_save_workbook(workbook: openpyxl.Workbook, path: str | Path) -> None:
    """Save, validate, and atomically replace an XLSX workbook."""
    destination = Path(path)
    destination.parent.mkdir(parents=True, exist_ok=True)
    fd, name = tempfile.mkstemp(
        prefix=f".{destination.stem}.", suffix=destination.suffix, dir=destination.parent
    )
    os.close(fd)
    temporary = Path(name)
    try:
        workbook.save(temporary)
        check = openpyxl.load_workbook(temporary, read_only=True, data_only=False)
        check.close()
        os.replace(temporary, destination)
        fsync_directory(destination.parent)
    finally:
        temporary.unlink(missing_ok=True)


def safe_excel_text(value: Any) -> Any:
    """Force attacker-controlled strings that resemble formulas to plain text."""
    if isinstance(value, str) and value.startswith(("=", "+", "-", "@")):
        return "'" + value
    return value


def fsync_directory(path: str | Path) -> None:
    """Persist directory-entry changes where the platform supports it."""
    flags = os.O_RDONLY | getattr(os, "O_DIRECTORY", 0)
    try:
        descriptor = os.open(Path(path), flags)
    except OSError:  # pragma: no cover - unsupported on some platforms/filesystems.
        return
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)
