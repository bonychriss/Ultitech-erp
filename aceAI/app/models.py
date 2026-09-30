from pydantic import BaseModel, Field, field_validator


class ChatRequest(BaseModel):
    message: str = Field(min_length=1, max_length=4000)
    conversation_id: str | None = None

    @field_validator("message")
    @classmethod
    def strip_message(cls, value: str) -> str:
        cleaned = value.strip()
        if not cleaned:
            raise ValueError("Message is empty.")
        return cleaned


class ConfirmRequest(BaseModel):
    pending_id: str = Field(min_length=1)
    approved: bool


class ActionView(BaseModel):
    tool_name: str
    status: str
    arguments: dict = Field(default_factory=dict)
    result: str | None = None
    created_at: str | None = None


class PendingView(BaseModel):
    id: str
    tool_name: str
    summary: str
    arguments: dict = Field(default_factory=dict)


class ChatResponse(BaseModel):
    conversation_id: str
    message: str
    pending_action: PendingView | None = None
    actions: list[ActionView] = Field(default_factory=list)
    elapsed_seconds: float | None = None


class ConversationSummary(BaseModel):
    id: str
    title: str
    created_at: str


class SettingsUpdate(BaseModel):
    provider: str
    openai_model: str
    local_model: str
    ollama_base_url: str
    openai_api_key: str | None = None


class SettingsView(BaseModel):
    provider: str
    openai_model: str
    local_model: str
    ollama_base_url: str
    openai_key_set: bool
    model: str


class ConversationDetail(BaseModel):
    id: str
    title: str
    created_at: str
    messages: list[dict]
    actions: list[ActionView]
    pending_action: PendingView | None = None
