<?php
/**
 * Aether v2 — Command Centre (super_admin only)
 * The dashboard is restricted server-side to super_admin. Other roles see a
 * friendly "use the chat panel instead" message.
 *
 * Tabs:
 *   • Overview        — KPIs, system health, schema watcher, learning
 *   • Pending Tasks   — ALL proposed plans across users; super-admin can
 *                       approve / reject / re-assign each one
 *   • Reports         — Module reports + impact-report history with CSV export
 *   • Schema Diff     — Visual diff between snapshots
 *   • Knowledge Graph — Entity browser, modules
 *   • Audit Trail     — Full audit log with severity filter
 */
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Aether — Command Centre</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Manrope:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="icon" type="image/svg+xml" href="logo.svg">
<link rel="stylesheet" href="style.css">
</head>
<body class="aev-light">

<!-- ── Auth + role overlay ── -->
<div class="aev-overlay" id="auth-overlay">
  <div class="aev-auth-card">
    <div class="aev-mark-lg"></div>
    <h3 id="overlay-title">Sign in to access Aether</h3>
    <p id="overlay-msg">Aether's Command Centre is restricted. Sign in to your ERP first to access live system data.</p>
    <a class="aev-btn primary" href="/" data-testid="overlay-login-link"><i class="fa-solid fa-arrow-left"></i> Go to ERP login</a>
    <div id="overlay-role-tag" class="role-required" style="display:none">SUPER ADMIN ONLY</div>
  </div>
</div>

<div class="aev-app" id="app" style="display:none">
  <header class="aev-topbar">
    <a class="aev-back" href="/" data-testid="back-to-erp"><i class="fa-solid fa-arrow-left"></i> Back to ERP</a>
    <div class="aev-brand">
      <div class="aev-mark"></div>
      <div>
        <h1>Aether <span>·</span> Command Centre</h1>
        <div class="aev-subtitle">
          <span>Autonomous · local · adaptive</span>
          <span class="role-tag" id="user-role-tag">…</span>
        </div>
      </div>
    </div>
    <span class="aev-pill" id="live-pill"><span class="dot"></span> LIVE</span>
    <div class="aev-actions">
      <button class="aev-btn" id="btn-refresh" data-testid="btn-refresh" title="Reload all data"><i class="fa-solid fa-rotate"></i> Refresh</button>
      <button class="aev-btn" id="btn-sync" data-testid="btn-sync" title="Re-snapshot the schema"><i class="fa-solid fa-diagram-project"></i> Sync schema</button>
      <button class="aev-btn primary" id="btn-heal" data-testid="btn-heal" title="Run all checks + auto-heal"><i class="fa-solid fa-wand-magic-sparkles"></i> Self-heal</button>
    </div>
  </header>

  <!-- Tab navigation -->
  <nav class="aev-dash-tabs" id="dash-tabs">
    <button class="active" data-tab="overview"  data-testid="dash-tab-overview"><i class="fa-solid fa-gauge-high"></i> Overview</button>
    <button data-tab="tasks"     data-testid="dash-tab-tasks"><i class="fa-solid fa-list-check"></i> Pending Tasks <span class="badge-num" id="badge-tasks">0</span></button>
    <button data-tab="reports"   data-testid="dash-tab-reports"><i class="fa-solid fa-chart-pie"></i> Reports</button>
    <button data-tab="schema"    data-testid="dash-tab-schema"><i class="fa-solid fa-code-branch"></i> Schema Diff</button>
    <button data-tab="knowledge" data-testid="dash-tab-knowledge"><i class="fa-solid fa-network-wired"></i> Knowledge</button>
    <button data-tab="audit"     data-testid="dash-tab-audit"><i class="fa-solid fa-clock-rotate-left"></i> Audit Trail</button>
  </nav>

  <main class="aev-main">

    <!-- ─── OVERVIEW ─── -->
    <section class="aev-dash-pane active" data-pane="overview">
      <section class="aev-kpis" id="kpis-grid"></section>
      <section class="aev-grid">
        <div class="aev-card span-6" data-testid="card-health">
          <header><h2><i class="fa-solid fa-heart-pulse"></i> System Health</h2><span class="meta" id="health-count">…</span></header>
          <div id="health-overall" class="aev-banner ok" style="display:none"><span class="dot"></span><div><div class="aev-banner-title" id="h-label">…</div><div class="aev-banner-meta" id="h-meta"></div></div></div>
          <div id="health-checks"></div>
        </div>
        <div class="aev-card span-6" data-testid="card-audit-mini">
          <header><h2><i class="fa-solid fa-clock-rotate-left"></i> Recent Activity</h2><span class="meta" id="audit-count">…</span></header>
          <div class="aev-feed" id="audit-feed"><div class="aev-empty"><span class="aev-loader"></span></div></div>
        </div>
        <div class="aev-card span-3" data-testid="card-schema">
          <header><h2><i class="fa-solid fa-camera"></i> Schema Watcher</h2></header>
          <div class="aev-kvlist" id="schema-info"></div>
        </div>
        <div class="aev-card span-3" data-testid="card-learning">
          <header><h2><i class="fa-solid fa-brain"></i> Learning Engine</h2></header>
          <div class="aev-stats" id="learn-grid"></div>
        </div>
        <div class="aev-card span-6" data-testid="card-knowledge-mini">
          <header><h2><i class="fa-solid fa-network-wired"></i> Modules in Knowledge Graph</h2><span class="meta" id="kg-count">…</span></header>
          <div class="aev-modules" id="modules-grid"></div>
        </div>
      </section>
    </section>

    <!-- ─── PENDING TASKS ─── -->
    <section class="aev-dash-pane" data-pane="tasks">
      <div class="aev-tasks-toolbar">
        <strong style="font-size:14px">Pending plans across all users</strong>
        <select class="filter" id="filter-intent" data-testid="filter-intent"><option value="">All intents</option></select>
        <select class="filter" id="filter-assigned" data-testid="filter-assigned"><option value="">All users</option></select>
        <select class="filter" id="filter-age" data-testid="filter-age">
          <option value="">All ages</option>
          <option value="overdue">Overdue (>48h)</option>
          <option value="aging">Aging (>24h)</option>
          <option value="fresh">Fresh (&lt;24h)</option>
        </select>
        <span style="flex:1"></span>
        <span id="tasks-summary" style="font-size:12.5px;color:var(--aev-muted)"></span>
      </div>
      <div id="tasks-list" data-testid="tasks-list"><div class="aev-empty">Loading…</div></div>
    </section>

    <!-- ─── REPORTS ─── -->
    <section class="aev-dash-pane" data-pane="reports">
      <div class="aev-reports-bar">
        <span class="lbl">Live module report:</span>
        <select id="rep-module" data-testid="rep-module">
          <option value="donations">Donations</option>
          <option value="expenses">Expenses</option>
          <option value="hr">HR / Employees</option>
          <option value="inventory">Inventory</option>
          <option value="programs">Programs</option>
          <option value="volunteers">Volunteers</option>
          <option value="cms">Blog / CMS</option>
          <option value="audit">Audit</option>
        </select>
        <select id="rep-period" data-testid="rep-period">
          <option value="7 days">Last 7 days</option>
          <option value="30 days">Last 30 days</option>
          <option value="90 days" selected>Last 90 days</option>
          <option value="this quarter">This quarter</option>
          <option value="last 12 months">Last 12 months</option>
        </select>
        <button class="aev-btn primary" id="btn-render-report" data-testid="btn-render-report"><i class="fa-solid fa-bolt"></i> Build report</button>
        <button class="aev-btn" id="btn-export-report" data-testid="btn-export-report"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
        <button class="aev-btn" id="btn-print-report" data-testid="btn-print-report"><i class="fa-solid fa-print"></i> Print</button>
      </div>

      <div class="aev-report-preview" id="report-preview" data-testid="report-preview">
        <div class="aev-empty">Pick a module and click <strong>Build report</strong> to render live data.</div>
      </div>

      <div style="display:flex;align-items:center;gap:10px;margin:24px 0 12px">
        <h2 style="font-size:16px;margin:0"><i class="fa-solid fa-archive" style="color:var(--aev-muted)"></i> Aether-generated reports history</h2>
        <span class="meta" id="rep-history-count" style="color:var(--aev-muted);font-size:12px"></span>
      </div>
      <div class="aev-history-list" id="rep-history" data-testid="rep-history"><div class="aev-empty">Loading…</div></div>
    </section>

    <!-- ─── SCHEMA DIFF ─── -->
    <section class="aev-dash-pane" data-pane="schema">
      <div class="aev-card span-12" data-testid="card-schema-diff">
        <header>
          <h2><i class="fa-solid fa-code-branch"></i> Schema Diff Viewer</h2>
          <span class="meta" id="schema-meta">…</span>
        </header>
        <div id="schema-diff-pane"></div>
      </div>
    </section>

    <!-- ─── KNOWLEDGE GRAPH ─── -->
    <section class="aev-dash-pane" data-pane="knowledge">
      <div class="aev-card span-12">
        <header>
          <h2><i class="fa-solid fa-network-wired"></i> Knowledge Graph — entities &amp; relationships</h2>
          <span class="meta" id="kg-full-meta">…</span>
        </header>
        <div style="margin:12px 0">
          <input type="text" id="kg-search" data-testid="kg-search" placeholder="Search entities (e.g. donor, expense, salary)" style="width:100%;padding:10px 14px;border:1px solid var(--aev-line);border-radius:8px;font-family:Manrope,sans-serif;font-size:13px">
        </div>
        <div id="kg-results" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px;margin-top:14px"></div>
        <div style="margin-top:24px">
          <h3 style="font-size:14px;margin:0 0 10px;color:var(--aev-text-2)">Modules</h3>
          <div class="aev-modules" id="modules-grid-full"></div>
        </div>
      </div>
    </section>

    <!-- ─── AUDIT TRAIL ─── -->
    <section class="aev-dash-pane" data-pane="audit">
      <div class="aev-card span-12">
        <header>
          <h2><i class="fa-solid fa-clock-rotate-left"></i> Full Audit Trail</h2>
          <span class="meta" id="audit-full-count">…</span>
        </header>
        <div style="display:flex;gap:10px;margin:8px 0 14px;flex-wrap:wrap">
          <select id="audit-type" data-testid="audit-type" class="filter" style="padding:7px 11px;border-radius:8px;border:1px solid var(--aev-line);font-size:12.5px">
            <option value="">All event types</option>
          </select>
          <select id="audit-limit" class="filter" style="padding:7px 11px;border-radius:8px;border:1px solid var(--aev-line);font-size:12.5px">
            <option value="50">50 latest</option>
            <option value="100" selected>100 latest</option>
            <option value="200">200 latest</option>
            <option value="500">500 latest</option>
          </select>
          <button class="aev-btn" id="btn-load-audit" data-testid="btn-load-audit"><i class="fa-solid fa-rotate"></i> Reload</button>
          <span style="flex:1"></span>
          <a href="api/aether.php?action=report_export&module=audit&period=90+days" target="_blank" class="aev-btn"><i class="fa-solid fa-file-csv"></i> Export full audit</a>
        </div>
        <div class="aev-feed" id="audit-feed-full"><div class="aev-empty">Loading…</div></div>
      </div>
    </section>

  </main>
</div>

<!-- Assign-task modal -->
<div class="aev-modal-bg" id="assign-modal" data-testid="assign-modal">
  <div class="aev-modal">
    <h3>Assign / Re-assign task</h3>
    <div class="sub" id="assign-sub">—</div>
    <label>Assign to</label>
    <select id="assign-user" data-testid="assign-user"></select>
    <label>Note (optional)</label>
    <textarea id="assign-note" data-testid="assign-note" placeholder="Any context for the assignee — deadline, special handling, etc."></textarea>
    <div class="actions">
      <button id="assign-cancel">Cancel</button>
      <button id="assign-confirm" class="primary" data-testid="assign-confirm">Assign task</button>
    </div>
  </div>
</div>

<script src="panel.js"></script>
<script>
(function(){
  const API = 'api/aether.php';
  const get = (k) => localStorage.getItem(k);
  const token = (function(){
    for (const k of ['token','authToken','auth_token','jwt','access_token','userToken']) {
      const v = get(k); if (v && v.split('.').length === 3) return v;
    }
    return null;
  })();
  if (!token) { document.getElementById('auth-overlay').classList.add('show'); return; }

  let usersCache = [];
  let intentsCache = new Set();

  async function call(action, body={}) {
    const r = await fetch(API, {
      method:'POST',
      headers:{'Content-Type':'application/json','Authorization':'Bearer '+token},
      body: JSON.stringify({action, ...body}),
    });
    if (r.status === 401) { showOverlay('Authentication required', 'Your session expired. Sign in again.'); throw new Error('auth'); }
    if (r.status === 403) { showOverlay('Super-admin access only', 'The Command Centre is restricted to super-admins. You can still chat with Aether through the floating panel on any ERP page.', true); throw new Error('forbidden'); }
    return r.json();
  }
  function showOverlay(title, msg, roleRequired){
    document.getElementById('overlay-title').textContent = title;
    document.getElementById('overlay-msg').innerHTML = msg;
    document.getElementById('overlay-role-tag').style.display = roleRequired ? 'inline-block' : 'none';
    document.getElementById('auth-overlay').classList.add('show');
  }

  function toast(msg, kind='ok'){
    const t = document.createElement('div');
    t.className = 'aev-toast' + (kind==='bad' ? ' bad' : kind==='warn' ? ' warn' : '');
    t.innerHTML = '<i class="fa-solid fa-' + (kind==='bad' ? 'circle-exclamation' : kind==='warn' ? 'triangle-exclamation' : 'circle-check') + '"></i> ' + esc(msg);
    document.body.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; t.style.transform = 'translateX(20px)'; setTimeout(()=>t.remove(), 300); }, 3500);
  }
  function esc(s){return String(s||'').replace(/[&<>]/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;'}[m]))}
  function md(t){
    return String(t||'')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>')
      .replace(/\*([^*\n]+?)\*/g,'<em>$1</em>')
      .replace(/`([^`\n]+)`/g,'<code>$1</code>')
      .replace(/(₹\s?[\d,]+(?:\.\d+)?)/g,'<span class="amount">$1</span>')
      .replace(/\n/g,'<br>');
  }
  function fmt(n){return Number(n).toLocaleString('en-IN')}
  function fmtTime(ts){ if(!ts) return '—'; return new Date(ts.replace(' ','T')).toLocaleString('en-IN',{dateStyle:'medium',timeStyle:'short'}); }
  function ageBucket(hours){ if (hours >= 48) return 'overdue'; if (hours >= 24) return 'aging'; return 'fresh'; }
  function ageLabel(hours){
    if (hours < 1) return 'just now';
    if (hours < 24) return Math.round(hours) + 'h ago';
    return Math.round(hours/24) + 'd ago';
  }

  function kpiCategory(label){
    const l = label.toLowerCase();
    if (l.includes('donat') || l.includes('contributor') || l.includes('gift') || l.includes('donor')) return 'donations';
    if (l.includes('expense') || l.includes('spend')) return 'expenses';
    if (l.includes('employ') || l.includes('staff') || l.includes('payroll') || l.includes('volunteer')) return 'hr';
    if (l.includes('inventor') || l.includes('stock') || l.includes('item')) return 'inventory';
    if (l.includes('blog') || l.includes('gallery') || l.includes('content') || l.includes('project') || l.includes('program')) return 'cms';
    return 'donations';
  }

  /* ─── Tabs ─── */
  document.querySelectorAll('.aev-dash-tabs button').forEach(b => {
    b.addEventListener('click', () => activateTab(b.dataset.tab));
  });
  function activateTab(name){
    document.querySelectorAll('.aev-dash-tabs button').forEach(x=>x.classList.toggle('active', x.dataset.tab===name));
    document.querySelectorAll('.aev-dash-pane').forEach(x=>x.classList.toggle('active', x.dataset.pane===name));
    if (name==='tasks')     loadPendingTasks();
    if (name==='reports')   { loadReportsHistory(); }
    if (name==='schema')    renderSchemaDiff();
    if (name==='knowledge') loadKnowledge();
    if (name==='audit')     loadAuditFull();
  }

  /* ─── KPI / Health / Audit-mini / Schema info / Learn ─── */
  function renderKpis(kpis){
    const grid = document.getElementById('kpis-grid');
    grid.innerHTML = (kpis||[]).map((k,i) => {
      const cat = kpiCategory(k.label);
      return `
      <div class="aev-kpi ${cat}" data-testid="kpi-${esc(k.label.replace(/\s+/g,'-').toLowerCase())}" style="animation-delay:${i*40}ms">
        <i class="fa-solid fa-${esc(k.icon||'circle')}"></i>
        <div class="kpi-label">${esc(k.label)}</div>
        <div class="kpi-value">${esc(k.value)}</div>
      </div>`;
    }).join('');
  }
  function renderHealth(health){
    const overall = (health.overall||'ok').toLowerCase();
    const o = document.getElementById('health-overall');
    o.style.display='flex'; o.className = 'aev-banner '+overall;
    document.getElementById('h-label').textContent =
      overall==='ok' ? 'All systems nominal' :
      overall==='warn' ? 'Warnings detected' : 'Failures detected';
    document.getElementById('h-meta').textContent =
      `${health.issue_count||0} open issue${health.issue_count===1?'':'s'}` + (health.healed_count? ` · ${health.healed_count} healed`:'');
    document.getElementById('health-count').textContent = `${(health.checks||[]).length} checks`;
    document.getElementById('health-checks').innerHTML = (health.checks||[]).map(c => `
      <div class="aev-check ${c.status}">
        <div class="check-ico"><i class="fa-solid fa-${c.status==='ok'?'check':c.status==='warn'?'triangle-exclamation':'xmark'}"></i></div>
        <div><div class="check-title">${esc(c.title)}</div><div class="check-detail">${esc(c.detail||'')}</div></div>
        <span class="aev-tag sev-${c.severity}">${esc(c.severity)}</span>
      </div>`).join('');
  }
  function renderAuditMini(audit){
    document.getElementById('audit-count').textContent = `${(audit.recent||[]).length} events · 24h: ${(audit.counts.high||0)+(audit.counts.critical||0)} severe`;
    document.getElementById('audit-feed').innerHTML = (audit.recent||[]).length === 0
      ? '<div class="aev-empty">No audit events yet.</div>'
      : audit.recent.slice(0,8).map(r => `
        <div class="aev-feed-row">
          <div class="feed-time">${esc(fmtTime(r.created_at))}</div>
          <div class="feed-body"><span class="feed-type">${esc(r.event_type)}</span> ${esc(r.summary||'')}</div>
          <span class="aev-tag sev-${r.severity}">${esc(r.severity)}</span>
        </div>`).join('');
  }
  function renderModules(kg, target){
    if (target === 'modules-grid') document.getElementById('kg-count').textContent = `${kg.tables||0} tables · ${kg.columns||0} cols · ${kg.relationships||0} links`;
    document.getElementById(target).innerHTML = (kg.modules||[]).map(m => `
      <div class="aev-module-chip" data-module="${esc(m.module)}">
        <div class="mod-name">${esc(m.module)}</div>
        <div class="mod-count">${esc(m.c)}</div>
      </div>`).join('');
  }
  function renderSchema(s){
    const fp = (s.fingerprint||'—').slice(-12);
    document.getElementById('schema-info').innerHTML = `
      <div class="kv"><span class="k">Tables</span><span class="v">${esc(s.tables||0)}</span></div>
      <div class="kv"><span class="k">Columns</span><span class="v">${esc(s.columns||0)}</span></div>
      <div class="kv"><span class="k">Last Sync</span><span class="v">${esc(fmtTime(s.taken_at))}</span></div>
      <div class="kv"><span class="k">Fingerprint</span><span class="v fp" title="${esc(s.fingerprint||'')}">…${esc(fp)}</span></div>`;
  }
  function renderLearn(l){
    const rate = l.success_rate_pct;
    document.getElementById('learn-grid').innerHTML = `
      <div class="stat"><div class="l">Interactions</div><div class="v">${fmt(l.interactions||0)}</div></div>
      <div class="stat"><div class="l">Avg confidence</div><div class="v">${(l.avg_confidence_7d||0).toFixed(2)}</div></div>
      <div class="stat"><div class="l">Learned weights</div><div class="v">${fmt(l.learned_weights||0)}</div></div>
      <div class="stat"><div class="l">Success rate</div><div class="v ${rate>=70?'good':rate==null?'':'warn'}">${rate==null?'—':rate+'%'}</div></div>`;
  }

  /* ─── Schema Diff ─── */
  async function renderSchemaDiff(){
    try {
      const r = await call('schema_diff');
      const pane = document.getElementById('schema-diff-pane');
      const meta = document.getElementById('schema-meta');
      const snaps = r.snapshots || [];
      const changes = r.changes || [];
      meta.textContent = `${snaps.length} snapshot${snaps.length===1?'':'s'} · ${changes.length} change${changes.length===1?'':'s'} tracked`;
      if (!snaps.length) { pane.innerHTML = '<div class="aev-empty">No snapshots yet — click <strong>Sync schema</strong> to create the first.</div>'; return; }

      const grouped = { created:[], dropped:[], added:[], removed:[], modified:[] };
      changes.forEach(c => { (grouped[c.change_type] || (grouped[c.change_type]=[])).push(c); });
      const cur = snaps[0], prev = snaps[1];
      const card = (title, items, icon, cls) => `
        <div class="diff-card ${cls}">
          <header>
            <i class="fa-solid fa-${icon}"></i>
            <h3>${title}</h3>
            <span class="diff-count">${items.length}</span>
          </header>
          <div class="diff-list">
            ${items.length === 0 ? '<div class="aev-empty mini">— none —</div>' :
              items.slice(0,10).map(i => `
                <div class="diff-row">
                  <span class="aev-tag sev-${i.impact_level||i.impact||'info'}">${esc(i.object_type)}</span>
                  <code>${esc(i.object_name)}</code>
                  <span class="diff-impact">${esc(i.impact_level||i.impact||'info')}</span>
                </div>`).join('')}
            ${items.length > 10 ? `<div class="aev-empty mini">+${items.length-10} more</div>` : ''}
          </div>
        </div>`;
      pane.innerHTML = `
        <div class="diff-summary">
          <div class="diff-snap">
            <div class="snap-label">Current</div>
            <div class="snap-fp">…${esc((cur.fingerprint||'').slice(-16))}</div>
            <div class="snap-stats">${cur.table_count} tables · ${cur.column_count} cols · ${cur.fk_count} FKs</div>
            <div class="snap-time">${esc(fmtTime(cur.taken_at))}</div>
          </div>
          ${prev ? `
          <i class="fa-solid fa-right-long arrow"></i>
          <div class="diff-snap prev">
            <div class="snap-label">Previous</div>
            <div class="snap-fp">…${esc((prev.fingerprint||'').slice(-16))}</div>
            <div class="snap-stats">${prev.table_count} tables · ${prev.column_count} cols · ${prev.fk_count} FKs</div>
            <div class="snap-time">${esc(fmtTime(prev.taken_at))}</div>
          </div>` : '<div class="aev-empty mini">no previous snapshot</div>'}
        </div>
        <div class="diff-grid">
          ${card('Tables created',  grouped.created,  'circle-plus',  'good')}
          ${card('Tables dropped',  grouped.dropped,  'circle-minus', 'bad')}
          ${card('Columns added',   grouped.added,    'plus',         'good')}
          ${card('Columns removed', grouped.removed,  'minus',        'bad')}
          ${card('Modified',        grouped.modified, 'pen',          'warn')}
        </div>`;
    } catch (e) { /* auth handled */ }
  }

  /* ─── Pending Tasks (super_admin) ─── */
  async function loadPendingTasks(){
    const list = document.getElementById('tasks-list');
    list.innerHTML = '<div class="aev-empty"><span class="aev-loader"></span></div>';
    if (!usersCache.length) {
      try { const u = await call('users_list'); usersCache = u.users || []; populateUserFilters(); } catch(e) {}
    }
    try {
      const intent = document.getElementById('filter-intent').value || null;
      const assignedTo = document.getElementById('filter-assigned').value || null;
      const ageF = document.getElementById('filter-age').value || '';
      const r = await call('all_pending_plans', { intent, assigned_to: assignedTo ? Number(assignedTo) : 0 });
      let plans = r.plans || [];

      if (ageF === 'overdue') plans = plans.filter(p => p.age_hours >= 48);
      else if (ageF === 'aging') plans = plans.filter(p => p.age_hours >= 24 && p.age_hours < 48);
      else if (ageF === 'fresh') plans = plans.filter(p => p.age_hours < 24);

      // gather intents for filter dropdown
      r.plans.forEach(p => intentsCache.add(p.intent));
      populateIntentFilter();

      const summary = r.summary || {};
      document.getElementById('badge-tasks').textContent = summary.total || 0;
      document.getElementById('tasks-summary').innerHTML =
        `<strong>${summary.total||0}</strong> total · ` +
        `<span style="color:var(--aev-warn)">${summary.aging||0}</span> aging · ` +
        `<span style="color:var(--aev-bad)">${summary.overdue||0}</span> overdue`;

      if (!plans.length) {
        list.innerHTML = '<div class="aev-empty"><i class="fa-solid fa-check-double" style="font-size:32px;color:var(--aev-primary);margin-bottom:8px;display:block"></i>All caught up — no pending plans match these filters.</div>';
        return;
      }
      list.innerHTML = plans.map(p => taskRowHtml(p)).join('');
      list.querySelectorAll('.aev-task-row').forEach(row => {
        const pid = Number(row.dataset.plan);
        row.querySelector('.approve')?.addEventListener('click', () => approveAdminPlan(pid));
        row.querySelector('.reject')?.addEventListener('click', () => rejectAdminPlan(pid));
        row.querySelector('.assign')?.addEventListener('click', () => openAssignModal(pid, row.dataset.preview));
      });
    } catch(e){ list.innerHTML='<div class="aev-empty">Failed to load tasks.</div>'; }
  }
  function taskRowHtml(p){
    const ageCls = ageBucket(p.age_hours||0);
    const ageStr = ageLabel(p.age_hours||0);
    const asgn = (p.assignments||[]).length
      ? `<div class="t-asg"><i class="fa-solid fa-user-tag"></i> Assigned to <strong>${esc(p.assignments[0].assignee_name||'?')}</strong>${p.assignments[0].note?` — <em>${esc(p.assignments[0].note)}</em>`:''}</div>`
      : '';
    return `
      <div class="aev-task-row ${ageCls}" data-plan="${p.id}" data-preview="${esc(p.preview||'').replace(/"/g,'&quot;')}" data-testid="task-row-${p.id}">
        <div>
          <div class="t-meta">
            <span class="aev-tag sev-info">#${p.id}</span>
            <span class="aev-tag" style="background:var(--aev-violet-bg);color:var(--aev-violet);border-color:var(--aev-violet-bd)">${esc(p.intent||'')}</span>
            <span class="age ${ageCls==='overdue'?'bad':ageCls==='aging'?'warn':''}"><i class="fa-regular fa-clock"></i> ${ageStr}</span>
            <span>·</span>
            <span class="by">${esc(p.full_name||p.username||'unknown')}</span>
            <span style="opacity:.65">(${esc(p.role||'?')})</span>
          </div>
          <div class="t-title">${esc((p.preview||'').split('\n')[0].replace(/[*_`#]/g,''))}</div>
          <div class="t-preview">${md((p.preview||'').split('\n').slice(1).join('\n'))}</div>
          ${asgn}
        </div>
        <div class="t-actions">
          <button class="approve" data-testid="task-approve-${p.id}"><i class="fa-solid fa-check"></i> Approve</button>
          <button class="reject" data-testid="task-reject-${p.id}"><i class="fa-solid fa-xmark"></i> Reject</button>
          <button class="assign" data-testid="task-assign-${p.id}"><i class="fa-solid fa-user-plus"></i> Assign</button>
        </div>
      </div>`;
  }
  function populateUserFilters(){
    const sel = document.getElementById('filter-assigned');
    const opts = '<option value="">All users</option>' +
      usersCache.map(u => `<option value="${u.id}">${esc(u.full_name)} (${esc(u.role)})</option>`).join('');
    sel.innerHTML = opts;
    const asgSel = document.getElementById('assign-user');
    asgSel.innerHTML = usersCache.map(u => `<option value="${u.id}">${esc(u.full_name)} (${esc(u.role)})</option>`).join('');
  }
  function populateIntentFilter(){
    const sel = document.getElementById('filter-intent');
    const cur = sel.value;
    sel.innerHTML = '<option value="">All intents</option>' +
      [...intentsCache].sort().map(i => `<option value="${esc(i)}">${esc(i)}</option>`).join('');
    sel.value = cur;
  }
  document.getElementById('filter-intent').addEventListener('change', loadPendingTasks);
  document.getElementById('filter-assigned').addEventListener('change', loadPendingTasks);
  document.getElementById('filter-age').addEventListener('change', loadPendingTasks);

  async function approveAdminPlan(pid){
    if (!confirm(`Approve and execute plan #${pid} on behalf of the proposer?`)) return;
    try {
      const r = await call('approve_plan', { plan_id: pid });
      if (r.ok) { toast(`Plan #${pid} executed`); loadPendingTasks(); load(); }
      else toast(r.error || 'Approve failed', 'bad');
    } catch(e){ toast('Approval blocked', 'bad'); }
  }
  async function rejectAdminPlan(pid){
    if (!confirm(`Reject plan #${pid}?`)) return;
    try {
      await call('reject_plan', { plan_id: pid });
      toast(`Plan #${pid} rejected`, 'warn');
      loadPendingTasks();
    } catch(e){ toast('Reject blocked', 'bad'); }
  }

  /* ─── Assign modal ─── */
  let assignTargetPlan = null;
  function openAssignModal(pid, preview){
    assignTargetPlan = pid;
    document.getElementById('assign-sub').textContent = `Plan #${pid} — ${(preview||'').slice(0,150)}`;
    document.getElementById('assign-note').value = '';
    document.getElementById('assign-modal').classList.add('show');
  }
  document.getElementById('assign-cancel').addEventListener('click', () => document.getElementById('assign-modal').classList.remove('show'));
  document.getElementById('assign-modal').addEventListener('click', e => {
    if (e.target.id === 'assign-modal') document.getElementById('assign-modal').classList.remove('show');
  });
  document.getElementById('assign-confirm').addEventListener('click', async () => {
    const aid = Number(document.getElementById('assign-user').value);
    const note = document.getElementById('assign-note').value.trim();
    if (!assignTargetPlan || !aid) return;
    try {
      const r = await call('assign_plan', { plan_id: assignTargetPlan, assignee_id: aid, note });
      if (r.ok) {
        toast(`Plan #${assignTargetPlan} assigned to ${r.assignee.name}`);
        document.getElementById('assign-modal').classList.remove('show');
        loadPendingTasks();
      } else toast(r.error || 'Assign failed', 'bad');
    } catch(e){ toast('Assign failed', 'bad'); }
  });

  /* ─── Reports ─── */
  async function buildModuleReport(){
    const module = document.getElementById('rep-module').value;
    const period = document.getElementById('rep-period').value;
    const pane = document.getElementById('report-preview');
    pane.innerHTML = '<div class="aev-empty"><span class="aev-loader"></span></div>';
    try {
      const r = await call('module_report', { module, period });
      let html = `<h2><i class="fa-solid fa-chart-pie" style="color:var(--aev-primary);margin-right:8px"></i>${esc(module.toUpperCase())} — ${esc(period)}</h2>`;
      if (r.cards && r.cards.length) {
        html += '<div class="kpis">' + r.cards.map(c => `<div class="kpi"><div class="l">${esc(c.label)}</div><div class="v">${esc(c.value)}</div></div>`).join('') + '</div>';
      }
      html += `<div class="text">${md(r.text||'')}</div>`;
      pane.innerHTML = html;
    } catch(e){ pane.innerHTML = '<div class="aev-empty">Failed to build report.</div>'; }
  }
  document.getElementById('btn-render-report').addEventListener('click', buildModuleReport);
  document.getElementById('btn-export-report').addEventListener('click', () => {
    const module = document.getElementById('rep-module').value;
    const period = encodeURIComponent(document.getElementById('rep-period').value);
    const url = `${API}?action=report_export&module=${module}&period=${period}`;
    // Pass token via fetch + blob to download
    fetch(url, { headers: { 'Authorization': 'Bearer ' + token }})
      .then(r => r.blob()).then(blob => {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `aether-${module}-${Date.now()}.csv`;
        document.body.appendChild(a); a.click(); a.remove();
        toast(`Exported ${module}.csv`);
      }).catch(() => toast('Export failed', 'bad'));
  });
  document.getElementById('btn-print-report').addEventListener('click', () => {
    const w = window.open('', '_blank', 'width=800,height=900');
    const pane = document.getElementById('report-preview').innerHTML;
    w.document.write(`<!doctype html><html><head><title>Aether Report</title>
      <style>body{font-family:Arial;padding:24px;color:#0f172a}h2{color:#059669;border-bottom:2px solid #10b981;padding-bottom:8px}
      .kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin:14px 0}
      .kpi{padding:10px;border:1px solid #e2e8f0;border-radius:6px}.kpi .l{font-size:10px;color:#64748b;text-transform:uppercase}.kpi .v{font-size:20px;font-weight:600}
      .text{font-size:12px;line-height:1.6;white-space:pre-wrap}.amount{color:#059669;font-weight:600}</style></head>
      <body>${pane}<script>window.print();<\/script></body></html>`);
  });

  async function loadReportsHistory(){
    const list = document.getElementById('rep-history');
    list.innerHTML = '<div class="aev-empty"><span class="aev-loader"></span></div>';
    try {
      const r = await call('reports_history');
      const items = r.items || [];
      document.getElementById('rep-history-count').textContent = `${items.length} entries`;
      if (!items.length) { list.innerHTML = '<div class="aev-empty">No reports generated yet.</div>'; return; }
      list.innerHTML = items.map(it => {
        const status = (it.status||'logged').toLowerCase();
        return `
        <div class="aev-history-row">
          <div class="t-time">${esc(fmtTime(it.created_at))}</div>
          <div class="t-title">${esc(it.title)}<small>${esc(it.author||'—')} · <code style="font-size:10.5px">${esc(it.kind)}</code></small></div>
          <span class="t-status ${status}">${esc(status)}</span>
        </div>`;
      }).join('');
    } catch(e){ list.innerHTML = '<div class="aev-empty">Failed to load history.</div>'; }
  }

  /* ─── Knowledge ─── */
  async function loadKnowledge(){
    try {
      const s = await call('knowledge_summary');
      const k = s.data || {};
      document.getElementById('kg-full-meta').textContent = `${k.tables||0} tables · ${k.columns||0} cols · ${k.relationships||0} links`;
      renderModules(k, 'modules-grid-full');
    } catch(e){}
  }
  let kgTimer = null;
  document.getElementById('kg-search').addEventListener('input', e => {
    clearTimeout(kgTimer);
    const q = e.target.value;
    kgTimer = setTimeout(async () => {
      const out = document.getElementById('kg-results');
      if (!q.trim()) { out.innerHTML = ''; return; }
      try {
        const r = await call('knowledge_search', { query: q });
        const matches = r.matches || [];
        if (!matches.length) { out.innerHTML = '<div class="aev-empty mini">No matches.</div>'; return; }
        out.innerHTML = matches.map(m => `
          <div style="padding:10px 12px;background:#fff;border:1px solid var(--aev-line);border-radius:8px">
            <div style="font-size:11px;color:var(--aev-muted);text-transform:uppercase;letter-spacing:.06em">${esc(m.entity_type||'')}</div>
            <div style="font-weight:600;font-size:13px;margin-top:2px"><code>${esc(m.entity_name)}</code></div>
            <div style="font-size:11.5px;color:var(--aev-text-2);margin-top:4px">${esc(m.business_label||'')}</div>
          </div>`).join('');
      } catch(e){}
    }, 250);
  });

  /* ─── Audit Trail ─── */
  async function loadAuditFull(){
    const limit = Number(document.getElementById('audit-limit').value || 100);
    const type = document.getElementById('audit-type').value || null;
    const out = document.getElementById('audit-feed-full');
    out.innerHTML = '<div class="aev-empty"><span class="aev-loader"></span></div>';
    try {
      const r = await call('audit', { limit, type });
      const events = r.events || [];
      document.getElementById('audit-full-count').textContent = `${events.length} events`;

      // populate type filter once
      const typeSel = document.getElementById('audit-type');
      if (typeSel.options.length <= 1) {
        const types = [...new Set(events.map(e => e.event_type))].sort();
        typeSel.innerHTML = '<option value="">All event types</option>' +
          types.map(t => `<option value="${esc(t)}">${esc(t)}</option>`).join('');
      }
      if (!events.length) { out.innerHTML = '<div class="aev-empty">No matching events.</div>'; return; }
      out.innerHTML = events.map(e => `
        <div class="aev-feed-row">
          <div class="feed-time">${esc(fmtTime(e.created_at))}</div>
          <div class="feed-body"><span class="feed-type">${esc(e.event_type)}</span> ${esc(e.summary||'')}</div>
          <span class="aev-tag sev-${e.severity}">${esc(e.severity)}</span>
        </div>`).join('');
    } catch(e){ out.innerHTML = '<div class="aev-empty">Failed to load audit.</div>'; }
  }
  document.getElementById('btn-load-audit').addEventListener('click', loadAuditFull);
  document.getElementById('audit-type').addEventListener('change', loadAuditFull);
  document.getElementById('audit-limit').addEventListener('change', loadAuditFull);

  /* ─── Bootstrap load ─── */
  async function load() {
    try {
      const me = await call('identity');
      const role = me?.user?.role;
      const isSuper = role === 'super_admin';
      document.getElementById('user-role-tag').textContent = role || '—';
      if (!isSuper) {
        showOverlay(
          'Super-admin access only',
          'The Command Centre is restricted to <strong>super-admins</strong>. As <code>' + esc(role||'?') + '</code> you can still ask Aether anything through the <strong>floating panel</strong> on any ERP page.',
          true
        );
        return;
      }
      document.getElementById('app').style.display = 'block';

      const d = await call('dashboard');
      renderKpis(d.kpis);
      renderHealth(d.health);
      renderAuditMini(d.audit);
      renderModules(d.knowledge, 'modules-grid');
      renderSchema(d.schema);
      renderLearn(d.learning);

      // Update tasks badge in header (without switching tab)
      try {
        const t = await call('all_pending_plans');
        document.getElementById('badge-tasks').textContent = (t.summary?.total) || 0;
      } catch(e){}
    } catch (e) { /* error handled by call() */ }
  }

  document.getElementById('btn-refresh').addEventListener('click', () => { load(); toast('Refreshed'); });
  document.getElementById('btn-sync').addEventListener('click', async () => {
    try {
      const r = await call('schema_sync');
      toast(r.changed ? `Schema sync: ${(r.changes||[]).length} change(s)` : 'Schema unchanged');
      load();
      if (document.querySelector('.aev-dash-pane.active').dataset.pane === 'schema') renderSchemaDiff();
    } catch (e) { toast('Sync blocked', 'bad'); }
  });
  document.getElementById('btn-heal').addEventListener('click', async () => {
    if (!confirm('Run all health checks and apply auto-heals where allowed?')) return;
    try {
      const r = await call('self_heal');
      toast(`Healed ${r.healed_count||0} issue(s); overall: ${r.overall}`,
            r.overall === 'ok' ? 'ok' : 'warn');
      load();
    } catch (e) { toast('Self-heal blocked', 'bad'); }
  });

  load();
  setInterval(load, 30000);
})();
</script>
</body>
</html>
