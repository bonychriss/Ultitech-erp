import json

RISK = {
    "open_application": "safe",
    "open_folder": "safe",
    "open_file": "safe",
    "read_file": "safe",
    "search_web": "safe",
    "get_current_time": "safe",
    "get_system_information": "safe",
    "get_customer": "safe",
    "get_customer_balance": "safe",
    "get_stock": "safe",
    "generate_test_report": "safe",
    "create_file": "consequential",
    "create_test_invoice": "consequential",
    "ultitech_create_invoice": "consequential",
    "create_test_payment_voucher": "consequential",
}


def ok(message: str, **extra) -> str:
    return json.dumps({"ok": True, "message": message, **extra})


def fail(message: str, **extra) -> str:
    return json.dumps({"ok": False, "message": message, **extra})


async def guarded(name: str, arguments: dict, work) -> str:
    from app.memory import log_tool

    try:
        result = work()
        if not isinstance(result, str):
            result = json.dumps(result)
        try:
            status = "executed" if json.loads(result).get("ok") else "error"
        except json.JSONDecodeError:
            status = "executed"
    except Exception:
        result = fail("The tool failed before it could finish.")
        status = "error"
    log_tool(name, arguments, result, status)
    return result


def get_tools() -> list:
    from tools.files import create_file, open_file, read_file
    from tools.test_erp import (
        create_test_invoice,
        create_test_payment_voucher,
        generate_test_report,
        get_customer,
        get_customer_balance,
        get_stock,
    )
    from tools.web import search_web
    from tools.windows import get_current_time, get_system_information, open_application, open_folder

    return [
        open_application,
        open_folder,
        open_file,
        read_file,
        create_file,
        search_web,
        get_current_time,
        get_system_information,
        get_customer,
        get_customer_balance,
        get_stock,
        create_test_invoice,
        create_test_payment_voucher,
        generate_test_report,
    ]


def is_known_tool(name: str) -> bool:
    return name in RISK


def is_consequential(name: str) -> bool:
    return RISK.get(name) == "consequential"
