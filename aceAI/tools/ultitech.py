"""Ultitech ERP tools. These read the signed-in company through the PHP bridge."""

import httpx
from agents import function_tool

from app.erp import current_erp
from tools.registry import fail, guarded, ok


def call_ultitech(action: str, **payload) -> str:
    link = current_erp()
    if link is None:
        return fail("Ultitech is not connected for this chat.")
    body = {"action": action, **payload}
    try:
        response = httpx.post(
            link.url,
            json=body,
            headers={
                "X-Ultitech-Ace-Token": link.token,
                "Accept": "application/json",
            },
            timeout=20.0,
            follow_redirects=False,
        )
    except httpx.HTTPError:
        return fail("Ultitech did not respond.")
    if response.status_code in {401, 403}:
        return fail("Ultitech refused this company context.")
    try:
        data = response.json()
    except ValueError:
        return fail("Ultitech returned an unreadable response.")
    if response.status_code >= 400 or not data.get("ok"):
        return fail(str(data.get("error") or data.get("message") or "Ultitech could not answer."))
    message = str(data.get("message") or "Done.")
    extra = {key: value for key, value in data.items() if key not in {"ok", "message"}}
    return ok(message, **extra)


@function_tool
async def ultitech_daily_briefing() -> str:
    """Read today's Ultitech briefing: receivables, approvals, stock, and procurement for this company only."""
    return await guarded("ultitech_daily_briefing", {}, lambda: call_ultitech("daily_briefing"))


@function_tool
async def ultitech_receivables_summary() -> str:
    """Read outstanding, overdue, and due-soon receivables for this company."""
    return await guarded("ultitech_receivables_summary", {}, lambda: call_ultitech("receivables_summary"))


@function_tool
async def ultitech_overdue_invoices() -> str:
    """List the oldest overdue customer invoices for this company."""
    return await guarded("ultitech_overdue_invoices", {}, lambda: call_ultitech("overdue_invoices"))


@function_tool
async def ultitech_due_soon_invoices() -> str:
    """List customer invoices due within the next 7 days."""
    return await guarded("ultitech_due_soon_invoices", {}, lambda: call_ultitech("due_soon_invoices"))


@function_tool
async def ultitech_recent_invoice() -> str:
    """Read the most recently created customer invoice for this company. This does not create or change an invoice."""
    return await guarded("ultitech_recent_invoice", {}, lambda: call_ultitech("recent_invoice"))


@function_tool
async def ultitech_oldest_overdue_invoice() -> str:
    """Find the oldest overdue customer invoice."""
    return await guarded("ultitech_oldest_overdue_invoice", {}, lambda: call_ultitech("oldest_overdue"))


@function_tool
async def ultitech_customer_receivables(name: str = "") -> str:
    """Find what a customer owes, or list the largest overdue customer balances. Leave name empty to list them."""
    return await guarded(
        "ultitech_customer_receivables",
        {"name": name},
        lambda: call_ultitech("customer_receivables", name=name),
    )


@function_tool
async def ultitech_month_receivables() -> str:
    """Read customer invoices issued this month and how much is still outstanding."""
    return await guarded("ultitech_month_receivables", {}, lambda: call_ultitech("month_receivables"))


@function_tool
async def ultitech_stock_alerts() -> str:
    """Read how many products are below their reorder level."""
    return await guarded("ultitech_stock_alerts", {}, lambda: call_ultitech("stock_alerts"))


@function_tool
async def ultitech_pending_approvals() -> str:
    """Read how many payment vouchers are waiting for approval."""
    return await guarded("ultitech_pending_approvals", {}, lambda: call_ultitech("pending_approvals"))


@function_tool
async def ultitech_stalled_procurement() -> str:
    """Read purchase requests that have been waiting more than 3 days."""
    return await guarded("ultitech_stalled_procurement", {}, lambda: call_ultitech("stalled_procurement"))


async def _invoice_needs_approval(_ctx, params: dict, _call_id: str) -> bool:
    name = str(params.get("customer_name") or "").strip()
    description = str(params.get("description") or "").strip()
    try:
        amount = float(params.get("amount"))
    except (TypeError, ValueError):
        return False
    return bool(name) and amount > 0


@function_tool(needs_approval=_invoice_needs_approval)
async def ultitech_create_invoice(customer_name: str, amount: float, description: str = "") -> str:
    """Create one customer invoice in Ultitech after the user confirms. Customer and amount are required. Description may be empty."""
    return await guarded(
        "ultitech_create_invoice",
        {"customer_name": customer_name, "amount": amount, "description": description},
        lambda: call_ultitech(
            "create_invoice",
            customer_name=customer_name,
            amount=amount,
            description=description,
        ),
    )


@function_tool
async def ultitech_prepare_follow_up(invoice_id: int) -> str:
    """Prepare a customer follow-up draft for one invoice. This does not send email or WhatsApp and does not change the invoice."""
    return await guarded(
        "ultitech_prepare_follow_up",
        {"invoice_id": invoice_id},
        lambda: call_ultitech("prepare_follow_up", invoice_id=invoice_id),
    )


def ultitech_tools() -> list:
    return [
        ultitech_daily_briefing,
        ultitech_receivables_summary,
        ultitech_overdue_invoices,
        ultitech_due_soon_invoices,
        ultitech_recent_invoice,
        ultitech_oldest_overdue_invoice,
        ultitech_customer_receivables,
        ultitech_month_receivables,
        ultitech_stock_alerts,
        ultitech_pending_approvals,
        ultitech_stalled_procurement,
        ultitech_create_invoice,
        ultitech_prepare_follow_up,
    ]
