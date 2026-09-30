import pytest


@pytest.fixture
def ace_env(tmp_path, monkeypatch):
    workspace = tmp_path / "workspace"
    workspace.mkdir()
    monkeypatch.setenv("ACE_DB", str(tmp_path / "ace.db"))
    monkeypatch.setenv("ACE_ERP_DB", str(tmp_path / "erp.db"))
    monkeypatch.setenv("ACE_WORKSPACE", str(workspace))
    monkeypatch.setenv("OPENAI_API_KEY", "")
    monkeypatch.setenv("ACE_ALLOWED_ROOTS", "")
    monkeypatch.setenv("ACE_SETTINGS", str(tmp_path / "settings.json"))
    monkeypatch.setenv("ACE_PROVIDER", "openai")
    from app.config import get_settings

    get_settings.cache_clear()
    return get_settings()


@pytest.fixture
def client(ace_env):
    from fastapi.testclient import TestClient

    from app.main import app

    with TestClient(app) as test_client:
        yield test_client
