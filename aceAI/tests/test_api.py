def test_chat_without_api_key_does_not_start_a_conversation(client):
    response = client.post("/api/chat", json={"message": "Open notepad"})
    assert response.status_code == 503
    assert "OpenAI" in response.json()["detail"]
    assert client.get("/api/conversations").json() == []


def test_unknown_conversation_and_page(client):
    missing = client.get("/api/conversations/does-not-exist")
    assert missing.status_code == 404
    page = client.get("/")
    assert page.status_code == 200
    assert "ACE AI" in page.text
    script = client.get("/static/app.js")
    assert script.status_code == 200


def test_confirm_unknown_tool(client):
    response = client.post("/api/tools/delete_database/confirm", json={"pending_id": "x", "approved": True})
    assert response.status_code == 404


def test_empty_message_is_rejected(client):
    response = client.post("/api/chat", json={"message": "   "})
    assert response.status_code == 422
