/**
 * @file
 * AES Chatbot widget behavior.
 *
 * The API key never reaches this file. We POST {messages} to the per-block
 * endpoint and render the reply.
 */
((Drupal, drupalSettings, once) => {
  'use strict';

  const MAX_HISTORY = 20;

  /**
   * Fetch a CSRF token from Drupal core's /session/token endpoint.
   *
   * Cached for the page's lifetime because the token is session-bound,
   * not request-bound — refetching on every message wastes round-trips.
   * On failure we null the cache so the next attempt re-fetches instead
   * of replaying a known-bad token.
   */
  let csrfPromise;
  const getCsrfToken = () => {
    if (!csrfPromise) {
      csrfPromise = fetch(Drupal.url('session/token'), { credentials: 'same-origin' })
        .then((r) => {
          if (!r.ok) throw new Error('CSRF token request failed');
          return r.text();
        })
        .catch((err) => {
          csrfPromise = null;
          throw err;
        });
    }
    return csrfPromise;
  };

  const escapeHtml = (str) =>
    String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');

  /**
   * Very light markdown: paragraphs, line-breaks, **bold**, *italic*, `code`.
   *
   * Used for ASSISTANT messages only — escapeHtml runs first so the
   * markdown patterns operate on already-escaped text, preventing the
   * LLM from injecting raw HTML/script even if the upstream response
   * tries to. User messages bypass this and go through textContent
   * directly (see appendMessage) — never trust user input as markup.
   */
  const renderText = (text) => {
    const safe = escapeHtml(text);
    return safe
      .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
      .replace(/\*([^*]+)\*/g, '<em>$1</em>')
      .replace(/`([^`]+)`/g, '<code>$1</code>')
      .replace(/\n{2,}/g, '</p><p>')
      .replace(/\n/g, '<br>')
      .replace(/^/, '<p>')
      .replace(/$/, '</p>');
  };

  class Chatbot {
    constructor(root, settings) {
      this.root = root;
      this.uuid = root.dataset.aesChatbotUuid;
      this.settings = settings;
      this.history = [];
      this.busy = false;
      this.storageKey = `aes-chatbot:${this.uuid}`;

      this.toggle = root.querySelector('.aes-chatbot__toggle');
      this.panel = root.querySelector('.aes-chatbot__panel');
      this.closeBtn = root.querySelector('.aes-chatbot__close');
      this.messagesEl = root.querySelector('[data-aes-chatbot-messages]');
      this.form = root.querySelector('[data-aes-chatbot-form]');
      this.input = root.querySelector('[data-aes-chatbot-input]');

      this.bindEvents();
      this.restoreState();
    }

    /**
     * Rehydrate the conversation from sessionStorage so a page refresh in
     * the same tab continues the same chat. Clears the rendered welcome
     * message if prior history exists.
     *
     * sessionStorage (not localStorage) is intentional: per-tab, cleared
     * when the tab closes. That gives a natural "fresh session = fresh
     * chat" boundary without server-side state to manage.
     *
     * We defensively re-validate shape on read — sessionStorage is
     * writable by anything else running in the tab, so we never trust
     * what we find there to be the shape we wrote.
     */
    restoreState() {
      let state;
      try {
        const raw = sessionStorage.getItem(this.storageKey);
        if (!raw) return;
        state = JSON.parse(raw);
      } catch (_) {
        return; // storage disabled / bad JSON — start fresh.
      }
      if (!state || typeof state !== 'object') return;

      if (Array.isArray(state.history) && state.history.length > 0) {
        this.history = state.history.filter(
          (m) => m && (m.role === 'user' || m.role === 'assistant') && typeof m.content === 'string'
        );
        if (this.history.length > 0) {
          this.messagesEl.innerHTML = '';
          for (const msg of this.history) {
            this.appendMessage(msg.role, msg.content);
          }
        }
      }

      if (state.open === true) {
        this.toggleOpen(true);
      }
    }

    persistState() {
      try {
        sessionStorage.setItem(
          this.storageKey,
          JSON.stringify({
            history: this.history,
            open: !this.panel.hidden,
          })
        );
      } catch (_) {
        // Quota exceeded or storage blocked — ignore, in-memory state is enough.
      }
    }

    bindEvents() {
      this.toggle.addEventListener('click', () => this.toggleOpen());
      this.closeBtn.addEventListener('click', () => this.toggleOpen(false));
      this.form.addEventListener('submit', (e) => {
        e.preventDefault();
        this.send();
      });
      this.input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          this.send();
        }
      });
      this.input.addEventListener('input', () => this.autoResize());
    }

    toggleOpen(force) {
      const open = force ?? this.panel.hidden;
      this.panel.hidden = !open;
      this.toggle.setAttribute('aria-expanded', String(open));
      if (open) {
        // Slight delay so the textarea is visible before focusing.
        setTimeout(() => this.input.focus(), 50);
      }
      this.persistState();
    }

    autoResize() {
      this.input.style.height = 'auto';
      this.input.style.height = `${Math.min(this.input.scrollHeight, 120)}px`;
    }

    appendMessage(role, text, { typing = false } = {}) {
      const wrap = document.createElement('div');
      wrap.className = `aes-chatbot__message aes-chatbot__message--${role === 'user' ? 'user' : 'bot'}`;
      const bubble = document.createElement('div');
      bubble.className = 'aes-chatbot__bubble';
      if (typing) {
        bubble.classList.add('aes-chatbot__bubble--typing');
        bubble.innerHTML = '<span></span><span></span><span></span>';
      } else if (role === 'user') {
        // User input: textContent — never trust visitor input as markup.
        bubble.textContent = text;
      } else {
        // Assistant: light markdown via renderText, which escapes HTML
        // first and then applies a known-safe pattern set. A jailbroken
        // LLM that tries to emit raw <script> tags still gets escaped.
        bubble.innerHTML = renderText(text);
      }
      wrap.appendChild(bubble);
      this.messagesEl.appendChild(wrap);
      this.messagesEl.scrollTop = this.messagesEl.scrollHeight;
      return wrap;
    }

    setBusy(busy) {
      this.busy = busy;
      this.form.classList.toggle('aes-chatbot__form--busy', busy);
      this.input.disabled = busy;
    }

    async send() {
      if (this.busy) return;
      const text = this.input.value.trim();
      if (!text) return;

      this.input.value = '';
      this.autoResize();
      this.appendMessage('user', text);
      this.history.push({ role: 'user', content: text });
      if (this.history.length > MAX_HISTORY) {
        this.history = this.history.slice(-MAX_HISTORY);
      }
      this.persistState();

      this.setBusy(true);
      const typingNode = this.appendMessage('bot', '', { typing: true });

      try {
        const token = await getCsrfToken();
        const response = await fetch(this.settings.endpoint, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': token,
            Accept: 'application/json',
          },
          body: JSON.stringify({ messages: this.history }),
        });

        let data = {};
        try {
          data = await response.json();
        } catch (_) {
          /* leave empty */
        }

        if (!response.ok) {
          const msg =
            data.error ||
            (response.status === 429
              ? Drupal.t('You have sent too many messages. Please slow down.')
              : Drupal.t('Something went wrong. Please try again.'));
          typingNode.querySelector('.aes-chatbot__bubble').outerHTML =
            `<div class="aes-chatbot__bubble aes-chatbot__bubble--error">${escapeHtml(msg)}</div>`;
          return;
        }

        const reply = String(data.reply || '').trim();
        if (!reply) {
          typingNode.querySelector('.aes-chatbot__bubble').outerHTML =
            `<div class="aes-chatbot__bubble aes-chatbot__bubble--error">${escapeHtml(Drupal.t('Empty response.'))}</div>`;
          return;
        }

        typingNode.querySelector('.aes-chatbot__bubble').outerHTML =
          `<div class="aes-chatbot__bubble">${renderText(reply)}</div>`;
        this.history.push({ role: 'assistant', content: reply });
        this.persistState();
      } catch (err) {
        typingNode.querySelector('.aes-chatbot__bubble').outerHTML =
          `<div class="aes-chatbot__bubble aes-chatbot__bubble--error">${escapeHtml(Drupal.t('Network error. Please check your connection.'))}</div>`;
      } finally {
        this.setBusy(false);
        this.input.focus();
      }
    }
  }

  Drupal.behaviors.aesChatbot = {
    attach(context) {
      once('aes-chatbot', '.js-aes-chatbot', context).forEach((root) => {
        const uuid = root.dataset.aesChatbotUuid;
        const settings = drupalSettings.aesChatbot && drupalSettings.aesChatbot[uuid];
        if (!settings || !settings.endpoint) {
          // Block was rendered but settings didn't come through — bail quietly.
          return;
        }
        new Chatbot(root, settings);
      });
    },
  };
})(Drupal, drupalSettings, once);
