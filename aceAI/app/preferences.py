import json
import re
from dataclasses import dataclass
from urllib.parse import urlparse

import httpx

from app.config import get_settings
from app.errors import AceError

MODEL_NAME = re.compile(r"^[A-Za-z0-9_.:/-]{1,80}$")
LOCAL_HOSTS = {"127.0.0.1", "localhost"}


@dataclass(frozen=True)
class Preferences:
    provider: str
    openai_model: str
    local_model: str
    ollama_base_url: str
    openai_api_key: str

    @property
    def model(self) -> str:
        if self.provider == "local":
            return self.local_model
        return self.openai_model


def _read_file() -> dict:
    path = get_settings().settings_path
    if not path.is_file():
        return {}
    try:
        loaded = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return {}
    return loaded if isinstance(loaded, dict) else {}


def load_preferences() -> Preferences:
    env = get_settings()
    saved = _read_file()
    provider = str(saved.get("provider") or env.provider or "openai").lower()
    if provider not in {"local", "openai"}:
        provider = "openai"
    stored_key = saved.get("openai_api_key")
    if isinstance(stored_key, str) and stored_key.strip():
        api_key = stored_key.strip()
    else:
        api_key = env.openai_api_key
    try:
        ollama_base_url = _local_url(str(saved.get("ollama_base_url") or env.ollama_base_url))
    except AceError:
        ollama_base_url = _local_url(env.ollama_base_url)
    return Preferences(
        provider=provider,
        openai_model=_model_or(saved.get("openai_model"), env.model),
        local_model=_model_or(saved.get("local_model"), env.local_model),
        ollama_base_url=ollama_base_url,
        openai_api_key=api_key,
    )


def public_preferences() -> dict:
    prefs = load_preferences()
    return {
        "provider": prefs.provider,
        "openai_model": prefs.openai_model,
        "local_model": prefs.local_model,
        "ollama_base_url": prefs.ollama_base_url,
        "openai_key_set": bool(prefs.openai_api_key),
        "model": prefs.model,
    }


def save_preferences(update: dict) -> dict:
    provider = str(update.get("provider") or "").lower()
    if provider not in {"local", "openai"}:
        raise AceError("Choose Local or OpenAI.")
    openai_model = _require_model(update.get("openai_model"), "OpenAI model")
    local_model = _require_model(update.get("local_model"), "Local model")
    ollama_base_url = _local_url(str(update.get("ollama_base_url") or ""))
    current = _read_file()
    current.update(
        {
            "provider": provider,
            "openai_model": openai_model,
            "local_model": local_model,
            "ollama_base_url": ollama_base_url,
        }
    )
    incoming_key = update.get("openai_api_key")
    if isinstance(incoming_key, str) and incoming_key.strip():
        current["openai_api_key"] = incoming_key.strip()
    path = get_settings().settings_path
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps({key: current[key] for key in (
        "provider",
        "openai_model",
        "local_model",
        "ollama_base_url",
        "openai_api_key",
    ) if key in current}, indent=2), encoding="utf-8")
    return public_preferences()


def ollama_is_running(base_url: str) -> bool:
    parsed = urlparse(base_url)
    origin = f"{parsed.scheme}://{parsed.netloc}"
    try:
        response = httpx.get(f"{origin}/api/tags", timeout=2.0)
    except httpx.HTTPError:
        return False
    return response.status_code == 200


def _model_or(value, fallback: str) -> str:
    if isinstance(value, str) and MODEL_NAME.fullmatch(value.strip()):
        return value.strip()
    return fallback


def _require_model(value, label: str) -> str:
    if not isinstance(value, str) or not MODEL_NAME.fullmatch(value.strip()):
        raise AceError(f"{label} should be a short model name, such as gpt-4o-mini or qwen2.5:7b.")
    return value.strip()


def _local_url(value: str) -> str:
    cleaned = (value or "").strip().rstrip("/")
    parsed = urlparse(cleaned)
    if parsed.scheme not in {"http", "https"} or parsed.hostname not in LOCAL_HOSTS or not parsed.port:
        raise AceError("The local model address must be on this PC, for example http://127.0.0.1:11434/v1.")
    return cleaned
