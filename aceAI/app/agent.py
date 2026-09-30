import json

from agents import (
    Agent,
    ModelSettings,
    RunConfig,
    Runner,
    RunState,
    ToolsToFinalOutputResult,
    set_tracing_disabled,
)
from agents.models.openai_provider import OpenAIProvider
from agents.tool import FunctionToolResult

from app.errors import AceError, Conflict, MissingConfig, NotFound
from app.preferences import load_preferences, ollama_is_running
from app.memory import MemoryStore, clear_conversation, turn_actions, use_conversation
from app.erp import current_erp
from app.quick import QUICK_HELP, TOOL_LOG_NAMES, route_ultitech
from tools.registry import get_tools, is_consequential
from tools.test_erp import money
from tools.ultitech import ultitech_tools

set_tracing_disabled(True)

INSTRUCTIONS = """
You are ACE, a digital operator on the user's Windows PC.
Complete tasks by calling tools. Do not claim an action happened unless a tool result says it succeeded.
If details are missing, or more than one customer matches, ask a short clarifying question. Do not guess.
Creating an invoice, payment voucher, or file waits for the user's confirmation. Do not say it was created until the tool result says so.
Never invent shell commands or SQL. Never ask the user for an API key.
Amounts are Tanzanian shillings. Record the number the user gave.
Use search_web to search. Use open_application with only the application name.
Call the needed tools immediately. Reply in one or two short sentences.
""".strip()

ERP_INSTRUCTIONS = """
You are ACE inside Ultitech ERP. You can only see the signed-in company.
Call a Ultitech tool before every answer.
When asked how much customers owe, call ultitech_receivables_summary and stop. Do not add customer balances into a new total.
When asked for a briefing, call ultitech_daily_briefing and stop.
Do not change any amount the tool returns.
Do not create, edit, delete, pay, or post invoices, vouchers, or accounting records.
ultitech_prepare_follow_up only drafts a message. It is not sent.
""".strip()

APPROVE = {
    "yes",
    "y",
    "confirm",
    "confirmed",
    "approve",
    "approved",
    "go ahead",
    "do it",
    "proceed",
    "ok",
    "okay",
    "yes please",
}
REJECT = {
    "no",
    "n",
    "cancel",
    "stop",
    "reject",
    "no thanks",
    "no thank you",
}


def finish_after_tools(_ctx, results: list[FunctionToolResult]) -> ToolsToFinalOutputResult:
    if any(result.interruptions for result in results):
        return ToolsToFinalOutputResult(is_final_output=False)
    lines = [_tool_message(result.output) for result in results]
    return ToolsToFinalOutputResult(is_final_output=True, final_output="\n".join(lines))


def _tool_message(output) -> str:
    if not isinstance(output, str):
        return str(output)
    try:
        payload = json.loads(output)
    except json.JSONDecodeError:
        return output
    if isinstance(payload, dict) and payload.get("message"):
        return str(payload["message"])
    return output


def build_agent() -> Agent:
    prefs = load_preferences()
    erp = current_erp()
    settings = {
        "name": "ACE",
        "instructions": ERP_INSTRUCTIONS if erp is not None else INSTRUCTIONS,
        "model": prefs.model,
        "tools": ultitech_tools() if erp is not None else get_tools(),
    }
    if erp is not None:
        settings["model_settings"] = ModelSettings(temperature=0)
        settings["tool_use_behavior"] = finish_after_tools
    elif prefs.provider == "local":
        settings["model_settings"] = ModelSettings(temperature=0, max_tokens=120)
        settings["tool_use_behavior"] = finish_after_tools
    return Agent(**settings)


def current_run_config() -> RunConfig:
    prefs = load_preferences()
    if prefs.provider == "local":
        provider = OpenAIProvider(
            api_key="ollama",
            base_url=prefs.ollama_base_url,
            use_responses=False,
        )
    else:
        provider = OpenAIProvider(api_key=prefs.openai_api_key or None, use_responses=True)
    return RunConfig(model=prefs.model, model_provider=provider, tracing_disabled=True)


def classify_reply(message: str) -> bool | None:
    text = " ".join(message.strip().lower().rstrip(".!").split())
    if text in APPROVE:
        return True
    if text in REJECT:
        return False
    return None


def _parse_arguments(raw) -> dict:
    if isinstance(raw, dict):
        return raw
    if isinstance(raw, str) and raw.strip():
        try:
            loaded = json.loads(raw)
        except json.JSONDecodeError:
            return {"raw": raw}
        if isinstance(loaded, dict):
            return loaded
    return {}


def _shown_amount(value) -> str:
    try:
        return money(value)
    except (TypeError, ValueError):
        return str(value)


def describe_pending(tool_name: str, arguments: dict) -> str:
    if tool_name == "create_test_invoice":
        sentence = (
            f"I've prepared a test invoice for {arguments.get('customer_name')} "
            f"for {_shown_amount(arguments.get('amount', 0))}."
        )
    elif tool_name == "create_test_payment_voucher":
        sentence = (
            f"I've prepared a payment voucher for {arguments.get('customer_name')} "
            f"for {_shown_amount(arguments.get('amount', 0))}."
        )
    elif tool_name == "create_file":
        sentence = f"I've prepared a file at {arguments.get('path')}."
    else:
        sentence = f"I've prepared the action {tool_name}."
    description = str(arguments.get("description") or "").strip()
    if description:
        sentence += f" Description: {description}."
    return sentence + " Do you want me to create it?"


def public_error(exc: Exception) -> str:
    name = type(exc).__name__
    text = str(exc).lower()
    prefs = load_preferences()
    if prefs.provider == "local" and ("connect" in text or "not found" in text or "notfound" in name.lower()):
        return (
            f"ACE could not use the local model {prefs.local_model}. "
            "Start Ollama, pull that model, or choose another one in Settings."
        )
    if "authentication" in name.lower() or "api key" in text or "sk-" in text:
        return "OpenAI rejected the API key. Update it in Settings."
    if "RateLimit" in name:
        return "The model service is busy. Try again shortly."
    return "ACE could not complete that request."


def ensure_model_ready() -> None:
    prefs = load_preferences()
    if prefs.provider == "openai":
        if not prefs.openai_api_key:
            raise MissingConfig("Add an OpenAI API key in Settings, or switch the provider to Local.")
        return
    if not ollama_is_running(prefs.ollama_base_url):
        raise MissingConfig(
            "Local mode needs Ollama running on this PC. Start Ollama, pull a model, or switch to OpenAI in Settings."
        )


def _payload(conversation_id: str, message: str, pending: dict | None, actions: list[dict]) -> dict:
    return {
        "conversation_id": conversation_id,
        "message": message,
        "pending_action": pending,
        "actions": actions,
    }


async def handle_chat(store: MemoryStore, message: str, conversation_id: str | None) -> dict:
    text = message.strip()
    if not text:
        raise AceError("Message is empty.")
    if conversation_id is not None and not store.conversation_exists(conversation_id):
        raise NotFound("Conversation not found.")

    pending = store.get_open_pending(conversation_id) if conversation_id else None
    decision = classify_reply(text) if pending else None
    route = route_ultitech(text) if current_erp() is not None else None
    confirming = pending is not None and decision is not None and route is None
    if current_erp() is None or confirming:
        ensure_model_ready()
    if conversation_id is None:
        conversation_id = store.create_conversation()

    first_message = not store.get_conversation(conversation_id)["messages"]
    store.add_message(conversation_id, "user", text)
    if first_message:
        store.set_title(conversation_id, text)

    if confirming:
        return await apply_confirmation(store, conversation_id, pending["id"], pending["tool_name"], decision)

    if pending and decision is None:
        store.set_pending_status(pending["id"], "rejected")
        store.add_action(
            conversation_id,
            pending["tool_name"],
            pending["arguments"],
            "Cancelled because a new request arrived before confirmation.",
            "rejected",
        )

    if route is not None:
        return await run_quick_turn(store, conversation_id, route[0], route[1])
    if current_erp() is not None:
        store.add_message(conversation_id, "assistant", QUICK_HELP)
        return _payload(conversation_id, QUICK_HELP, None, [])

    return await run_turn(store, conversation_id)


async def run_quick_turn(store: MemoryStore, conversation_id: str, action: str, arguments: dict) -> dict:
    from tools.registry import guarded
    from tools.ultitech import call_ultitech

    tokens = use_conversation(store, conversation_id)
    try:
        result = await guarded(
            TOOL_LOG_NAMES[action],
            arguments,
            lambda: call_ultitech(action, **arguments),
        )
        message = _tool_message(result)
        actions = turn_actions()
    finally:
        clear_conversation(tokens)
    store.add_message(conversation_id, "assistant", message)
    return _payload(conversation_id, message, None, actions)


async def run_turn(store: MemoryStore, conversation_id: str) -> dict:
    agent = build_agent()
    history = store.model_messages(conversation_id)
    tokens = use_conversation(store, conversation_id)
    try:
        try:
            result = await Runner.run(agent, history, max_turns=8, run_config=current_run_config())
            if result.interruptions:
                return await pause_for_approval(store, conversation_id, result)
            message = result.final_output or "Done."
            actions = turn_actions()
        except AceError:
            raise
        except Exception as exc:
            message = public_error(exc)
            store.add_message(conversation_id, "assistant", message)
            raise AceError(message, 502) from exc
    finally:
        clear_conversation(tokens)
    store.add_message(conversation_id, "assistant", message)
    return _payload(conversation_id, message, None, actions)


async def pause_for_approval(store: MemoryStore, conversation_id: str, result) -> dict:
    interruption = result.interruptions[0]
    tool_name = interruption.tool_name or interruption.name or "tool"
    arguments = _parse_arguments(interruption.arguments)
    summary = describe_pending(tool_name, arguments)
    state = result.to_state().to_json()
    pending = store.create_pending(
        conversation_id,
        tool_name,
        arguments,
        summary,
        state,
        interruption.call_id,
    )
    store.add_action(conversation_id, tool_name, arguments, summary, "pending")
    store.add_message(conversation_id, "assistant", summary)
    actions = turn_actions()
    actions.append(
        {
            "tool_name": tool_name,
            "status": "pending",
            "arguments": arguments,
            "result": summary,
            "created_at": None,
        }
    )
    return _payload(conversation_id, summary, pending, actions)


async def apply_confirmation(
    store: MemoryStore,
    conversation_id: str,
    pending_id: str,
    tool_name: str,
    approved: bool,
) -> dict:
    ensure_model_ready()
    pending = store.claim_pending(pending_id, conversation_id)
    if pending is None:
        raise Conflict("That action is no longer waiting for confirmation.")
    if pending["tool_name"] != tool_name:
        store.set_pending_status(pending_id, "pending")
        raise Conflict("The tool name does not match the prepared action.")
    if not is_consequential(tool_name):
        store.set_pending_status(pending_id, "error")
        raise Conflict("That tool cannot be confirmed.")

    agent = build_agent()
    tokens = use_conversation(store, conversation_id)
    try:
        try:
            state = await RunState.from_json(agent, json.loads(pending["state_json"]))
            match = _matching_interruption(state.get_interruptions(), pending)
            if match is None:
                store.set_pending_status(pending_id, "error")
                raise Conflict("The prepared action expired. Ask ACE again.")
            if approved:
                state.approve(match)
            else:
                state.reject(
                    match,
                    rejection_message="The user declined this action. Do not execute it.",
                )
            result = await Runner.run(agent, state, max_turns=8, run_config=current_run_config())
        except Conflict:
            raise
        except Exception as exc:
            store.set_pending_status(pending_id, "error")
            message = public_error(exc)
            store.add_action(conversation_id, tool_name, pending["arguments"], message, "error")
            store.add_message(conversation_id, "assistant", message)
            raise AceError(message, 502) from exc

        status = "confirmed" if approved else "rejected"
        store.set_pending_status(pending_id, status)
        if result.interruptions:
            return await pause_for_approval(store, conversation_id, result)
        message = result.final_output or ("Done." if approved else "Cancelled. I did not run that action.")
        actions = turn_actions()
    finally:
        clear_conversation(tokens)
    store.add_message(conversation_id, "assistant", message)
    return _payload(conversation_id, message, None, actions)


def _matching_interruption(interruptions, pending: dict):
    for item in interruptions:
        if pending.get("call_id") and item.call_id == pending["call_id"]:
            return item
    for item in interruptions:
        if (item.tool_name or item.name) == pending["tool_name"]:
            return item
    return None
