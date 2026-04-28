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
    <button data-tab="compliance" data-testid="dash-tab-compliance"><i class="fa-solid fa-scale-balanced"></i> Compliance</button>
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
        <span class="lbl">Module:</span>
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
        <span class="lbl">Period:</span>
        <select id="rep-period" data-testid="rep-period">
          <option value="custom">Custom range…</option>
          <option value="7 days">Last 7 days</option>
          <option value="30 days">Last 30 days</option>
          <option value="90 days" selected>Last 90 days</option>
          <option value="this quarter">This quarter</option>
          <option value="last 12 months">Last 12 months</option>
          <option value="fy current">FY 2025-26 (Apr–Mar)</option>
          <option value="fy previous">FY 2024-25</option>
        </select>
        <span id="custom-range" style="display:none;gap:8px;align-items:center">
          <input type="date" id="rep-from" data-testid="rep-from" style="padding:7px 9px;border:1px solid var(--aev-line);border-radius:8px;font-family:Manrope;font-size:12.5px">
          <span style="color:var(--aev-muted);font-size:12px">→</span>
          <input type="date" id="rep-to"   data-testid="rep-to"   style="padding:7px 9px;border:1px solid var(--aev-line);border-radius:8px;font-family:Manrope;font-size:12.5px">
        </span>
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

    <!-- ─── COMPLIANCE (Indian govt audit reports) ─── -->
    <section class="aev-dash-pane" data-pane="compliance">
      <div class="aev-reports-bar" style="background:linear-gradient(135deg,#fff,#fef3e2);border-color:#f59e0b">
        <span class="lbl">Compliance section:</span>
        <select id="comp-section" data-testid="comp-section">
          <option value="overview">📊 Overview (all sections summary)</option>
          <option value="80g">📜 Section 80G — Donor &amp; receipt register</option>
          <option value="12a">⚖️ Section 12A — Income &amp; Expenditure</option>
          <option value="form10b">📋 Form 10B — Auditor's report</option>
          <option value="fcra">🌐 FCRA — Foreign contributions (Form FC-4)</option>
          <option value="csr">🏢 CSR — Corporate donations (Sec 135)</option>
          <option value="combined">📚 Combined — Full compliance pack</option>
        </select>
        <span class="lbl">From:</span>
        <input type="date" id="comp-from" data-testid="comp-from" style="padding:7px 9px;border:1px solid var(--aev-line);border-radius:8px;font-family:Manrope;font-size:12.5px">
        <span class="lbl">To:</span>
        <input type="date" id="comp-to" data-testid="comp-to" style="padding:7px 9px;border:1px solid var(--aev-line);border-radius:8px;font-family:Manrope;font-size:12.5px">
        <select id="comp-fy" data-testid="comp-fy" style="padding:7px 11px;border-radius:8px;border:1px solid var(--aev-line);font-size:12.5px;background:#fff" title="Quick FY selector">
          <option value="">Quick FY…</option>
          <option value="2025-26">FY 2025-26</option>
          <option value="2024-25">FY 2024-25</option>
          <option value="2023-24">FY 2023-24</option>
        </select>
        <button class="aev-btn primary" id="btn-comp-build" data-testid="btn-comp-build"><i class="fa-solid fa-bolt"></i> Build</button>
        <button class="aev-btn" id="btn-comp-export" data-testid="btn-comp-export"><i class="fa-solid fa-file-csv"></i> Export</button>
        <button class="aev-btn" id="btn-comp-print" data-testid="btn-comp-print"><i class="fa-solid fa-print"></i> Print / Save PDF</button>
      </div>

      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px;font-size:11.5px;color:var(--aev-muted)">
        <span><i class="fa-solid fa-circle-info" style="color:var(--aev-primary-2)"></i> Reports follow Indian tax statute formats (Income Tax Act 1961, FCRA 2010, Companies Act 2013).</span>
      </div>

      <div class="aev-report-preview" id="compliance-pane" data-testid="compliance-pane">
        <div class="aev-empty">Pick a section + date range and click <strong>Build</strong>. <em>Tip:</em> use the FY selector to set Indian fiscal year (1 Apr → 31 Mar) automatically.</div>
      </div>
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

<!-- KPI drill-down modal -->
<div class="aev-modal-bg" id="drill-modal" data-testid="drill-modal">
  <div class="aev-modal" style="max-width:920px;max-height:88vh;display:flex;flex-direction:column">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
      <h3 id="drill-title" style="margin:0">—</h3>
      <button id="drill-close" style="background:transparent;border:none;font-size:22px;cursor:pointer;color:var(--aev-muted)">×</button>
    </div>
    <div class="sub" id="drill-sub" style="margin-bottom:14px">—</div>
    <div id="drill-body" style="overflow:auto;flex:1;min-height:200px"></div>
    <div class="actions" style="margin-top:14px">
      <button id="drill-export" class="primary" data-testid="drill-export"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
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
    if (name==='tasks')      loadPendingTasks();
    if (name==='reports')    { loadReportsHistory(); }
    if (name==='compliance') initCompliance();
    if (name==='schema')     renderSchemaDiff();
    if (name==='knowledge')  loadKnowledge();
    if (name==='audit')      loadAuditFull();
  }

  /* ─── KPI / Health / Audit-mini / Schema info / Learn ─── */
  function renderKpis(kpis){
    const grid = document.getElementById('kpis-grid');
    grid.innerHTML = (kpis||[]).map((k,i) => {
      const cat = kpiCategory(k.label);
      const drillKey = drillKeyForLabel(k.label);
      return `
      <div class="aev-kpi ${cat}${drillKey?' clickable':''}" data-testid="kpi-${esc(k.label.replace(/\s+/g,'-').toLowerCase())}" data-drill="${esc(drillKey||'')}" style="animation-delay:${i*40}ms">
        <i class="fa-solid fa-${esc(k.icon||'circle')}"></i>
        <div class="kpi-label">${esc(k.label)}</div>
        <div class="kpi-value">${esc(k.value)}</div>
        ${drillKey ? '<div class="kpi-drill-hint"><i class="fa-solid fa-arrow-up-right-from-square"></i> Click for details</div>' : ''}
      </div>`;
    }).join('');
    grid.querySelectorAll('.aev-kpi.clickable').forEach(el => {
      el.addEventListener('click', () => openDrill(el.dataset.drill, el.querySelector('.kpi-label').textContent.trim()));
    });
  }
  function drillKeyForLabel(label){
    const l = label.toLowerCase();
    if (l.includes('donation')) return 'total_donations';
    if (l.includes('donor')) return 'donors';
    if (l.includes('expense') || l.includes('spend')) return 'expenses';
    if (l.includes('employ') || l.includes('staff')) return 'employees';
    if (l.includes('volunteer')) return 'volunteers';
    if (l.includes('inventor') || l.includes('stock') || l.includes('item')) return 'inventory';
    if (l.includes('program') || l.includes('project')) return 'programs';
    return null;
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

  /* ─── KPI Drill-down modal ─── */
  let drillCurrent = null;
  function openDrill(kpiKey, title){
    drillCurrent = kpiKey;
    document.getElementById('drill-title').textContent = title + ' — detail';
    document.getElementById('drill-sub').textContent = 'Live records (RBAC-scoped to your role)';
    document.getElementById('drill-body').innerHTML = '<div class="aev-empty"><span class="aev-loader"></span></div>';
    document.getElementById('drill-modal').classList.add('show');
    call('kpi_details', { kpi: kpiKey })
      .then(r => {
        if (!r.ok) {
          document.getElementById('drill-body').innerHTML =
            `<div class="aev-empty" style="color:var(--aev-bad)">${esc(r.error||'Unable to load')}</div>`;
          return;
        }
        renderDrill(r);
      })
      .catch(()=> { document.getElementById('drill-body').innerHTML = '<div class="aev-empty">Failed to load.</div>'; });
  }
  function renderDrill(r){
    const a = r.aggregates || {};
    let html = '<div class="kpis" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:14px">';
    for (const [k, v] of Object.entries(a)) {
      if (typeof v !== 'object') {
        html += `<div class="kpi" style="padding:12px;border:1px solid var(--aev-line);border-radius:8px;background:linear-gradient(135deg,#fff,#fafbfc)">
                  <div style="font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--aev-muted);font-weight:600">${esc(k.replace(/_/g,' '))}</div>
                  <div style="font-size:18px;font-weight:600;margin-top:2px">${esc(typeof v === 'number' ? fmt(v) : v)}</div>
                </div>`;
      }
    }
    html += '</div>';
    if (r.breakdown_by_method?.length) {
      html += '<div style="font-size:11.5px;color:var(--aev-muted);margin-bottom:6px">By payment method</div>';
      html += '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px">' +
        r.breakdown_by_method.map(x => `<span class="aev-tag" style="font-size:11px">${esc(x.payment_method||'—')}: ${fmt(x.c)} (₹${fmt(Math.round(x.total))})</span>`).join('') + '</div>';
    }
    if (r.breakdown_by_category?.length) {
      html += '<div style="font-size:11.5px;color:var(--aev-muted);margin-bottom:6px">By category</div>';
      html += '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px">' +
        r.breakdown_by_category.map(x => `<span class="aev-tag" style="font-size:11px">${esc(x.expense_category||'—')}: ${fmt(x.c)} (₹${fmt(Math.round(x.total))})</span>`).join('') + '</div>';
    }
    if (r.aggregates?.departments) {
      html += '<div style="font-size:11.5px;color:var(--aev-muted);margin-bottom:6px">By department</div>';
      html += '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px">' +
        Object.entries(r.aggregates.departments).map(([d,c]) => `<span class="aev-tag" style="font-size:11px">${esc(d)}: ${fmt(c)}</span>`).join('') + '</div>';
    }
    const rows = r.rows || [];
    if (rows.length) {
      const cols = Object.keys(rows[0]);
      html += '<div style="overflow:auto;border:1px solid var(--aev-line);border-radius:8px"><table style="width:100%;border-collapse:collapse;font-size:11.5px">' +
        '<thead><tr style="background:var(--aev-primary-bg);color:var(--aev-primary-3);text-align:left">' +
        cols.map(c => `<th style="padding:9px 12px;font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;font-weight:600">${esc(c.replace(/_/g,' '))}</th>`).join('') + '</tr></thead><tbody>' +
        rows.map(row => '<tr style="border-top:1px solid var(--aev-line)">' +
          cols.map(c => `<td style="padding:8px 12px">${esc(row[c] ?? '—')}</td>`).join('') + '</tr>').join('') +
        '</tbody></table></div>';
    } else {
      html += '<div class="aev-empty">No records to show.</div>';
    }
    document.getElementById('drill-body').innerHTML = html;
  }
  document.getElementById('drill-close').addEventListener('click', () => document.getElementById('drill-modal').classList.remove('show'));
  document.getElementById('drill-modal').addEventListener('click', e => {
    if (e.target.id === 'drill-modal') document.getElementById('drill-modal').classList.remove('show');
  });
  document.getElementById('drill-export').addEventListener('click', () => {
    if (!drillCurrent) return;
    const moduleMap = { total_donations: 'donations', donors: 'donations', expenses: 'expenses',
      employees: 'hr', volunteers: 'volunteers', inventory: 'inventory', programs: 'programs' };
    const m = moduleMap[drillCurrent] || 'donations';
    fetch(`${API}?action=report_export&module=${m}&period=last+12+months`, {
      headers: { 'Authorization': 'Bearer ' + token }
    }).then(r => r.blob()).then(blob => {
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = `aether-${m}-${Date.now()}.csv`;
      document.body.appendChild(a); a.click(); a.remove();
      toast(`Exported ${m}.csv`);
    });
  });

  /* ─── Reports: custom date range + FY presets ─── */
  document.getElementById('rep-period').addEventListener('change', e => {
    const v = e.target.value;
    document.getElementById('custom-range').style.display = (v === 'custom') ? 'inline-flex' : 'none';
    if (v === 'fy current') {
      const now = new Date();
      const y = now.getMonth() < 3 ? now.getFullYear()-1 : now.getFullYear();
      document.getElementById('rep-from').value = y + '-04-01';
      document.getElementById('rep-to').value   = (y+1) + '-03-31';
      document.getElementById('custom-range').style.display = 'inline-flex';
    } else if (v === 'fy previous') {
      const now = new Date();
      const y = now.getMonth() < 3 ? now.getFullYear()-2 : now.getFullYear()-1;
      document.getElementById('rep-from').value = y + '-04-01';
      document.getElementById('rep-to').value   = (y+1) + '-03-31';
      document.getElementById('custom-range').style.display = 'inline-flex';
    }
  });

  /* ─── Compliance (Indian govt audit reports) ─── */
  let lastCompliance = null;
  function initCompliance(){
    const fyEl = document.getElementById('comp-from');
    if (!fyEl.value) {
      const now = new Date();
      const y = now.getMonth() < 3 ? now.getFullYear()-1 : now.getFullYear();
      document.getElementById('comp-from').value = y + '-04-01';
      document.getElementById('comp-to').value   = (y+1) + '-03-31';
    }
  }
  document.getElementById('comp-fy').addEventListener('change', e => {
    const v = e.target.value;
    if (!v) return;
    const [s, _e] = v.split('-');
    const yr = parseInt(s, 10);
    document.getElementById('comp-from').value = yr + '-04-01';
    document.getElementById('comp-to').value   = (yr+1) + '-03-31';
  });
  document.getElementById('btn-comp-build').addEventListener('click', async () => {
    const section = document.getElementById('comp-section').value;
    const from    = document.getElementById('comp-from').value;
    const to      = document.getElementById('comp-to').value;
    if (!from || !to) { toast('Pick a from + to date', 'warn'); return; }
    const pane = document.getElementById('compliance-pane');
    pane.innerHTML = '<div class="aev-empty"><span class="aev-loader"></span></div>';
    try {
      const r = await call('compliance_report', { section, from, to });
      if (!r.ok) { pane.innerHTML = `<div class="aev-empty" style="color:var(--aev-bad)">${esc(r.error)}</div>`; return; }
      lastCompliance = { section, from, to };
      pane.innerHTML = renderCompliance(r);
    } catch(e){ pane.innerHTML = '<div class="aev-empty">Build failed.</div>'; }
  });
  document.getElementById('btn-comp-export').addEventListener('click', () => {
    if (!lastCompliance) { toast('Build a report first', 'warn'); return; }
    const { section, from, to } = lastCompliance;
    const url = `${API}?action=compliance_export&section=${section}&from=${from}&to=${to}`;
    fetch(url, { headers: { 'Authorization': 'Bearer ' + token }})
      .then(r => r.blob()).then(blob => {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `compliance-${section}-${from}-to-${to}.csv`;
        document.body.appendChild(a); a.click(); a.remove();
        toast(`Exported compliance-${section}.csv`);
      }).catch(()=>toast('Export failed','bad'));
  });
  document.getElementById('btn-comp-print').addEventListener('click', () => {
    const pane = document.getElementById('compliance-pane').innerHTML;
    const w = window.open('', '_blank', 'width=900,height=1000');
    w.document.write(`<!doctype html><html><head><title>Aether Compliance Report</title>
      <style>
        body{font-family:Arial,sans-serif;padding:32px;color:#0f172a}
        h1{color:#10b981;border-bottom:3px solid #10b981;padding-bottom:8px;margin:0 0 6px}
        h2{color:#059669;font-size:18px;border-bottom:1px solid #e2e8f0;padding-bottom:4px;margin:24px 0 10px}
        h3{font-size:14px;margin:14px 0 6px;color:#475569}
        .meta{font-size:11px;color:#64748b;margin-bottom:18px}
        table{width:100%;border-collapse:collapse;margin:8px 0;font-size:11px}
        th,td{padding:6px 9px;border:1px solid #cbd5e1;text-align:left}
        th{background:#f1f5f9;font-weight:600}
        .kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin:10px 0}
        .kpi{padding:9px;border:1px solid #cbd5e1;border-radius:6px}
        .kpi .l{font-size:9px;color:#64748b;text-transform:uppercase}
        .kpi .v{font-size:16px;font-weight:600;color:#059669}
        .pill{display:inline-block;padding:2px 8px;border-radius:9999px;background:#dcfce7;color:#166534;font-size:10px;font-weight:600;text-transform:uppercase}
        .pill.bad{background:#fee2e2;color:#991b1b}
        .footer{margin-top:32px;padding-top:14px;border-top:1px solid #e2e8f0;font-size:10px;color:#94a3b8;text-align:center}
        @media print { body{padding:18px} .no-print{display:none} }
      </style></head>
      <body>
        <h1>Compliance Audit Report</h1>
        <div class="meta">Generated by Aether on ${new Date().toLocaleString('en-IN')} · Dhrub Foundation</div>
        ${pane}
        <div class="footer">This report is computer-generated by Aether v2 — fully on-premise, statutory format. Verify with your Chartered Accountant before submission.</div>
        <script>window.print();<\/script>
      </body></html>`);
  });

  function renderCompliance(r){
    const meta = r.meta || {};
    const data = r.data || {};
    const head = `<h2 style="margin:0 0 4px">Compliance Report — ${esc(meta.section.toUpperCase())}</h2>
      <div style="font-size:11.5px;color:var(--aev-muted);margin-bottom:18px">
        Period: <strong>${esc(meta.from)}</strong> → <strong>${esc(meta.to)}</strong> · FY <strong>${esc(meta.fy)}</strong> · ${esc(meta.org_name)} · Generated ${esc(meta.generated)}
      </div>`;
    let body = '';
    if (meta.section === '80g')      body = render80G(data);
    else if (meta.section === '12a') body = render12A(data);
    else if (meta.section === 'fcra')body = renderFCRA(data);
    else if (meta.section === 'csr') body = renderCSR(data);
    else if (meta.section === 'form10b') body = renderForm10B(data);
    else if (meta.section === 'overview') body = renderCompOverview(data);
    else if (meta.section === 'combined') body = render80G(data['80g']) + render12A(data['12a']) + renderFCRA(data['fcra']) + renderCSR(data['csr']) + renderForm10B(data['form10b']);
    return head + body;
  }

  function compKpiBlock(items){
    return '<div class="kpis" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:10px 0 18px">' +
      items.map(([l,v,cls]) => `
        <div class="kpi" style="padding:12px;border:1px solid var(--aev-line);border-radius:8px;background:#fff">
          <div style="font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--aev-muted);font-weight:600">${esc(l)}</div>
          <div style="font-size:20px;font-weight:600;margin-top:3px;color:${cls==='bad'?'#dc2626':cls==='good'?'#059669':'inherit'};letter-spacing:-.01em">${v}</div>
        </div>`).join('') + '</div>';
  }
  function tableRows(rows, cols){
    if (!rows || !rows.length) return '<div class="aev-empty mini">— no rows —</div>';
    const c = cols || Object.keys(rows[0]);
    return '<div style="overflow:auto;border:1px solid var(--aev-line);border-radius:8px;margin-bottom:18px">' +
      '<table style="width:100%;border-collapse:collapse;font-size:11.5px">' +
      '<thead><tr style="background:var(--aev-primary-bg);color:var(--aev-primary-3);text-align:left">' +
      c.map(k => `<th style="padding:8px 11px;font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;font-weight:600">${esc(k.replace(/_/g,' '))}</th>`).join('') + '</tr></thead><tbody>' +
      rows.map(r => '<tr style="border-top:1px solid var(--aev-line)">' +
        c.map(k => `<td style="padding:7px 11px">${esc(r[k] ?? '—')}</td>`).join('') + '</tr>').join('') +
      '</tbody></table></div>';
  }
  function render80G(d){
    if (!d) return '';
    const t = d.totals || {};
    let h = '<h3>80G — Donor &amp; Receipt Register (Income Tax Act, Sec 80G)</h3>' +
      compKpiBlock([
        ['Total receipts', fmt(t.count||0)],
        ['Total amount', '₹' + fmt(t.amount||0), 'good'],
        ['Cash receipts', '₹' + fmt(t.cash_amount||0), (t.cash_amount||0) > 0 ? 'warn' : ''],
        ['Non-cash', '₹' + fmt(t.non_cash_amount||0), 'good'],
      ]);
    if (t.cash_above_2k && t.cash_above_2k.length) {
      h += '<div style="padding:11px 14px;background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;font-size:12px;margin-bottom:12px;color:#92400e">' +
           '<i class="fa-solid fa-triangle-exclamation"></i> <strong>Compliance flag:</strong> ' + t.cash_above_2k.length + ' cash donation(s) exceed ₹2,000 — these are <strong>NOT</strong> 80G eligible (Sec 80G(5D)).</div>';
    }
    if (d.pan_missing && d.pan_missing.length) {
      h += '<div style="padding:11px 14px;background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;font-size:12px;margin-bottom:12px;color:#991b1b">' +
           '<i class="fa-solid fa-id-card"></i> <strong>PAN missing:</strong> ' + d.pan_missing.length + ' donor(s). Mandatory for receipts ≥ ₹10,000.</div>';
    }
    h += '<h3>Receipt register</h3>' + tableRows(d.rows||[], ['receipt_no','receipt_date','donor_name','pan','mode','amount']);
    return h;
  }
  function render12A(d){
    if (!d) return '';
    return '<h3>12A — Income &amp; Expenditure Statement (Trust)</h3>' +
      compKpiBlock([
        ['Income', '₹' + fmt(d.income_total||0), 'good'],
        ['Expenditure', '₹' + fmt(d.expense_total||0)],
        ['Application %', (d.application_pct||0).toFixed(1) + '%', d.compliance_85 ? 'good' : 'bad'],
        ['85% Required', '₹' + fmt(Math.round(d.required_application_85pct||0))],
        ['Surplus / Shortfall', '₹' + fmt(Math.round((d.income_minus_expense||0))), (d.income_minus_expense||0) >= 0 ? 'good' : 'bad'],
        ['85% Compliance', d.compliance_85 ? '✓ Met' : '✗ Shortfall', d.compliance_85 ? 'good' : 'bad'],
      ]) +
      (d.shortfall_85pct > 0
        ? '<div style="padding:11px 14px;background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;font-size:12px;margin-bottom:14px;color:#991b1b"><i class="fa-solid fa-triangle-exclamation"></i> <strong>Shortfall of ₹' + fmt(Math.round(d.shortfall_85pct)) + '</strong> — file Form 10 to accumulate (Sec 11(2)).</div>'
        : '<div style="padding:11px 14px;background:#dcfce7;border:1px solid #86efac;border-radius:8px;font-size:12px;margin-bottom:14px;color:#166534"><i class="fa-solid fa-circle-check"></i> 85% application rule met — compliant under Sec 11(1)(a).</div>') +
      '<h3>Expense breakdown</h3>' + tableRows(d.expense_breakdown||[], ['expense_category','total','c']) +
      '<div style="font-size:11.5px;color:var(--aev-muted);margin-top:12px;font-style:italic">' + esc(d.note||'') + '</div>';
  }
  function renderFCRA(d){
    if (!d) return '';
    const q = d.quarters || {};
    return '<h3>FCRA — Foreign Contribution Receipts (FC-4)</h3>' +
      compKpiBlock([
        ['Total receipts', fmt(d.count||0)],
        ['Total amount', '₹' + fmt(d.total||0)],
        ['Q1 (Apr-Jun)', '₹' + fmt(q.Q1||0)],
        ['Q2 (Jul-Sep)', '₹' + fmt(q.Q2||0)],
        ['Q3 (Oct-Dec)', '₹' + fmt(q.Q3||0)],
        ['Q4 (Jan-Mar)', '₹' + fmt(q.Q4||0)],
      ]) +
      '<h3>Receipts by country</h3>' +
      '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px">' +
        Object.entries(d.by_country||{}).map(([c,v]) => `<span class="aev-tag" style="font-size:11px;padding:3px 9px"><i class="fa-solid fa-globe"></i> ${esc(c)}: ₹${fmt(v)}</span>`).join('') +
      '</div>' +
      tableRows(d.rows||[], ['donation_code','donation_date','name','donor_country','amount','payment_method']) +
      '<div style="font-size:11.5px;color:var(--aev-muted);margin-top:8px;font-style:italic">' + esc(d.note||'') + '</div>';
  }
  function renderCSR(d){
    if (!d) return '';
    return '<h3>CSR — Corporate Donations (Companies Act Sec 135)</h3>' +
      compKpiBlock([
        ['Companies', fmt(Object.keys(d.by_company||{}).length)],
        ['Total CSR amount', '₹' + fmt(d.total||0), 'good'],
        ['Number of donations', fmt(d.count||0)],
      ]) +
      '<h3>Top corporate donors</h3>' +
      tableRows(Object.entries(d.by_company||{}).slice(0,10).map(([c,v]) => ({company: c, amount: '₹'+fmt(v)})), ['company','amount']) +
      tableRows(d.rows||[], ['donation_code','donation_date','company','pan','amount','program_name']) +
      '<div style="font-size:11.5px;color:var(--aev-muted);margin-top:8px;font-style:italic">' + esc(d.note||'') + '</div>';
  }
  function renderForm10B(d){
    if (!d) return '';
    let h = render12A(d);
    h += '<h3>Programme-wise utilisation</h3>' + tableRows(d.programme_utilisation||[], ['program_name','budget','received','spent']);
    h += '<h3>Auditor checklist</h3><ul style="font-size:12.5px;list-style:none;padding-left:0">';
    for (const [k, v] of Object.entries(d.auditor_checklist||{})) {
      h += `<li style="padding:5px 0"><i class="fa-solid fa-${v ? 'circle-check' : 'circle-xmark'}" style="color:${v ? '#10b981' : '#dc2626'};margin-right:8px"></i> ${esc(k)}</li>`;
    }
    h += '</ul>';
    return h;
  }
  function renderCompOverview(d){
    return '<h3>Compliance Overview</h3>' +
      '<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:14px">' +
      '<div style="padding:14px;border:1px solid var(--aev-line);border-radius:9px"><h3 style="margin:0 0 6px">80G</h3>' +
        compKpiBlock([['Receipts', fmt(d['80g']?.count||0)], ['Amount', '₹'+fmt(d['80g']?.amount||0), 'good']]) + '</div>' +
      '<div style="padding:14px;border:1px solid var(--aev-line);border-radius:9px"><h3 style="margin:0 0 6px">12A</h3>' +
        compKpiBlock([['Income', '₹'+fmt(d['12a']?.income_total||0), 'good'], ['Application', (d['12a']?.application_pct||0).toFixed(1)+'%', d['12a']?.compliance_85 ? 'good' : 'bad']]) + '</div>' +
      '<div style="padding:14px;border:1px solid var(--aev-line);border-radius:9px"><h3 style="margin:0 0 6px">FCRA</h3>' +
        compKpiBlock([['Receipts', fmt(d.fcra?.count||0)], ['Amount', '₹'+fmt(d.fcra?.total||0)]]) + '</div>' +
      '<div style="padding:14px;border:1px solid var(--aev-line);border-radius:9px"><h3 style="margin:0 0 6px">CSR</h3>' +
        compKpiBlock([['Companies', fmt(d.csr?.count||0)], ['Amount', '₹'+fmt(d.csr?.total||0), 'good']]) + '</div>' +
      '</div>';
  }

  /* ─── /KPI Drill + Compliance ─── */
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
    const periodSel = document.getElementById('rep-period').value;
    const from = document.getElementById('rep-from').value;
    const to   = document.getElementById('rep-to').value;
    const useDates = (periodSel === 'custom' || periodSel === 'fy current' || periodSel === 'fy previous') && from && to;
    const period = useDates ? `${from} to ${to}` : periodSel;
    const pane = document.getElementById('report-preview');
    pane.innerHTML = '<div class="aev-empty"><span class="aev-loader"></span></div>';
    try {
      const payload = { module, period };
      if (useDates) { payload.from = from; payload.to = to; }
      const r = await call('module_report', payload);
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
    const periodSel = document.getElementById('rep-period').value;
    const from = document.getElementById('rep-from').value;
    const to   = document.getElementById('rep-to').value;
    const useDates = (periodSel === 'custom' || periodSel === 'fy current' || periodSel === 'fy previous') && from && to;
    const url = useDates
      ? `${API}?action=report_export&module=${module}&from=${from}&to=${to}`
      : `${API}?action=report_export&module=${module}&period=${encodeURIComponent(periodSel)}`;
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
