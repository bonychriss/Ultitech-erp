const historyEl = document.querySelector("#history");
const messagesEl = document.querySelector("#messages");
const confirmEl = document.querySelector("#confirm");
const statusEl = document.querySelector("#status");
const form = document.querySelector("#composer");
const input = document.querySelector("#input");
const sendButton = form.querySelector("button");
const settingsForm = document.querySelector("#settings");
const providerInput = document.querySelector("#provider");
const modeEl = document.querySelector("#mode");

let conversationId = null;
let pending = null;
let timerId = null;
let timerStarted = 0;

function setStatus(text) {
  statusEl.textContent = text;
}

function formatDuration(totalSeconds) {
  const seconds = Math.max(0, Math.round(Number(totalSeconds) || 0));
  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  const secs = seconds % 60;
  const unit = (count, name) => `${count} ${name}${count === 1 ? "" : "s"}`;
  return `${unit(hours, "hour")}, ${unit(minutes, "minute")}, ${unit(secs, "second")}`;
}

function startTimer(label) {
  stopTimer();
  timerStarted = performance.now();
  const tick = () => {
    const elapsed = (performance.now() - timerStarted) / 1000;
    setStatus(`${label} · ${formatDuration(elapsed)}`);
  };
  tick();
  timerId = setInterval(tick, 1000);
}

function stopTimer() {
  if (timerId) clearInterval(timerId);
  timerId = null;
}

function addMessage(role, text, elapsedSeconds) {
  const empty = messagesEl.querySelector(".empty");
  if (empty) empty.remove();
  const article = document.createElement("article");
  article.className = role === "user" ? "user" : "ace";
  const who = document.createElement("span");
  who.textContent = role === "user" ? "You" : "ACE";
  const body = document.createElement("p");
  body.textContent = text;
  article.append(who, body);
  if (role !== "user" && elapsedSeconds != null) {
    const time = document.createElement("p");
    time.className = "elapsed";
    time.textContent = `Time used: ${formatDuration(elapsedSeconds)}`;
    article.append(time);
  }
  messagesEl.append(article);
  messagesEl.scrollTop = messagesEl.scrollHeight;
}

function showPending(action) {
  pending = action;
  confirmEl.hidden = !action;
  confirmEl.replaceChildren();
  if (!action) return;
  const text = document.createElement("p");
  text.textContent = action.summary;
  const actions = document.createElement("div");
  actions.className = "actions";
  const yes = document.createElement("button");
  yes.className = "yes";
  yes.type = "button";
  yes.textContent = "Confirm";
  yes.addEventListener("click", () => decide(true));
  const no = document.createElement("button");
  no.className = "no";
  no.type = "button";
  no.textContent = "Cancel";
  no.addEventListener("click", () => decide(false));
  actions.append(yes, no);
  confirmEl.append(text, actions);
  setStatus("Waiting for confirmation");
}

function renderConversation(data) {
  messagesEl.replaceChildren();
  if (!data.messages.length) {
    const empty = document.createElement("p");
    empty.className = "empty";
    empty.textContent = "Ask ACE to do something on this PC, or to prepare a test invoice.";
    messagesEl.append(empty);
  }
  for (const message of data.messages) addMessage(message.role, message.content, message.elapsed_seconds);
  showPending(data.pending_action);
  if (!data.pending_action) setStatus("Ready");
}

async function loadHistory() {
  const response = await fetch("/api/conversations");
  const items = await response.json();
  historyEl.replaceChildren();
  for (const item of items) {
    const li = document.createElement("li");
    const button = document.createElement("button");
    button.type = "button";
    button.textContent = item.title;
    if (item.id === conversationId) button.className = "active";
    button.addEventListener("click", () => openConversation(item.id));
    li.append(button);
    historyEl.append(li);
  }
}

async function openConversation(id) {
  conversationId = id;
  const response = await fetch(`/api/conversations/${id}`);
  if (!response.ok) {
    setStatus("Could not open that conversation.");
    return;
  }
  renderConversation(await response.json());
  await loadHistory();
}

function resetConversation() {
  stopTimer();
  conversationId = null;
  pending = null;
  messagesEl.replaceChildren();
  const empty = document.createElement("p");
  empty.className = "empty";
  empty.textContent = "Ask ACE to do something on this PC, or to prepare a test invoice.";
  messagesEl.append(empty);
  showPending(null);
  setStatus("Ready");
  loadHistory();
}

async function sendMessage(message) {
  sendButton.disabled = true;
  input.disabled = true;
  const local = modeEl.textContent.startsWith("Local");
  startTimer(local ? "ACE is working" : "ACE is working");
  addMessage("user", message);
  try {
    const response = await fetch("/api/chat", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ message, conversation_id: conversationId }),
    });
    const data = await response.json();
    stopTimer();
    const waited = (performance.now() - timerStarted) / 1000;
    if (!response.ok) {
      const detail = data.detail || "ACE could not complete that request.";
      addMessage("ace", detail, waited);
      setStatus(detail);
      return;
    }
    conversationId = data.conversation_id;
    addMessage("ace", data.message, data.elapsed_seconds ?? waited);
    showPending(data.pending_action);
    if (!data.pending_action) {
      const latest = (data.actions || []).at(-1);
      setStatus(latest ? `${latest.tool_name}: ${latest.status}` : "Ready");
    }
    await loadHistory();
  } catch (_error) {
    stopTimer();
    setStatus("Could not reach ACE.");
  } finally {
    stopTimer();
    sendButton.disabled = false;
    input.disabled = false;
    input.focus();
  }
}

async function decide(approved) {
  if (!pending) return;
  const action = pending;
  confirmEl.querySelectorAll("button").forEach((button) => {
    button.disabled = true;
  });
  sendButton.disabled = true;
  startTimer(approved ? "Running the confirmed action" : "Cancelling");
  try {
    const response = await fetch(`/api/tools/${encodeURIComponent(action.tool_name)}/confirm`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ pending_id: action.id, approved }),
    });
    const data = await response.json();
    stopTimer();
    const waited = (performance.now() - timerStarted) / 1000;
    if (!response.ok) {
      const detail = data.detail || "Could not confirm that action.";
      addMessage("ace", detail, data.elapsed_seconds ?? waited);
      setStatus(detail);
      return;
    }
    addMessage("user", approved ? "Yes." : "No.");
    addMessage("ace", data.message, data.elapsed_seconds ?? waited);
    showPending(data.pending_action);
    if (!data.pending_action) setStatus(approved ? "Confirmed" : "Cancelled");
    await loadHistory();
  } catch (_error) {
    stopTimer();
    setStatus("Could not reach ACE.");
  } finally {
    stopTimer();
    sendButton.disabled = false;
  }
}

function showProviderFields() {
  const local = providerInput.value === "local";
  document.querySelector("#local-fields").hidden = !local;
  document.querySelector("#openai-fields").hidden = local;
}

function showMode(settings) {
  const model = settings.provider === "local" ? settings.local_model : settings.openai_model;
  const label = settings.provider === "local" ? "Local" : "OpenAI";
  modeEl.textContent = `${label} · ${model}`;
}

async function loadSettings() {
  const response = await fetch("/api/settings");
  if (!response.ok) return;
  const settings = await response.json();
  providerInput.value = settings.provider;
  document.querySelector("#local-model").value = settings.local_model;
  document.querySelector("#openai-model").value = settings.openai_model;
  document.querySelector("#ollama-url").value = settings.ollama_base_url;
  document.querySelector("#openai-key").value = "";
  document.querySelector("#openai-key").placeholder = settings.openai_key_set
    ? "A key is saved. Leave blank to keep it."
    : "Paste an OpenAI API key";
  showProviderFields();
  showMode(settings);
}

document.querySelector("#settings-toggle").addEventListener("click", () => {
  settingsForm.hidden = !settingsForm.hidden;
});

providerInput.addEventListener("change", showProviderFields);

settingsForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  const note = document.querySelector("#settings-note");
  note.textContent = "Saving";
  const payload = {
    provider: providerInput.value,
    local_model: document.querySelector("#local-model").value.trim(),
    openai_model: document.querySelector("#openai-model").value.trim(),
    ollama_base_url: document.querySelector("#ollama-url").value.trim(),
  };
  const key = document.querySelector("#openai-key").value.trim();
  if (key) payload.openai_api_key = key;
  try {
    const response = await fetch("/api/settings", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    const data = await response.json();
    if (!response.ok) {
      note.textContent = data.detail || "Could not save settings.";
      return;
    }
    document.querySelector("#openai-key").value = "";
    showMode(data);
    note.textContent = data.provider === "local" ? "Using the local model." : "Using OpenAI.";
  } catch (_error) {
    note.textContent = "Could not reach ACE.";
  }
});

form.addEventListener("submit", (event) => {
  event.preventDefault();
  const message = input.value.trim();
  if (!message) return;
  input.value = "";
  sendMessage(message);
});

document.querySelector("#new-chat").addEventListener("click", resetConversation);
loadSettings();
resetConversation();
