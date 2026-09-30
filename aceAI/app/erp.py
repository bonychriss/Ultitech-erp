"""Request-scoped link from ACE back to the signed-in Ultitech company."""

from contextvars import ContextVar
from dataclasses import dataclass
from urllib.parse import urlparse

_erp: ContextVar["ErpLink | None"] = ContextVar("ace_erp", default=None)


class ErpContextError(Exception):
    pass


@dataclass(frozen=True)
class ErpLink:
    url: str
    token: str


def current_erp() -> ErpLink | None:
    return _erp.get()


def bind_erp(url: str | None, token: str | None):
    clean_url = (url or "").strip()
    clean_token = (token or "").strip()
    if clean_url == "" and clean_token == "":
        return None
    if clean_url == "" or clean_token == "":
        raise ErpContextError("Ultitech connection is incomplete.")
    if not clean_token or len(clean_token) < 32:
        raise ErpContextError("Ultitech connection is incomplete.")
    parsed = urlparse(clean_url)
    host = (parsed.hostname or "").lower()
    path = parsed.path or ""
    if parsed.scheme != "http" or host not in {"127.0.0.1", "localhost"}:
        raise ErpContextError("ACE can only reach Ultitech on this computer.")
    if not path.endswith("/modules/ai-agent/ace.php"):
        raise ErpContextError("ACE can only reach the Ultitech agent bridge.")
    if parsed.username or parsed.password or parsed.query or parsed.fragment:
        raise ErpContextError("The Ultitech bridge address is not valid.")
    return _erp.set(ErpLink(clean_url, clean_token))


def reset_erp(token) -> None:
    if token is not None:
        _erp.reset(token)
