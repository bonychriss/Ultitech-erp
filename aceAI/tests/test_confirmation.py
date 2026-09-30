import asyncio

from app.agent import classify_reply, describe_pending
from app.config import get_settings
from tools.files import create_file
from tools.test_erp import create_test_invoice, create_test_payment_voucher, invoice_count


def test_reply_classification():
    assert classify_reply("Yes.") is True
    assert classify_reply("go ahead") is True
    assert classify_reply("No thanks") is False
    assert classify_reply("create another invoice") is None


def test_prepared_invoice_summary():
    text = describe_pending(
        "create_test_invoice",
        {"customer_name": "ABC Ltd", "amount": 500000, "description": ""},
    )
    assert "ABC Ltd" in text
    assert "TSh 500,000" in text
    assert "Do you want me to create it?" in text


def test_consequential_tools_ask_before_valid_writes(ace_env):
    valid = {"customer_name": "ABC Ltd", "amount": 500000, "description": "test"}
    assert asyncio.run(create_test_invoice.needs_approval(None, valid, "call")) is True
    assert asyncio.run(create_test_payment_voucher.needs_approval(None, valid, "call")) is True
    assert asyncio.run(create_test_invoice.needs_approval(None, {"customer_name": "Nobody", "amount": 10, "description": ""}, "call")) is False
    assert asyncio.run(create_test_invoice.needs_approval(None, {"customer_name": "ABC Ltd", "amount": -1, "description": ""}, "call")) is False
    assert create_file.needs_approval is not False
    assert invoice_count() == 0


def test_confirm_rejects_unknown_and_mismatched_actions(client):
    from app.main import app

    store = app.state.store
    conversation_id = store.create_conversation()
    pending = store.create_pending(
        conversation_id,
        "create_test_invoice",
        {"customer_name": "ABC Ltd", "amount": 500000},
        "prepared",
        {"not": "a run state"},
        "call-1",
    )
    missing = client.post("/api/tools/create_test_invoice/confirm", json={"pending_id": "missing", "approved": True})
    assert missing.status_code == 404
    mismatch = client.post(
        "/api/tools/create_file/confirm",
        json={"pending_id": pending["id"], "approved": True},
    )
    assert mismatch.status_code == 409
    assert store.get_pending(pending["id"])["status"] == "pending"
    assert invoice_count() == 0


def test_confirm_does_not_write_when_saved_state_is_invalid(ace_env, monkeypatch):
    from fastapi.testclient import TestClient

    monkeypatch.setenv("OPENAI_API_KEY", "sk-test-not-used")
    get_settings.cache_clear()
    from app.main import app

    with TestClient(app) as client:
        store = app.state.store
        conversation_id = store.create_conversation()
        pending = store.create_pending(
            conversation_id,
            "create_test_invoice",
            {"customer_name": "ABC Ltd", "amount": 500000},
            "prepared",
            {"not": "a run state"},
            "call-1",
        )
        response = client.post(
            "/api/tools/create_test_invoice/confirm",
            json={"pending_id": pending["id"], "approved": True},
        )
        assert response.status_code == 502
        assert store.get_pending(pending["id"])["status"] == "error"
        assert invoice_count() == 0
        again = client.post(
            "/api/tools/create_test_invoice/confirm",
            json={"pending_id": pending["id"], "approved": True},
        )
        assert again.status_code == 409
        assert invoice_count() == 0
