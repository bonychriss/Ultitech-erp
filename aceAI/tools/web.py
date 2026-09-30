from urllib.parse import quote_plus

from agents import function_tool

from app.security import ToolDenied
from tools.browser import open_url
from tools.registry import fail, guarded, ok


def perform_search_web(query: str) -> str:
    text = (query or "").strip()
    if not text:
        return fail("A search query is required.")
    if len(text) > 300:
        return fail("That search is too long.")
    if any(ord(char) < 32 for char in text):
        return fail("The search text contains unsupported characters.")
    url = "https://www.google.com/search?q=" + quote_plus(text)
    try:
        open_url(url)
    except ToolDenied as exc:
        return fail(str(exc))
    return ok(f"Opened a web search for: {text}", url=url, query=text)


@function_tool
async def search_web(query: str) -> str:
    """Open the default browser to a web search for the given query."""
    return await guarded("search_web", {"query": query}, lambda: perform_search_web(query))
