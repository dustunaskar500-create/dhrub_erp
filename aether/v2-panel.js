/**
 * Aether v2 — Floating Command Panel
 * Self-contained vanilla JS. Works on any ERP page, requires no build step.
 * Drop into ERP HTML: <script src="/aether/v2-panel.js"></script>
 *
 * Surfaces:
 *   • Floating launcher (bottom-right)
 *   • Sliding panel with chat + quick actions + plan approvals
 *   • Talks to /aether/api/v2/aether.php
 *   • Auto-detects auth token from localStorage
 */
(function () {
  'use strict';
  if (window.__AETHER_V2_PANEL__) return;
  window.__AETHER_V2_PANEL__ = true;

  const API = '/aether/api/v2/aether.php';

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

  // ── styles ─────────────────────────────────────────────────────────────
  const css = `
    :root{
      --aev-bg-0:#0a0c12; --aev-bg-1:#0f1320; --aev-bg-2:#161a2a; --aev-bg-3:#1d2235;
      --aev-line:#252a40; --aev-text:#e7eaf3; --aev-muted:#8b91a8;
      --aev-accent:#7af0c6; --aev-accent-2:#5dd1f5; --aev-warn:#f5c465; --aev-bad:#ef6c6c; --aev-plasma:#c3a6ff;
    }
    .aev-launcher{position:fixed;bottom:24px;right:24px;width:56px;height:56px;border-radius:18px;cursor:pointer;z-index:99998;
      background:radial-gradient(circle at 30% 25%,var(--aev-accent),transparent 60%),radial-gradient(circle at 70% 75%,var(--aev-plasma),transparent 60%),#0a0c12;
      box-shadow:0 12px 36px rgba(0,0,0,.45),0 0 28px rgba(122,240,198,.4),inset 0 0 0 1px rgba(255,255,255,.1);
      display:grid;place-items:center;transition:transform .25s,box-shadow .25s;font-family:'Outfit',system-ui,sans-serif}
    .aev-launcher::after{content:'';width:18px;height:18px;background:#0a0c12;border-radius:50%;display:block}
    .aev-launcher:hover{transform:translateY(-3px) scale(1.04);box-shadow:0 14px 44px rgba(0,0,0,.5),0 0 36px rgba(122,240,198,.55)}
    .aev-launcher.open{transform:scale(.85);opacity:.5}
    .aev-launcher .aev-pulse{position:absolute;inset:-6px;border-radius:22px;border:1px solid rgba(122,240,198,.5);animation:aevPulse 2.4s infinite}
    @keyframes aevPulse{0%{transform:scale(1);opacity:.7}100%{transform:scale(1.4);opacity:0}}
    .aev-launcher .aev-badge{position:absolute;top:-6px;right:-6px;background:var(--aev-bad);color:#fff;font-size:10px;font-weight:700;padding:3px 6px;border-radius:99px;border:2px solid #0a0c12;display:none}
    .aev-launcher .aev-badge.show{display:block}

    .aev-panel{position:fixed;bottom:24px;right:24px;width:420px;max-width:calc(100vw - 32px);height:640px;max-height:calc(100vh - 48px);
      background:linear-gradient(180deg,var(--aev-bg-2),var(--aev-bg-1));border:1px solid var(--aev-line);border-radius:18px;color:var(--aev-text);
      font-family:'Outfit',system-ui,sans-serif;display:none;flex-direction:column;z-index:99999;overflow:hidden;
      box-shadow:0 28px 80px rgba(0,0,0,.55),0 0 0 1px rgba(255,255,255,.04);transform:translateY(20px);opacity:0;transition:transform .3s,opacity .3s}
    .aev-panel.open{display:flex;transform:translateY(0);opacity:1}

    .aev-head{display:flex;align-items:center;gap:12px;padding:14px 16px;border-bottom:1px solid var(--aev-line)}
    .aev-mark{width:32px;height:32px;border-radius:10px;background:radial-gradient(circle at 30% 25%,var(--aev-accent),transparent 60%),radial-gradient(circle at 70% 75%,var(--aev-plasma),transparent 60%);display:grid;place-items:center;flex-shrink:0}
    .aev-mark::after{content:'';width:10px;height:10px;background:var(--aev-bg-0);border-radius:50%}
    .aev-title{flex:1;min-width:0}
    .aev-title h3{margin:0;font-size:14px;font-weight:600}
    .aev-title .aev-sub{font-size:11px;color:var(--aev-muted);letter-spacing:.06em}
    .aev-title .aev-sub::before{content:'';display:inline-block;width:6px;height:6px;background:var(--aev-accent);border-radius:50%;margin-right:6px;box-shadow:0 0 6px var(--aev-accent)}
    .aev-iconbtn{width:32px;height:32px;border-radius:8px;border:none;background:var(--aev-bg-3);color:var(--aev-muted);cursor:pointer;display:grid;place-items:center;font-size:12px;transition:.2s}
    .aev-iconbtn:hover{color:var(--aev-text);background:var(--aev-line)}

    .aev-tabs{display:flex;gap:2px;padding:8px 12px 0;background:var(--aev-bg-1)}
    .aev-tab{flex:1;padding:8px 10px;font-size:12px;font-weight:500;color:var(--aev-muted);background:transparent;border:none;cursor:pointer;border-bottom:2px solid transparent;transition:.2s}
    .aev-tab.active{color:var(--aev-accent);border-bottom-color:var(--aev-accent)}
    .aev-tab:hover:not(.active){color:var(--aev-text)}
    .aev-tab i{margin-right:6px;font-size:11px}

    .aev-body{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:10px}
    .aev-body::-webkit-scrollbar{width:6px}
    .aev-body::-webkit-scrollbar-thumb{background:var(--aev-line);border-radius:3px}

    .aev-msg{max-width:88%;padding:10px 14px;border-radius:14px;font-size:13.5px;line-height:1.55;word-wrap:break-word}
    .aev-msg.user{background:var(--aev-accent);color:#031;margin-left:auto;border-bottom-right-radius:4px;font-weight:500}
    .aev-msg.bot{background:var(--aev-bg-3);border:1px solid var(--aev-line);border-bottom-left-radius:4px}
    .aev-msg.bot strong{color:var(--aev-accent)}
    .aev-msg.bot code{background:rgba(0,0,0,.3);padding:1px 5px;border-radius:3px;font-family:'JetBrains Mono',monospace;font-size:.85em;color:var(--aev-accent-2)}
    .aev-msg.bot em{color:var(--aev-muted)}

    .aev-cards{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-top:6px}
    .aev-cards .aev-cardlet{padding:10px;background:var(--aev-bg-2);border:1px solid var(--aev-line);border-radius:8px}
    .aev-cards .aev-cardlet .l{font-size:10px;color:var(--aev-muted);letter-spacing:.08em;text-transform:uppercase}
    .aev-cards .aev-cardlet .v{font-size:14px;font-weight:600;margin-top:2px}

    .aev-plan{margin-top:8px;padding:14px;background:linear-gradient(180deg,rgba(122,240,198,.06),transparent);border:1px solid rgba(122,240,198,.3);border-radius:10px;font-size:12.5px}
    .aev-plan .aev-plan-h{font-weight:600;color:var(--aev-accent);margin-bottom:6px;font-size:11px;letter-spacing:.08em;text-transform:uppercase}
    .aev-plan .aev-plan-text{margin-bottom:12px;line-height:1.5}
    .aev-plan-actions{display:flex;gap:8px}
    .aev-plan-actions button{flex:1;padding:8px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;border:1px solid var(--aev-line);background:var(--aev-bg-3);color:var(--aev-text);transition:.2s}
    .aev-plan-actions .approve{background:linear-gradient(135deg,var(--aev-accent),var(--aev-accent-2));color:#031;border-color:transparent}
    .aev-plan-actions .approve:hover{box-shadow:0 6px 20px rgba(122,240,198,.3)}
    .aev-plan-actions .reject:hover{color:var(--aev-bad);border-color:var(--aev-bad)}

    .aev-quick{display:flex;flex-wrap:wrap;gap:6px;padding:10px 16px;border-top:1px solid var(--aev-line);background:var(--aev-bg-1)}
    .aev-quick button{padding:6px 11px;font-size:11px;font-weight:500;background:var(--aev-bg-3);border:1px solid var(--aev-line);color:var(--aev-muted);border-radius:99px;cursor:pointer;transition:.2s;font-family:inherit}
    .aev-quick button:hover{color:var(--aev-accent);border-color:var(--aev-accent)}

    .aev-input{display:flex;gap:8px;padding:14px;border-top:1px solid var(--aev-line);background:var(--aev-bg-1)}
    .aev-input input{flex:1;padding:10px 14px;border:1px solid var(--aev-line);background:var(--aev-bg-2);color:var(--aev-text);border-radius:10px;font-family:inherit;font-size:13px;outline:none;transition:.2s}
    .aev-input input:focus{border-color:var(--aev-accent)}
    .aev-input button{padding:10px 16px;background:linear-gradient(135deg,var(--aev-accent),var(--aev-accent-2));color:#031;border:none;border-radius:10px;font-weight:600;font-size:12px;cursor:pointer;transition:.2s;font-family:inherit}
    .aev-input button:hover:not(:disabled){box-shadow:0 6px 18px rgba(122,240,198,.4)}
    .aev-input button:disabled{opacity:.5;cursor:not-allowed}

    .aev-typing{display:inline-flex;gap:3px;padding:10px 14px}
    .aev-typing span{width:6px;height:6px;background:var(--aev-muted);border-radius:50%;animation:aevDot 1.4s infinite}
    .aev-typing span:nth-child(2){animation-delay:.2s}
    .aev-typing span:nth-child(3){animation-delay:.4s}
    @keyframes aevDot{0%,80%,100%{opacity:.3;transform:scale(.7)}40%{opacity:1;transform:scale(1)}}

    .aev-panes{flex:1;overflow:hidden;display:flex;flex-direction:column}
    .aev-pane{display:none;flex:1;overflow-y:auto;padding:14px}
    .aev-pane.active{display:block}
    .aev-pane::-webkit-scrollbar{width:6px}
    .aev-pane::-webkit-scrollbar-thumb{background:var(--aev-line);border-radius:3px}

    .aev-status-card{padding:14px;border-radius:10px;border:1px solid var(--aev-line);background:var(--aev-bg-3);margin-bottom:10px}
    .aev-status-card.ok{border-left:3px solid var(--aev-accent)}
    .aev-status-card.warn{border-left:3px solid var(--aev-warn)}
    .aev-status-card.fail{border-left:3px solid var(--aev-bad)}
    .aev-status-card .t{font-weight:500;font-size:13px;margin-bottom:4px}
    .aev-status-card .d{font-size:12px;color:var(--aev-muted);line-height:1.5}

    .aev-issue{padding:12px;border:1px solid var(--aev-line);border-radius:8px;margin-bottom:8px;font-size:12.5px}
    .aev-issue.severity-high,.aev-issue.severity-critical{border-left:3px solid var(--aev-bad)}
    .aev-issue.severity-medium{border-left:3px solid var(--aev-warn)}
    .aev-issue.severity-low{border-left:3px solid var(--aev-accent-2)}
    .aev-issue .t{font-weight:500;margin-bottom:4px}
    .aev-issue .d{color:var(--aev-muted);font-size:11px;line-height:1.5}

    .aev-empty{text-align:center;color:var(--aev-muted);padding:40px 16px;font-size:13px}
    .aev-feedback{display:inline-flex;gap:4px;margin-left:8px}
    .aev-feedback button{width:24px;height:24px;border-radius:6px;border:1px solid var(--aev-line);background:transparent;color:var(--aev-muted);cursor:pointer;font-size:11px;transition:.2s}
    .aev-feedback button:hover{color:var(--aev-accent);border-color:var(--aev-accent)}
    .aev-feedback button.bad:hover{color:var(--aev-bad);border-color:var(--aev-bad)}

    @media(max-width:480px){
      .aev-panel{right:8px;left:8px;bottom:8px;width:auto;height:calc(100vh - 16px);border-radius:14px}
    }
  `;
  const style = document.createElement('style'); style.textContent = css; document.head.appendChild(style);

  // ── DOM ────────────────────────────────────────────────────────────────
  const launcher = document.createElement('div');
  launcher.className = 'aev-launcher';
  launcher.setAttribute('data-testid','aether-v2-launcher');
  launcher.innerHTML = '<div class="aev-pulse"></div><div class="aev-badge" data-testid="aether-v2-badge"></div>';
  document.body.appendChild(launcher);

  const panel = document.createElement('div');
  panel.className = 'aev-panel';
  panel.setAttribute('data-testid','aether-v2-panel');
  panel.innerHTML = `
    <div class="aev-head">
      <div class="aev-mark"></div>
      <div class="aev-title">
        <h3>Aether</h3>
        <div class="aev-sub">Autonomous · local · adaptive</div>
      </div>
      <button class="aev-iconbtn" id="aev-dash" title="Open Command Centre" data-testid="aether-v2-open-dash"><i class="fa-solid fa-up-right-from-square"></i></button>
      <button class="aev-iconbtn" id="aev-clear" title="Clear chat" data-testid="aether-v2-clear"><i class="fa-solid fa-trash"></i></button>
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
      <div class="aev-pane" data-pane="plans" id="aev-plans-pane"><div class="aev-empty">Loading…</div></div>
    </div>
    <div class="aev-quick" id="aev-quick">
      <button data-q="show dashboard" data-testid="aether-v2-chip-dashboard">Dashboard</button>
      <button data-q="health status" data-testid="aether-v2-chip-health">Health</button>
      <button data-q="top donors" data-testid="aether-v2-chip-top-donors">Top donors</button>
      <button data-q="low stock" data-testid="aether-v2-chip-low-stock">Low stock</button>
      <button data-q="forecast donations" data-testid="aether-v2-chip-forecast">Forecast</button>
      <button data-q="recent audit" data-testid="aether-v2-chip-audit">Audit</button>
    </div>
    <div class="aev-input">
      <input id="aev-input" data-testid="aether-v2-input" placeholder="Ask Aether to do anything…" autocomplete="off"/>
      <button id="aev-send" data-testid="aether-v2-send"><i class="fa-solid fa-paper-plane"></i></button>
    </div>
  `;
  document.body.appendChild(panel);

  const body = panel.querySelector('#aev-body');
  const input = panel.querySelector('#aev-input');
  const sendBtn = panel.querySelector('#aev-send');
  const badge = launcher.querySelector('.aev-badge');

  // ── markdown-lite rendering ────────────────────────────────────────────
  function md(t){
    return String(t||'')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>')
      .replace(/\*(?!\s)([^*\n]+?)\*/g,'<em>$1</em>')
      .replace(/`([^`\n]+)`/g,'<code>$1</code>')
      .replace(/\n/g,'<br>');
  }

  // ── chat helpers ───────────────────────────────────────────────────────
  function bubble(role, html, opts={}){
    const div = document.createElement('div');
    div.className = 'aev-msg '+role;
    div.innerHTML = html;
    if (opts.cards) {
      const cards = document.createElement('div');
      cards.className = 'aev-cards';
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
          <button class="approve" data-plan-id="${p.id}">✓ Approve & execute</button>
          <button class="reject" data-plan-id="${p.id}">✗ Reject</button>
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
  function esc(s){return String(s||'').replace(/[&<>]/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;'}[m]))}

  function showTyping(){
    const t = document.createElement('div');
    t.className = 'aev-msg bot';
    t.innerHTML = '<div class="aev-typing"><span></span><span></span><span></span></div>';
    t.dataset.typing = '1';
    body.appendChild(t);
    body.scrollTop = body.scrollHeight;
    return t;
  }

  async function send(text){
    if (!text.trim()) return;
    bubble('user', md(text));
    input.value='';
    sendBtn.disabled = true;
    const t = showTyping();
    try {
      const r = await call('chat', { message: text });
      t.remove();
      if (r.error) { bubble('bot', `<em style="color:var(--aev-bad)">${esc(r.error)}</em>`); }
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
    if (r.ok) { el.querySelector('.aev-plan-text').innerHTML += `<br><strong style="color:var(--aev-accent)">✓ Executed successfully.</strong>`; }
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
      if (!plans.length) { pane.innerHTML='<div class="aev-empty">No pending plans.<br><br>Plans are auto-created when you ask Aether to *record*, *log*, or *update* something.</div>'; return; }
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

  // ── open / close / events ──────────────────────────────────────────────
  function openPanel(){
    panel.classList.add('open');
    launcher.classList.add('open');
    if (!body.children.length) {
      bubble('bot', md("Hi — I'm **Aether**, your ERP's autonomous brain.\n\nI run **fully on-premise** with zero external calls. Try one of the chips below or just type."), { feedback:false });
    }
    setTimeout(()=>input.focus(), 300);
  }
  function closePanel(){
    panel.classList.remove('open');
    launcher.classList.remove('open');
  }
  launcher.addEventListener('click', openPanel);
  panel.querySelector('#aev-close').addEventListener('click', closePanel);
  panel.querySelector('#aev-dash').addEventListener('click', () => window.location.href = '/aether/dashboard.php');
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

  // periodic badge refresh
  if (getToken()) {
    refreshBadge();
    setInterval(refreshBadge, 60000);
  }

  // expose API for ERP integration
  window.Aether = {
    open: openPanel,
    close: closePanel,
    ask: send,
    refreshBadge,
  };
})();
