# ACE AI

ACE is a local AI operator. Phase 1 runs on this PC, separate from UltiTech ERP. It turns a plain-language request into a named tool call, checks that the tool is allowed, and returns the result in a chat window.

## Architecture

```text
User
  -> chat page (HTML)
  -> FastAPI
  -> ACE agent (OpenAI Agents SDK)
  -> tool registry
  -> permission check
  -> tool execution
  -> SQLite log
  -> user
```

Safe tools run immediately. Creating a file, a test invoice, or a test payment voucher pauses first. ACE shows what it prepared and waits. The confirm endpoint resumes that saved agent run. The saved run stays on the server. The browser only receives the tool name, the arguments, and a short summary.

The agent cannot type a shell command or a SQL statement. Every action is a function in `tools/`.

Mock ERP calls go through `MockErpGateway` in `tools/test_erp.py`. A later UltiTech client can replace `get_gateway()` and call the PHP API over HTTP. The agent, the chat API, and the confirm step stay as they are.

## Tools

Safe: `open_application`, `open_folder`, `open_file`, `read_file`, `search_web`, `get_current_time`, `get_system_information`, `get_customer`, `get_customer_balance`, `get_stock`, `generate_test_report`.

Confirmation required: `create_file`, `create_test_invoice`, `create_test_payment_voucher`.

Applications ACE can open: Notepad, Calculator, Explorer, Chrome, and Edge. File tools stay inside `data/workspace` unless `ACE_ALLOWED_ROOTS` adds another folder. `.env` and key files are blocked.

## API

- `POST /api/chat`
- `GET /api/conversations`
- `GET /api/conversations/{id}`
- `POST /api/tools/{tool_name}/confirm`
- `GET /api/settings`
- `POST /api/settings`

Settings in the chat sidebar choose the model provider.

- **Local** uses Ollama on this PC. No OpenAI key. Install Ollama, then `ollama pull qwen2.5:3b`. The 3B model is faster on a laptop processor. `qwen2.5:7b` is slower and more capable.
- **OpenAI** uses `gpt-4o-mini` and an API key. The key is saved on this PC and is never sent back to the browser.

The API key can also stay in `.env`. A key entered in Settings is stored in `data/settings.json`.

## Run on Windows

Python 3.12 is required.

```powershell
cd c:\xampp\htdocs\aceAI
.\.venv\Scripts\python.exe -m pip install -r requirements.txt
copy .env.example .env
```

Put your OpenAI key in `.env`:

```text
OPENAI_API_KEY=sk-...
```

Start ACE:

```powershell
.\.venv\Scripts\python.exe -m app.main
```

Open `http://127.0.0.1:8765`.

Mock customers already loaded: ABC Ltd, Ace Supplies, and XYZ Traders.

Example: "Create a test invoice for ABC Ltd for TSh 500,000." ACE prepares the invoice and waits for Confirm.

## Tests

```powershell
.\.venv\Scripts\python.exe -m pytest
```

The tests cover each tool, path and application limits, the confirm rules, and API errors. A live model call needs a real `OPENAI_API_KEY` and is not part of the automated tests.
