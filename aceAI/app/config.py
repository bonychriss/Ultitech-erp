import os
from dataclasses import dataclass
from functools import lru_cache
from pathlib import Path

from dotenv import load_dotenv

ROOT = Path(__file__).resolve().parent.parent


def _under_root(value: str, default: str) -> Path:
    path = Path(value or default)
    if not path.is_absolute():
        path = ROOT / path
    return path.resolve()


@dataclass(frozen=True)
class Settings:
    openai_api_key: str
    model: str
    local_model: str
    ollama_base_url: str
    provider: str
    settings_path: Path
    host: str
    port: int
    db_path: Path
    erp_db_path: Path
    workspace: Path
    allowed_roots: tuple[Path, ...]


@lru_cache
def get_settings() -> Settings:
    load_dotenv(ROOT / ".env")
    roots: list[Path] = []
    for part in os.getenv("ACE_ALLOWED_ROOTS", "").split(os.pathsep):
        part = part.strip()
        if part:
            roots.append(_under_root(part, part))
    return Settings(
        openai_api_key=os.getenv("OPENAI_API_KEY", "").strip(),
        model=os.getenv("ACE_MODEL", "gpt-4o-mini").strip() or "gpt-4o-mini",
        local_model=os.getenv("ACE_LOCAL_MODEL", "qwen2.5:3b").strip() or "qwen2.5:3b",
        ollama_base_url=os.getenv("ACE_OLLAMA_URL", "http://127.0.0.1:11434/v1").strip()
        or "http://127.0.0.1:11434/v1",
        provider=os.getenv("ACE_PROVIDER", "openai").strip().lower() or "openai",
        settings_path=_under_root(os.getenv("ACE_SETTINGS", ""), "data/settings.json"),
        host=os.getenv("ACE_HOST", "127.0.0.1").strip() or "127.0.0.1",
        port=int(os.getenv("ACE_PORT", "8765")),
        db_path=_under_root(os.getenv("ACE_DB", ""), "data/ace.db"),
        erp_db_path=_under_root(os.getenv("ACE_ERP_DB", ""), "data/mock_erp.db"),
        workspace=_under_root(os.getenv("ACE_WORKSPACE", ""), "data/workspace"),
        allowed_roots=tuple(roots),
    )
