def test_local_tool_reply_skips_a_second_model_call():
    from agents.tool import FunctionToolResult

    from app.agent import finish_after_tools

    result = finish_after_tools(
        None,
        [FunctionToolResult(tool=None, output='{"ok": true, "message": "Opened Google Chrome."}', run_item=None)],
    )
    assert result.is_final_output is True
    assert result.final_output == "Opened Google Chrome."


def test_settings_default_and_hide_the_api_key(client):
    response = client.get("/api/settings")
    assert response.status_code == 200
    body = response.json()
    assert body["provider"] == "openai"
    assert body["openai_key_set"] is False
    assert "openai_api_key" not in body
    saved = client.post(
        "/api/settings",
        json={
            "provider": "openai",
            "openai_model": "gpt-4o-mini",
            "local_model": "qwen2.5:7b",
            "ollama_base_url": "http://127.0.0.1:11434/v1",
            "openai_api_key": "sk-test-secret",
        },
    )
    assert saved.status_code == 200
    visible = saved.json()
    assert visible["openai_key_set"] is True
    assert "sk-test-secret" not in saved.text
    again = client.get("/api/settings")
    assert "sk-test-secret" not in again.text


def test_switch_to_local_and_back(client, monkeypatch):
    monkeypatch.setattr("app.agent.ollama_is_running", lambda url: False)
    saved = client.post(
        "/api/settings",
        json={
            "provider": "local",
            "openai_model": "gpt-4o-mini",
            "local_model": "qwen2.5:7b",
            "ollama_base_url": "http://127.0.0.1:11434/v1",
        },
    )
    assert saved.status_code == 200
    assert saved.json()["provider"] == "local"
    assert saved.json()["model"] == "qwen2.5:7b"
    chat = client.post("/api/chat", json={"message": "What time is it?"})
    assert chat.status_code == 503
    assert "Ollama" in chat.json()["detail"]
    assert client.get("/api/conversations").json() == []
    remote = client.post(
        "/api/settings",
        json={
            "provider": "local",
            "openai_model": "gpt-4o-mini",
            "local_model": "qwen2.5:7b",
            "ollama_base_url": "http://example.com/v1",
        },
    )
    assert remote.status_code == 400
