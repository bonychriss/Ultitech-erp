from agents import function_tool

from app.security import ToolDenied, resolve_path
from tools.registry import fail, guarded, ok
from tools.windows import open_existing_path

MAX_TEXT = 100_000


def perform_read_file(path: str) -> str:
    try:
        target = resolve_path(path, write=False)
    except ToolDenied as exc:
        return fail(str(exc))
    if not target.is_file():
        return fail("That file does not exist.")
    if target.stat().st_size > MAX_TEXT:
        return fail("That file is too large to read.")
    data = target.read_bytes()
    if b"\x00" in data:
        return fail("ACE can only read text files.")
    text = data.decode("utf-8", errors="replace")
    return ok(f"Read {target.name}.", path=str(target), content=text[:MAX_TEXT])


def perform_open_file(path: str) -> str:
    try:
        target = resolve_path(path, write=False)
    except ToolDenied as exc:
        return fail(str(exc))
    if not target.is_file():
        return fail("That file does not exist.")
    try:
        open_existing_path(target)
    except ToolDenied as exc:
        return fail(str(exc))
    return ok(f"Opened {target.name}.", path=str(target))


def perform_create_file(path: str, content: str) -> str:
    if not isinstance(content, str):
        return fail("File content must be text.")
    if len(content) > MAX_TEXT:
        return fail("That content is too large.")
    try:
        target = resolve_path(path, write=True)
    except ToolDenied as exc:
        return fail(str(exc))
    if target.exists():
        return fail("That file already exists. ACE will not overwrite it.")
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(content, encoding="utf-8")
    return ok(f"Created {target.name}.", path=str(target))


async def create_file_needs_approval(_ctx, params: dict, _call_id: str) -> bool:
    path = str(params.get("path") or "").strip()
    content = params.get("content")
    if not path or not isinstance(content, str):
        return False
    try:
        target = resolve_path(path, write=True)
    except ToolDenied:
        return False
    return not target.exists() and len(content) <= MAX_TEXT


@function_tool
async def read_file(path: str) -> str:
    """Read a text file from an allowed folder."""
    return await guarded("read_file", {"path": path}, lambda: perform_read_file(path))


@function_tool
async def open_file(path: str) -> str:
    """Open a file from an allowed folder in Windows."""
    return await guarded("open_file", {"path": path}, lambda: perform_open_file(path))


@function_tool(needs_approval=create_file_needs_approval)
async def create_file(path: str, content: str) -> str:
    """Create a new text file inside the ACE workspace. Asks for confirmation before writing."""
    return await guarded(
        "create_file",
        {"path": path, "content": content[:500]},
        lambda: perform_create_file(path, content),
    )
