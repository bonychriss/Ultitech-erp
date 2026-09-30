import json
import os

import pytest

from tools.browser import set_opener
from tools.files import create_file_needs_approval, perform_create_file, perform_open_file, perform_read_file
from tools.registry import RISK, get_tools
from tools.test_erp import (
    invoice_count,
    perform_create_test_invoice,
    perform_create_test_payment_voucher,
    perform_generate_test_report,
    perform_get_customer,
    perform_get_customer_balance,
    perform_get_stock,
)
from tools.web import perform_search_web
from tools.windows import (
    perform_get_current_time,
    perform_get_system_information,
    perform_open_application,
    perform_open_folder,
)


def _body(result: str) -> dict:
    return json.loads(result)


def test_registry_lists_every_tool(ace_env):
    names = {item.name for item in get_tools()}
    assert names == set(RISK)


def test_open_application_uses_allowlist_without_shell(ace_env, monkeypatch):
    calls = []
    monkeypatch.setattr("tools.windows.start_process", lambda args: calls.append(args))
    assert _body(perform_open_application("notepad"))["ok"] is True
    assert calls == [["notepad.exe"]]
    calls.clear()
    denied = _body(perform_open_application("cmd /c whoami"))
    assert denied["ok"] is False
    assert calls == []


def test_chrome_launch_is_an_argument_list(ace_env, monkeypatch):
    calls = []
    monkeypatch.setattr("tools.windows.start_process", lambda args: calls.append(args))
    result = _body(perform_open_application("chrome"))
    if result["ok"]:
        assert isinstance(calls[0], list)
        assert calls[0][0].lower().endswith("chrome.exe")
    else:
        assert calls == []


def test_open_folder_and_file_stay_in_workspace(ace_env, monkeypatch, tmp_path):
    opened = []
    monkeypatch.setattr(os, "startfile", lambda path: opened.append(path), raising=False)
    folder = ace_env.workspace / "notes"
    folder.mkdir()
    target = folder / "hello.txt"
    target.write_text("hello", encoding="utf-8")
    assert _body(perform_open_folder(str(folder)))["ok"] is True
    assert _body(perform_open_file("notes/hello.txt"))["ok"] is True
    outside = tmp_path / "outside"
    outside.mkdir()
    assert _body(perform_open_folder(str(outside)))["ok"] is False
    assert _body(perform_read_file("notes/hello.txt"))["content"] == "hello"


def test_read_and_create_file_are_blocked_outside_workspace(ace_env, tmp_path):
    secret = tmp_path / "secret.txt"
    secret.write_text("nope", encoding="utf-8")
    blocked = ace_env.workspace / ".env"
    blocked.write_text("OPENAI_API_KEY=hidden", encoding="utf-8")
    assert _body(perform_read_file(str(secret)))["ok"] is False
    assert _body(perform_read_file(".env"))["ok"] is False
    assert _body(perform_create_file("../escape.txt", "x"))["ok"] is False
    assert not (tmp_path / "escape.txt").exists()
    missing = _body(perform_read_file("missing.txt"))
    assert missing["ok"] is False


def test_create_file_confirmation_rule_and_write(ace_env):
    params = {"path": "invoice-note.txt", "content": "draft"}
    assert pytest_run(create_file_needs_approval(None, params, "call")) is True
    assert pytest_run(create_file_needs_approval(None, {"path": "../x.txt", "content": "x"}, "call")) is False
    created = _body(perform_create_file("invoice-note.txt", "draft"))
    assert created["ok"] is True
    assert (ace_env.workspace / "invoice-note.txt").read_text(encoding="utf-8") == "draft"
    assert _body(perform_create_file("invoice-note.txt", "again"))["ok"] is False


def test_search_web_builds_https_query(ace_env):
    opened = []
    set_opener(lambda url: opened.append(url))
    try:
        result = _body(perform_search_web("latest PHP documentation"))
    finally:
        import webbrowser

        set_opener(webbrowser.open)
    assert result["ok"] is True
    assert opened[0].startswith("https://www.google.com/search?q=")
    assert "PHP" in opened[0]
    assert _body(perform_search_web(""))["ok"] is False


def test_time_and_system_information_hide_environment(ace_env):
    current = _body(perform_get_current_time())
    info = _body(perform_get_system_information())
    assert current["ok"] is True
    assert "iso" in current
    assert info["ok"] is True
    assert "OPENAI" not in info["message"]
    assert info["os"]


def test_mock_erp_reads_and_report(ace_env):
    customer = _body(perform_get_customer("ABC Ltd"))
    balance = _body(perform_get_customer_balance("abc"))
    stock = _body(perform_get_stock("cement"))
    report = _body(perform_generate_test_report())
    assert customer["ok"] is True
    assert balance["balance"] == 1500000
    assert stock["items"][0]["sku"] == "CEM-50"
    assert "3 customers" in report["message"]
    ambiguous = _body(perform_get_customer("a"))
    assert ambiguous["ok"] is False
    assert "Several customers" in ambiguous["message"]


def test_invoice_and_voucher_validate_before_write(ace_env):
    assert invoice_count() == 0
    missing = _body(perform_create_test_invoice("Nobody", 10, ""))
    assert missing["ok"] is False
    assert invoice_count() == 0
    bad_amount = _body(perform_create_test_invoice("ABC Ltd", -5, ""))
    assert bad_amount["ok"] is False
    created = _body(perform_create_test_invoice("ABC Ltd", 500000, "test"))
    voucher = _body(perform_create_test_payment_voucher("ABC Ltd", 500000, ""))
    assert created["ok"] is True
    assert voucher["ok"] is True
    assert invoice_count() == 1
    report = _body(perform_generate_test_report())
    assert "1 invoices" in report["message"]
    assert "1 payment" in report["message"]


def pytest_run(awaitable):
    import asyncio

    return asyncio.run(awaitable)
