from pathlib import Path

from app.config import get_settings

SENSITIVE_NAMES = {
    ".env",
    ".env.local",
    "id_rsa",
    "id_dsa",
    "id_ecdsa",
    "id_ed25519",
    "credentials.json",
}
SENSITIVE_PARTS = {".ssh", ".gnupg"}
APP_ALIASES = {
    "notepad": "notepad.exe",
    "calculator": "calc.exe",
    "calc": "calc.exe",
    "explorer": "explorer.exe",
    "file explorer": "explorer.exe",
    "chrome": "chrome",
    "google chrome": "chrome",
    "edge": "msedge.exe",
    "microsoft edge": "msedge.exe",
}


class ToolDenied(Exception):
    pass


def _within(path: Path, root: Path) -> bool:
    try:
        path.relative_to(root)
    except ValueError:
        return False
    return True


def resolve_path(user_path: str, *, write: bool) -> Path:
    raw = (user_path or "").strip()
    if not raw:
        raise ToolDenied("A file path is required.")
    settings = get_settings()
    workspace = settings.workspace
    candidate = Path(raw)
    if not candidate.is_absolute():
        candidate = workspace / candidate
    resolved = candidate.resolve()
    roots = (workspace,) if write else (workspace, *settings.allowed_roots)
    if not any(_within(resolved, root) for root in roots):
        raise ToolDenied("That path is outside the folders ACE is allowed to use.")
    if resolved == workspace and write:
        raise ToolDenied("Choose a file name inside the workspace folder.")
    if resolved.name.lower() in SENSITIVE_NAMES or any(part.lower() in SENSITIVE_PARTS for part in resolved.parts):
        raise ToolDenied("That file is blocked.")
    return resolved


def allowed_application(name: str) -> str:
    cleaned = (name or "").strip().lower()
    if not cleaned or any(char in cleaned for char in "\\/&|;$<>`\n\r"):
        raise ToolDenied("That application name is not allowed.")
    if cleaned not in APP_ALIASES:
        known = "notepad, calculator, explorer, chrome, edge"
        raise ToolDenied(f"'{name}' is not an allowed application. ACE can open: {known}.")
    return APP_ALIASES[cleaned]
