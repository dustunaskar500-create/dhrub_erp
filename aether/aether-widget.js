/**
 * Aether AI Widget — standalone vanilla JS, no build step required.
 * Add to your ERP's HTML: <script src="/dhrub_erp/aether/aether-widget.js"></script>
 * Remove the old <AetherChat /> React component from your bundle to avoid duplicates.
 */
(function () {
  'use strict';

  // ── Config ──────────────────────────────────────────────────────────────────
  const API_URL        = '/dhrub_erp/aether/api/aether.php';
  const AUTH_CHECK_URL = '/dhrub_erp/aether/api/auth-check.php';
  const ERP_ROOT       = '/dhrub_erp/';

  // ── JWT helpers ─────────────────────────────────────────────────────────────
  function getToken() {
    const keys = ['token', 'authToken', 'auth_token', 'jwt', 'access_token', 'userToken'];
    for (const k of keys) {
      const v = localStorage.getItem(k);
      if (v && v.split('.').length === 3) return v;
    }
    return null;
  }

  function authHeaders() {
    const t = getToken();
    return t
      ? { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + t }
      : { 'Content-Type': 'application/json' };
  }

  // ── Auth check ───────────────────────────────────────────────────────────────
  async function checkAuth() {
    const token = getToken();
    if (!token) return false;
    try {
      const res = await fetch(AUTH_CHECK_URL, {
        headers: { 'Accept': 'application/json', 'Authorization': 'Bearer ' + token },
        credentials: 'same-origin',
      });
      const ct = res.headers.get('content-type') || '';
      if (!ct.includes('application/json')) {
        const body = await res.text();
        return !body.trimStart().startsWith('<');
      }
      const data = await res.json();
      return data.authenticated === true;
    } catch { return false; }
  }

  // ── Markdown (minimal) ───────────────────────────────────────────────────────
  function mdToHtml(text) {
    return text
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      // Tables: simple pass-through as preformatted
      // Bold
      .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
      // Italic
      .replace(/\*(.+?)\*/g, '<em>$1</em>')
      // Inline code
      .replace(/`(.+?)`/g, '<code style="background:rgba(0,0,0,.08);border-radius:3px;padding:1px 4px;font-size:.85em">$1</code>')
      // Links
      .replace(/\[(.+?)\]\((.+?)\)/g, '<a href="$2" target="_blank" rel="noreferrer" style="color:#059669;text-decoration:underline;word-break:break-all">$1</a>')
      // Headings
      .replace(/^#{1,3} (.+)$/gm, '<strong style="color:#065f46">$1</strong>')
      // Bullet points
      .replace(/^[-*] (.+)$/gm, '<div style="display:flex;gap:6px;margin:2px 0"><span style="margin-top:7px;width:5px;height:5px;border-radius:50%;background:currentColor;flex-shrink:0;opacity:.6"></span><span>$1</span></div>')
      // Line breaks
      .replace(/\n/g, '<br>');
  }

  // ── Conversation state ───────────────────────────────────────────────────────
  let convId = (function() {
    try {
      const s = sessionStorage.getItem('aether_conv_id');
      if (s) return s;
    } catch {}
    const id = 'conv_' + Date.now() + '_' + Math.random().toString(36).slice(2, 7);
    try { sessionStorage.setItem('aether_conv_id', id); } catch {}
    return id;
  })();

  let isTyping = false;

  // ── Build UI ─────────────────────────────────────────────────────────────────
  function buildWidget() {
    const style = document.createElement('style');
    style.textContent = `
      #aether-fab {
        position:fixed;bottom:32px;right:32px;width:60px;height:60px;
        background:#059669;border:none;border-radius:16px;
        box-shadow:0 8px 24px rgba(0,0,0,.18);cursor:pointer;
        font-size:28px;z-index:9999;transition:transform .15s,background .15s;
        display:flex;align-items:center;justify-content:center;
      }
      #aether-fab:hover { background:#047857; }
      #aether-fab.open { transform:rotate(12deg); }
      #aether-panel {
        position:fixed;bottom:108px;right:32px;width:380px;height:580px;
        background:#fff;border:1px solid #e2e8f0;border-radius:24px;
        box-shadow:0 20px 60px rgba(0,0,0,.15);
        display:none;flex-direction:column;overflow:hidden;z-index:9999;
      }
      #aether-panel.open { display:flex; }
      #aether-header {
        background:#059669;color:#fff;padding:16px 18px;
        display:flex;align-items:center;justify-content:space-between;flex-shrink:0;
      }
      #aether-header-left { display:flex;align-items:center;gap:10px; }
      #aether-header h3 { margin:0;font-size:17px;font-weight:600; }
      #aether-header p  { margin:2px 0 0;font-size:11px;opacity:.8; }
      #aether-header-right { display:flex;gap:6px; }
      .aether-hbtn {
        background:transparent;border:none;color:#fff;cursor:pointer;
        border-radius:8px;padding:4px 10px;font-size:13px;
      }
      .aether-hbtn:hover { background:rgba(255,255,255,.15); }
      #aether-messages {
        flex:1;padding:14px;overflow-y:auto;background:#f8fafc;
        display:flex;flex-direction:column;gap:10px;
      }
      .aether-empty {
        text-align:center;color:#94a3b8;padding:40px 16px;
      }
      .aether-empty .aether-emoji { font-size:40px;margin-bottom:10px; }
      .aether-empty strong { display:block;color:#64748b;margin-bottom:4px; }
      .aether-bubble-wrap { display:flex; }
      .aether-bubble-wrap.user { justify-content:flex-end; }
      .aether-bubble {
        max-width:88%;padding:10px 14px;border-radius:18px;
        font-size:13px;line-height:1.55;
      }
      .aether-bubble.user {
        background:#059669;color:#fff;border-bottom-right-radius:4px;
      }
      .aether-bubble.bot {
        background:#fff;border:1px solid #e2e8f0;
        border-bottom-left-radius:4px;box-shadow:0 1px 3px rgba(0,0,0,.06);
        color:#1e293b;
      }
      .aether-bubble.error {
        background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;
        border-bottom-left-radius:4px;
      }
      .aether-typing {
        display:flex;gap:4px;align-items:center;padding:12px 14px;
        background:#fff;border:1px solid #e2e8f0;border-radius:18px;
        border-bottom-left-radius:4px;width:fit-content;
      }
      .aether-dot {
        width:8px;height:8px;background:#059669;border-radius:50%;
        animation:aether-bounce .9s infinite;
      }
      .aether-dot:nth-child(2){animation-delay:.15s}
      .aether-dot:nth-child(3){animation-delay:.3s}
      @keyframes aether-bounce{0%,60%,100%{transform:translateY(0)}30%{transform:translateY(-6px)}}
      #aether-input-area {
        padding:12px 14px;border-top:1px solid #e2e8f0;background:#fff;flex-shrink:0;
      }
      #aether-input-row { display:flex;gap:8px;margin-bottom:10px; }
      #aether-input {
        flex:1;border:1px solid #cbd5e1;border-radius:12px;
        padding:9px 14px;font-size:13px;outline:none;
        transition:border-color .15s;
      }
      #aether-input:focus { border-color:#059669; }
      #aether-send {
        background:#059669;color:#fff;border:none;border-radius:12px;
        padding:9px 18px;font-size:13px;font-weight:500;cursor:pointer;
        transition:background .15s;
      }
      #aether-send:hover { background:#047857; }
      #aether-send:disabled { background:#cbd5e1;cursor:default; }
      #aether-quick { display:flex;flex-wrap:wrap;gap:6px; }
      .aether-quick-btn {
        background:#f1f5f9;border:none;border-radius:20px;
        padding:4px 10px;font-size:11px;cursor:pointer;transition:background .15s;
      }
      .aether-quick-btn:hover { background:#d1fae5;color:#065f46; }
      .aether-quick-btn:disabled { opacity:.4;cursor:default; }
    `;
    document.head.appendChild(style);

    // FAB
    const fab = document.createElement('button');
    fab.id = 'aether-fab';
    fab.title = 'Open Aether AI Assistant';
    fab.innerHTML = '🤖';

    // Panel
    const panel = document.createElement('div');
    panel.id = 'aether-panel';
    panel.innerHTML = `
      <div id="aether-header">
        <div id="aether-header-left">
          <span style="font-size:28px">🤖</span>
          <div>
            <h3>Aether</h3>
            <p>AI Office Assistant</p>
          </div>
        </div>
        <div id="aether-header-right">
          <button class="aether-hbtn" id="aether-clear-btn">Clear</button>
          <button class="aether-hbtn" id="aether-close-btn" style="font-size:20px;padding:2px 8px">×</button>
        </div>
      </div>
      <div id="aether-messages">
        <div class="aether-empty">
          <div class="aether-emoji">🤖</div>
          <strong>Hi! I'm Aether.</strong>
          <span style="font-size:12px">Ask me about donations, payroll, expenses,<br>or generate receipts &amp; payslips.</span>
        </div>
      </div>
      <div id="aether-input-area">
        <div id="aether-input-row">
          <input id="aether-input" type="text" placeholder="Ask Aether anything…" maxlength="2000" autocomplete="off" />
          <button id="aether-send">Send</button>
        </div>
        <div id="aether-quick">
          <button class="aether-quick-btn" data-prompt="Show me dashboard statistics">📊 Dashboard</button>
          <button class="aether-quick-btn" data-prompt="List the 10 most recent donations">💰 Donations</button>
          <button class="aether-quick-btn" data-prompt="Generate a payslip for an employee">📄 Payslip</button>
          <button class="aether-quick-btn" data-prompt="Generate a donation receipt">🧾 Receipt</button>
          <button class="aether-quick-btn" data-prompt="Get details of a donor">👤 Donor</button>
          <button class="aether-quick-btn" data-prompt="Get details of an employee">👥 Employee</button>
        </div>
      </div>
    `;

    document.body.appendChild(fab);
    document.body.appendChild(panel);

    // ── Events ─────────────────────────────────────────────────────────────────
    fab.addEventListener('click', () => {
      const open = panel.classList.toggle('open');
      fab.classList.toggle('open', open);
      if (open) setTimeout(() => document.getElementById('aether-input').focus(), 80);
    });

    document.getElementById('aether-close-btn').addEventListener('click', () => {
      panel.classList.remove('open');
      fab.classList.remove('open');
    });

    document.getElementById('aether-clear-btn').addEventListener('click', async () => {
      if (!confirm('Clear entire chat history?')) return;
      try {
        await fetch(API_URL, {
          method: 'POST', headers: authHeaders(), credentials: 'same-origin',
          body: JSON.stringify({ action: 'clear', conversation_id: convId }),
        });
      } catch {}
      renderMessages([]);
    });

    document.getElementById('aether-input').addEventListener('keydown', e => {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
    });

    document.getElementById('aether-send').addEventListener('click', sendMessage);

    document.querySelectorAll('.aether-quick-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const prompt = btn.dataset.prompt;
        if (!prompt || isTyping) return;
        document.getElementById('aether-input').value = prompt;
        sendMessage();
      });
    });
  }

  // ── Message state ────────────────────────────────────────────────────────────
  let messages = [];

  function renderMessages(msgs) {
    messages = msgs;
    const container = document.getElementById('aether-messages');
    if (!container) return;

    if (msgs.length === 0) {
      container.innerHTML = `
        <div class="aether-empty">
          <div class="aether-emoji">🤖</div>
          <strong>Hi! I'm Aether.</strong>
          <span style="font-size:12px">Ask me about donations, payroll, expenses,<br>or generate receipts &amp; payslips.</span>
        </div>`;
      return;
    }

    container.innerHTML = msgs.map(msg => `
      <div class="aether-bubble-wrap ${msg.role === 'user' ? 'user' : ''}">
        <div class="aether-bubble ${msg.role === 'user' ? 'user' : msg.error ? 'error' : 'bot'}">
          ${msg.role === 'user' ? escHtml(msg.content) : mdToHtml(msg.content)}
        </div>
      </div>
    `).join('');

    container.scrollTop = container.scrollHeight;
  }

  function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  function showTyping() {
    const container = document.getElementById('aether-messages');
    const el = document.createElement('div');
    el.id = 'aether-typing-indicator';
    el.className = 'aether-bubble-wrap';
    el.innerHTML = `<div class="aether-typing">
      <div class="aether-dot"></div>
      <div class="aether-dot"></div>
      <div class="aether-dot"></div>
    </div>`;
    container.appendChild(el);
    container.scrollTop = container.scrollHeight;
  }

  function hideTyping() {
    const el = document.getElementById('aether-typing-indicator');
    if (el) el.remove();
  }

  function setInputDisabled(disabled) {
    const input = document.getElementById('aether-input');
    const send  = document.getElementById('aether-send');
    const btns  = document.querySelectorAll('.aether-quick-btn');
    if (input) input.disabled = disabled;
    if (send)  send.disabled  = disabled;
    btns.forEach(b => b.disabled = disabled);
  }

  // ── Send ─────────────────────────────────────────────────────────────────────
  async function sendMessage() {
    const input = document.getElementById('aether-input');
    const text  = (input ? input.value : '').trim();
    if (!text || isTyping) return;

    if (input) input.value = '';
    isTyping = true;
    setInputDisabled(true);

    messages = [...messages, { role: 'user', content: text }];
    renderMessages(messages);
    showTyping();

    try {
      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), 90000);

      const res = await fetch(API_URL, {
        method: 'POST',
        headers: authHeaders(),
        body: JSON.stringify({ message: text, conversation_id: convId }),
        credentials: 'same-origin',
        signal: controller.signal,
      });

      clearTimeout(timer);

      // Detect HTML interception (login page redirect)
      const ct = res.headers.get('content-type') || '';
      if (!ct.includes('application/json')) {
        const body = await res.text();
        if (body.trimStart().startsWith('<')) {
          hideTyping();
          messages = [...messages, { role: 'assistant', content: '⚠️ Session expired. Please refresh and log in again.', error: true }];
          renderMessages(messages);
          setTimeout(() => { window.location.href = ERP_ROOT; }, 2500);
          return;
        }
        throw new Error('Unexpected response (HTTP ' + res.status + ')');
      }

      if (res.status === 401) {
        hideTyping();
        messages = [...messages, { role: 'assistant', content: '⚠️ Not authenticated. Please log in.', error: true }];
        renderMessages(messages);
        setTimeout(() => { window.location.href = ERP_ROOT; }, 2000);
        return;
      }

      if (!res.ok) throw new Error('Server error (HTTP ' + res.status + ')');

      const data = await res.json();
      hideTyping();

      if (data.error) {
        messages = [...messages, { role: 'assistant', content: '⚠️ ' + data.error, error: true }];
      } else {
        messages = [...messages, { role: 'assistant', content: (data.reply || '').trim() || '(No response)' }];
      }
      renderMessages(messages);

    } catch (e) {
      hideTyping();
      const msg = e && e.name === 'AbortError'
        ? 'Request timed out. Please try again.'
        : (e && e.message) || 'Connection error. Please try again.';
      messages = [...messages, { role: 'assistant', content: '⚠️ ' + msg, error: true }];
      renderMessages(messages);
    } finally {
      isTyping = false;
      setInputDisabled(false);
      setTimeout(() => { const i = document.getElementById('aether-input'); if (i) i.focus(); }, 50);
    }
  }

  // ── Init ─────────────────────────────────────────────────────────────────────
  async function init() {
    const authed = await checkAuth();
    if (!authed) return; // not logged in — don't render widget at all

    buildWidget();

    // Re-check auth every 5 min to hide widget on logout
    setInterval(async () => {
      const still = await checkAuth();
      if (!still) {
        const fab   = document.getElementById('aether-fab');
        const panel = document.getElementById('aether-panel');
        if (fab)   fab.remove();
        if (panel) panel.remove();
      }
    }, 5 * 60 * 1000);

    // Re-check on SPA navigation
    window.addEventListener('popstate', async () => {
      const still = await checkAuth();
      const fab   = document.getElementById('aether-fab');
      if (!still && fab) { fab.remove(); document.getElementById('aether-panel')?.remove(); }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
