import os
import platform
import shutil
import subprocess
from datetime import datetime
from pathlib import Path

from agents import function_tool

from app.security import ToolDenied, allowed_application, resolve_path
from tools.registry import fail, guarded, ok


def start_process(args: list[str]) -> None:
    subprocess.Popen(args, shell=False)


def open_existing_path(path: Path) -> None:
    if not hasattr(os, "startfile"):
        raise ToolDenied("Opening folders and files is set up for Windows in this version.")
    os.startfile(path)  # type: ignore[attr-defined]


def _chrome_executable() -> str | None:
    candidates = [
        Path(os.environ.get("PROGRAMFILES", "")) / "Google/Chrome/Application/chrome.exe",
        Path(os.environ.get("PROGRAMFILES(X86)", "")) / "Google/Chrome/Application/chrome.exe",
        Path(os.environ.get("LOCALAPPDATA", "")) / "Google/Chrome/Application/chrome.exe",
    ]
    for candidate in candidates:
        if candidate.is_file():
            return str(candidate)
    found = shutil.which("chrome.exe")
    return found


def perform_open_application(name: str) -> str:
    try:
        token = allowed_application(name)
    except ToolDenied as exc:
        return fail(str(exc))
    if token == "chrome":
        executable = _chrome_executable()
        if not executable:
            return fail("Google Chrome is not installed in a known location.")
        command = [executable]
        label = "Google Chrome"
    else:
        command = [token]
        label = name.strip()
    start_process(command)
    return ok(f"Opened {label}.", application=label)


def perform_open_folder(path: str) -> str:
    try:
        target = resolve_path(path, write=False)
    except ToolDenied as exc:
        return fail(str(exc))
    if not target.is_dir():
        return fail("That folder does not exist.")
    try:
        open_existing_path(target)
    except ToolDenied as exc:
        return fail(str(exc))
    return ok(f"Opened folder {target}.", path=str(target))


def perform_get_current_time() -> str:
    now = datetime.now().astimezone()
    clock = now.strftime("%Y-%m-%d %H:%M:%S")
    return ok(f"The local time is {clock}.", iso=now.isoformat(timespec="seconds"))


def perform_get_system_information() -> str:
    info = {
        "os": platform.system(),
        "release": platform.release(),
        "machine": platform.machine(),
        "python": platform.python_version(),
    }
    message = f"{info['os']} {info['release']} on {info['machine']}, Python {info['python']}."
    return ok(message, **info)


@function_tool
async def open_application(name: str) -> str:
    """Open an allowed Windows application. Names: notepad, calculator, explorer, chrome, edge."""
    return await guarded("open_application", {"name": name}, lambda: perform_open_application(name))


@function_tool
async def open_folder(path: str) -> str:
    """Open a folder that sits inside the allowed folders."""
    return await guarded("open_folder", {"path": path}, lambda: perform_open_folder(path))


@function_tool
async def get_current_time() -> str:
    """Return the current local date and time."""
    return await guarded("get_current_time", {}, perform_get_current_time)


@function_tool
async def get_system_information() -> str:
    """Return basic operating system and Python information. Does not include secrets or environment variables."""
    return await guarded("get_system_information", {}, perform_get_system_information)
