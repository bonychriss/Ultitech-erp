import sqlite3
from datetime import datetime, timezone
from pathlib import Path

from agents import function_tool

from app.config import get_settings
from tools.registry import fail, guarded, ok

MAX_AMOUNT = 1_000_000_000_000
CUSTOMERS = (
    ("ABC Ltd", 1_500_000),
    ("Ace Supplies", 80_000),
    ("XYZ Traders", 250_000),
)
STOCK = (
    ("CEM-50", "Cement 50kg", 120),
    ("PAP-A4", "A4 Paper", 40),
)


def _now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def money(value) -> str:
    number = float(value)
    if number.is_integer():
        return f"TSh {number:,.0f}"
    return f"TSh {number:,.2f}"


class MockErpGateway:
    """Local stand-in for the future UltiTech HTTP API."""

    def __init__(self, path: Path):
        self.path = path
        self.path.parent.mkdir(parents=True, exist_ok=True)
        with self._connect() as conn:
            conn.executescript(
                """
                CREATE TABLE IF NOT EXISTS customers (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL UNIQUE,
                    balance REAL NOT NULL
                );
                CREATE TABLE IF NOT EXISTS stock (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    sku TEXT NOT NULL UNIQUE,
                    name TEXT NOT NULL,
                    quantity INTEGER NOT NULL
                );
                CREATE TABLE IF NOT EXISTS invoices (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    customer_name TEXT NOT NULL,
                    amount REAL NOT NULL,
                    description TEXT NOT NULL,
                    created_at TEXT NOT NULL
                );
                CREATE TABLE IF NOT EXISTS vouchers (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    customer_name TEXT NOT NULL,
                    amount REAL NOT NULL,
                    description TEXT NOT NULL,
                    created_at TEXT NOT NULL
                );
                """
            )
            if conn.execute("SELECT COUNT(*) FROM customers").fetchone()[0] == 0:
                conn.executemany("INSERT INTO customers (name, balance) VALUES (?, ?)", CUSTOMERS)
            if conn.execute("SELECT COUNT(*) FROM stock").fetchone()[0] == 0:
                conn.executemany("INSERT INTO stock (sku, name, quantity) VALUES (?, ?, ?)", STOCK)

    def _connect(self) -> sqlite3.Connection:
        conn = sqlite3.connect(self.path)
        conn.row_factory = sqlite3.Row
        return conn

    def customers(self) -> list[dict]:
        with self._connect() as conn:
            rows = conn.execute("SELECT id, name, balance FROM customers ORDER BY name").fetchall()
        return [dict(row) for row in rows]

    def find_customer(self, name: str) -> dict | list[dict] | None:
        needle = (name or "").strip().lower()
        if not needle:
            return None
        rows = self.customers()
        exact = [row for row in rows if row["name"].lower() == needle]
        if len(exact) == 1:
            return exact[0]
        partial = [row for row in rows if needle in row["name"].lower()]
        if len(partial) == 1:
            return partial[0]
        if partial:
            return partial
        return None

    def find_stock(self, item: str) -> list[dict]:
        needle = (item or "").strip().lower()
        with self._connect() as conn:
            rows = conn.execute("SELECT sku, name, quantity FROM stock ORDER BY name").fetchall()
        items = [dict(row) for row in rows]
        if not needle:
            return items
        return [row for row in items if needle in row["name"].lower() or needle in row["sku"].lower()]

    def create_invoice(self, customer_name: str, amount: float, description: str) -> dict:
        with self._connect() as conn:
            cursor = conn.execute(
                """INSERT INTO invoices (customer_name, amount, description, created_at)
                   VALUES (?, ?, ?, ?)""",
                (customer_name, amount, description, _now()),
            )
            invoice_id = cursor.lastrowid
        return {"invoice_id": invoice_id, "customer_name": customer_name, "amount": amount, "description": description}

    def create_voucher(self, customer_name: str, amount: float, description: str) -> dict:
        with self._connect() as conn:
            cursor = conn.execute(
                """INSERT INTO vouchers (customer_name, amount, description, created_at)
                   VALUES (?, ?, ?, ?)""",
                (customer_name, amount, description, _now()),
            )
            voucher_id = cursor.lastrowid
        return {"voucher_id": voucher_id, "customer_name": customer_name, "amount": amount, "description": description}

    def report(self) -> dict:
        with self._connect() as conn:
            invoices = [dict(row) for row in conn.execute("SELECT * FROM invoices ORDER BY id").fetchall()]
            vouchers = [dict(row) for row in conn.execute("SELECT * FROM vouchers ORDER BY id").fetchall()]
        return {
            "customers": self.customers(),
            "stock": self.find_stock(""),
            "invoices": invoices,
            "vouchers": vouchers,
        }


def get_gateway() -> MockErpGateway:
    return MockErpGateway(get_settings().erp_db_path)


def _customer_or_error(name: str, gateway: MockErpGateway) -> tuple[dict | None, str | None]:
    found = gateway.find_customer(name)
    if found is None:
        return None, f"No mock customer matches '{name}'. Ask the user which customer to use."
    if isinstance(found, list):
        names = ", ".join(row["name"] for row in found)
        return None, f"Several customers match '{name}': {names}. Ask the user which one."
    return found, None


def _amount_or_error(amount) -> tuple[float | None, str | None]:
    try:
        number = float(amount)
    except (TypeError, ValueError):
        return None, "The amount must be a number."
    if number != number or number in (float("inf"), float("-inf")):
        return None, "The amount must be a real number."
    if number <= 0:
        return None, "The amount must be greater than zero."
    if number > MAX_AMOUNT:
        return None, "That amount is too large."
    return number, None


def _prepare_money_action(customer_name: str, amount, description: str) -> tuple[dict | None, str | None]:
    if len((description or "").strip()) > 500:
        return None, "The description is too long."
    gateway = get_gateway()
    customer, error = _customer_or_error(customer_name, gateway)
    if error:
        return None, error
    number, error = _amount_or_error(amount)
    if error:
        return None, error
    return {
        "customer_name": customer["name"],
        "amount": number,
        "description": (description or "").strip(),
    }, None


def perform_get_customer(name: str) -> str:
    customer, error = _customer_or_error(name, get_gateway())
    if error:
        return fail(error)
    return ok(
        f"{customer['name']} is on file. Balance {money(customer['balance'])}.",
        customer=customer,
    )


def perform_get_customer_balance(name: str) -> str:
    customer, error = _customer_or_error(name, get_gateway())
    if error:
        return fail(error)
    return ok(
        f"The mock balance for {customer['name']} is {money(customer['balance'])}.",
        customer_name=customer["name"],
        balance=customer["balance"],
    )


def perform_get_stock(item: str = "") -> str:
    rows = get_gateway().find_stock(item)
    if not rows:
        return fail(f"No mock stock matches '{item}'.")
    summary = ", ".join(f"{row['name']} ({row['quantity']})" for row in rows)
    return ok(f"Stock: {summary}.", items=rows)


def perform_create_test_invoice(customer_name: str, amount, description: str = "") -> str:
    prepared, error = _prepare_money_action(customer_name, amount, description)
    if error:
        return fail(error)
    record = get_gateway().create_invoice(**prepared)
    return ok(
        f"Created test invoice {record['invoice_id']} for {record['customer_name']} for {money(record['amount'])}.",
        invoice=record,
    )


def perform_create_test_payment_voucher(customer_name: str, amount, description: str = "") -> str:
    prepared, error = _prepare_money_action(customer_name, amount, description)
    if error:
        return fail(error)
    record = get_gateway().create_voucher(**prepared)
    return ok(
        f"Created test payment voucher {record['voucher_id']} for {record['customer_name']} for {money(record['amount'])}.",
        voucher=record,
    )


def perform_generate_test_report() -> str:
    report = get_gateway().report()
    message = (
        f"Test report: {len(report['customers'])} customers, "
        f"{len(report['invoices'])} invoices, {len(report['vouchers'])} payment vouchers."
    )
    return ok(message, report=report)


async def _money_needs_approval(_ctx, params: dict, _call_id: str) -> bool:
    prepared, error = _prepare_money_action(
        str(params.get("customer_name") or ""),
        params.get("amount"),
        str(params.get("description") or ""),
    )
    return error is None and prepared is not None


@function_tool
async def get_customer(name: str) -> str:
    """Look up a customer in the local mock ERP."""
    return await guarded("get_customer", {"name": name}, lambda: perform_get_customer(name))


@function_tool
async def get_customer_balance(name: str) -> str:
    """Read a customer's balance from the local mock ERP."""
    return await guarded("get_customer_balance", {"name": name}, lambda: perform_get_customer_balance(name))


@function_tool
async def get_stock(item: str = "") -> str:
    """Read stock from the local mock ERP. Leave item empty to list everything."""
    return await guarded("get_stock", {"item": item}, lambda: perform_get_stock(item))


@function_tool(needs_approval=_money_needs_approval)
async def create_test_invoice(customer_name: str, amount: float, description: str = "") -> str:
    """Save a test invoice in the local mock ERP after the user confirms. Does not touch UltiTech."""
    return await guarded(
        "create_test_invoice",
        {"customer_name": customer_name, "amount": amount, "description": description},
        lambda: perform_create_test_invoice(customer_name, amount, description),
    )


@function_tool(needs_approval=_money_needs_approval)
async def create_test_payment_voucher(customer_name: str, amount: float, description: str = "") -> str:
    """Save a test payment voucher in the local mock ERP after the user confirms. Does not touch UltiTech."""
    return await guarded(
        "create_test_payment_voucher",
        {"customer_name": customer_name, "amount": amount, "description": description},
        lambda: perform_create_test_payment_voucher(customer_name, amount, description),
    )


@function_tool
async def generate_test_report() -> str:
    """Summarize mock customers, stock, invoices, and payment vouchers."""
    return await guarded("generate_test_report", {}, perform_generate_test_report)


def invoice_count() -> int:
    gateway = get_gateway()
    with gateway._connect() as conn:
        return conn.execute("SELECT COUNT(*) FROM invoices").fetchone()[0]
