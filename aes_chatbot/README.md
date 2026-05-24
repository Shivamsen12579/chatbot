# AES Chatbot

Self-contained custom block type that renders a floating chatbot widget on
any page. Each block instance carries its own Groq API key, model, prompt,
and presentation settings. Conversation history persists across page
refreshes via sessionStorage.

## What ships in this module

| Piece | File(s) |
|---|---|
| Block content type `aes_chatbot` | `config/install/block_content.type.aes_chatbot.yml` |
| 8 fields (API key, model, system prompt, bot name, welcome, max tokens, temperature, demo URL) | `config/install/field.storage.*.yml`, `field.field.*.yml` |
| Form & view displays | `config/install/core.entity_form_display.*.yml`, `core.entity_view_display.*.yml` |
| Widget HTML | `templates/aes-chatbot-widget.html.twig` |
| Widget JS (history, sessionStorage, CSRF) | `js/chatbot.js` |
| Widget CSS | `css/chatbot.css` |
| Groq client (server-side only) | `src/Service/GroqClient.php` |
| Upstream error wrapper | `src/Service/GroqException.php` |
| AJAX endpoint controller | `src/Controller/ChatbotController.php` |
| AJAX route | `aes_chatbot.routing.yml` |
| Permission `use aes chatbot` | `aes_chatbot.permissions.yml` |
| Services + library + module hooks | `aes_chatbot.services.yml`, `aes_chatbot.libraries.yml`, `aes_chatbot.module` |

## How it works

1. Block entity holds the Groq API key + model + prompt + settings.
2. Widget renders on the page; JS only knows the block UUID, never the key.
3. User sends a message → `POST /aes-chatbot/api/message/{uuid}` with the
   message history.
4. Controller loads the block by UUID, reads the key + settings, calls
   `https://api.groq.com/openai/v1/chat/completions` server-side via
   `GroqClient`, returns the assistant reply.
5. Conversation is mirrored to `sessionStorage` so a page refresh keeps the
   chat going in the same tab.

## Security

- API key lives only on the block entity — never sent to the browser.
- View display hides the API key field; it does not render to HTML.
- Endpoint requires `use aes chatbot` permission and an `X-CSRF-Token`
  header for authenticated users (anonymous users are bypassed since they
  have no session to hijack; flood control is the abuse guardrail there).
- Flood control: 30 requests/hour per `{ip, block_uuid}` pair. Constants
  in `ChatbotController::FLOOD_*`.
- User message cap: 2000 chars. History cap: 30 messages.

> ⚠️ Anyone with "Administer block content" can read the key by editing the
> block. Keep that permission tight.

## Install

```bash
lando drush -l https://aes-global.lndo.site en aes_chatbot -y
lando drush -l https://aes-global.lndo.site cr
```

Then grant **Use AES Chatbot** to the roles that should be able to chat
(typically anonymous + authenticated) at `/admin/people/permissions`.

## Create a chatbot instance

1. Get a Groq API key at https://console.groq.com/keys (free tier
   available).
2. Go to `/block/add/aes_chatbot`.
3. Fill in:
   - **Block description** — admin-only label.
   - **Groq API key** — `gsk_...`.
   - **Groq model** — pick from the dropdown
     (`llama-3.1-8b-instant` recommended).
   - **System prompt** — instructions, tone, guardrails.
   - **Bot display name** — header label.
   - **Welcome message** — first message visitors see.
   - **Max response tokens** — caps reply length (default 500).
   - **Temperature** — 0..2.
   - **Request Demo URL** *(optional)* — if set, a "Request a demo" button
     appears at the top of the chat panel and links to this URL. Leave blank
     to hide.
4. Save.

## Place the block

### Via Layout Builder (per-page)
1. Edit the node → **Layout** tab.
2. **Add block** → search for your chatbot instance → place it.

### Via block layout (site-wide)
`/admin/structure/block` → place in a region (footer works well) → set
**Pages** visibility to negate `/admin/*`, `/user/*`, `/node/*/edit`.

## Persistence

Conversation history + open/closed panel state are kept in `sessionStorage`
keyed by `aes-chatbot:<uuid>`. Refresh = same conversation. Close the tab =
fresh chat. No server-side session storage.

## Switching to another LLM provider

This build targets Groq's OpenAI-compatible endpoint. To switch to OpenAI,
Together, Fireworks, or any other OpenAI-compatible API, change the
`ENDPOINT` constant in `src/Service/GroqClient.php`. For providers with a
different wire format (e.g. Anthropic), write a sibling client class and
swap the injected dependency in `ChatbotController`.

## Rotating the key

Edit the block at `/admin/content/block`, paste the new key, save. No
cache clear needed.

## Uninstall

```bash
lando drush -l https://aes-global.lndo.site pmu aes_chatbot -y
```

Drupal will refuse if any `aes_chatbot` block content entities still exist.
Delete them first at `/admin/content/block`.

## Operational notes

- **Cost monitoring:** Groq has a generous free tier; check usage at
  https://console.groq.com/usage.
- **Logs:** errors go to the `aes_chatbot` log channel —
  `/admin/reports/dblog`, filter by type. Each upstream 4xx is logged with
  Groq's exact response body.
- **Tuning flood limits:** edit `FLOOD_THRESHOLD` / `FLOOD_WINDOW` in
  `src/Controller/ChatbotController.php`.
- **Adding models:** edit allowed values on
  `/admin/structure/block/block-content/manage/aes_chatbot/fields/block_content.aes_chatbot.field_aes_chatbot_model`.
