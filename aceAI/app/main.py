import time
from contextlib import asynccontextmanager
from fastapi import FastAPI
from fastapi.responses import FileResponse, JSONResponse
from fastapi.staticfiles import StaticFiles

from app.agent import apply_confirmation, handle_chat
from app.config import ROOT, get_settings
from app.errors import AceError, Conflict, NotFound
from app.memory import MemoryStore
from app.models import (
    ChatRequest,
    ChatResponse,
    ConfirmRequest,
    ConversationDetail,
    ConversationSummary,
    SettingsUpdate,
    SettingsView,
)
from app.preferences import public_preferences, save_preferences
from tools.registry import is_consequential, is_known_tool

STATIC = ROOT / "static"


@asynccontextmanager
async def lifespan(app: FastAPI):
    settings = get_settings()
    settings.workspace.mkdir(parents=True, exist_ok=True)
    app.state.store = MemoryStore(settings.db_path)
    yield


app = FastAPI(title="ACE AI", lifespan=lifespan)


@app.exception_handler(AceError)
async def handle_ace_error(_request, exc: AceError):
    return JSONResponse(status_code=exc.status, content={"detail": exc.message})


@app.get("/api/settings", response_model=SettingsView)
def read_settings():
    return public_preferences()


@app.post("/api/settings", response_model=SettingsView)
def update_settings(body: SettingsUpdate):
    return save_preferences(body.model_dump())


@app.get("/")
def index():
    return FileResponse(STATIC / "index.html")


def _with_elapsed(store: MemoryStore, payload: dict, started: float) -> dict:
    elapsed = round(time.perf_counter() - started, 2)
    payload["elapsed_seconds"] = elapsed
    store.set_latest_assistant_elapsed(payload["conversation_id"], elapsed)
    return payload


@app.post("/api/chat", response_model=ChatResponse)
async def chat(body: ChatRequest):
    started = time.perf_counter()
    payload = await handle_chat(app.state.store, body.message, body.conversation_id)
    return _with_elapsed(app.state.store, payload, started)


@app.get("/api/conversations", response_model=list[ConversationSummary])
def conversations():
    return app.state.store.list_conversations()


@app.get("/api/conversations/{conversation_id}", response_model=ConversationDetail)
def conversation(conversation_id: str):
    found = app.state.store.get_conversation(conversation_id)
    if found is None:
        raise NotFound("Conversation not found.")
    return found


@app.post("/api/tools/{tool_name}/confirm", response_model=ChatResponse)
async def confirm_tool(tool_name: str, body: ConfirmRequest):
    if not is_known_tool(tool_name) or not is_consequential(tool_name):
        raise NotFound("That tool cannot be confirmed.")
    pending = app.state.store.get_pending(body.pending_id)
    if pending is None:
        raise NotFound("Prepared action not found.")
    if pending["status"] != "pending":
        raise Conflict("That action is no longer waiting for confirmation.")
    if pending["tool_name"] != tool_name:
        raise Conflict("The tool name does not match the prepared action.")
    app.state.store.add_message(pending["conversation_id"], "user", "Yes." if body.approved else "No.")
    started = time.perf_counter()
    payload = await apply_confirmation(
        app.state.store,
        pending["conversation_id"],
        body.pending_id,
        tool_name,
        body.approved,
    )
    return _with_elapsed(app.state.store, payload, started)


app.mount("/static", StaticFiles(directory=STATIC), name="static")


def main() -> None:
    import uvicorn

    settings = get_settings()
    uvicorn.run("app.main:app", host=settings.host, port=settings.port, reload=False)


if __name__ == "__main__":
    main()
