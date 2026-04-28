<?php
/**
 * Aether v2 — Command Centre
 * Light theme matching the Dhrub Foundation ERP (emerald primary, slate borders, Outfit/Manrope).
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
<link rel="icon" type="image/png" href="/logo.png">
<link rel="stylesheet" href="style.css">
</head>
<body class="aev-light">
<div class="aev-overlay" id="auth-overlay">
  <div class="aev-auth-card">
    <div class="aev-mark-lg"></div>
    <h3>Sign in to access Aether</h3>
    <p>Aether's Command Centre is restricted. Sign in to your ERP first to access live system data.</p>
    <a class="aev-btn primary" href="/" data-testid="overlay-login-link"><i class="fa-solid fa-arrow-left"></i> Go to ERP login</a>
  </div>
</div>

<div class="aev-app" id="app" style="display:none">
  <header class="aev-topbar">
    <a class="aev-back" href="/" data-testid="back-to-erp"><i class="fa-solid fa-arrow-left"></i> Back to ERP</a>
    <div class="aev-brand">
      <div class="aev-mark"></div>
      <div>
        <h1>Aether <span>·</span> Command Centre</h1>
        <div class="aev-subtitle">Autonomous ERP brain · zero external calls · live</div>
      </div>
    </div>
    <span class="aev-pill live" id="live-pill"><span class="dot"></span> LIVE</span>
    <div class="aev-actions">
      <button class="aev-btn" id="btn-refresh" data-testid="btn-refresh"><i class="fa-solid fa-rotate"></i> Refresh</button>
      <button class="aev-btn" id="btn-sync" data-testid="btn-sync"><i class="fa-solid fa-diagram-project"></i> Sync schema</button>
      <button class="aev-btn primary" id="btn-heal" data-testid="btn-heal"><i class="fa-solid fa-wand-magic-sparkles"></i> Self-heal</button>
    </div>
  </header>

  <main class="aev-main">
    <!-- KPIs -->
    <section class="aev-kpis" id="kpis-grid"></section>

    <!-- 12-col grid -->
    <section class="aev-grid">
      <div class="aev-card span-6" data-testid="card-health">
        <header><h2><i class="fa-solid fa-heart-pulse"></i> System Health</h2><span class="meta" id="health-count">…</span></header>
        <div id="health-overall" class="aev-banner ok" style="display:none"><span class="dot"></span><div><div class="aev-banner-title" id="h-label">…</div><div class="aev-banner-meta" id="h-meta"></div></div></div>
        <div id="health-checks"></div>
      </div>

      <div class="aev-card span-6" data-testid="card-audit">
        <header><h2><i class="fa-solid fa-clock-rotate-left"></i> Audit Trail</h2><span class="meta" id="audit-count">…</span></header>
        <div class="aev-feed" id="audit-feed"><div class="aev-empty"><span class="aev-loader"></span></div></div>
      </div>

      <div class="aev-card span-12" data-testid="card-schema-diff">
        <header>
          <h2><i class="fa-solid fa-code-branch"></i> Schema Diff Viewer</h2>
          <span class="meta" id="schema-meta">…</span>
        </header>
        <div id="schema-diff-pane"></div>
      </div>

      <div class="aev-card span-6" data-testid="card-knowledge">
        <header><h2><i class="fa-solid fa-network-wired"></i> Knowledge Graph</h2><span class="meta" id="kg-count">…</span></header>
        <div class="aev-modules" id="modules-grid"></div>
      </div>

      <div class="aev-card span-3" data-testid="card-schema">
        <header><h2><i class="fa-solid fa-camera"></i> Schema Watcher</h2></header>
        <div class="aev-kvlist" id="schema-info"></div>
      </div>

      <div class="aev-card span-3" data-testid="card-learning">
        <header><h2><i class="fa-solid fa-brain"></i> Learning Engine</h2></header>
        <div class="aev-stats" id="learn-grid"></div>
      </div>
    </section>
  </main>
</div>

<script src="panel.js"></script>
<script>
(function(){
  const API = 'api/aether.php';
  const get = (k) => localStorage.getItem(k);
  const token = (function(){
    for (const k of ['token','authToken','auth_token','jwt','access_token','userToken']) {
      const v = get(k);
      if (v && v.split('.').length === 3) return v;
    }
    return null;
  })();
  if (!token) { document.getElementById('auth-overlay').classList.add('show'); return; }

  async function call(action, body={}) {
    const r = await fetch(API, {
      method:'POST',
      headers:{'Content-Type':'application/json','Authorization':'Bearer '+token},
      body: JSON.stringify({action, ...body}),
    });
    if (r.status === 401) { document.getElementById('auth-overlay').classList.add('show'); throw new Error('auth'); }
    return r.json();
  }

  function toast(msg, bad=false){
    const t = document.createElement('div');
    t.className = 'aev-toast' + (bad ? ' bad' : '');
    t.innerHTML = '<i class="fa-solid fa-' + (bad ? 'circle-exclamation' : 'circle-check') + '"></i> ' + esc(msg);
    document.body.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; t.style.transform = 'translateX(20px)'; setTimeout(()=>t.remove(), 300); }, 3500);
  }
  function esc(s){return String(s||'').replace(/[&<>]/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;'}[m]))}
  function fmt(n){return Number(n).toLocaleString('en-IN')}
  function fmtTime(ts){ if(!ts) return '—'; return new Date(ts.replace(' ','T')).toLocaleString('en-IN',{dateStyle:'medium',timeStyle:'short'}); }

  function renderKpis(kpis){
    const grid = document.getElementById('kpis-grid');
    grid.innerHTML = (kpis||[]).map((k,i) => `
      <div class="aev-kpi" data-testid="kpi-${esc(k.label.replace(/\s+/g,'-').toLowerCase())}" style="animation-delay:${i*40}ms">
        <i class="fa-solid fa-${esc(k.icon||'circle')}"></i>
        <div class="kpi-label">${esc(k.label)}</div>
        <div class="kpi-value">${esc(k.value)}</div>
      </div>`).join('');
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
        <div class="check-body"><div class="check-title">${esc(c.title)}</div><div class="check-detail">${esc(c.detail||'')}</div></div>
        <span class="aev-tag sev-${c.severity}">${esc(c.severity)}</span>
      </div>`).join('');
  }

  function renderAudit(audit){
    document.getElementById('audit-count').textContent = `${(audit.recent||[]).length} events · 24h: ${(audit.counts.high||0)+(audit.counts.critical||0)} severe`;
    document.getElementById('audit-feed').innerHTML = (audit.recent||[]).length === 0
      ? '<div class="aev-empty">No audit events yet.</div>'
      : audit.recent.map(r => `
        <div class="aev-feed-row">
          <div class="feed-time">${esc(fmtTime(r.created_at))}</div>
          <div class="feed-body"><span class="feed-type">${esc(r.event_type)}</span> ${esc(r.summary||'')}</div>
          <span class="aev-tag sev-${r.severity}">${esc(r.severity)}</span>
        </div>`).join('');
  }

  function renderModules(kg){
    document.getElementById('kg-count').textContent = `${kg.tables||0} tables · ${kg.columns||0} cols · ${kg.relationships||0} links`;
    document.getElementById('modules-grid').innerHTML = (kg.modules||[]).map(m => `
      <div class="aev-module-chip">
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

  async function renderSchemaDiff(){
    try {
      const r = await call('schema_diff');
      const pane = document.getElementById('schema-diff-pane');
      const meta = document.getElementById('schema-meta');
      const snaps = r.snapshots || [];
      const changes = r.changes || [];
      meta.textContent = `${snaps.length} snapshot${snaps.length===1?'':'s'} · ${changes.length} change${changes.length===1?'':'s'} tracked`;

      if (!snaps.length) { pane.innerHTML = '<div class="aev-empty">No snapshots yet — click <strong>Sync schema</strong> to create the first.</div>'; return; }

      // group changes
      const grouped = { created:[], dropped:[], added:[], removed:[], modified:[] };
      changes.forEach(c => { (grouped[c.change_type] || (grouped[c.change_type]=[])).push(c); });
      const cur = snaps[0], prev = snaps[1];

      const card = (title, badge, items, icon, cls) => `
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
          ${card('Tables created', null, grouped.created || [], 'circle-plus', 'good')}
          ${card('Tables dropped', null, grouped.dropped || [], 'circle-minus', 'bad')}
          ${card('Columns added',  null, grouped.added   || [], 'plus',      'good')}
          ${card('Columns removed',null, grouped.removed || [], 'minus',     'bad')}
          ${card('Modified',       null, grouped.modified|| [], 'pen',       'warn')}
        </div>`;
    } catch (e) { /* auth handled */ }
  }

  async function load() {
    document.getElementById('app').style.display = 'block';
    try {
      const d = await call('dashboard');
      renderKpis(d.kpis);
      renderHealth(d.health);
      renderAudit(d.audit);
      renderModules(d.knowledge);
      renderSchema(d.schema);
      renderLearn(d.learning);
      renderSchemaDiff();
    } catch (e) { if (e.message!=='auth') toast('Failed to load dashboard', true); }
  }

  document.getElementById('btn-refresh').addEventListener('click', () => { load(); toast('Refreshed'); });
  document.getElementById('btn-sync').addEventListener('click', async () => {
    try {
      const r = await call('schema_sync');
      toast(r.changed ? `Schema sync: ${(r.changes||[]).length} change(s)` : 'Schema unchanged');
      load();
    } catch (e) { toast('Sync blocked (admin only?)', true); }
  });
  document.getElementById('btn-heal').addEventListener('click', async () => {
    if (!confirm('Run all health checks and apply auto-heals where allowed?')) return;
    try {
      const r = await call('self_heal');
      toast(`Healed ${r.healed_count||0} issue(s); overall: ${r.overall}`);
      load();
    } catch (e) { toast('Self-heal blocked (admin only?)', true); }
  });

  load();
  setInterval(load, 30000);
})();
</script>
</body>
</html>
