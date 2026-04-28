/**
 * Aether v2 — Floating Command Panel (light theme, ERP-aligned)
 * Drop-in vanilla JS; no build step. Imports its own stylesheet from /aetherV2/style.css
 * if not already loaded.
 */
(function () {
  'use strict';
  if (window.__AETHER_V2_PANEL__) return;
  window.__AETHER_V2_PANEL__ = true;

  // resolve path relative to this script's <script src=...> URL
  const SCRIPT = document.currentScript || (function(){ const s=document.getElementsByTagName('script'); return s[s.length-1]; })();
  const BASE = (SCRIPT && SCRIPT.src) ? SCRIPT.src.replace(/[^/]+$/, '') : '/aetherV2/';
  const API  = BASE + 'api/aether.php';
  const DASH = BASE + 'dashboard.php';

  // Inject stylesheet if not present
  if (!document.getElementById('aev-style-link')) {
    const link = document.createElement('link');
    link.id = 'aev-style-link';
    link.rel = 'stylesheet';
    link.href = BASE + 'style.css';
    document.head.appendChild(link);
  }
  // Make sure FontAwesome is available (load if not)
  if (!document.querySelector('link[href*="font-awesome"]')) {
    const fa = document.createElement('link');
    fa.rel = 'stylesheet';
    fa.href = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css';
    document.head.appendChild(fa);
  }

  // ── token ──────────────────────────────────────────────────────────────
  function getToken() {
    for (const k of ['token','authToken','auth_token','jwt','access_token','userToken']) {
      const v = localStorage.getItem(k);
      if (v && v.split('.').length === 3) return v;
    }
    return null;
  }
  async function call(action, body = {}) {
    const token = getToken();
    if (!token) throw new Error('not_authenticated');
    const r = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type':'application/json', 'Authorization':'Bearer '+token },
      body: JSON.stringify({ action, ...body }),
    });
    return r.json();
  }
  function esc(s){return String(s||'').replace(/[&<>]/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;'}[m]))}
  function md(t){return String(t||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>').replace(/\*(?!\s)([^*\n]+?)\*/g,'<em>$1</em>').replace(/`([^`\n]+)`/g,'<code>$1</code>').replace(/\n/g,'<br>')}

  // ── DOM ────────────────────────────────────────────────────────────────
  const launcher = document.createElement('div');
  launcher.className = 'aev-launcher';
  launcher.setAttribute('data-testid','aether-v2-launcher');
  launcher.title = 'Open Aether';
  launcher.innerHTML = '<div class="aev-pulse"></div><div class="aev-badge" data-testid="aether-v2-badge"></div>';
  document.body.appendChild(launcher);

  const panel = document.createElement('div');
  panel.className = 'aev-panel';
  panel.setAttribute('data-testid','aether-v2-panel');
  panel.innerHTML = `
    <div class="aev-panel-head">
      <div class="aev-mark"></div>
      <div class="aev-panel-title">
        <h3>Aether</h3>
        <div class="panel-sub">Autonomous · local · adaptive</div>
      </div>
      <button class="aev-iconbtn" id="aev-dash" title="Open Command Centre" data-testid="aether-v2-open-dash"><i class="fa-solid fa-up-right-from-square"></i></button>
      <button class="aev-iconbtn" id="aev-clear" title="Clear chat" data-testid="aether-v2-clear"><i class="fa-solid fa-trash-can"></i></button>
      <button class="aev-iconbtn" id="aev-close" title="Close" data-testid="aether-v2-close"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="aev-tabs">
      <button class="aev-tab active" data-pane="chat" data-testid="aether-v2-tab-chat"><i class="fa-solid fa-comments"></i>Chat</button>
      <button class="aev-tab" data-pane="health" data-testid="aether-v2-tab-health"><i class="fa-solid fa-heart-pulse"></i>Health</button>
      <button class="aev-tab" data-pane="plans" data-testid="aether-v2-tab-plans"><i class="fa-solid fa-clipboard-check"></i>Plans</button>
    </div>
    <div class="aev-panes">
      <div class="aev-pane active" data-pane="chat">
        <div class="aev-body" id="aev-body" data-testid="aether-v2-body"></div>
      </div>
      <div class="aev-pane" data-pane="health" id="aev-health-pane"><div class="aev-empty">Loading…</div></div>
      <div class="aev-pane" data-pane="plans"  id="aev-plans-pane"><div class="aev-empty">Loading…</div></div>
    </div>
    <div class="aev-quick" id="aev-quick">
      <button data-q="show dashboard" data-testid="aether-v2-chip-dashboard">📊 Dashboard</button>
      <button data-q="health status" data-testid="aether-v2-chip-health">💚 Health</button>
      <button data-q="top donors" data-testid="aether-v2-chip-top-donors">⭐ Top donors</button>
      <button data-q="low stock" data-testid="aether-v2-chip-low-stock">📦 Low stock</button>
      <button data-q="forecast donations" data-testid="aether-v2-chip-forecast">📈 Forecast</button>
      <button data-q="recent audit" data-testid="aether-v2-chip-audit">📜 Audit</button>
    </div>
    <div class="aev-input">
      <input id="aev-input" data-testid="aether-v2-input" placeholder="Ask Aether — try 'record donation of ₹5000 from \"X\"'" autocomplete="off"/>
      <button id="aev-send" data-testid="aether-v2-send" title="Send"><i class="fa-solid fa-paper-plane"></i></button>
    </div>
  `;
  document.body.appendChild(panel);

  const body    = panel.querySelector('#aev-body');
  const input   = panel.querySelector('#aev-input');
  const sendBtn = panel.querySelector('#aev-send');
  const badge   = launcher.querySelector('.aev-badge');

  // ── chat ───────────────────────────────────────────────────────────────
  function bubble(role, html, opts={}){
    const div = document.createElement('div');
    div.className = 'aev-msg '+role;
    div.innerHTML = html;
    if (opts.cards) {
      const cards = document.createElement('div');
      cards.className = 'aev-cardlets';
      cards.innerHTML = opts.cards.map(c =>
        `<div class="aev-cardlet"><div class="l">${esc(c.label)}</div><div class="v">${esc(c.value)}</div></div>`
      ).join('');
      div.appendChild(cards);
    }
    if (opts.plan) {
      const p = opts.plan;
      const planEl = document.createElement('div');
      planEl.className = 'aev-plan';
      planEl.innerHTML = `
        <div class="aev-plan-h">⚡ Action plan #${p.id} — needs approval</div>
        <div class="aev-plan-text">${md(p.preview||'')}</div>
        <div class="aev-plan-actions">
          <button class="approve">✓ Approve & execute</button>
          <button class="reject">✗ Reject</button>
        </div>`;
      div.appendChild(planEl);
      planEl.querySelector('.approve').addEventListener('click', () => approvePlan(p.id, planEl));
      planEl.querySelector('.reject').addEventListener('click', () => rejectPlan(p.id, planEl));
    }
    if (role === 'bot' && opts.feedback !== false) {
      const fb = document.createElement('div');
      fb.className = 'aev-feedback';
      fb.innerHTML = '<button title="Helpful" data-fb="1"><i class="fa-solid fa-thumbs-up"></i></button>'
                   + '<button title="Not helpful" class="bad" data-fb="-1"><i class="fa-solid fa-thumbs-down"></i></button>';
      fb.querySelectorAll('button').forEach(b => {
        b.addEventListener('click', async () => {
          await call('feedback',{score:Number(b.dataset.fb)});
          fb.innerHTML = `<span style="font-size:11px;color:var(--aev-muted)">Thanks — Aether will learn from this.</span>`;
        });
      });
      div.appendChild(fb);
    }
    body.appendChild(div);
    body.scrollTop = body.scrollHeight;
    return div;
  }
  function showTyping(){
    const t = document.createElement('div');
    t.className = 'aev-msg bot';
    t.innerHTML = '<div class="aev-typing"><span></span><span></span><span></span></div>';
    body.appendChild(t);
    body.scrollTop = body.scrollHeight;
    return t;
  }

  async function send(text){
    if (!text.trim()) return;
    bubble('user', md(text));
    input.value=''; sendBtn.disabled = true;
    const t = showTyping();
    try {
      const r = await call('chat', { message: text });
      t.remove();
      if (r.error) bubble('bot', `<em style="color:var(--aev-bad)">${esc(r.error)}</em>`);
      else bubble('bot', md(r.reply||''), { cards: r.cards, plan: r.plan });
    } catch (e) {
      t.remove();
      bubble('bot', '<em style="color:var(--aev-bad)">Network or auth error. Are you logged in?</em>');
    } finally {
      sendBtn.disabled = false;
      input.focus();
    }
  }
  async function approvePlan(id, el){
    const r = await call('approve_plan', { plan_id: id });
    if (r.ok) el.querySelector('.aev-plan-text').innerHTML += `<br><strong style="color:var(--aev-primary-2)">✓ Executed successfully.</strong>`;
    else el.querySelector('.aev-plan-text').innerHTML += `<br><strong style="color:var(--aev-bad)">✗ ${esc(r.error||'Failed')}</strong>`;
    el.querySelector('.aev-plan-actions').remove();
    refreshBadge();
  }
  async function rejectPlan(id, el){
    await call('reject_plan',{ plan_id:id });
    el.querySelector('.aev-plan-text').innerHTML += `<br><em>Plan rejected.</em>`;
    el.querySelector('.aev-plan-actions').remove();
    refreshBadge();
  }

  // ── tabs ───────────────────────────────────────────────────────────────
  panel.querySelectorAll('.aev-tab').forEach(t => {
    t.addEventListener('click', () => {
      panel.querySelectorAll('.aev-tab').forEach(x=>x.classList.remove('active'));
      panel.querySelectorAll('.aev-pane').forEach(x=>x.classList.remove('active'));
      t.classList.add('active');
      const pane = t.dataset.pane;
      panel.querySelector(`.aev-pane[data-pane="${pane}"]`).classList.add('active');
      if (pane==='health') loadHealth();
      if (pane==='plans')  loadPlans();
    });
  });
  async function loadHealth(){
    const pane = panel.querySelector('#aev-health-pane');
    pane.innerHTML = '<div class="aev-empty">Running checks…</div>';
    try {
      const r = await call('health');
      pane.innerHTML = '';
      const head = document.createElement('div');
      const cls = r.overall||'ok';
      head.className = 'aev-status-card '+cls;
      head.innerHTML = `<div class="t">${cls==='ok'?'✓ All systems nominal':cls==='warn'?'! Warnings detected':'✗ Failures detected'}</div>
        <div class="d">${r.issue_count||0} open issue(s) · ${(r.checks||[]).length} checks</div>`;
      pane.appendChild(head);
      (r.checks||[]).forEach(c => {
        const d = document.createElement('div');
        d.className = 'aev-status-card '+c.status;
        d.innerHTML = `<div class="t">${esc(c.title)}</div><div class="d">${esc(c.detail||'')}</div>`;
        pane.appendChild(d);
      });
    } catch(e){ pane.innerHTML='<div class="aev-empty">Failed to load health.</div>'; }
  }
  async function loadPlans(){
    const pane = panel.querySelector('#aev-plans-pane');
    pane.innerHTML = '<div class="aev-empty">Loading plans…</div>';
    try {
      const r = await call('list_plans', { status:'proposed' });
      const plans = r.plans || [];
      if (!plans.length) { pane.innerHTML='<div class="aev-empty">No pending plans.<br><br>Plans are auto-created when you ask Aether to <strong>record</strong>, <strong>log</strong>, or <strong>update</strong> something.</div>'; return; }
      pane.innerHTML = '';
      plans.forEach(p => {
        const el = document.createElement('div');
        el.className = 'aev-plan';
        el.innerHTML = `
          <div class="aev-plan-h">Plan #${p.id} · ${esc(p.intent)} · ${esc(p.created_at)}</div>
          <div class="aev-plan-text">${md(p.preview||'')}</div>
          <div class="aev-plan-actions">
            <button class="approve">✓ Approve</button>
            <button class="reject">✗ Reject</button>
          </div>`;
        el.querySelector('.approve').addEventListener('click', () => approvePlan(p.id, el));
        el.querySelector('.reject').addEventListener('click', () => rejectPlan(p.id, el));
        pane.appendChild(el);
      });
    } catch(e){ pane.innerHTML='<div class="aev-empty">Failed to load plans.</div>'; }
  }
  async function refreshBadge(){
    try {
      const r = await call('list_plans',{ status:'proposed' });
      const n = (r.plans||[]).length;
      if (n>0){ badge.textContent = n; badge.classList.add('show'); }
      else badge.classList.remove('show');
    } catch(e){}
  }

  // ── open / close ───────────────────────────────────────────────────────
  function openPanel(){
    panel.classList.add('open');
    launcher.classList.add('open');
    if (!body.children.length) {
      bubble('bot', md("Hi — I'm **Aether**, your ERP's autonomous brain.\n\nI run **fully on-premise** with zero external calls. Try a chip below or just type — I can record donations, log expenses, update salaries, run health checks, forecast trends, and more."), { feedback:false });
    }
    setTimeout(()=>input.focus(), 300);
  }
  function closePanel(){
    panel.classList.remove('open');
    launcher.classList.remove('open');
  }
  launcher.addEventListener('click', openPanel);
  panel.querySelector('#aev-close').addEventListener('click', closePanel);
  panel.querySelector('#aev-dash').addEventListener('click', () => window.location.href = DASH);
  panel.querySelector('#aev-clear').addEventListener('click', async () => {
    await call('clear_history');
    body.innerHTML='';
    bubble('bot', 'Chat cleared.', { feedback:false });
  });
  sendBtn.addEventListener('click', () => send(input.value));
  input.addEventListener('keydown', e => { if (e.key==='Enter' && !e.shiftKey) { e.preventDefault(); send(input.value); }});
  panel.querySelectorAll('#aev-quick button').forEach(b => {
    b.addEventListener('click', () => { input.value = b.dataset.q; send(b.dataset.q); });
  });

  if (getToken()) {
    refreshBadge();
    setInterval(refreshBadge, 60000);
  }

  window.Aether = { open: openPanel, close: closePanel, ask: send, refreshBadge };
})();
