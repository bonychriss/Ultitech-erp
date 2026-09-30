"""Route a signed-in Ultitech question straight to one read tool."""

import re

QUICK_HELP = (
    "I can listen to a command and read this company's records. I can answer "
    "today's briefing, what customers owe, overdue invoices, the latest invoice, "
    "invoices due soon, the oldest overdue invoice, this month's receivables, "
    "which customers are overdue, stock alerts, pending vouchers, and stalled purchase requests."
)

TOOL_LOG_NAMES = {
    "daily_briefing": "ultitech_daily_briefing",
    "receivables_summary": "ultitech_receivables_summary",
    "overdue_invoices": "ultitech_overdue_invoices",
    "due_soon_invoices": "ultitech_due_soon_invoices",
    "oldest_overdue": "ultitech_oldest_overdue_invoice",
    "recent_invoice": "ultitech_recent_invoice",
    "customer_receivables": "ultitech_customer_receivables",
    "month_receivables": "ultitech_month_receivables",
    "stock_alerts": "ultitech_stock_alerts",
    "pending_approvals": "ultitech_pending_approvals",
    "stalled_procurement": "ultitech_stalled_procurement",
    "prepare_follow_up": "ultitech_prepare_follow_up",
}


def route_ultitech(message: str) -> tuple[str, dict] | None:
    text = " ".join(message.strip().lower().split())
    if not text:
        return None

    if "ultitech_prepare_follow_up" in text or re.search(r"follow[- ]?up", text):
        invoice = re.search(r"invoice_id\s+(\d+)", text)
        if invoice:
            return ("prepare_follow_up", {"invoice_id": int(invoice.group(1))})

    explicit = (
        ("ultitech_daily_briefing", "daily_briefing", {}),
        ("ultitech_receivables_summary", "receivables_summary", {}),
        ("ultitech_overdue_invoices", "overdue_invoices", {}),
        ("ultitech_due_soon_invoices", "due_soon_invoices", {}),
        ("ultitech_oldest_overdue_invoice", "oldest_overdue", {}),
        ("ultitech_recent_invoice", "recent_invoice", {}),
        ("ultitech_month_receivables", "month_receivables", {}),
        ("ultitech_stock_alerts", "stock_alerts", {}),
        ("ultitech_pending_approvals", "pending_approvals", {}),
        ("ultitech_stalled_procurement", "stalled_procurement", {}),
        ("ultitech_customer_receivables", "customer_receivables", {"name": ""}),
    )
    for needle, action, arguments in explicit:
        if needle in text:
            return (action, dict(arguments))

    if re.search(r"daily briefing|today'?s briefing|attention today|needs my attention|\bbriefing\b", text):
        return ("daily_briefing", {})
    if re.search(r"\binvoices?\b", text) and re.search(
        r"recent|latest|newest|\blast\b|created|just made|we made|did we create",
        text,
    ):
        return ("recent_invoice", {})
    if re.search(r"\boldest\b", text) and re.search(r"invoice|overdue", text):
        return ("oldest_overdue", {})
    if re.search(r"due soon|due within", text):
        return ("due_soon_invoices", {})
    if re.search(r"receivables for|invoices? (?:for|this) month|this month'?s receivables", text):
        return ("month_receivables", {})
    if re.search(r"low stock|reorder|stock alert|below the minimum", text):
        return ("stock_alerts", {})
    if re.search(r"voucher|pending approval|waiting for approval", text):
        return ("pending_approvals", {})
    if re.search(r"procurement|purchase request|purchase order", text):
        return ("stalled_procurement", {})
    if re.search(r"which customers|customers have overdue|who owes|who is overdue", text):
        return ("customer_receivables", {"name": ""})

    named = re.search(
        r"(?:what does|how much does|balance for)\s+([a-z0-9][a-z0-9 .&'-]{1,80}?)\s+owe\b",
        text,
    )
    if named:
        return ("customer_receivables", {"name": named.group(1).strip()})

    if re.search(r"owe us|currently owe|outstanding|how much do customers|how much is outstanding", text):
        return ("receivables_summary", {})
    if re.search(r"\boverdue\b", text):
        return ("overdue_invoices", {})
    return None
