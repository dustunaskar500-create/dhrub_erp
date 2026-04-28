<?php
/**
 * Aether v2 — Command Centre Dashboard
 * Self-contained PHP page. Reads JWT from sessionStorage/localStorage on load.
 * Loads its own data via /aether/api/v2/aether.php (action=dashboard).
 */
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Aether — Command Centre</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="icon" type="image/png" href="/logo.png">
<style>
  :root{
    --ink-0:#0a0c12; --ink-1:#0f1320; --ink-2:#161a2a; --ink-3:#1d2235;
    --line:#252a40; --line-2:#2f354f;
    --text:#e7eaf3; --muted:#8b91a8; --dim:#5b617a;
    --accent:#7af0c6; --accent-2:#5dd1f5; --warn:#f5c465; --bad:#ef6c6c;
    --good:#7af0c6; --plasma:#c3a6ff;
    --grad: radial-gradient(1200px 600px at 80% -10%, rgba(122,240,198,.10), transparent 60%),
            radial-gradient(900px 500px at -10% 110%, rgba(195,166,255,.10), transparent 60%);
  }
  *{box-sizing:border-box}
  html,body{margin:0;padding:0;background:var(--ink-0);color:var(--text);font-family:'Outfit',system-ui,sans-serif}
  body{background:var(--ink-0) var(--grad);min-height:100vh;overflow-x:hidden}
  body::before{content:'';position:fixed;inset:0;pointer-events:none;background-image:radial-gradient(rgba(255,255,255,.025) 1px, transparent 1px);background-size:3px 3px;mix-blend-mode:overlay;opacity:.6;z-index:0}
  a{color:inherit;text-decoration:none}
  ::selection{background:var(--accent);color:#031}
  .mono{font-family:'JetBrains Mono',ui-monospace,monospace}
  .wrap{max-width:1480px;margin:0 auto;padding:24px 32px 80px;position:relative;z-index:1}
  .topbar{display:flex;align-items:center;gap:16px;padding-bottom:24px;border-bottom:1px solid var(--line);margin-bottom:32px}
  .topbar .back{padding:9px 14px;border:1px solid var(--line);border-radius:999px;color:var(--muted);font-size:13px;display:inline-flex;gap:8px;align-items:center;transition:border-color .2s,color .2s}
  .topbar .back:hover{border-color:var(--accent);color:var(--accent)}
  .brand{display:flex;align-items:center;gap:14px;flex:1}
  .brand-mark{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;
    background:radial-gradient(circle at 30% 25%,var(--accent),transparent 60%),radial-gradient(circle at 70% 75%,var(--plasma),transparent 60%);
    box-shadow:0 0 30px rgba(122,240,198,.3),inset 0 0 0 1px rgba(255,255,255,.1)}
  .brand-mark::after{content:'';width:14px;height:14px;background:var(--ink-0);border-radius:50%;display:block}
  .brand h1{font-size:22px;font-weight:600;letter-spacing:-.01em;margin:0}
  .brand h1 span{color:var(--accent)}
  .brand .sub{color:var(--muted);font-size:13px;margin-top:2px;letter-spacing:.02em}
  .pill{padding:6px 12px;border-radius:999px;font-size:11px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;border:1px solid var(--line)}
  .pill.live{color:var(--good);border-color:rgba(122,240,198,.4);background:rgba(122,240,198,.06)}
  .pill.live::before{content:'';display:inline-block;width:6px;height:6px;background:var(--good);border-radius:50%;margin-right:6px;box-shadow:0 0 8px var(--good);animation:pulse 2s infinite}
  @keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
  .topbar .actions{display:flex;gap:8px}
  .btn{padding:9px 16px;border:1px solid var(--line);background:var(--ink-2);color:var(--text);border-radius:10px;font:inherit;font-size:13px;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:8px}
  .btn:hover{border-color:var(--accent);color:var(--accent)}
  .btn.primary{background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#031;border-color:transparent;font-weight:600}
  .btn.primary:hover{box-shadow:0 8px 32px rgba(122,240,198,.35);transform:translateY(-1px)}
  .btn.danger{color:var(--bad);border-color:rgba(239,108,108,.3)}
  .btn.danger:hover{border-color:var(--bad);background:rgba(239,108,108,.08)}

  .grid{display:grid;gap:20px}
  .grid-12{grid-template-columns:repeat(12,1fr)}
  .card{background:linear-gradient(180deg,var(--ink-2),var(--ink-1));border:1px solid var(--line);border-radius:16px;padding:24px;position:relative;overflow:hidden;transition:border-color .25s}
  .card:hover{border-color:var(--line-2)}
  .card h2{font-size:13px;font-weight:600;color:var(--muted);letter-spacing:.1em;text-transform:uppercase;margin:0 0 18px}
  .card h2 .count{margin-left:8px;color:var(--accent);font-weight:500}
  .span-3{grid-column:span 3}.span-4{grid-column:span 4}.span-6{grid-column:span 6}.span-8{grid-column:span 8}.span-12{grid-column:span 12}
  @media(max-width:1100px){.span-3,.span-4{grid-column:span 6}}
  @media(max-width:680px){.span-3,.span-4,.span-6,.span-8{grid-column:span 12}}

  .kpi{display:flex;flex-direction:column;gap:12px}
  .kpi .label{font-size:11px;color:var(--muted);letter-spacing:.12em;text-transform:uppercase}
  .kpi .value{font-size:28px;font-weight:600;letter-spacing:-.02em;line-height:1}
  .kpi .icon{position:absolute;top:18px;right:18px;color:var(--dim);font-size:22px;opacity:.7}
  .kpi:hover .icon{color:var(--accent);opacity:1}

  .health-overall{display:flex;align-items:center;gap:14px;padding:18px;border-radius:12px;background:var(--ink-3);border:1px solid var(--line);margin-bottom:18px}
  .health-dot{width:14px;height:14px;border-radius:50%;flex-shrink:0;animation:pulse 2s infinite}
  .health-overall.ok .health-dot{background:var(--good);box-shadow:0 0 18px var(--good)}
  .health-overall.warn .health-dot{background:var(--warn);box-shadow:0 0 18px var(--warn)}
  .health-overall.fail .health-dot{background:var(--bad);box-shadow:0 0 18px var(--bad)}
  .health-overall .h-label{font-weight:600;font-size:14px}
  .health-overall .h-meta{color:var(--muted);font-size:12px;margin-left:auto}
  .check-row{display:grid;grid-template-columns:auto 1fr auto;gap:12px;padding:12px 8px;border-bottom:1px dashed var(--line)}
  .check-row:last-child{border-bottom:0}
  .check-row .ico{width:24px;height:24px;border-radius:6px;display:grid;place-items:center;font-size:11px}
  .check-row.ok .ico{background:rgba(122,240,198,.12);color:var(--good)}
  .check-row.warn .ico{background:rgba(245,196,101,.12);color:var(--warn)}
  .check-row.fail .ico{background:rgba(239,108,108,.12);color:var(--bad)}
  .check-row .body{min-width:0}
  .check-row .title{font-weight:500;font-size:13px;margin-bottom:2px}
  .check-row .detail{color:var(--muted);font-size:12px;line-height:1.4}
  .check-row .sev{font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:var(--dim);align-self:start;padding-top:4px}

  .audit{display:flex;flex-direction:column;gap:8px;max-height:520px;overflow-y:auto;padding-right:8px}
  .audit::-webkit-scrollbar{width:6px}
  .audit::-webkit-scrollbar-thumb{background:var(--line-2);border-radius:3px}
  .audit-row{display:grid;grid-template-columns:auto 1fr auto;gap:14px;padding:10px 0;border-bottom:1px dashed var(--line);align-items:center}
  .audit-row:last-child{border-bottom:0}
  .audit-row .stamp{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--dim);white-space:nowrap}
  .audit-row .desc{font-size:13px;color:var(--text);line-height:1.4}
  .audit-row .desc .type{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--accent-2);background:rgba(93,209,245,.08);padding:1px 7px;border-radius:4px;margin-right:6px}
  .sev-tag{font-size:10px;letter-spacing:.08em;text-transform:uppercase;padding:3px 7px;border-radius:4px;font-weight:600}
  .sev-tag.info{color:var(--muted);background:var(--ink-3)}
  .sev-tag.low{color:var(--accent-2);background:rgba(93,209,245,.08)}
  .sev-tag.medium{color:var(--warn);background:rgba(245,196,101,.08)}
  .sev-tag.high,.sev-tag.critical{color:var(--bad);background:rgba(239,108,108,.08)}

  .modules-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px}
  .mod-chip{padding:14px;border:1px solid var(--line);border-radius:10px;background:var(--ink-3);transition:all .2s}
  .mod-chip:hover{border-color:var(--accent);transform:translateY(-2px)}
  .mod-chip .name{font-weight:500;font-size:13px;text-transform:capitalize}
  .mod-chip .count{font-size:24px;font-weight:600;color:var(--accent);margin-top:6px;font-family:'JetBrains Mono',monospace}

  .schema-info{display:flex;flex-direction:column;gap:14px}
  .schema-info .row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px dashed var(--line)}
  .schema-info .row:last-child{border-bottom:0}
  .schema-info .row .k{color:var(--muted);font-size:12px;letter-spacing:.06em;text-transform:uppercase}
  .schema-info .row .v{font-family:'JetBrains Mono',monospace;font-size:13px;color:var(--text)}
  .schema-info .row .v.fp{font-size:11px;color:var(--accent-2);max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;direction:rtl;text-align:left}

  .learn-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px}
  .learn-grid .stat{padding:14px;border:1px solid var(--line);border-radius:10px;background:var(--ink-3)}
  .learn-grid .stat .l{font-size:11px;color:var(--muted);letter-spacing:.08em;text-transform:uppercase;margin-bottom:6px}
  .learn-grid .stat .v{font-size:22px;font-weight:600;font-family:'JetBrains Mono',monospace}
  .learn-grid .stat .v.good{color:var(--good)}
  .learn-grid .stat .v.warn{color:var(--warn)}

  .empty{padding:24px;text-align:center;color:var(--muted);font-size:13px}
  .loader{display:inline-block;width:14px;height:14px;border:2px solid var(--line-2);border-top-color:var(--accent);border-radius:50%;animation:spin .8s linear infinite}
  @keyframes spin{to{transform:rotate(360deg)}}

  .overlay{position:fixed;inset:0;background:rgba(10,12,18,.75);backdrop-filter:blur(8px);z-index:9999;display:none;align-items:center;justify-content:center;padding:20px}
  .overlay.show{display:flex;animation:fadeIn .25s}
  @keyframes fadeIn{from{opacity:0}to{opacity:1}}
  .auth-card{background:var(--ink-2);border:1px solid var(--line);border-radius:16px;padding:32px;max-width:420px;width:100%;text-align:center}
  .auth-card h3{margin:0 0 12px;font-weight:600}
  .auth-card p{color:var(--muted);font-size:14px;margin:0 0 18px;line-height:1.5}

  .toast{position:fixed;bottom:30px;right:30px;background:var(--ink-3);border:1px solid var(--line);border-left:3px solid var(--accent);padding:14px 18px;border-radius:8px;font-size:13px;z-index:10000;box-shadow:0 12px 40px rgba(0,0,0,.4);animation:slideIn .3s}
  .toast.bad{border-left-color:var(--bad)}
  @keyframes slideIn{from{transform:translateX(100%);opacity:0}to{transform:translateX(0);opacity:1}}
</style>
</head>
<body>
<div class="overlay" id="auth-overlay">
  <div class="auth-card">
    <h3>Authentication required</h3>
    <p>Aether's Command Centre is restricted. Sign in to your ERP first to access live system data.</p>
    <a class="btn primary" href="/">← Go to ERP login</a>
  </div>
</div>

<div class="wrap" id="app" style="display:none">
  <div class="topbar">
    <a class="back" href="/" data-testid="back-to-erp"><i class="fa-solid fa-arrow-left"></i> Back to ERP</a>
    <div class="brand">
      <div class="brand-mark"></div>
      <div>
        <h1>Aether <span>·</span> Command Centre</h1>
        <div class="sub mono">Autonomous ERP brain · zero external calls · live</div>
      </div>
    </div>
    <span class="pill live" id="live-pill">LIVE</span>
    <div class="actions">
      <button class="btn" id="btn-refresh" data-testid="btn-refresh"><i class="fa-solid fa-rotate"></i> Refresh</button>
      <button class="btn" id="btn-sync" data-testid="btn-sync"><i class="fa-solid fa-diagram-project"></i> Sync schema</button>
      <button class="btn primary" id="btn-heal" data-testid="btn-heal"><i class="fa-solid fa-wand-magic-sparkles"></i> Self-heal</button>
    </div>
  </div>

  <!-- KPIs -->
  <div class="grid grid-12" style="margin-bottom:24px" id="kpis-grid"></div>

  <!-- Main 12-col layout -->
  <div class="grid grid-12">
    <!-- Health -->
    <div class="card span-6">
      <h2>System Health <span class="count" id="health-count">…</span></h2>
      <div id="health-overall" class="health-overall ok" style="display:none">
        <div class="health-dot"></div>
        <div>
          <div class="h-label" id="h-label">…</div>
          <div class="h-meta" id="h-meta"></div>
        </div>
      </div>
      <div id="health-checks"></div>
    </div>

    <!-- Audit -->
    <div class="card span-6">
      <h2>Audit Trail <span class="count" id="audit-count">…</span></h2>
      <div class="audit" id="audit-feed"><div class="empty"><span class="loader"></span></div></div>
    </div>

    <!-- Knowledge / Modules -->
    <div class="card span-6">
      <h2>Knowledge Graph <span class="count" id="kg-count">…</span></h2>
      <div class="modules-grid" id="modules-grid"></div>
    </div>

    <!-- Schema watcher -->
    <div class="card span-3">
      <h2>Schema Watcher</h2>
      <div class="schema-info" id="schema-info"></div>
    </div>

    <!-- Learning -->
    <div class="card span-3">
      <h2>Learning Engine</h2>
      <div class="learn-grid" id="learn-grid"></div>
    </div>
  </div>
</div>

<script src="/aether/v2-panel.js"></script>
<script>
(async function(){
  const API = '/aether/api/v2/aether.php';
  const token = (function(){
    for (const k of ['token','authToken','auth_token','jwt','access_token','userToken']) {
      const v = localStorage.getItem(k);
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
    const t=document.createElement('div');
    t.className='toast'+(bad?' bad':'');
    t.textContent=msg;
    document.body.appendChild(t);
    setTimeout(()=>t.remove(),3500);
  }

  const fmt = (n) => Number(n).toLocaleString('en-IN');
  const escape = (s) => String(s||'').replace(/[&<>]/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;'}[m]));

  function renderKpis(kpis){
    const grid = document.getElementById('kpis-grid');
    grid.innerHTML = (kpis||[]).map(k => `
      <div class="card span-3 kpi" data-testid="kpi-${escape(k.label.replace(/\s+/g,'-').toLowerCase())}">
        <i class="fa-solid fa-${escape(k.icon||'circle')} icon"></i>
        <div class="label">${escape(k.label)}</div>
        <div class="value">${escape(k.value)}</div>
      </div>`).join('');
  }

  function renderHealth(health){
    const overall = (health.overall||'ok').toLowerCase();
    const o = document.getElementById('health-overall');
    o.style.display='flex'; o.className = 'health-overall '+overall;
    document.getElementById('h-label').textContent =
      overall==='ok' ? 'All systems nominal' :
      overall==='warn' ? 'Warnings detected' : 'Failures detected';
    document.getElementById('h-meta').textContent =
      `${health.issue_count||0} open issue(s)` + (health.healed_count? ` · ${health.healed_count} healed`:'');

    document.getElementById('health-count').textContent = (health.checks||[]).length+' checks';
    document.getElementById('health-checks').innerHTML = (health.checks||[]).map(c => `
      <div class="check-row ${c.status}">
        <div class="ico"><i class="fa-solid fa-${c.status==='ok'?'check':c.status==='warn'?'triangle-exclamation':'xmark'}"></i></div>
        <div class="body">
          <div class="title">${escape(c.title)}</div>
          <div class="detail">${escape(c.detail||'')}</div>
        </div>
        <div class="sev">${escape(c.severity||'')}</div>
      </div>`).join('');
  }

  function renderAudit(audit){
    document.getElementById('audit-count').textContent = `${(audit.recent||[]).length} events · 24h: ${(audit.counts.high||0)+(audit.counts.critical||0)} critical`;
    document.getElementById('audit-feed').innerHTML = (audit.recent||[]).length === 0
      ? '<div class="empty">No audit events yet.</div>'
      : audit.recent.map(r => `
        <div class="audit-row">
          <div class="stamp">${escape(r.created_at)}</div>
          <div class="desc">
            <span class="type">${escape(r.event_type)}</span>${escape(r.summary||'')}
          </div>
          <span class="sev-tag ${r.severity}">${escape(r.severity)}</span>
        </div>`).join('');
  }

  function renderModules(kg){
    document.getElementById('kg-count').textContent = `${kg.tables||0} tables · ${kg.columns||0} columns · ${kg.relationships||0} links`;
    document.getElementById('modules-grid').innerHTML = (kg.modules||[]).map(m => `
      <div class="mod-chip">
        <div class="name">${escape(m.module)}</div>
        <div class="count">${escape(m.c)}</div>
      </div>`).join('');
  }

  function renderSchema(s){
    const fp = (s.fingerprint||'—').slice(0,32);
    document.getElementById('schema-info').innerHTML = `
      <div class="row"><span class="k">Tables</span><span class="v mono">${escape(s.tables||0)}</span></div>
      <div class="row"><span class="k">Columns</span><span class="v mono">${escape(s.columns||0)}</span></div>
      <div class="row"><span class="k">Last Sync</span><span class="v mono">${escape(s.taken_at||'—')}</span></div>
      <div class="row"><span class="k">Fingerprint</span><span class="v fp mono" title="${escape(s.fingerprint||'')}">…${escape(fp)}</span></div>`;
  }

  function renderLearn(l){
    const rate = l.success_rate_pct;
    document.getElementById('learn-grid').innerHTML = `
      <div class="stat"><div class="l">Interactions</div><div class="v">${fmt(l.interactions||0)}</div></div>
      <div class="stat"><div class="l">Avg Confidence</div><div class="v">${(l.avg_confidence_7d||0).toFixed(2)}</div></div>
      <div class="stat"><div class="l">Learned Weights</div><div class="v">${fmt(l.learned_weights||0)}</div></div>
      <div class="stat"><div class="l">Success Rate</div><div class="v ${rate>=70?'good':rate==null?'':'warn'}">${rate==null?'—':rate+'%'}</div></div>`;
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
    } catch (e) { if (e.message!=='auth') toast('Failed to load dashboard', true); }
  }

  document.getElementById('btn-refresh').addEventListener('click', () => load());
  document.getElementById('btn-sync').addEventListener('click', async () => {
    try {
      const r = await call('schema_sync');
      toast(r.changed ? `Schema sync: ${(r.changes||[]).length} change(s) applied` : 'Schema unchanged');
      load();
    } catch (e) { toast('Sync failed', true); }
  });
  document.getElementById('btn-heal').addEventListener('click', async () => {
    if (!confirm('Run all health checks and apply auto-heals where allowed?')) return;
    try {
      const r = await call('self_heal');
      toast(`Healed ${r.healed_count||0} issue(s); overall: ${r.overall}`);
      load();
    } catch (e) { toast('Self-heal blocked (admin only?)', true); }
  });

  await load();
  // auto-refresh dashboard every 30s
  setInterval(load, 30000);
})();
</script>
</body>
</html>
