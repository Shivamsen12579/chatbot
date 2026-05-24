# AES Chatbot — Design Write-up

## AI tools used during development

I used **Claude Code** (Anthropic's CLI agent, Claude Opus 4.7) throughout
the build:

- **Scaffolding** — generated the initial module skeleton: info / routing /
  services / permissions YAMLs, the `FormBase` and `ControllerBase`
  classes, the Twig template, the vanilla-ES2020 widget JS, and the CSS.
- **Drupal config YAML** — drafted the `block_content` type, 8 field
  storages + instances, and the form/view displays from prose specs.
- **Debugging** — pasted real errors verbatim ("csrf_token URL query
  argument is invalid", "Cannot redeclare non-readonly property", Symfony
  Mime "Reply-To must be unique") and got root-cause diagnoses with
  targeted fixes, usually 1-2 lines each.
- **Refactoring** — collapsed an early multi-provider abstraction (OpenAI
  + Anthropic + Groq, dispatched by model prefix) down to a single Groq
  client once the requirements settled.
- **Documentation** — drafted the module README, this write-up, and inline
  doc comments.

Working pattern: every proposed change got read before it was accepted.
Claude is fast at the mechanical work; reviewing it is still my job.

## Key technical decisions

- **Custom `block_content` bundle, not `drupal/ai`** — the editor flow
  ("paste your own key per block") was clearer than centralized provider
  config, and the module stays fully self-contained with no external
  dependencies beyond core.
- **API key stored server-side only** — the widget JS knows only the
  block UUID; the AJAX endpoint loads the entity, reads the key, and
  proxies the upstream call. The key never enters the DOM or browser
  network panel.
- **Groq for the LLM** — OpenAI-compatible wire format, fast inference,
  generous free tier. One-line endpoint swap if we ever change providers.
- **`sessionStorage` for chat history** — per-tab, survives refresh,
  zero server-side state to clean up.
- **`_csrf_request_header_token`** (not `_csrf_token`) — header-based
  CSRF that auto-bypasses for anonymous users, which is correct for a
  public widget. Flood control (30 req/hour per IP + block) handles
  abuse on the anonymous path.
- **Custom Drupal `FormBase`, not Webform**, for the demo form — three
  fields + two emails doesn't justify Webform's machinery. We rely on
  the site's `mailsystem` + `symfony_mailer_lite` stack for delivery via
  `MailManager` and `hook_mail`.

## CRM hand-off — Salesforce design

The demo form already captures `name`, `email`, `company` explicitly — that
half is trivially exportable. The interesting half is **lead extraction
from the chatbot conversation itself**, which I'd build like this:

1. **Prompt the bot to fish for it.** The system prompt nudges the
   assistant to ask for `name` / `work email` / `company` naturally during
   the conversation when intent looks commercial — interspersed questions,
   not all up front.
2. **Sanitize on the server.** After each assistant turn, a server-side
   pass extracts whatever info has accumulated — regex for email, a small
   structured-output LLM call for name + company. Validated + normalized.
   This handles both direct-answer and free-text cases.
3. **Threshold to "qualified."** Once name + email + company are all
   present, the chat is treated as a qualified lead — same shape as a
   direct demo form submission.
4. **Decouple via queue.** Both paths (form submit, chat extraction)
   write a normalized payload to a Drupal queue:
   ```json
   {"source":"chatbot|form","name":"...","email":"...","company":"...",
    "conversation_id":"...","captured_at":"..."}
   ```
5. **Worker pushes to Salesforce.** A `QueueWorker` calls Salesforce —
   either Web-to-Lead (no OAuth, fire-and-forget) or `drupal/salesforce`
   REST (full Lead mapping, dedup-aware), whichever the CRM admin
   prefers.
6. **Audit table.** Every push lands in an `aes_demo_request` custom
   entity with `salesforce_id`, `status`, `last_attempt_at`, full
   payload. Retries on failure. CRM admin reconciles from this table.

**Clean boundaries.** Drupal owns: capture, validation, retry, audit.
CRM admin owns: Salesforce field mapping, dedup rules, OAuth credentials.
Single contract — Drupal POSTs the normalized JSON, the CRM admin defines
the Salesforce-side handler. If Salesforce changes (renamed field, new
object), nothing on the Drupal side changes.

## What I'd improve with more time

- **Streaming responses** (SSE) — measurably faster perceived UX.
- **Honeypot / reCAPTCHA** on the demo form before public launch.
- **Persist demo submissions** to a custom `aes_demo_request` entity
  (currently email-only) — gives admin visibility and is the foundation
  for the audit table above.
- **PHPUnit kernel tests** — controller, form submission, lead extraction.
- **PII redaction** in `aes_chatbot` logs (we quote upstream bodies
  verbatim today).
- **Per-IP rate limit** in addition to per-block flood control.
- **Translatable system prompts** — one per locale for multilingual sites.
