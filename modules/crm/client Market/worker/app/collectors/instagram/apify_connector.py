"""Apify Instagram connector — uses dashboard-stored token, never hardcoded."""

from app.integrations.apify.configuration import DEFAULT_ACTOR_ID


class ApifyConnector:
    def __init__(self, token: str, actor_id: str | None = None) -> None:
        if not token:
            raise ValueError("Apify API token is not configured.")
        self._token = token
        self.actor_id = actor_id or DEFAULT_ACTOR_ID
