from app.erp import ErpContextError, bind_erp


def test_erp_bridge_rejects_remote_urls():
    try:
        bind_erp("https://example.com/modules/ai-agent/ace.php", "a" * 64)
    except ErpContextError as exc:
        assert "this computer" in str(exc)
    else:
        raise AssertionError("remote bridge was accepted")


def test_erp_bridge_accepts_local_ultitech_path():
    token = bind_erp("http://127.0.0.1/public_html/ultimate/modules/ai-agent/ace.php", "ab" * 32)
    assert token is not None
    from app.erp import reset_erp

    reset_erp(token)


def test_blank_erp_fields_leave_mock_mode():
    assert bind_erp(None, None) is None
    assert bind_erp("", "") is None


def test_quick_route_picks_the_read_tool():
    from app.quick import route_ultitech

    assert route_ultitech("How much do customers owe?") == ("receivables_summary", {})
    assert route_ultitech("Call ultitech_daily_briefing and write today's briefing.")[0] == "daily_briefing"
    assert route_ultitech("Call ultitech_prepare_follow_up with invoice_id 42.") == (
        "prepare_follow_up",
        {"invoice_id": 42},
    )
    assert route_ultitech("How much does Hesu Investment owe?") == (
        "customer_receivables",
        {"name": "hesu investment"},
    )
    assert route_ultitech("What is the weather?") is None


def test_erp_chat_answers_without_the_model(client, monkeypatch):
    from tools.registry import ok

    monkeypatch.setattr(
        "tools.ultitech.call_ultitech",
        lambda action, **payload: ok("Customers owe 10."),
    )
    response = client.post(
        "/api/chat",
        json={
            "message": "How much do customers owe?",
            "erp_api_url": "http://127.0.0.1/public_html/ultimate/modules/ai-agent/ace.php",
            "erp_token": "ab" * 32,
        },
    )
    assert response.status_code == 200
    body = response.json()
    assert body["message"] == "Customers owe 10."
    assert body["actions"][0]["tool_name"] == "ultitech_receivables_summary"


def test_erp_chat_answers_an_unknown_question_without_the_model(client):
    response = client.post(
        "/api/chat",
        json={
            "message": "What is the weather?",
            "erp_api_url": "http://127.0.0.1/public_html/ultimate/modules/ai-agent/ace.php",
            "erp_token": "ab" * 32,
        },
    )
    assert response.status_code == 200
    assert "briefing" in response.json()["message"].lower()
