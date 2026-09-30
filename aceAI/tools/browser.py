import webbrowser

from app.security import ToolDenied

_opener = webbrowser.open


def set_opener(opener) -> None:
    global _opener
    _opener = opener


def open_url(url: str) -> None:
    if not url.startswith("https://"):
        raise ToolDenied("Only https addresses can be opened.")
    _opener(url)
