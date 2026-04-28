/**
 * Aether v2 — Floating Command Panel
 * Drop-in vanilla JS. No build step.
 *
 * Features:
 *   • Auth-gated: launcher only appears when a valid JWT is in localStorage
 *   • Periodic re-check — disappears on logout
 *   • Image upload → gallery (with caption suggestions)
 *   • Plan approval inline
 *   • Module reports as cards
 *   • Highlight money/numbers/alerts inside replies
 */
(function () {
  'use strict';
  if (window.__AETHER_V2_PANEL__) return;
  window.__AETHER_V2_PANEL__ = true;

  const SCRIPT = document.currentScript || (function(){ const s=document.getElementsByTagName('script'); return s[s.length-1]; })();
  const BASE = (SCRIPT && SCRIPT.src) ? SCRIPT.src.replace(/[^/]+$/, '') : '/aetherV2/';
  const API  = BASE + 'api/aether.php';
  const DASH = BASE + 'dashboard.php';

  if (!document.getElementById('aev-style-link')) {
    const link = document.createElement('link');
    link.id = 'aev-style-link'; link.rel = 'stylesheet'; link.href = BASE + 'style.css';
    document.head.appendChild(link);
  }
  if (!document.querySelector('link[href*="font-awesome"]')) {
    const fa = document.createElement('link');
    fa.rel = 'stylesheet'; fa.href = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css';
    document.head.appendChild(fa);
  }

  // ── token gate ─────────────────────────────────────────────────────────
  function getToken() {
    for (const k of ['token','authToken','auth_token','jwt','access_token','userToken']) {
      const v = localStorage.getItem(k);
      if (v && v.split('.').length === 3) return v;
    }
    return null;
  }
  let currentUser = null;

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
  function md(t){
    return String(t||'')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>')
      .replace(/\*(?!\s)([^*\n]+?)\*/g,'<em>$1</em>')
      .replace(/`([^`\n]+)`/g,'<code>$1</code>')
      // highlight ₹ amounts
      .replace(/(₹\s?[\d,]+(?:\.\d+)?)/g, '<span class="amount">$1</span>')
      // alert keywords
      .replace(/\b(low stock|critical|failed|warning|alert|missing|orphan)\b/gi, '<span class="alert">$1</span>')
      .replace(/\n/g,'<br>');
  }

  // ── DOM (only injected when authenticated) ─────────────────────────────
  let launcher, panel, body, input, sendBtn, badge, attachedView, currentAttachment = null;

  function inject() {
    if (launcher) return; // already injected

    launcher = document.createElement('div');
    launcher.className = 'aev-launcher';
    launcher.setAttribute('data-testid','aether-v2-launcher');
    launcher.title = 'Open Aether';
    launcher.innerHTML = '<div class="aev-pulse"></div><div class="aev-launch-glyph"></div><div class="aev-badge" data-testid="aether-v2-badge"></div>';
    document.body.appendChild(launcher);

    panel = document.createElement('div');
    panel.className = 'aev-panel';
    panel.setAttribute('data-testid','aether-v2-panel');
    panel.innerHTML = `
      <div class="aev-panel-head">
        <div class="aev-mark"></div>
        <div class="aev-panel-title">
          <h3>Aether</h3>
          <div class="panel-sub" id="aev-sub">Autonomous · local · adaptive</div>
        </div>
        <button class="aev-iconbtn" id="aev-dash" title="Open Command Centre" data-testid="aether-v2-open-dash"><i class="fa-solid fa-up-right-from-square"></i></button>
        <button class="aev-iconbtn" id="aev-clear" title="Clear chat" data-testid="aether-v2-clear"><i class="fa-solid fa-trash-can"></i></button>
        <button class="aev-iconbtn" id="aev-close" title="Close" data-testid="aether-v2-close"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <div class="aev-tabs">
        <button class="aev-tab active" data-pane="chat" data-testid="aether-v2-tab-chat"><i class="fa-solid fa-comments"></i>Chat</button>
        <button class="aev-tab" data-pane="reports" data-testid="aether-v2-tab-reports"><i class="fa-solid fa-chart-pie"></i>Reports</button>
        <button class="aev-tab" data-pane="health" data-testid="aether-v2-tab-health"><i class="fa-solid fa-heart-pulse"></i>Health</button>
        <button class="aev-tab" data-pane="plans" data-testid="aether-v2-tab-plans"><i class="fa-solid fa-clipboard-check"></i>Plans</button>
      </div>
      <div class="aev-panes">
        <div class="aev-pane active" data-pane="chat">
          <div class="aev-body" id="aev-body" data-testid="aether-v2-body"></div>
        </div>
        <div class="aev-pane" data-pane="reports" id="aev-reports-pane"><div class="aev-empty">Loading…</div></div>
        <div class="aev-pane" data-pane="health" id="aev-health-pane"><div class="aev-empty">Loading…</div></div>
        <div class="aev-pane" data-pane="plans"  id="aev-plans-pane"><div class="aev-empty">Loading…</div></div>
      </div>
      <div class="aev-quick" id="aev-quick">
        <button data-q="show dashboard" data-testid="aether-v2-chip-dashboard"><i class="fa-solid fa-gauge"></i>Dashboard</button>
        <button data-q="report on donations this quarter" data-testid="aether-v2-chip-report"><i class="fa-solid fa-chart-pie"></i>Report</button>
        <button data-q="top donors" data-testid="aether-v2-chip-top-donors"><i class="fa-solid fa-star"></i>Top donors</button>
        <button data-q="low stock" data-testid="aether-v2-chip-low-stock"><i class="fa-solid fa-box"></i>Low stock</button>
        <button data-q="forecast donations" data-testid="aether-v2-chip-forecast"><i class="fa-solid fa-chart-line"></i>Forecast</button>
        <button data-q="health status" data-testid="aether-v2-chip-health"><i class="fa-solid fa-heart-pulse"></i>Health</button>
      </div>
      <div class="aev-attached" id="aev-attached">
        <img id="aev-attached-img" alt="">
        <span class="name" id="aev-attached-name"></span>
        <button id="aev-attached-clear" title="Remove"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <div class="aev-input">
        <button class="attach" id="aev-attach" title="Attach image" data-testid="aether-v2-attach"><i class="fa-solid fa-paperclip"></i></button>
        <input type="file" id="aev-file" accept="image/*" style="display:none">
        <input type="text" id="aev-input" data-testid="aether-v2-input" placeholder="Ask Aether to do anything…" autocomplete="off"/>
        <button class="send" id="aev-send" data-testid="aether-v2-send" title="Send"><i class="fa-solid fa-paper-plane"></i></button>
      </div>
    `;
    document.body.appendChild(panel);

    body    = panel.querySelector('#aev-body');
    input   = panel.querySelector('#aev-input');
    sendBtn = panel.querySelector('#aev-send');
    badge   = launcher.querySelector('.aev-badge');
    attachedView = panel.querySelector('#aev-attached');

    // ── chat ─────────────────────────────────────────────────────────
    panel.querySelectorAll('.aev-tab').forEach(t => {
      t.addEventListener('click', () => {
        panel.querySelectorAll('.aev-tab').forEach(x=>x.classList.remove('active'));
        panel.querySelectorAll('.aev-pane').forEach(x=>x.classList.remove('active'));
        t.classList.add('active');
        const pane = t.dataset.pane;
        panel.querySelector(`.aev-pane[data-pane="${pane}"]`).classList.add('active');
        if (pane==='health') loadHealth();
        if (pane==='plans')  loadPlans();
        if (pane==='reports') loadReports();
      });
    });

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

    // attach handler
    const fileInput = panel.querySelector('#aev-file');
    panel.querySelector('#aev-attach').addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', () => attachFile(fileInput.files[0]));
    panel.querySelector('#aev-attached-clear').addEventListener('click', clearAttachment);

    launcher.addEventListener('click', openPanel);
  }

  function uninject() {
    if (launcher) { launcher.remove(); launcher = null; }
    if (panel)    { panel.remove(); panel = null; }
  }

  function openPanel(){
    panel.classList.add('open');
    launcher.classList.add('open');
    if (!body.children.length) {
      const role = currentUser?.role || 'user';
      const name = currentUser?.full_name || currentUser?.username || 'there';
      bubble('bot',
        md(`Hi **${name}** — I'm **Aether**. I run **fully on-premise** with zero external calls.\n\n` +
           `I can run reports, record donations (with auto-emailed PDF receipts + thank-you SMS), update inventory, draft blog posts, suggest image captions, send custom emails, and more — all proposed as **plans you approve once**.\n\n` +
           `Try a chip below or just type. _Logged in as \`${role}\`._`),
        { feedback:false });
    }
    setTimeout(()=>input.focus(), 300);
  }
  function closePanel(){
    panel.classList.remove('open');
    launcher.classList.remove('open');
  }

  function attachFile(file){
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => {
      const dataUri = reader.result;
      const base64 = dataUri.split(',')[1];
      currentAttachment = { filename: file.name, mime: file.type, data: base64, preview: dataUri };
      attachedView.querySelector('#aev-attached-img').src = dataUri;
      attachedView.querySelector('#aev-attached-name').textContent = file.name + ' (' + Math.round(file.size/1024) + ' KB)';
      attachedView.classList.add('show');
      input.placeholder = 'Add a caption / title (or just press send)…';
      input.focus();
    };
    reader.readAsDataURL(file);
  }
  function clearAttachment(){
    currentAttachment = null;
    attachedView.classList.remove('show');
    input.placeholder = 'Ask Aether to do anything…';
    panel.querySelector('#aev-file').value = '';
  }

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
        <div class="aev-plan-h"><i class="fa-solid fa-bolt"></i> Action plan #${p.id} · needs your approval</div>
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
          fb.innerHTML = `<span style="font-size:11px;color:var(--aev-muted);font-style:italic">Thanks — Aether will learn from this.</span>`;
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
    text = (text||'').trim();
    if (!text && !currentAttachment) return;

    // ── Attachment flow: upload to gallery + suggest caption ─────────
    if (currentAttachment) {
      const att = currentAttachment;
      const userMsg = text || `(uploaded ${att.filename})`;
      bubble('user', md(userMsg) + `<div style="margin-top:6px"><img src="${att.preview}" style="max-width:200px;max-height:140px;border-radius:8px;border:1px solid rgba(255,255,255,.3)"></div>`);
      input.value=''; clearAttachment();
      sendBtn.disabled = true;
      const t = showTyping();
      try {
        const up = await call('upload_image', { filename: att.filename, data: att.data, title: text || att.filename });
        const sug = await call('suggest_caption', { filename: att.filename });
        t.remove();
        bubble('bot', md(`✓ Uploaded **${att.filename}** to gallery (id #${up.id}, ${Math.round((up.size||0)/1024)} KB).`));
        bubble('bot', md(sug.reply || 'Image saved. No caption suggestions available.'));
      } catch (e) {
        t.remove();
        bubble('bot', '<em style="color:var(--aev-bad)">Upload failed — your role may not have gallery permission.</em>');
      } finally {
        sendBtn.disabled = false;
        input.focus();
      }
      return;
    }

    bubble('user', md(text));
    input.value=''; sendBtn.disabled = true;
    const t = showTyping();
    try {
      const r = await call('chat', { message: text });
      t.remove();
      if (r.error) bubble('bot', `<em style="color:var(--aev-bad)">${esc(r.error)}</em>`);
      else bubble('bot', md(r.reply||''), { cards: r.cards, plan: r.plan });
      if (r.plan) refreshBadge();
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
    if (r.ok) {
      let msg = `<br><strong style="color:var(--aev-primary-2)">✓ Executed successfully.</strong>`;
      if (r.result && r.result.receipt) {
        const rc = r.result.receipt;
        msg += `<br><span style="color:var(--aev-muted);font-size:11.5px">Receipt: email ${rc.email?'✓':'✗'} · sms ${rc.sms?'✓':'✗'}` +
               (rc.reasons && rc.reasons.length ? ` <br>📝 ${rc.reasons.map(esc).join(', ')}` : '') + '</span>';
      }
      el.querySelector('.aev-plan-text').innerHTML += msg;
    } else {
      el.querySelector('.aev-plan-text').innerHTML += `<br><strong style="color:var(--aev-bad)">✗ ${esc(r.error||'Failed')}</strong>`;
    }
    el.querySelector('.aev-plan-actions').remove();
    refreshBadge();
  }
  async function rejectPlan(id, el){
    await call('reject_plan',{ plan_id:id });
    el.querySelector('.aev-plan-text').innerHTML += `<br><em>Plan rejected.</em>`;
    el.querySelector('.aev-plan-actions').remove();
    refreshBadge();
  }

  // ── Tabs: Health, Plans, Reports ─────────────────────────────────
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
      if (!plans.length) { pane.innerHTML='<div class="aev-empty">No pending plans.<br><br>Plans appear when you ask Aether to <strong>record</strong>, <strong>log</strong>, <strong>send</strong>, <strong>update</strong>, or <strong>create</strong> something.</div>'; return; }
      pane.innerHTML = '';
      plans.forEach(p => {
        const el = document.createElement('div');
        el.className = 'aev-plan';
        el.innerHTML = `
          <div class="aev-plan-h"><i class="fa-solid fa-bolt"></i> Plan #${p.id} · ${esc(p.intent)}</div>
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

  async function loadReports(){
    const pane = panel.querySelector('#aev-reports-pane');
    const modules = [
      {key:'donations', icon:'hand-holding-heart', label:'Donations'},
      {key:'expenses',  icon:'wallet',             label:'Expenses'},
      {key:'hr',        icon:'user-tie',           label:'HR'},
      {key:'inventory', icon:'box',                label:'Inventory'},
      {key:'programs',  icon:'diagram-project',    label:'Programs'},
      {key:'volunteers',icon:'people-group',       label:'Volunteers'},
      {key:'cms',       icon:'newspaper',          label:'CMS / Blog'},
      {key:'audit',     icon:'clock-rotate-left',  label:'Audit'},
    ];
    pane.innerHTML = '<div style="color:var(--aev-muted);font-size:12px;margin-bottom:10px">Pick a module — Aether builds the report from live ERP data:</div>'
      + '<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px">'
      + modules.map(m => `<button class="aev-btn" style="justify-content:flex-start;width:100%;font-size:12px" data-mod="${m.key}"><i class="fa-solid fa-${m.icon}"></i>${m.label}</button>`).join('')
      + '</div><div id="aev-report-result" style="margin-top:14px"></div>';
    pane.querySelectorAll('button[data-mod]').forEach(b => {
      b.addEventListener('click', async () => {
        const result = pane.querySelector('#aev-report-result');
        result.innerHTML = '<div class="aev-empty"><span class="aev-loader"></span></div>';
        try {
          const r = await call('module_report', { module: b.dataset.mod });
          let html = `<div style="font-size:13px;line-height:1.55">${md(r.text||'')}</div>`;
          if (r.cards && r.cards.length) {
            html = `<div class="aev-cardlets" style="grid-template-columns:repeat(2,1fr);margin-bottom:12px">` +
                   r.cards.map(c => `<div class="aev-cardlet"><div class="l">${esc(c.label)}</div><div class="v">${esc(c.value)}</div></div>`).join('') +
                   '</div>' + html;
          }
          result.innerHTML = html;
        } catch(e) { result.innerHTML = '<div class="aev-empty">Failed to load report.</div>'; }
      });
    });
  }

  async function refreshBadge(){
    try {
      const r = await call('list_plans',{ status:'proposed' });
      const n = (r.plans||[]).length;
      if (badge) {
        if (n>0){ badge.textContent = n; badge.classList.add('show'); }
        else badge.classList.remove('show');
      }
    } catch(e){}
  }

  // ── Auth gate: only inject when user is logged in ─────────────────
  async function authCheck(){
    const t = getToken();
    if (!t) {
      if (launcher) uninject();
      currentUser = null;
      return;
    }
    if (!launcher) {
      try {
        const me = await call('identity');
        currentUser = me?.user || null;
        if (currentUser) {
          inject();
          refreshBadge();
        }
      } catch(e) {}
    }
  }
  authCheck();
  setInterval(authCheck,    30000);
  setInterval(refreshBadge, 60000);
  // Re-check whenever localStorage changes (login/logout in other tab)
  window.addEventListener('storage', authCheck);

  window.Aether = {
    open: () => { if (panel) openPanel(); },
    close: () => { if (panel) closePanel(); },
    ask: send,
    refresh: authCheck,
  };
})();
