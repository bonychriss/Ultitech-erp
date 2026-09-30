import contextvars
import json
import sqlite3
import uuid
from datetime import datetime, timezone
from pathlib import Path

_store: contextvars.ContextVar["MemoryStore | None"] = contextvars.ContextVar("ace_store", default=None)
_conversation: contextvars.ContextVar[str | None] = contextvars.ContextVar("ace_conversation", default=None)
_turn_actions: contextvars.ContextVar[list | None] = contextvars.ContextVar("ace_turn_actions", default=None)


def utcnow() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="microseconds")


def use_conversation(store: "MemoryStore", conversation_id: str) -> tuple:
    return (
        _store.set(store),
        _conversation.set(conversation_id),
        _turn_actions.set([]),
    )


def clear_conversation(tokens: tuple) -> None:
    _store.reset(tokens[0])
    _conversation.reset(tokens[1])
    _turn_actions.reset(tokens[2])


def turn_actions() -> list[dict]:
    bucket = _turn_actions.get()
    return list(bucket or [])


def log_tool(tool_name: str, arguments: dict, result: str, status: str) -> None:
    store = _store.get()
    conversation_id = _conversation.get()
    if store is None or conversation_id is None:
        return
    entry = store.add_action(conversation_id, tool_name, arguments, result, status)
    bucket = _turn_actions.get()
    if bucket is not None:
        bucket.append(entry)


class MemoryStore:
    def __init__(self, path: Path):
        self.path = path
        self.path.parent.mkdir(parents=True, exist_ok=True)
        with self._connect() as conn:
            conn.executescript(
                """
                CREATE TABLE IF NOT EXISTS conversations (
                    id TEXT PRIMARY KEY,
                    title TEXT NOT NULL,
                    created_at TEXT NOT NULL
                );
                CREATE TABLE IF NOT EXISTS messages (
                    id TEXT PRIMARY KEY,
                    conversation_id TEXT NOT NULL,
                    role TEXT NOT NULL,
                    content TEXT NOT NULL,
                    created_at TEXT NOT NULL
                );
                CREATE TABLE IF NOT EXISTS actions (
                    id TEXT PRIMARY KEY,
                    conversation_id TEXT NOT NULL,
                    tool_name TEXT NOT NULL,
                    arguments_json TEXT NOT NULL,
                    result_json TEXT,
                    status TEXT NOT NULL,
                    created_at TEXT NOT NULL
                );
                CREATE TABLE IF NOT EXISTS pending_actions (
                    id TEXT PRIMARY KEY,
                    conversation_id TEXT NOT NULL,
                    tool_name TEXT NOT NULL,
                    arguments_json TEXT NOT NULL,
                    summary TEXT NOT NULL,
                    state_json TEXT NOT NULL,
                    call_id TEXT,
                    status TEXT NOT NULL,
                    created_at TEXT NOT NULL
                );
                """
            )
            columns = {row[1] for row in conn.execute("PRAGMA table_info(messages)")}
            if "elapsed_seconds" not in columns:
                conn.execute("ALTER TABLE messages ADD COLUMN elapsed_seconds REAL")

    def _connect(self) -> sqlite3.Connection:
        conn = sqlite3.connect(self.path)
        conn.row_factory = sqlite3.Row
        return conn

    def create_conversation(self) -> str:
        conversation_id = str(uuid.uuid4())
        with self._connect() as conn:
            conn.execute(
                "INSERT INTO conversations (id, title, created_at) VALUES (?, ?, ?)",
                (conversation_id, "New conversation", utcnow()),
            )
        return conversation_id

    def set_title(self, conversation_id: str, title: str) -> None:
        cleaned = " ".join(title.split())[:80] or "New conversation"
        with self._connect() as conn:
            conn.execute("UPDATE conversations SET title = ? WHERE id = ?", (cleaned, conversation_id))

    def conversation_exists(self, conversation_id: str) -> bool:
        with self._connect() as conn:
            row = conn.execute("SELECT 1 FROM conversations WHERE id = ?", (conversation_id,)).fetchone()
        return row is not None

    def add_message(self, conversation_id: str, role: str, content: str, elapsed_seconds: float | None = None) -> dict:
        message = {
            "id": str(uuid.uuid4()),
            "conversation_id": conversation_id,
            "role": role,
            "content": content,
            "created_at": utcnow(),
            "elapsed_seconds": elapsed_seconds,
        }
        with self._connect() as conn:
            conn.execute(
                """INSERT INTO messages (id, conversation_id, role, content, created_at, elapsed_seconds)
                   VALUES (:id, :conversation_id, :role, :content, :created_at, :elapsed_seconds)""",
                message,
            )
        return message

    def set_latest_assistant_elapsed(self, conversation_id: str, elapsed_seconds: float) -> None:
        with self._connect() as conn:
            conn.execute(
                """UPDATE messages SET elapsed_seconds = ?
                   WHERE id = (
                       SELECT id FROM messages
                       WHERE conversation_id = ? AND role = 'assistant'
                       ORDER BY created_at DESC, rowid DESC
                       LIMIT 1
                   )""",
                (elapsed_seconds, conversation_id),
            )

    def add_action(self, conversation_id: str, tool_name: str, arguments: dict, result: str, status: str) -> dict:
        entry = {
            "id": str(uuid.uuid4()),
            "conversation_id": conversation_id,
            "tool_name": tool_name,
            "arguments": arguments,
            "result": (result or "")[:20000],
            "status": status,
            "created_at": utcnow(),
        }
        with self._connect() as conn:
            conn.execute(
                """INSERT INTO actions
                   (id, conversation_id, tool_name, arguments_json, result_json, status, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?)""",
                (
                    entry["id"],
                    conversation_id,
                    tool_name,
                    json.dumps(arguments),
                    entry["result"],
                    status,
                    entry["created_at"],
                ),
            )
        return entry

    def model_messages(self, conversation_id: str, limit: int = 30) -> list[dict]:
        with self._connect() as conn:
            rows = conn.execute(
                """SELECT role, content FROM messages
                   WHERE conversation_id = ? AND role IN ('user', 'assistant')
                   ORDER BY created_at ASC, rowid ASC""",
                (conversation_id,),
            ).fetchall()
        selected = rows[-limit:]
        return [{"role": row["role"], "content": row["content"]} for row in selected]

    def list_conversations(self) -> list[dict]:
        with self._connect() as conn:
            rows = conn.execute(
                "SELECT id, title, created_at FROM conversations ORDER BY created_at DESC, rowid DESC"
            ).fetchall()
        return [dict(row) for row in rows]

    def get_conversation(self, conversation_id: str) -> dict | None:
        with self._connect() as conn:
            conversation = conn.execute(
                "SELECT id, title, created_at FROM conversations WHERE id = ?",
                (conversation_id,),
            ).fetchone()
            if conversation is None:
                return None
            messages = conn.execute(
                """SELECT role, content, created_at, elapsed_seconds FROM messages
                   WHERE conversation_id = ? ORDER BY created_at ASC, rowid ASC""",
                (conversation_id,),
            ).fetchall()
            actions = conn.execute(
                """SELECT tool_name, arguments_json, result_json, status, created_at
                   FROM actions WHERE conversation_id = ? ORDER BY created_at ASC, rowid ASC""",
                (conversation_id,),
            ).fetchall()
        return {
            "id": conversation["id"],
            "title": conversation["title"],
            "created_at": conversation["created_at"],
            "messages": [dict(row) for row in messages],
            "actions": [_action_row(row) for row in actions],
            "pending_action": self.get_open_pending(conversation_id),
        }

    def create_pending(
        self,
        conversation_id: str,
        tool_name: str,
        arguments: dict,
        summary: str,
        state: dict,
        call_id: str | None,
    ) -> dict:
        pending = {
            "id": str(uuid.uuid4()),
            "conversation_id": conversation_id,
            "tool_name": tool_name,
            "arguments": arguments,
            "summary": summary,
            "state_json": json.dumps(state),
            "call_id": call_id,
            "status": "pending",
            "created_at": utcnow(),
        }
        with self._connect() as conn:
            conn.execute(
                """INSERT INTO pending_actions
                   (id, conversation_id, tool_name, arguments_json, summary, state_json, call_id, status, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)""",
                (
                    pending["id"],
                    conversation_id,
                    tool_name,
                    json.dumps(arguments),
                    summary,
                    pending["state_json"],
                    call_id,
                    "pending",
                    pending["created_at"],
                ),
            )
        return {
            "id": pending["id"],
            "tool_name": tool_name,
            "summary": summary,
            "arguments": arguments,
        }

    def get_open_pending(self, conversation_id: str) -> dict | None:
        with self._connect() as conn:
            row = conn.execute(
                """SELECT id, tool_name, arguments_json, summary, status
                   FROM pending_actions
                   WHERE conversation_id = ? AND status = 'pending'
                   ORDER BY created_at DESC LIMIT 1""",
                (conversation_id,),
            ).fetchone()
        if row is None:
            return None
        return _pending_public(row)

    def get_pending(self, pending_id: str) -> dict | None:
        with self._connect() as conn:
            row = conn.execute("SELECT * FROM pending_actions WHERE id = ?", (pending_id,)).fetchone()
        if row is None:
            return None
        data = dict(row)
        data["arguments"] = json.loads(data.pop("arguments_json"))
        return data

    def claim_pending(self, pending_id: str, conversation_id: str) -> dict | None:
        with self._connect() as conn:
            updated = conn.execute(
                """UPDATE pending_actions SET status = 'running'
                   WHERE id = ? AND conversation_id = ? AND status = 'pending'""",
                (pending_id, conversation_id),
            )
            if updated.rowcount != 1:
                return None
            row = conn.execute("SELECT * FROM pending_actions WHERE id = ?", (pending_id,)).fetchone()
        data = dict(row)
        data["arguments"] = json.loads(data.pop("arguments_json"))
        return data

    def set_pending_status(self, pending_id: str, status: str) -> None:
        with self._connect() as conn:
            conn.execute("UPDATE pending_actions SET status = ? WHERE id = ?", (status, pending_id))


def _action_row(row: sqlite3.Row) -> dict:
    return {
        "tool_name": row["tool_name"],
        "arguments": json.loads(row["arguments_json"]),
        "result": row["result_json"],
        "status": row["status"],
        "created_at": row["created_at"],
    }


def _pending_public(row: sqlite3.Row) -> dict:
    return {
        "id": row["id"],
        "tool_name": row["tool_name"],
        "summary": row["summary"],
        "arguments": json.loads(row["arguments_json"]),
    }
