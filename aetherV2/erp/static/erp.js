/* ============================================================
   Aether ERP — Frontend module (vanilla JS)
   Routes (hash-based):
     #dashboard, #stock, #stock/:id, #vendors, #grn, #grn/new, #grn/:id,
     #adjustments, #invoices, #invoices/new, #invoices/:id, #reports
   ============================================================ */
(function () {
const API = '/aetherV2/erp/api/router.php';
const STORE_TOKEN_KEYS = ['access_token','token','authToken','auth_token','jwt','userToken'];

let token = null;
let me = null;
let org = null;
let stateList = [];

/* ──────────── Token + identity ──────────── */
function readToken() {
  for (const k of STORE_TOKEN_KEYS) {
    const v = localStorage.getItem(k);
    if (v && v.split('.').length === 3) return v;
  }
  return null;
}

async function api(action, body = {}) {
  const r = await fetch(API + '?action=' + encodeURIComponent(action), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
    body: JSON.stringify(body),
  });
  if (r.status === 401) {
    location.href = '/aetherV2/chat.php';
    throw new Error('auth');
  }
  let j;
  try { j = await r.json(); } catch (e) { throw new Error('Bad response'); }
  if (!r.ok || j.error) throw new Error(j.error || 'Request failed (' + r.status + ')');
  return j;
}

async function apiFormData(action, formData) {
  const r = await fetch(API + '?action=' + encodeURIComponent(action), {
    method: 'POST',
    headers: { 'Authorization': 'Bearer ' + token },
    body: formData,
  });
  if (r.status === 401) { location.href = '/aetherV2/chat.php'; throw new Error('auth'); }
  const j = await r.json();
  if (!r.ok || j.error) throw new Error(j.error || 'Upload failed');
  return j;
}

/* ──────────── Toast ──────────── */
function toast(msg, kind = 'success', ms = 3000) {
  const host = document.getElementById('toast-host') || (() => {
    const h = document.createElement('div'); h.id = 'toast-host'; h.className = 'toast-host';
    document.body.appendChild(h); return h;
  })();
  const t = document.createElement('div');
  t.className = 'toast ' + kind;
  const icon = kind === 'success' ? 'check-circle' : kind === 'error' ? 'circle-exclamation' : 'circle-info';
  t.innerHTML = `<i class="fa-solid fa-${icon}"></i><span>${esc(msg)}</span>`;
  host.appendChild(t);
  setTimeout(() => t.remove(), ms);
}

/* ──────────── Util ──────────── */
function esc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, m => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m]));
}
function money(n, withCurrency = true) {
  if (n == null || n === '') return '—';
  const v = Number(n);
  if (Number.isNaN(v)) return '—';
  const s = v.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  return (withCurrency ? '₹' : '') + s;
}
function date(d) {
  if (!d) return '—';
  const dt = new Date(d);
  if (Number.isNaN(dt.getTime())) return d;
  return dt.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
}
function todayISO() { return new Date().toISOString().slice(0, 10); }
function debounce(fn, ms = 250) {
  let h; return (...a) => { clearTimeout(h); h = setTimeout(() => fn(...a), ms); };
}

/* ──────────── Layout ──────────── */
const NAV = [
  ['#dashboard', 'gauge-high', 'Dashboard'],
  ['SECTION', 'STOCK'],
  ['#stock', 'boxes-stacked', 'Stock Items'],
  ['#vendors', 'truck-field', 'Vendors'],
  ['#grn', 'clipboard-check', 'Goods Receipt'],
  ['#adjustments', 'scale-unbalanced', 'Adjustments'],
  ['SECTION', 'BILLING'],
  ['#invoices', 'file-invoice-dollar', 'Tax Invoices'],
  ['#reports', 'chart-line', 'Reports & GST'],
  ['SECTION', ''],
  ['/aetherV2/chat.php', 'comments', 'Ask Aether', true],
  ['/aetherV2/dashboard.php', 'crown', 'Command Centre', true],
];

function renderShell() {
  const role = (me?.role || '').replace('_', ' ');
  const initial = (me?.full_name || me?.username || '?').slice(0, 1).toUpperCase();
  const navItems = NAV.map(item => {
    if (item[0] === 'SECTION') return `<h3>${esc(item[1])}</h3>`;
    const [href, ico, label, external] = item;
    const isActive = !external && (location.hash.split('/')[0] === href);
    return `<a class="nav-item ${isActive ? 'active' : ''}" href="${href}" ${external ? '' : 'data-nav'}>
      <i class="fa-solid fa-${ico}"></i><span>${esc(label)}</span>
      ${external ? '<i class="fa-solid fa-arrow-up-right-from-square" style="margin-left:auto;font-size:9px;opacity:.5"></i>' : ''}
    </a>`;
  }).join('');

  document.getElementById('app').innerHTML = `
    <div class="shell">
      <aside class="sidebar" id="sidebar">
        <div class="brand">
          <div class="mark"></div>
          <div>
            <h1>${esc(org?.org_name || 'ERP')}</h1>
            <div class="sub">stock · billing · gst</div>
          </div>
        </div>
        <nav class="nav" id="nav">${navItems}</nav>
        <div class="user-card">
          <div class="avatar">${esc(initial)}</div>
          <div class="info">
            <div class="name">${esc(me?.full_name || me?.username)}</div>
            <div class="role">${esc(role)}</div>
          </div>
          <a href="/aetherV2/chat.php" class="icon-btn" title="Back to Aether"><i class="fa-solid fa-arrow-left"></i></a>
        </div>
      </aside>
      <main class="content">
        <div class="topbar">
          <div class="flex gap-12">
            <button class="menu-btn" id="menu-btn" aria-label="Toggle menu"><i class="fa-solid fa-bars"></i></button>
            <div class="crumb" id="crumb">…</div>
          </div>
          <div class="actions" id="topbar-actions"></div>
        </div>
        <div class="scroll" id="scroll">
          <div class="page" id="page"><div class="empty"><i class="fa-solid fa-spinner fa-spin"></i><h4>Loading…</h4></div></div>
        </div>
      </main>
    </div>
    <div class="drawer-overlay" id="drawer-overlay"></div>
    <div class="drawer" id="drawer"></div>
    <div class="lightbox" id="lightbox"><button class="close-x" data-close-lb><i class="fa-solid fa-xmark"></i></button><div class="content" id="lb-content"></div></div>
  `;

  // Mobile menu
  document.getElementById('menu-btn')?.addEventListener('click', () => {
    document.getElementById('sidebar').classList.toggle('open');
  });
  document.getElementById('drawer-overlay').addEventListener('click', closeDrawer);
  document.getElementById('lightbox').addEventListener('click', (e) => {
    if (e.target.closest('[data-close-lb]') || e.target.id === 'lightbox') closeLightbox();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { closeDrawer(); closeLightbox(); }
  });
}

/* ──────────── Router ──────────── */
const ROUTES = {
  '#dashboard':   renderDashboard,
  '#stock':       renderStockList,
  '#vendors':     renderVendors,
  '#grn':         renderGrnList,
  '#adjustments': renderAdjustments,
  '#invoices':    renderInvoiceList,
  '#reports':     renderReports,
};

async function route() {
  const hash = location.hash || '#dashboard';
  const [base, ...rest] = hash.split('/');
  const fn = ROUTES[base] || renderDashboard;
  document.querySelectorAll('.nav-item').forEach(el => {
    el.classList.toggle('active', el.getAttribute('href') === base);
  });
  document.getElementById('sidebar')?.classList.remove('open');
  try {
    await fn(...rest);
  } catch (e) {
    setPage(`<div class="empty"><i class="fa-solid fa-circle-exclamation"></i><h4>Could not load</h4><p>${esc(e.message)}</p></div>`);
  }
}

function setPage(html) { document.getElementById('page').innerHTML = html; }
function setCrumb(...parts) {
  document.getElementById('crumb').innerHTML = parts.map((p, i) => i === parts.length - 1 ? `<strong>${esc(p)}</strong>` : esc(p) + ' /').join(' ');
}
function setTopActions(html) { document.getElementById('topbar-actions').innerHTML = html; }

/* ============================================================
   Dashboard
   ============================================================ */
async function renderDashboard() {
  setCrumb('Dashboard');
  setTopActions(`
    <a href="#grn/new" class="pill-btn"><i class="fa-solid fa-plus"></i><span>New GRN</span></a>
    <a href="#invoices/new" class="pill-btn btn-primary"><i class="fa-solid fa-file-invoice"></i><span>New Invoice</span></a>
  `);
  setPage(`<div class="empty"><i class="fa-solid fa-spinner fa-spin"></i><h4>Gathering insights…</h4></div>`);
  const d = await api('dash_overview');
  const inv = d.invoice || {};
  const adj = d.adjustments || {};
  const stock = d.stock || {};
  const grn = d.grn || {};

  setPage(`
    <h2>Estate overview</h2>
    <p class="lede">A bird's-eye view of the foundation's stock, billing, and discrepancies, sir.</p>

    <div class="kpis">
      <div class="kpi good"><div class="label">Stock Value</div><div class="value">${money(stock.value)}</div><div class="sub">${esc(stock.items || 0)} active SKUs</div></div>
      <div class="kpi ${(+stock.low_stock || 0) > 0 ? 'warn' : 'good'}"><div class="label">Low Stock</div><div class="value">${esc(stock.low_stock || 0)}</div><div class="sub">${esc(stock.out_of_stock || 0)} out of stock</div></div>
      <div class="kpi info"><div class="label">Revenue (30d)</div><div class="value">${money(inv.revenue)}</div><div class="sub">${esc(inv.total || 0)} invoices</div></div>
      <div class="kpi violet"><div class="label">Tax Collected</div><div class="value">${money(inv.tax_collected)}</div><div class="sub">${money(inv.outstanding)} outstanding</div></div>
      <div class="kpi ${(+grn.discrepancies || 0) > 0 ? 'warn' : 'good'}"><div class="label">GRN Discrepancies</div><div class="value">${esc(grn.discrepancies || 0)}</div><div class="sub">${esc(grn.pending || 0)} pending posting</div></div>
      <div class="kpi bad"><div class="label">Stock Losses</div><div class="value">${money(adj.realised_loss)}</div><div class="sub">net ${money((+adj.realised_gain || 0) - (+adj.realised_loss || 0), false)}</div></div>
    </div>

    <div class="form-row cols-2">
      <div class="card">
        <div class="card-h"><h3><i class="fa-solid fa-clipboard-check" style="color:var(--primary-2);margin-right:6px"></i>Recent goods receipts</h3><a href="#grn" class="btn-sm btn">View all</a></div>
        ${d.recent_grns.length ? `
        <table class="tbl"><thead><tr><th>#</th><th>Date</th><th>Vendor</th><th>Status</th></tr></thead><tbody>
          ${d.recent_grns.map(g => `
            <tr class="row-link" data-go="#grn/${g.id}">
              <td>${esc(g.grn_number)}</td>
              <td>${date(g.received_date)}</td>
              <td>${esc(g.vendor_name)}</td>
              <td>
                <span class="pill ${g.status}">${esc(g.status)}</span>
                ${+g.has_discrepancy ? ' <span class="pill attention">!</span>' : ''}
              </td>
            </tr>
          `).join('')}
        </tbody></table>
        ` : '<div class="empty"><i class="fa-solid fa-inbox"></i><h4>No GRNs yet</h4></div>'}
      </div>

      <div class="card">
        <div class="card-h"><h3><i class="fa-solid fa-file-invoice-dollar" style="color:var(--primary-2);margin-right:6px"></i>Recent invoices</h3><a href="#invoices" class="btn-sm btn">View all</a></div>
        ${d.recent_invoices.length ? `
        <table class="tbl"><thead><tr><th>#</th><th>Date</th><th>Buyer</th><th class="num">Total</th><th>Payment</th></tr></thead><tbody>
          ${d.recent_invoices.map(i => `
            <tr class="row-link" data-go="#invoices/${i.id}">
              <td>${esc(i.invoice_number)}</td>
              <td>${date(i.invoice_date)}</td>
              <td>${esc(i.buyer_name)}</td>
              <td class="num"><span class="amt">${money(i.grand_total)}</span></td>
              <td><span class="pill ${i.payment_status}">${esc(i.payment_status)}</span></td>
            </tr>
          `).join('')}
        </tbody></table>
        ` : '<div class="empty"><i class="fa-solid fa-file-invoice"></i><h4>No invoices yet</h4></div>'}
      </div>
    </div>

    ${d.low_stock_items.length ? `
      <div class="card">
        <div class="card-h"><h3><i class="fa-solid fa-triangle-exclamation" style="color:var(--amber);margin-right:6px"></i>Items running low</h3></div>
        <table class="tbl"><thead><tr><th>SKU</th><th>Item</th><th class="num">In stock</th><th class="num">Minimum</th><th></th></tr></thead><tbody>
          ${d.low_stock_items.map(it => `
            <tr class="row-link" data-go="#stock/${it.id}">
              <td class="font-mono muted">${esc(it.sku || '—')}</td>
              <td>${esc(it.item_name)}</td>
              <td class="num"><span class="amt loss">${esc(it.quantity)} ${esc(it.unit || '')}</span></td>
              <td class="num muted">${esc(it.min_stock)}</td>
              <td><a href="#grn/new?item=${it.id}" class="btn-sm btn"><i class="fa-solid fa-plus"></i> Receive</a></td>
            </tr>
          `).join('')}
        </tbody></table>
      </div>
    ` : ''}
  `);
  wireRowLinks();
}

/* ============================================================
   Stock items
   ============================================================ */
async function renderStockList(...rest) {
  if (rest.length && rest[0]) return renderStockDetail(rest[0]);
  setCrumb('Stock items');
  setTopActions(`<button class="pill-btn btn-primary" id="new-item-btn"><i class="fa-solid fa-plus"></i><span>New item</span></button>`);
  setPage(`<div class="empty"><i class="fa-solid fa-spinner fa-spin"></i></div>`);
  const search = sessionStorage.getItem('stock-q') || '';
  const filterLow = sessionStorage.getItem('stock-low') === '1';

  async function load(q = search, low = filterLow) {
    const d = await api('stock_items', { q, low_stock: low });
    const k = d.kpi || {};
    document.getElementById('page').innerHTML = `
      <h2>Stock items</h2>
      <p class="lede">${esc(k.total || 0)} items · stock value <span class="amt">${money(k.stock_value)}</span> · <strong>${esc(k.low || 0)}</strong> below reorder</p>
      <div class="toolbar">
        <input type="search" id="stock-search" placeholder="Search SKU, name, HSN, barcode…" value="${esc(q)}" data-testid="stock-search">
        <label class="checkbox-field"><input type="checkbox" id="filter-low" ${low ? 'checked' : ''} data-testid="filter-low"> Low stock only</label>
        <div class="grow"></div>
        <span class="muted">${d.items.length} results</span>
      </div>
      ${d.items.length ? `
        <table class="tbl"><thead><tr>
          <th>SKU</th><th>Item</th><th>HSN</th><th class="num">GST%</th>
          <th class="num">Qty</th><th class="num">Cost</th><th class="num">Sale</th>
          <th class="num">Min</th><th></th>
        </tr></thead><tbody>
          ${d.items.map(it => `
            <tr class="row-link" data-go="#stock/${it.id}">
              <td class="font-mono muted">${esc(it.sku || '—')}</td>
              <td><strong>${esc(it.item_name)}</strong></td>
              <td class="font-mono">${esc(it.hsn_code || '—')}</td>
              <td class="num">${esc(it.gst_rate || 0)}%</td>
              <td class="num"><span class="amt ${+it.quantity <= +it.min_stock ? 'loss' : ''}">${esc(it.quantity)} <small class="muted">${esc(it.unit || '')}</small></span></td>
              <td class="num muted">${money(it.cost_price)}</td>
              <td class="num">${money(it.sale_price)}</td>
              <td class="num muted">${esc(it.min_stock)}</td>
              <td><i class="fa-solid fa-chevron-right muted"></i></td>
            </tr>
          `).join('')}
        </tbody></table>
      ` : '<div class="empty"><i class="fa-solid fa-box"></i><h4>No items match</h4><p>Try a different search or clear the filter.</p></div>'}
    `;
    wireRowLinks();
    document.getElementById('stock-search').addEventListener('input', debounce((e) => {
      sessionStorage.setItem('stock-q', e.target.value);
      load(e.target.value, document.getElementById('filter-low').checked);
    }));
    document.getElementById('filter-low').addEventListener('change', (e) => {
      sessionStorage.setItem('stock-low', e.target.checked ? '1' : '0');
      load(document.getElementById('stock-search').value, e.target.checked);
    });
  }
  await load();
  document.getElementById('new-item-btn').addEventListener('click', () => openItemEditor(null, () => load()));
}

async function renderStockDetail(idStr) {
  const id = parseInt(idStr, 10);
  if (!id) return location.hash = '#stock';
  setCrumb('Stock', 'Item #' + id);
  setTopActions(`<a href="#stock" class="pill-btn"><i class="fa-solid fa-arrow-left"></i><span>Back</span></a>`);
  setPage(`<div class="empty"><i class="fa-solid fa-spinner fa-spin"></i></div>`);
  const d = await api('stock_get', { id });
  const it = d.item;
  setPage(`
    <h2>${esc(it.item_name)}</h2>
    <p class="lede">${esc(it.sku || 'No SKU')} · HSN <code>${esc(it.hsn_code || '—')}</code> · GST ${esc(it.gst_rate || 0)}%</p>

    <div class="kpis">
      <div class="kpi ${+it.quantity <= +it.min_stock ? 'warn' : 'good'}"><div class="label">In stock</div><div class="value">${esc(it.quantity)} <small style="font-size:14px;color:var(--text-3)">${esc(it.unit || '')}</small></div><div class="sub">min ${esc(it.min_stock)}</div></div>
      <div class="kpi"><div class="label">Cost</div><div class="value">${money(it.cost_price)}</div></div>
      <div class="kpi info"><div class="label">Sale</div><div class="value">${money(it.sale_price)}</div></div>
      <div class="kpi violet"><div class="label">Stock Value</div><div class="value">${money(+it.cost_price * +it.quantity)}</div></div>
    </div>

    <div class="card">
      <div class="card-h"><h3>Details</h3><button id="edit-item" class="btn-sm btn"><i class="fa-solid fa-pen"></i> Edit</button></div>
      <div class="form-row cols-3">
        <div><div class="muted">Category</div><strong>${esc(it.category || '—')}</strong></div>
        <div><div class="muted">Location</div><strong>${esc(it.location || '—')}</strong></div>
        <div><div class="muted">Barcode</div><strong class="font-mono">${esc(it.barcode || '—')}</strong></div>
      </div>
      ${it.description ? `<p style="color:var(--text-2);margin-top:10px">${esc(it.description)}</p>` : ''}
    </div>

    <div class="form-row cols-2">
      <div class="card">
        <div class="card-h"><h3><i class="fa-solid fa-right-left" style="color:var(--primary-2);margin-right:6px"></i>Movements</h3>
          <button class="btn-sm btn" id="adj-here"><i class="fa-solid fa-scale-unbalanced"></i> Adjust</button></div>
        ${d.transactions.length ? `
          <table class="tbl"><thead><tr><th>Date</th><th>Type</th><th class="num">Qty</th><th>Ref</th></tr></thead><tbody>
            ${d.transactions.map(t => `<tr>
              <td>${date(t.transaction_date)}</td>
              <td><span class="pill ${t.transaction_type === 'in' ? 'paid' : 'overdue'}">${esc(t.transaction_type)}</span></td>
              <td class="num">${esc(t.quantity)}</td>
              <td class="font-mono muted">${esc(t.reference || '—')}</td>
            </tr>`).join('')}
          </tbody></table>
        ` : '<div class="empty"><i class="fa-solid fa-inbox"></i><p>No movements yet</p></div>'}
      </div>
      <div class="card">
        <div class="card-h"><h3><i class="fa-solid fa-scale-unbalanced" style="color:var(--amber);margin-right:6px"></i>Adjustments</h3></div>
        ${d.adjustments.length ? `
          <table class="tbl"><thead><tr><th>#</th><th>Type</th><th class="num">Qty</th><th class="num">Loss/Gain</th><th>Status</th></tr></thead><tbody>
            ${d.adjustments.map(a => `<tr>
              <td class="font-mono muted">${esc(a.adj_number)}</td>
              <td><span class="pill ${a.adj_type}">${esc(a.adj_type)}</span></td>
              <td class="num">${esc(a.qty)}</td>
              <td class="num"><span class="amt ${a.direction === 'out' ? 'loss' : ''}">${money(a.value_impact)}</span></td>
              <td><span class="pill ${a.status}">${esc(a.status)}</span></td>
            </tr>`).join('')}
          </tbody></table>
        ` : '<div class="empty"><i class="fa-solid fa-check"></i><p>No adjustments</p></div>'}
      </div>
    </div>
  `);
  document.getElementById('edit-item').addEventListener('click', () => openItemEditor(it, () => renderStockDetail(idStr)));
  document.getElementById('adj-here').addEventListener('click', () => openAdjustmentDrawer(it, () => renderStockDetail(idStr)));
}

function openItemEditor(it, onSaved) {
  openDrawer(`${it ? 'Edit' : 'New'} item`, `
    <div class="form-row cols-2">
      <div class="field"><label>SKU</label><input id="f-sku" value="${esc(it?.sku || '')}" placeholder="SKU-XYZ-001" data-testid="item-sku"></div>
      <div class="field"><label>Barcode</label><input id="f-barcode" value="${esc(it?.barcode || '')}"></div>
    </div>
    <div class="field"><label>Item name *</label><input id="f-name" value="${esc(it?.item_name || '')}" required data-testid="item-name"></div>
    <div class="field"><label>Description</label><textarea id="f-desc">${esc(it?.description || '')}</textarea></div>
    <div class="form-row cols-3">
      <div class="field"><label>Category</label>
        <select id="f-cat">
          ${['food','clothing','medical','educational','household','equipment','other'].map(c =>
            `<option value="${c}" ${c === (it?.category || 'other') ? 'selected' : ''}>${c}</option>`).join('')}
        </select>
      </div>
      <div class="field"><label>HSN Code</label><input id="f-hsn" value="${esc(it?.hsn_code || '')}" placeholder="e.g. 1006" data-testid="item-hsn"></div>
      <div class="field"><label>GST Rate %</label><input id="f-gst" type="number" step="0.01" value="${esc(it?.gst_rate || 0)}" data-testid="item-gst"></div>
    </div>
    <div class="form-row cols-4">
      <div class="field"><label>Quantity</label><input id="f-qty" type="number" value="${esc(it?.quantity || 0)}" data-testid="item-qty"></div>
      <div class="field"><label>Unit</label><input id="f-unit" value="${esc(it?.unit || 'pcs')}"></div>
      <div class="field"><label>Min stock</label><input id="f-min" type="number" value="${esc(it?.min_stock || 0)}"></div>
      <div class="field"><label>Reorder qty</label><input id="f-reorder" type="number" value="${esc(it?.reorder_qty || 0)}"></div>
    </div>
    <div class="form-row cols-2">
      <div class="field"><label>Cost price ₹</label><input id="f-cost" type="number" step="0.01" value="${esc(it?.cost_price || 0)}" data-testid="item-cost"></div>
      <div class="field"><label>Sale price ₹</label><input id="f-sale" type="number" step="0.01" value="${esc(it?.sale_price || 0)}"></div>
    </div>
    <div class="field"><label>Location</label><input id="f-loc" value="${esc(it?.location || '')}"></div>
  `, [
    { label: 'Cancel', cls: 'pill-btn', action: closeDrawer },
    { label: 'Save item', cls: 'pill-btn btn-primary', testid: 'item-save', action: async (btn) => {
      btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Saving';
      try {
        const r = await api('stock_save', {
          id: it?.id || 0,
          sku: g('f-sku'), item_name: g('f-name'), description: g('f-desc'),
          category: g('f-cat'), hsn_code: g('f-hsn'), gst_rate: +g('f-gst') || 0,
          quantity: +g('f-qty') || 0, unit: g('f-unit'),
          min_stock: +g('f-min') || 0, reorder_qty: +g('f-reorder') || 0,
          cost_price: +g('f-cost') || 0, sale_price: +g('f-sale') || 0,
          barcode: g('f-barcode'), location: g('f-loc'),
        });
        toast('Item saved');
        closeDrawer();
        onSaved?.();
      } catch (e) { toast(e.message, 'error'); btn.disabled = false; btn.innerHTML = 'Save item'; }
    }},
  ]);
}

function openAdjustmentDrawer(item, onSaved) {
  openDrawer(`Adjust stock — ${item.item_name}`, `
    <p class="lede" style="margin-top:0">Record a change in stock with a reason. Damage/shortage/loss decrement; excess/found increment.</p>
    <div class="form-row cols-2">
      <div class="field"><label>Type *</label>
        <select id="adj-type" data-testid="adj-type">
          ${['damage','shortage','excess','wastage','loss','found','theft','return_in','return_out','correction']
            .map(t => `<option value="${t}">${t}</option>`).join('')}
        </select>
      </div>
      <div class="field"><label>Quantity *</label><input id="adj-qty" type="number" step="0.01" value="0" data-testid="adj-qty"></div>
    </div>
    <div class="form-row cols-2">
      <div class="field"><label>Unit cost ₹</label><input id="adj-cost" type="number" step="0.01" value="${esc(item.cost_price || 0)}"></div>
      <div class="field"><label>Reason</label><input id="adj-reason" placeholder="Brief reason" data-testid="adj-reason"></div>
    </div>
    <div class="field"><label>Photo/video evidence (optional)</label>
      <input type="file" id="adj-evidence" accept="image/*,video/*,application/pdf">
      <small>Strongly recommended for damages — uploads up to 64 MB.</small>
    </div>
  `, [
    { label: 'Cancel', cls: 'pill-btn', action: closeDrawer },
    { label: 'Save adjustment', cls: 'pill-btn btn-primary', testid: 'adj-save', action: async (btn) => {
      const qty = +g('adj-qty');
      if (qty <= 0) return toast('Quantity must be > 0', 'error');
      btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Saving';
      try {
        const ev = document.getElementById('adj-evidence').files[0];
        let evData = null;
        if (ev) evData = await fileToBase64(ev);
        await api('stock_adjust_create', {
          item_id: item.id, adj_type: g('adj-type'), qty, unit_cost: +g('adj-cost') || 0,
          reason: g('adj-reason'),
          evidence_data: evData ? evData.data : null,
          evidence_name: evData ? evData.name : null,
        });
        toast('Adjustment recorded');
        closeDrawer();
        onSaved?.();
      } catch (e) { toast(e.message, 'error'); btn.disabled = false; btn.innerHTML = 'Save adjustment'; }
    }},
  ]);
}

/* ============================================================
   Vendors
   ============================================================ */
async function renderVendors() {
  setCrumb('Vendors');
  setTopActions(`<button class="pill-btn btn-primary" id="new-vendor"><i class="fa-solid fa-plus"></i><span>New vendor</span></button>`);
  setPage(`<div class="empty"><i class="fa-solid fa-spinner fa-spin"></i></div>`);
  const d = await api('vendor_list');
  setPage(`
    <h2>Vendors &amp; Suppliers</h2>
    <p class="lede">${d.vendors.length} entries. GSTIN-validated suppliers, kept for our records, sir.</p>
    ${d.vendors.length ? `
      <table class="tbl"><thead><tr>
        <th>Code</th><th>Name</th><th>GSTIN</th><th>State</th><th>Contact</th><th></th>
      </tr></thead><tbody>
        ${d.vendors.map(v => `
          <tr class="row-link" data-vendor-id="${v.id}">
            <td class="font-mono muted">${esc(v.vendor_code)}</td>
            <td><strong>${esc(v.name)}</strong>${+v.is_active === 0 ? ' <span class="pill cancelled">archived</span>' : ''}</td>
            <td class="font-mono">${esc(v.gstin || '—')}</td>
            <td>${esc(v.state || '—')} <small class="muted">(${esc(v.state_code || '—')})</small></td>
            <td>${esc(v.contact_person || '')}${v.phone ? '<br><small class="muted">' + esc(v.phone) + '</small>' : ''}</td>
            <td><i class="fa-solid fa-chevron-right muted"></i></td>
          </tr>
        `).join('')}
      </tbody></table>
    ` : '<div class="empty"><i class="fa-solid fa-truck-field"></i><h4>No vendors yet</h4><p>Add your first supplier to start recording purchases.</p></div>'}
  `);
  document.getElementById('new-vendor').addEventListener('click', () => openVendorEditor(null, () => renderVendors()));
  document.querySelectorAll('[data-vendor-id]').forEach(tr => {
    tr.addEventListener('click', async () => {
      const j = await api('vendor_get', { id: +tr.dataset.vendorId });
      openVendorEditor(j.vendor, () => renderVendors());
    });
  });
}

function openVendorEditor(v, onSaved) {
  const stateOptions = stateList.map(s => `<option value="${esc(s.code)}" ${s.code === v?.state_code ? 'selected' : ''}>${esc(s.name)} (${esc(s.code)})</option>`).join('');
  openDrawer(`${v ? 'Edit' : 'New'} vendor`, `
    <div class="form-row cols-2">
      <div class="field"><label>Vendor code</label><input id="vf-code" value="${esc(v?.vendor_code || '')}" placeholder="Auto-generated if empty"></div>
      <div class="field"><label>Name *</label><input id="vf-name" value="${esc(v?.name || '')}" required data-testid="vendor-name"></div>
    </div>
    <div class="form-row cols-2">
      <div class="field"><label>GSTIN</label><input id="vf-gstin" value="${esc(v?.gstin || '')}" placeholder="e.g. 27AABCT1234E1Z9" data-testid="vendor-gstin">
        <small>15-char format. State code auto-derives.</small></div>
      <div class="field"><label>PAN</label><input id="vf-pan" value="${esc(v?.pan || '')}" placeholder="AAACS1234L"></div>
    </div>
    <div class="form-row cols-2">
      <div class="field"><label>Contact person</label><input id="vf-contact" value="${esc(v?.contact_person || '')}"></div>
      <div class="field"><label>Phone</label><input id="vf-phone" value="${esc(v?.phone || '')}"></div>
    </div>
    <div class="form-row cols-2">
      <div class="field"><label>Email</label><input id="vf-email" type="email" value="${esc(v?.email || '')}"></div>
      <div class="field"><label>Payment terms</label><input id="vf-terms" value="${esc(v?.payment_terms || '')}" placeholder="Net 30, COD, etc"></div>
    </div>
    <div class="field"><label>Address</label><textarea id="vf-addr">${esc(v?.address || '')}</textarea></div>
    <div class="form-row cols-3">
      <div class="field"><label>City</label><input id="vf-city" value="${esc(v?.city || '')}"></div>
      <div class="field"><label>State</label><select id="vf-state">${stateOptions}</select></div>
      <div class="field"><label>Pincode</label><input id="vf-pin" value="${esc(v?.pincode || '')}"></div>
    </div>
    <div class="form-row cols-2">
      <div class="field"><label>Bank A/C</label><input id="vf-bank" value="${esc(v?.bank_account || '')}"></div>
      <div class="field"><label>IFSC</label><input id="vf-ifsc" value="${esc(v?.bank_ifsc || '')}"></div>
    </div>
    <div class="field"><label>Notes</label><textarea id="vf-notes">${esc(v?.notes || '')}</textarea></div>
  `, [
    { label: 'Cancel', cls: 'pill-btn', action: closeDrawer },
    { label: 'Save vendor', cls: 'pill-btn btn-primary', testid: 'vendor-save', action: async (btn) => {
      btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Saving';
      try {
        const state = document.getElementById('vf-state').selectedOptions[0];
        await api('vendor_save', {
          id: v?.id || 0,
          vendor_code: g('vf-code'),
          name: g('vf-name'),
          gstin: g('vf-gstin'),
          pan: g('vf-pan'),
          contact_person: g('vf-contact'),
          email: g('vf-email'),
          phone: g('vf-phone'),
          address: g('vf-addr'),
          city: g('vf-city'),
          state: state ? state.textContent.replace(/\s*\(.*\)\s*$/, '') : '',
          state_code: state ? state.value : '',
          pincode: g('vf-pin'),
          bank_account: g('vf-bank'),
          bank_ifsc: g('vf-ifsc'),
          payment_terms: g('vf-terms'),
          notes: g('vf-notes'),
          is_active: v ? !!v.is_active : true,
        });
        toast('Vendor saved');
        closeDrawer();
        onSaved?.();
      } catch (e) { toast(e.message, 'error'); btn.disabled = false; btn.innerHTML = 'Save vendor'; }
    }},
  ]);
}

/* ============================================================
   GRN
   ============================================================ */
async function renderGrnList(...rest) {
  if (rest.length && rest[0] === 'new') return renderGrnEditor(null);
  if (rest.length && rest[0]) return renderGrnDetail(rest[0]);

  setCrumb('Goods Receipt');
  setTopActions(`<a class="pill-btn btn-primary" href="#grn/new" data-testid="new-grn"><i class="fa-solid fa-plus"></i><span>New receipt</span></a>`);
  setPage(`<div class="empty"><i class="fa-solid fa-spinner fa-spin"></i></div>`);
  const d = await api('grn_list');
  setPage(`
    <h2>Goods Receipt Notes</h2>
    <p class="lede">${d.grns.length} receipts. Standard 4-eyes process — record, attach evidence, then post to update stock.</p>
    <div class="toolbar">
      <select id="grn-status" data-testid="grn-status">
        <option value="">All statuses</option>
        <option value="draft">Draft</option>
        <option value="posted">Posted</option>
        <option value="disputed">Disputed</option>
      </select>
      <input type="search" id="grn-search" placeholder="Search GRN number, supplier invoice, vendor…">
    </div>
    <table class="tbl"><thead><tr>
      <th>GRN #</th><th>Received</th><th>Vendor</th><th>Supplier Inv</th>
      <th class="num">Value</th><th class="num">Loss</th><th>Attach</th><th>Status</th>
    </tr></thead><tbody id="grn-tbody"></tbody></table>
  `);
  const tbody = document.getElementById('grn-tbody');
  function render(rows) {
    if (!rows.length) {
      tbody.innerHTML = `<tr><td colspan="8"><div class="empty"><i class="fa-solid fa-clipboard-check"></i><h4>No receipts yet</h4><p>Create one to record incoming goods.</p></div></td></tr>`;
      return;
    }
    tbody.innerHTML = rows.map(g => `
      <tr class="row-link" data-go="#grn/${g.id}">
        <td class="font-mono">${esc(g.grn_number)}</td>
        <td>${date(g.received_date)}</td>
        <td>${esc(g.vendor_name)}</td>
        <td class="font-mono muted">${esc(g.supplier_invoice_no || '—')}</td>
        <td class="num"><span class="amt">${money(g.value_received)}</span></td>
        <td class="num"><span class="amt loss">${money(g.value_loss)}</span></td>
        <td>${+g.attachment_count > 0 ? `<span class="pill issued"><i class="fa-solid fa-paperclip"></i> ${g.attachment_count}</span>` : '<span class="muted">—</span>'}</td>
        <td><span class="pill ${g.status}">${esc(g.status)}</span>${+g.has_discrepancy ? ' <span class="pill attention" title="discrepancy">!</span>' : ''}</td>
      </tr>
    `).join('');
    wireRowLinks();
  }
  render(d.grns);
  const reload = debounce(async () => {
    const j = await api('grn_list', { status: g('grn-status'), q: g('grn-search') });
    render(j.grns);
  }, 250);
  document.getElementById('grn-status').addEventListener('change', reload);
  document.getElementById('grn-search').addEventListener('input', reload);
}

async function renderGrnEditor(grnId) {
  setCrumb('Goods Receipt', grnId ? 'Edit #' + grnId : 'New receipt');
  setTopActions(`<a href="#grn" class="pill-btn"><i class="fa-solid fa-arrow-left"></i><span>Back</span></a>`);
  setPage(`<div class="empty"><i class="fa-solid fa-spinner fa-spin"></i></div>`);

  // Pre-fetch vendors and item list
  const [vRes, iRes] = await Promise.all([
    api('vendor_list'),
    api('stock_items'),
  ]);
  const vendors = vRes.vendors.filter(v => +v.is_active === 1);
  const items   = iRes.items;

  let grn = null;
  let grnItems = [];
  if (grnId) {
    const j = await api('grn_get', { id: +grnId });
    grn = j.grn; grnItems = j.items;
  }

  setPage(`
    <h2>${grnId ? 'Edit GRN ' + esc(grn.grn_number) : 'New goods receipt'}</h2>
    <p class="lede">Receive goods against a supplier invoice. Damage and shortage automatically post to stock losses.</p>

    <div class="card">
      <div class="card-h"><h3>Header</h3></div>
      <div class="form-row cols-2">
        <div class="field"><label>Vendor *</label>
          <select id="g-vendor" data-testid="grn-vendor">
            <option value="">— Select vendor —</option>
            ${vendors.map(v => `<option value="${v.id}" ${grn?.vendor_id == v.id ? 'selected' : ''}>${esc(v.name)} (${esc(v.vendor_code)})</option>`).join('')}
          </select>
        </div>
        <div class="field"><label>Received date</label><input type="date" id="g-rcv-date" value="${esc(grn?.received_date || todayISO())}" data-testid="grn-date"></div>
      </div>
      <div class="form-row cols-3">
        <div class="field"><label>Supplier invoice #</label><input id="g-sup-inv" value="${esc(grn?.supplier_invoice_no || '')}" data-testid="grn-sup-inv"></div>
        <div class="field"><label>Supplier invoice date</label><input type="date" id="g-sup-date" value="${esc(grn?.supplier_invoice_date || '')}"></div>
        <div class="field"><label>Vehicle number</label><input id="g-vehicle" value="${esc(grn?.vehicle_number || '')}" placeholder="DL01AB1234"></div>
      </div>
      <div class="form-row cols-3">
        <div class="field"><label>Driver name</label><input id="g-driver" value="${esc(grn?.driver_name || '')}"></div>
        <div class="field"><label>Gate pass #</label><input id="g-gate" value="${esc(grn?.gate_pass_no || '')}"></div>
        <div class="field"><label>Transporter</label><input id="g-trans" value="${esc(grn?.transporter || '')}"></div>
      </div>
      <div class="field"><label>Notes</label><textarea id="g-notes">${esc(grn?.notes || '')}</textarea></div>
    </div>

    <div class="card">
      <div class="card-h"><h3>Items received</h3></div>
      <div class="line-editor grn-editor" id="lines">
        <div class="col-h">
          <div>Description / Item</div><div>HSN</div><div>Unit</div>
          <div>Ordered</div><div>Received</div><div>Damaged</div><div>Short</div><div>Unit cost</div><div></div>
        </div>
        <div id="line-rows"></div>
        <button class="add-line" id="add-line"><i class="fa-solid fa-plus"></i> Add line</button>
      </div>
    </div>

    ${grnId ? `
    <div class="card" id="att-card">
      <div class="card-h"><h3><i class="fa-solid fa-paperclip" style="color:var(--primary-2);margin-right:6px"></i>Evidence (photos &amp; videos)</h3></div>
      <p class="muted" style="margin-top:0">Discrepancies <strong>require</strong> photo or video evidence before posting. Drop files below.</p>
      <div class="uploader" id="uploader">
        <i class="fa-solid fa-cloud-arrow-up"></i>
        <div class="hint"><strong>Drag photos / videos here</strong> or click to browse</div>
        <div class="types">JPG · PNG · MP4 · MOV · WebM · PDF · DOC · up to 64 MB</div>
        <input type="file" id="att-file" multiple accept="image/*,video/*,application/pdf,application/msword,text/plain" style="display:none">
      </div>
      <div class="attachments-grid" id="att-grid"></div>
    </div>
    ` : '<div class="empty"><i class="fa-solid fa-paperclip"></i><h4>Save the GRN to enable photo &amp; video evidence uploads.</h4></div>'}

    <div class="flex-end" style="margin-top:18px">
      <button class="pill-btn" id="cancel-grn">Cancel</button>
      <button class="pill-btn" id="save-grn" data-testid="grn-save"><i class="fa-solid fa-floppy-disk"></i> Save draft</button>
      ${grnId && grn?.status === 'draft' ? `<button class="pill-btn btn-primary" id="post-grn" data-testid="grn-post"><i class="fa-solid fa-circle-check"></i> Post GRN</button>` : ''}
    </div>
  `);

  // Item rows
  const linesEl = document.getElementById('line-rows');
  function lineRow(it = {}) {
    const itemSelect = `<select class="grn-item">
      <option value="">— pick item —</option>
      ${items.map(x => `<option value="${x.id}" data-hsn="${esc(x.hsn_code || '')}" data-unit="${esc(x.unit || 'pcs')}" data-cost="${esc(x.cost_price || 0)}"
        ${it.item_id == x.id ? 'selected' : ''}>${esc(x.item_name)} (${esc(x.sku || '—')})</option>`).join('')}
    </select>`;
    const row = document.createElement('div');
    row.className = 'line-row';
    row.innerHTML = `
      ${itemSelect}
      <input class="grn-hsn" placeholder="HSN" value="${esc(it.hsn_code || '')}">
      <input class="grn-unit" placeholder="pcs" value="${esc(it.unit || 'pcs')}">
      <input class="grn-ord" type="number" step="0.001" placeholder="0" value="${esc(it.qty_ordered || 0)}">
      <input class="grn-rcv" type="number" step="0.001" placeholder="0" value="${esc(it.qty_received || 0)}" data-testid="grn-rcv">
      <input class="grn-dmg" type="number" step="0.001" placeholder="0" value="${esc(it.qty_damaged || 0)}" data-testid="grn-dmg">
      <input class="grn-sht" type="number" step="0.001" placeholder="0" value="${(+it.qty_short || 0)}">
      <input class="grn-cost" type="number" step="0.01" placeholder="0" value="${esc(it.unit_cost || 0)}">
      <button class="x" title="Remove"><i class="fa-solid fa-xmark"></i></button>
    `;
    linesEl.appendChild(row);
    const sel = row.querySelector('.grn-item');
    sel.addEventListener('change', () => {
      const opt = sel.selectedOptions[0];
      if (!opt) return;
      if (!row.querySelector('.grn-hsn').value) row.querySelector('.grn-hsn').value = opt.dataset.hsn || '';
      if (!row.querySelector('.grn-unit').value) row.querySelector('.grn-unit').value = opt.dataset.unit || 'pcs';
      if (!+row.querySelector('.grn-cost').value) row.querySelector('.grn-cost').value = opt.dataset.cost || 0;
    });
    row.querySelector('.x').addEventListener('click', () => row.remove());
  }
  if (grnItems.length) grnItems.forEach(lineRow); else lineRow();
  document.getElementById('add-line').addEventListener('click', () => lineRow());

  // Attachments
  if (grnId) {
    const grid = document.getElementById('att-grid');
    const uploaderEl = document.getElementById('uploader');
    const fileInput = document.getElementById('att-file');
    async function loadAtts() {
      const j = await api('grn_get', { id: +grnId });
      grid.innerHTML = j.attachments.map(a => attachmentTile(a)).join('');
      wireAttachmentTiles(grid, async (aid) => {
        await api('grn_delete_attachment', { id: aid });
        toast('Attachment removed');
        loadAtts();
      });
    }
    loadAtts();
    uploaderEl.addEventListener('click', () => fileInput.click());
    ['dragenter','dragover'].forEach(e => uploaderEl.addEventListener(e, ev => { ev.preventDefault(); uploaderEl.classList.add('dragover'); }));
    ['dragleave','drop'].forEach(e => uploaderEl.addEventListener(e, ev => { ev.preventDefault(); uploaderEl.classList.remove('dragover'); }));
    uploaderEl.addEventListener('drop', async (e) => {
      e.preventDefault();
      for (const f of e.dataTransfer.files) await uploadOne(f);
      loadAtts();
    });
    fileInput.addEventListener('change', async (e) => {
      for (const f of e.target.files) await uploadOne(f);
      fileInput.value = '';
      loadAtts();
    });
    async function uploadOne(file) {
      const fd = new FormData();
      fd.append('grn_id', grnId);
      fd.append('file', file);
      try {
        await apiFormData('grn_upload_attachment', fd);
        toast(`Uploaded ${file.name}`);
      } catch (e) { toast('Upload failed: ' + e.message, 'error'); }
    }
  }

  document.getElementById('cancel-grn').addEventListener('click', () => location.hash = '#grn');
  document.getElementById('save-grn').addEventListener('click', async (e) => {
    const btn = e.currentTarget; btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Saving';
    try {
      const items = [...document.querySelectorAll('.grn-editor .line-row')].map(r => ({
        item_id: +r.querySelector('.grn-item').value || null,
        description: r.querySelector('.grn-item').selectedOptions[0]?.textContent || 'Item',
        hsn_code: r.querySelector('.grn-hsn').value,
        unit: r.querySelector('.grn-unit').value,
        qty_ordered: +r.querySelector('.grn-ord').value || 0,
        qty_received: +r.querySelector('.grn-rcv').value || 0,
        qty_damaged: +r.querySelector('.grn-dmg').value || 0,
        unit_cost: +r.querySelector('.grn-cost').value || 0,
      })).filter(it => it.qty_received > 0 || it.qty_ordered > 0);
      if (!items.length) throw new Error('Add at least one item line');
      const r = await api('grn_save', {
        id: grnId || 0,
        vendor_id: +g('g-vendor'),
        received_date: g('g-rcv-date'),
        supplier_invoice_no: g('g-sup-inv'),
        supplier_invoice_date: g('g-sup-date'),
        vehicle_number: g('g-vehicle'),
        driver_name: g('g-driver'),
        gate_pass_no: g('g-gate'),
        transporter: g('g-trans'),
        notes: g('g-notes'),
        items,
      });
      toast('GRN saved');
      location.hash = '#grn/' + r.id;
    } catch (e) { toast(e.message, 'error'); btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save draft'; }
  });
  document.getElementById('post-grn')?.addEventListener('click', async (e) => {
    if (!confirm('Post this GRN? Stock will be updated and damage/shortage adjustments will be created. This cannot be reversed.')) return;
    const btn = e.currentTarget; btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Posting';
    try {
      await api('grn_post', { id: +grnId });
      toast('GRN posted — stock updated');
      location.hash = '#grn/' + grnId;
    } catch (e) { toast(e.message, 'error'); btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-circle-check"></i> Post GRN'; }
  });
}

async function renderGrnDetail(idStr) {
  const id = +idStr;
  setCrumb('Goods Receipt', '#' + id);
  setTopActions(`<a href="#grn" class="pill-btn"><i class="fa-solid fa-arrow-left"></i><span>Back</span></a>`);
  setPage(`<div class="empty"><i class="fa-solid fa-spinner fa-spin"></i></div>`);
  const j = await api('grn_get', { id });
  const g = j.grn;
  const editable = g.status === 'draft';
  setPage(`
    <h2>${esc(g.grn_number)} <span class="pill ${g.status}" style="font-size:11px;vertical-align:middle">${esc(g.status)}</span></h2>
    <p class="lede">Received ${date(g.received_date)} from <strong>${esc(g.vendor_name)}</strong> via ${esc(g.vehicle_number || 'unspecified vehicle')}.</p>

    <div class="kpis">
      <div class="kpi good"><div class="label">Value received</div><div class="value">${money(g.value_received)}</div></div>
      <div class="kpi bad"><div class="label">Value loss</div><div class="value">${money(g.value_loss)}</div></div>
      <div class="kpi warn"><div class="label">Discrepancy</div><div class="value">${+g.has_discrepancy ? 'YES' : 'No'}</div><div class="sub">${esc(g.total_qty_damaged)} dmg · ${esc(g.total_qty_short)} short · ${esc(g.total_qty_excess)} excess</div></div>
      <div class="kpi info"><div class="label">Attachments</div><div class="value">${j.attachments.length}</div></div>
    </div>

    <div class="card">
      <div class="card-h"><h3>Header</h3>
        <div class="actions">
          ${editable ? `<a href="#grn/new?edit=${id}" class="btn-sm btn"><i class="fa-solid fa-pen"></i> Edit</a>` : ''}
          ${editable ? `<button id="post-grn" class="btn-sm btn-primary pill-btn"><i class="fa-solid fa-circle-check"></i> Post</button>` : ''}
        </div>
      </div>
      <div class="form-row cols-3">
        <div><div class="muted">Vendor</div><strong>${esc(g.vendor_name)}</strong><br><small class="muted">${esc(g.vendor_gstin || 'no GSTIN')}</small></div>
        <div><div class="muted">Supplier Invoice</div><strong>${esc(g.supplier_invoice_no || '—')}</strong><br><small class="muted">${date(g.supplier_invoice_date)}</small></div>
        <div><div class="muted">Received by</div><strong>${esc(g.received_by_name || '—')}</strong><br><small class="muted">${date(g.posted_at)}</small></div>
      </div>
      ${g.notes ? `<p style="color:var(--text-2);margin-top:10px">${esc(g.notes)}</p>` : ''}
    </div>

    <div class="card">
      <div class="card-h"><h3>Items</h3></div>
      <table class="tbl"><thead><tr>
        <th>Item</th><th>HSN</th><th class="num">Ordered</th><th class="num">Received</th>
        <th class="num">Accepted</th><th class="num">Damaged</th><th class="num">Short</th><th class="num">Cost</th>
      </tr></thead><tbody>
        ${j.items.map(it => `
          <tr>
            <td><strong>${esc(it.description)}</strong>${it.batch_no ? '<br><small class="muted">batch ' + esc(it.batch_no) + '</small>' : ''}</td>
            <td class="font-mono">${esc(it.hsn_code || '—')}</td>
            <td class="num">${esc(it.qty_ordered)}</td>
            <td class="num">${esc(it.qty_received)}</td>
            <td class="num"><span class="amt">${esc(it.qty_accepted)}</span></td>
            <td class="num">${+it.qty_damaged > 0 ? `<span class="amt loss">${esc(it.qty_damaged)}</span>` : '0'}</td>
            <td class="num">${+it.qty_short > 0 ? `<span class="amt loss">${esc(it.qty_short)}</span>` : '0'}</td>
            <td class="num muted">${money(it.unit_cost)}</td>
          </tr>
        `).join('')}
      </tbody></table>
    </div>

    <div class="card">
      <div class="card-h"><h3><i class="fa-solid fa-paperclip" style="color:var(--primary-2);margin-right:6px"></i>Evidence (${j.attachments.length})</h3>
        ${editable ? `<button class="btn-sm btn" id="add-att"><i class="fa-solid fa-plus"></i> Add</button>` : ''}
      </div>
      ${j.attachments.length ? `
        <div class="attachments-grid" id="att-grid">${j.attachments.map(a => attachmentTile(a)).join('')}</div>
      ` : '<div class="empty"><i class="fa-solid fa-camera-retro"></i><p>No evidence attached</p></div>'}
    </div>
  `);

  wireAttachmentTiles(document.getElementById('att-grid') || document.createElement('div'), editable ? async (aid) => {
    if (!confirm('Remove this attachment?')) return;
    await api('grn_delete_attachment', { id: aid });
    toast('Removed');
    renderGrnDetail(idStr);
  } : null);

  document.getElementById('post-grn')?.addEventListener('click', async (e) => {
    if (!confirm('Post this GRN? Stock will be updated and damage/shortage adjustments will be created. This cannot be reversed.')) return;
    e.currentTarget.disabled = true;
    try {
      await api('grn_post', { id });
      toast('GRN posted'); renderGrnDetail(idStr);
    } catch (err) { toast(err.message, 'error'); e.currentTarget.disabled = false; }
  });

  document.getElementById('add-att')?.addEventListener('click', () => {
    const inp = document.createElement('input'); inp.type = 'file'; inp.accept = 'image/*,video/*,application/pdf'; inp.multiple = true;
    inp.addEventListener('change', async () => {
      for (const f of inp.files) {
        const fd = new FormData(); fd.append('grn_id', id); fd.append('file', f);
        try { await apiFormData('grn_upload_attachment', fd); toast('Uploaded ' + f.name); }
        catch (e) { toast('Failed: ' + e.message, 'error'); }
      }
      renderGrnDetail(idStr);
    });
    inp.click();
  });
}

function attachmentTile(a) {
  const url = a.file_path;
  if (a.kind === 'image') {
    return `<div class="attachment" data-lb-img="${esc(url)}">
      <div class="preview"><img src="${esc(url)}" alt="${esc(a.original_name || '')}"></div>
      <div class="meta"><span class="name">${esc(a.original_name || '')}</span>
        <button class="delete" data-del="${a.id}" title="Remove"><i class="fa-solid fa-trash"></i></button></div>
    </div>`;
  }
  if (a.kind === 'video') {
    return `<div class="attachment" data-lb-vid="${esc(url)}">
      <div class="preview" style="background:#000"><i class="fa-solid fa-film"></i><div class="play-overlay"><i class="fa-solid fa-play"></i></div></div>
      <div class="meta"><span class="name">${esc(a.original_name || '')}</span>
        <button class="delete" data-del="${a.id}" title="Remove"><i class="fa-solid fa-trash"></i></button></div>
    </div>`;
  }
  return `<a class="attachment" href="${esc(url)}" target="_blank" rel="noopener" style="text-decoration:none">
    <div class="preview"><i class="fa-solid fa-file-lines"></i></div>
    <div class="meta"><span class="name">${esc(a.original_name || '')}</span></div>
  </a>`;
}

function wireAttachmentTiles(container, onDelete) {
  container.querySelectorAll('[data-lb-img]').forEach(el => el.addEventListener('click', (e) => {
    if (e.target.closest('[data-del]')) return;
    openLightbox(`<img src="${esc(el.dataset.lbImg)}">`);
  }));
  container.querySelectorAll('[data-lb-vid]').forEach(el => el.addEventListener('click', (e) => {
    if (e.target.closest('[data-del]')) return;
    openLightbox(`<video controls autoplay src="${esc(el.dataset.lbVid)}"></video>`);
  }));
  if (onDelete) container.querySelectorAll('[data-del]').forEach(b => b.addEventListener('click', (e) => {
    e.stopPropagation(); onDelete(+b.dataset.del);
  }));
}

/* ============================================================
   Stock Adjustments
   ============================================================ */
async function renderAdjustments() {
  setCrumb('Stock Adjustments');
  setTopActions('');
  setPage(`<div class="empty"><i class="fa-solid fa-spinner fa-spin"></i></div>`);
  const j = await api('stock_adjust_list');
  setPage(`
    <h2>Stock Adjustments — P&amp;L impact</h2>
    <p class="lede">${j.adjustments.length} adjustments. Damage, shortage, wastage and theft post to realised losses.</p>
    <div class="kpis">
      <div class="kpi bad"><div class="label">Total realised loss</div><div class="value">${money(j.pl.total_loss)}</div></div>
      <div class="kpi good"><div class="label">Total realised gain</div><div class="value">${money(j.pl.total_gain)}</div></div>
      <div class="kpi info"><div class="label">Net impact</div><div class="value">${money((+j.pl.total_gain || 0) - (+j.pl.total_loss || 0))}</div></div>
    </div>
    <div class="toolbar">
      <select id="adj-type-f">
        <option value="">All types</option>
        ${['damage','shortage','excess','wastage','loss','found','theft','return_in','return_out','correction'].map(t => `<option value="${t}">${t}</option>`).join('')}
      </select>
      <select id="adj-status-f">
        <option value="">All statuses</option>
        <option value="pending">Pending</option>
        <option value="approved">Approved</option>
        <option value="rejected">Rejected</option>
      </select>
    </div>
    <table class="tbl"><thead><tr>
      <th>#</th><th>Date</th><th>Item</th><th>Type</th><th class="num">Qty</th>
      <th class="num">Impact</th><th>Status</th><th>By</th><th>Evidence</th>
    </tr></thead><tbody id="adj-rows">
      ${renderAdjRows(j.adjustments)}
    </tbody></table>
  `);
  const reload = async () => {
    const k = await api('stock_adjust_list', { adj_type: g('adj-type-f'), status: g('adj-status-f') });
    document.getElementById('adj-rows').innerHTML = renderAdjRows(k.adjustments);
    wireAdjActions();
  };
  document.getElementById('adj-type-f').addEventListener('change', reload);
  document.getElementById('adj-status-f').addEventListener('change', reload);
  wireAdjActions();
}

function renderAdjRows(rows) {
  if (!rows.length) return `<tr><td colspan="9"><div class="empty"><i class="fa-solid fa-check"></i><p>Nothing to show</p></div></td></tr>`;
  return rows.map(a => `<tr>
    <td class="font-mono muted">${esc(a.adj_number)}</td>
    <td>${date(a.created_at)}</td>
    <td><strong>${esc(a.item_name)}</strong></td>
    <td><span class="pill ${a.adj_type}">${esc(a.adj_type)}</span></td>
    <td class="num">${esc(a.qty)} ${esc(a.unit || '')}</td>
    <td class="num"><span class="amt ${a.direction === 'out' ? 'loss' : ''}">${money(a.value_impact)}</span></td>
    <td><span class="pill ${a.status}">${esc(a.status)}</span></td>
    <td>${esc(a.by_name || '—')}</td>
    <td>${a.evidence_path ? `<a href="${esc(a.evidence_path)}" target="_blank" class="btn-sm btn"><i class="fa-solid fa-eye"></i> View</a>` : '—'}
      ${a.status === 'pending' ? `<button class="btn-sm btn-primary pill-btn" data-approve="${a.id}"><i class="fa-solid fa-check"></i></button>` : ''}
    </td>
  </tr>`).join('');
}

function wireAdjActions() {
  document.querySelectorAll('[data-approve]').forEach(b => b.addEventListener('click', async () => {
    if (!confirm('Approve this adjustment? It will modify stock.')) return;
    try { await api('stock_adjust_approve', { id: +b.dataset.approve, decision: 'approve' });
      toast('Approved'); renderAdjustments();
    } catch (e) { toast(e.message, 'error'); }
  }));
}

/* ============================================================
   Tax Invoices
   ============================================================ */
async function renderInvoiceList(...rest) {
  if (rest.length && rest[0] === 'new') return renderInvoiceEditor(null);
  if (rest.length && rest[0]) return renderInvoiceDetail(rest[0]);
  setCrumb('Tax Invoices');
  setTopActions(`<a class="pill-btn btn-primary" href="#invoices/new" data-testid="new-invoice"><i class="fa-solid fa-file-invoice"></i><span>New invoice</span></a>`);
  setPage(`<div class="empty"><i class="fa-solid fa-spinner fa-spin"></i></div>`);
  const j = await api('invoice_list');
  const a = j.agg || {};
  setPage(`
    <h2>Tax Invoices (GST)</h2>
    <p class="lede">${esc(a.total || 0)} invoices issued · revenue <span class="amt">${money(a.revenue)}</span> · tax collected <span class="amt">${money(a.tax_collected)}</span></p>
    <div class="kpis">
      <div class="kpi good"><div class="label">Paid</div><div class="value">${money(a.paid_amt)}</div></div>
      <div class="kpi warn"><div class="label">Outstanding</div><div class="value">${money(a.outstanding)}</div></div>
      <div class="kpi violet"><div class="label">Tax collected</div><div class="value">${money(a.tax_collected)}</div></div>
    </div>
    <div class="toolbar">
      <select id="inv-status">
        <option value="">All statuses</option>
        <option value="draft">Draft</option><option value="issued">Issued</option>
        <option value="paid">Paid</option><option value="partial">Partial</option>
        <option value="overdue">Overdue</option><option value="cancelled">Cancelled</option>
      </select>
      <input type="date" id="inv-from" placeholder="From">
      <input type="date" id="inv-to" placeholder="To">
    </div>
    <table class="tbl" id="inv-tbl"><thead><tr>
      <th>Invoice #</th><th>Date</th><th>Buyer</th><th>GSTIN</th>
      <th class="num">Taxable</th><th class="num">Tax</th><th class="num">Total</th>
      <th>Payment</th><th>Status</th><th></th>
    </tr></thead><tbody id="inv-rows">${renderInvoiceRows(j.invoices)}</tbody></table>
  `);
  wireRowLinks();
  const reload = debounce(async () => {
    const r = await api('invoice_list', { status: g('inv-status'), from: g('inv-from'), to: g('inv-to') });
    document.getElementById('inv-rows').innerHTML = renderInvoiceRows(r.invoices);
    wireRowLinks();
  }, 250);
  ['inv-status','inv-from','inv-to'].forEach(id => document.getElementById(id).addEventListener('change', reload));
}

function renderInvoiceRows(rows) {
  if (!rows.length) return `<tr><td colspan="10"><div class="empty"><i class="fa-solid fa-file-invoice"></i><h4>No invoices match</h4></div></td></tr>`;
  return rows.map(i => `<tr class="row-link" data-go="#invoices/${i.id}">
    <td class="font-mono">${esc(i.invoice_number)}</td>
    <td>${date(i.invoice_date)}</td>
    <td><strong>${esc(i.buyer_name)}</strong></td>
    <td class="font-mono muted">${esc(i.buyer_gstin || 'B2C')}</td>
    <td class="num">${money(i.taxable_value)}</td>
    <td class="num"><span class="amt warn">${money(+(i.total_cgst || 0) + +(i.total_sgst || 0) + +(i.total_igst || 0))}</span></td>
    <td class="num"><span class="amt">${money(i.grand_total)}</span></td>
    <td><span class="pill ${i.payment_status}">${esc(i.payment_status)}</span></td>
    <td><span class="pill ${i.status}">${esc(i.status)}</span></td>
    <td><i class="fa-solid fa-chevron-right muted"></i></td>
  </tr>`).join('');
}

async function renderInvoiceEditor(invId) {
  setCrumb('Tax Invoices', invId ? 'Edit' : 'New');
  setTopActions(`<a href="#invoices" class="pill-btn"><i class="fa-solid fa-arrow-left"></i><span>Back</span></a>`);
  setPage(`<div class="empty"><i class="fa-solid fa-spinner fa-spin"></i></div>`);

  const [items] = await Promise.all([api('stock_items')]);
  let inv = null, invItems = [];
  if (invId) { const j = await api('invoice_get', { id: +invId }); inv = j.invoice; invItems = j.items; }

  const stateOptions = stateList.map(s => `<option value="${esc(s.code)}" ${s.code === (inv?.buyer_state_code || '') ? 'selected' : ''}>${esc(s.name)} (${esc(s.code)})</option>`).join('');

  setPage(`
    <h2>${invId ? 'Edit Invoice' : 'New Tax Invoice'}</h2>
    <p class="lede">GST-compliant. CGST/SGST splits intra-state · IGST inter-state — calculated automatically.</p>

    <div class="card">
      <div class="card-h"><h3>Invoice header</h3></div>
      <div class="form-row cols-3">
        <div class="field"><label>Type</label>
          <select id="i-type" data-testid="inv-type">
            ${['tax_invoice','bill_of_supply','credit_note','debit_note','proforma'].map(t =>
              `<option value="${t}" ${t === (inv?.invoice_type || 'tax_invoice') ? 'selected' : ''}>${t.replace('_',' ')}</option>`).join('')}
          </select>
        </div>
        <div class="field"><label>Date *</label><input type="date" id="i-date" value="${esc(inv?.invoice_date || todayISO())}"></div>
        <div class="field"><label>Due date</label><input type="date" id="i-due" value="${esc(inv?.due_date || '')}"></div>
      </div>
      <div class="form-row cols-2">
        <div class="field"><label>Buyer name *</label><input id="i-buyer" value="${esc(inv?.buyer_name || '')}" data-testid="inv-buyer"></div>
        <div class="field"><label>Buyer GSTIN</label><input id="i-gstin" value="${esc(inv?.buyer_gstin || '')}" placeholder="15-char GSTIN" data-testid="inv-gstin"></div>
      </div>
      <div class="form-row cols-3">
        <div class="field"><label>Email</label><input id="i-email" type="email" value="${esc(inv?.buyer_email || '')}"></div>
        <div class="field"><label>Phone</label><input id="i-phone" value="${esc(inv?.buyer_phone || '')}"></div>
        <div class="field"><label>PAN</label><input id="i-pan" value="${esc(inv?.buyer_pan || '')}"></div>
      </div>
      <div class="field"><label>Address</label><textarea id="i-addr">${esc(inv?.buyer_address || '')}</textarea></div>
      <div class="form-row cols-2">
        <div class="field"><label>Buyer state</label><select id="i-state">${stateOptions}</select></div>
        <div class="field"><label class="checkbox-field"><input type="checkbox" id="i-rcm" ${inv?.reverse_charge ? 'checked' : ''}> Reverse charge mechanism</label></div>
      </div>
    </div>

    <div class="card">
      <div class="card-h"><h3>Line items</h3></div>
      <div class="line-editor" id="inv-lines">
        <div class="col-h">
          <div>Description</div><div>HSN</div><div>Qty</div><div>Unit</div>
          <div>Rate</div><div>Disc %</div><div>GST %</div><div></div><div></div>
        </div>
        <div id="inv-line-rows"></div>
        <button class="add-line" id="inv-add"><i class="fa-solid fa-plus"></i> Add line</button>
      </div>
    </div>

    <div class="card">
      <div class="card-h"><h3>Summary</h3></div>
      <div id="inv-summary" style="font-family:'JetBrains Mono';font-size:13px"></div>
    </div>

    <div class="form-row cols-2">
      <div class="field"><label>Notes</label><textarea id="i-notes">${esc(inv?.notes || '')}</textarea></div>
      <div class="field"><label>Terms</label><textarea id="i-terms">${esc(inv?.terms || org?.invoice_terms || '')}</textarea></div>
    </div>

    <div class="flex-end" style="margin-top:18px">
      <button class="pill-btn" id="cancel-inv">Cancel</button>
      <button class="pill-btn" id="save-inv" data-testid="inv-save"><i class="fa-solid fa-floppy-disk"></i> Save draft</button>
      ${invId ? `<button class="pill-btn btn-primary" id="issue-inv" data-testid="inv-issue"><i class="fa-solid fa-paper-plane"></i> Issue invoice</button>` : ''}
    </div>
  `);

  const linesEl = document.getElementById('inv-line-rows');
  function lineRow(it = {}) {
    const sel = `<select class="i-pick"><option value="">— item —</option>
      ${items.items.map(x => `<option value="${x.id}" data-hsn="${esc(x.hsn_code || '')}" data-price="${esc(x.sale_price || 0)}" data-gst="${esc(x.gst_rate || 0)}" data-unit="${esc(x.unit || 'pcs')}" ${it.item_id == x.id ? 'selected' : ''}>${esc(x.item_name)}</option>`).join('')}
    </select>`;
    const row = document.createElement('div');
    row.className = 'line-row';
    row.innerHTML = `
      <div>${sel}<input class="i-desc" placeholder="Description" value="${esc(it.description || '')}" style="margin-top:4px;padding:6px"></div>
      <input class="i-hsn" placeholder="HSN" value="${esc(it.hsn_code || '')}">
      <input class="i-qty" type="number" step="0.001" value="${esc(it.qty || 1)}">
      <input class="i-unit" placeholder="pcs" value="${esc(it.unit || 'pcs')}">
      <input class="i-rate" type="number" step="0.01" value="${esc(it.unit_price || 0)}">
      <input class="i-disc" type="number" step="0.01" placeholder="0" value="${esc(it.discount_pct || 0)}">
      <input class="i-gst" type="number" step="0.01" value="${esc(it.gst_rate || 0)}">
      <span class="i-line muted font-mono">₹0.00</span>
      <button class="x"><i class="fa-solid fa-xmark"></i></button>
    `;
    linesEl.appendChild(row);
    const pick = row.querySelector('.i-pick');
    pick.addEventListener('change', () => {
      const opt = pick.selectedOptions[0];
      if (!opt) return;
      if (!row.querySelector('.i-desc').value) row.querySelector('.i-desc').value = opt.textContent;
      if (!row.querySelector('.i-hsn').value) row.querySelector('.i-hsn').value = opt.dataset.hsn || '';
      if (!+row.querySelector('.i-rate').value) row.querySelector('.i-rate').value = opt.dataset.price || 0;
      if (!+row.querySelector('.i-gst').value) row.querySelector('.i-gst').value = opt.dataset.gst || 0;
      if (!row.querySelector('.i-unit').value) row.querySelector('.i-unit').value = opt.dataset.unit || 'pcs';
      computeTotals();
    });
    row.querySelectorAll('input').forEach(el => el.addEventListener('input', computeTotals));
    row.querySelector('.x').addEventListener('click', () => { row.remove(); computeTotals(); });
  }
  if (invItems.length) invItems.forEach(lineRow); else lineRow();
  document.getElementById('inv-add').addEventListener('click', () => { lineRow(); computeTotals(); });

  function computeTotals() {
    const sellerState = (org?.org_state_code || '').toString();
    const buyerState = document.getElementById('i-state').value;
    const isInterstate = sellerState && buyerState && sellerState !== buyerState;
    let subtotal = 0, discTotal = 0, taxable = 0, cgst = 0, sgst = 0, igst = 0;
    [...document.querySelectorAll('#inv-line-rows .line-row')].forEach(r => {
      const qty = +r.querySelector('.i-qty').value || 0;
      const rate = +r.querySelector('.i-rate').value || 0;
      const dp = +r.querySelector('.i-disc').value || 0;
      const gst = (document.getElementById('i-type').value === 'bill_of_supply') ? 0 : (+r.querySelector('.i-gst').value || 0);
      const gross = qty * rate;
      const dAmt = +(gross * dp / 100).toFixed(2);
      const tv = +(gross - dAmt).toFixed(2);
      let lCgst = 0, lSgst = 0, lIgst = 0;
      if (gst > 0) {
        if (isInterstate) lIgst = +(tv * gst / 100).toFixed(2);
        else { lCgst = +(tv * gst / 200).toFixed(2); lSgst = lCgst; }
      }
      const total = +(tv + lCgst + lSgst + lIgst).toFixed(2);
      r.querySelector('.i-line').textContent = money(total);
      subtotal += gross; discTotal += dAmt; taxable += tv;
      cgst += lCgst; sgst += lSgst; igst += lIgst;
    });
    const grand = +(taxable + cgst + sgst + igst).toFixed(2);
    const rounded = Math.round(grand);
    const roundOff = +(rounded - grand).toFixed(2);
    document.getElementById('inv-summary').innerHTML = `
      <table class="tbl" style="width:auto;margin-left:auto"><tbody>
        <tr><td>Subtotal</td><td class="num">${money(subtotal)}</td></tr>
        ${discTotal > 0 ? `<tr><td>Discount</td><td class="num">- ${money(discTotal)}</td></tr>` : ''}
        <tr><td>Taxable</td><td class="num"><strong>${money(taxable)}</strong></td></tr>
        ${isInterstate
          ? `<tr><td>IGST</td><td class="num"><span class="amt warn">${money(igst)}</span></td></tr>`
          : `<tr><td>CGST</td><td class="num"><span class="amt warn">${money(cgst)}</span></td></tr>
             <tr><td>SGST</td><td class="num"><span class="amt warn">${money(sgst)}</span></td></tr>`}
        <tr><td>Round off</td><td class="num">${money(roundOff)}</td></tr>
        <tr><td><strong>Grand Total</strong></td><td class="num"><strong><span class="amt">${money(rounded)}</span></strong></td></tr>
      </tbody></table>
      <div class="muted" style="margin-top:8px;font-family:'Crimson Pro';font-style:italic">${isInterstate ? 'Inter-state supply (IGST applies)' : 'Intra-state supply (CGST + SGST splits)'}</div>
    `;
  }
  computeTotals();
  ['i-state','i-type'].forEach(id => document.getElementById(id).addEventListener('change', computeTotals));

  document.getElementById('cancel-inv').addEventListener('click', () => location.hash = '#invoices');
  document.getElementById('save-inv').addEventListener('click', async (e) => {
    const btn = e.currentTarget; btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Saving';
    try {
      const items = [...document.querySelectorAll('#inv-line-rows .line-row')].map(r => ({
        item_id: +r.querySelector('.i-pick').value || null,
        description: r.querySelector('.i-desc').value || (r.querySelector('.i-pick').selectedOptions[0]?.textContent || 'Item'),
        hsn_code: r.querySelector('.i-hsn').value,
        qty: +r.querySelector('.i-qty').value || 0,
        unit: r.querySelector('.i-unit').value,
        unit_price: +r.querySelector('.i-rate').value || 0,
        discount_pct: +r.querySelector('.i-disc').value || 0,
        gst_rate: +r.querySelector('.i-gst').value || 0,
      })).filter(x => x.qty > 0);
      if (!items.length) throw new Error('Add at least one item');
      const stateOpt = document.getElementById('i-state').selectedOptions[0];
      const r = await api('invoice_save', {
        id: invId || 0,
        invoice_type: g('i-type'),
        invoice_date: g('i-date'),
        due_date: g('i-due'),
        buyer_name: g('i-buyer'),
        buyer_gstin: g('i-gstin'),
        buyer_email: g('i-email'),
        buyer_phone: g('i-phone'),
        buyer_pan: g('i-pan'),
        buyer_address: g('i-addr'),
        buyer_state: stateOpt ? stateOpt.textContent.replace(/\s*\(.*\)\s*$/, '') : '',
        buyer_state_code: stateOpt ? stateOpt.value : '',
        place_of_supply: stateOpt ? stateOpt.textContent.replace(/\s*\(.*\)\s*$/, '') : '',
        reverse_charge: document.getElementById('i-rcm').checked,
        notes: g('i-notes'),
        terms: g('i-terms'),
        items,
      });
      toast('Invoice saved');
      location.hash = '#invoices/' + r.id;
    } catch (e) { toast(e.message, 'error'); btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save draft'; }
  });
  document.getElementById('issue-inv')?.addEventListener('click', async () => {
    if (!confirm('Issue this invoice? A PDF will be generated and the invoice will be locked from editing.')) return;
    await api('invoice_save', { id: +invId, /* persist current state first */
      invoice_type: g('i-type'), invoice_date: g('i-date'), buyer_name: g('i-buyer'),
      buyer_gstin: g('i-gstin'), buyer_address: g('i-addr'),
      buyer_state_code: document.getElementById('i-state').value,
      items: [...document.querySelectorAll('#inv-line-rows .line-row')].map(r => ({
        item_id: +r.querySelector('.i-pick').value || null,
        description: r.querySelector('.i-desc').value || 'Item',
        hsn_code: r.querySelector('.i-hsn').value,
        qty: +r.querySelector('.i-qty').value || 0,
        unit: r.querySelector('.i-unit').value,
        unit_price: +r.querySelector('.i-rate').value || 0,
        discount_pct: +r.querySelector('.i-disc').value || 0,
        gst_rate: +r.querySelector('.i-gst').value || 0,
      })).filter(x => x.qty > 0),
    });
    await api('invoice_issue', { id: +invId });
    toast('Invoice issued');
    location.hash = '#invoices/' + invId;
  });
}

async function renderInvoiceDetail(idStr) {
  const id = +idStr;
  setCrumb('Tax Invoices', '#' + id);
  setTopActions(`
    <a href="#invoices" class="pill-btn"><i class="fa-solid fa-arrow-left"></i><span>Back</span></a>
    <a href="${API}?action=invoice_pdf&id=${id}" target="_blank" class="pill-btn"><i class="fa-solid fa-file-pdf"></i><span>PDF</span></a>
  `);
  setPage(`<div class="empty"><i class="fa-solid fa-spinner fa-spin"></i></div>`);
  const j = await api('invoice_get', { id });
  const inv = j.invoice;
  const editable = inv.status === 'draft';
  setPage(`
    <h2>${esc(inv.invoice_number)} <span class="pill ${inv.status}" style="vertical-align:middle">${esc(inv.status)}</span></h2>
    <p class="lede">${inv.invoice_type.replace('_',' ')} dated ${date(inv.invoice_date)} · ${inv.is_interstate ? 'inter-state IGST' : 'intra-state CGST+SGST'}</p>

    <div class="kpis">
      <div class="kpi"><div class="label">Taxable</div><div class="value">${money(inv.taxable_value)}</div></div>
      <div class="kpi violet"><div class="label">Tax</div><div class="value">${money(+inv.total_cgst + +inv.total_sgst + +inv.total_igst)}</div><div class="sub">${inv.is_interstate ? `IGST ${money(inv.total_igst)}` : `CGST ${money(inv.total_cgst)} · SGST ${money(inv.total_sgst)}`}</div></div>
      <div class="kpi good"><div class="label">Grand total</div><div class="value">${money(inv.grand_total)}</div></div>
      <div class="kpi ${inv.payment_status === 'paid' ? 'good' : 'warn'}"><div class="label">${inv.payment_status}</div><div class="value">${money(inv.paid_amount)}</div><div class="sub">balance ${money(Math.max(0, +inv.grand_total - +inv.paid_amount))}</div></div>
    </div>

    <div class="card">
      <div class="card-h"><h3>Buyer</h3>
        <div class="actions">
          ${editable ? `<a href="#invoices/new?edit=${id}" class="btn-sm btn"><i class="fa-solid fa-pen"></i> Edit</a>` : ''}
          ${editable ? `<button class="btn-sm pill-btn btn-primary" id="issue-it"><i class="fa-solid fa-paper-plane"></i> Issue</button>` : ''}
          ${inv.payment_status !== 'paid' && inv.status !== 'cancelled' ? `<button class="btn-sm btn" id="record-pay"><i class="fa-solid fa-money-bill-wave"></i> Record payment</button>` : ''}
        </div>
      </div>
      <div class="form-row cols-3">
        <div><div class="muted">Name</div><strong>${esc(inv.buyer_name)}</strong></div>
        <div><div class="muted">GSTIN</div><strong class="font-mono">${esc(inv.buyer_gstin || 'B2C')}</strong></div>
        <div><div class="muted">State</div><strong>${esc(inv.buyer_state || '—')}</strong> <small class="muted">(${esc(inv.buyer_state_code || '—')})</small></div>
      </div>
      ${inv.buyer_address ? `<p style="color:var(--text-2);margin-top:8px">${esc(inv.buyer_address)}</p>` : ''}
    </div>

    <div class="card">
      <div class="card-h"><h3>Line items</h3></div>
      <table class="tbl"><thead><tr>
        <th>#</th><th>Description</th><th>HSN</th><th class="num">Qty</th>
        <th class="num">Rate</th><th class="num">Taxable</th>
        ${inv.is_interstate ? '<th class="num">IGST</th>' : '<th class="num">CGST</th><th class="num">SGST</th>'}
        <th class="num">Total</th>
      </tr></thead><tbody>
        ${j.items.map((it, idx) => `<tr>
          <td>${idx + 1}</td>
          <td><strong>${esc(it.description)}</strong></td>
          <td class="font-mono">${esc(it.hsn_code || '—')}</td>
          <td class="num">${esc(it.qty)} ${esc(it.unit || '')}</td>
          <td class="num">${money(it.unit_price)}</td>
          <td class="num">${money(it.taxable_value)}</td>
          ${inv.is_interstate ? `<td class="num">${money(it.igst)} <small class="muted">(${it.gst_rate}%)</small></td>`
            : `<td class="num">${money(it.cgst)} <small class="muted">(${+it.gst_rate/2}%)</small></td>
               <td class="num">${money(it.sgst)} <small class="muted">(${+it.gst_rate/2}%)</small></td>`}
          <td class="num"><span class="amt">${money(it.line_total)}</span></td>
        </tr>`).join('')}
      </tbody></table>
      <p class="muted" style="margin-top:10px;font-family:'Crimson Pro';font-style:italic">${esc(inv.amount_in_words)}</p>
    </div>

    ${j.payments.length ? `
    <div class="card">
      <div class="card-h"><h3>Payments</h3></div>
      <table class="tbl"><thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Ref</th><th>By</th></tr></thead><tbody>
        ${j.payments.map(p => `<tr>
          <td>${date(p.payment_date)}</td>
          <td><span class="amt">${money(p.amount)}</span></td>
          <td>${esc(p.method)}</td>
          <td class="font-mono muted">${esc(p.reference_no || '—')}</td>
          <td>${esc(p.by_name || '—')}</td>
        </tr>`).join('')}
      </tbody></table>
    </div>` : ''}
  `);

  document.getElementById('issue-it')?.addEventListener('click', async () => {
    if (!confirm('Issue invoice?')) return;
    await api('invoice_issue', { id }); toast('Invoice issued'); renderInvoiceDetail(idStr);
  });
  document.getElementById('record-pay')?.addEventListener('click', () => openPaymentDrawer(inv, () => renderInvoiceDetail(idStr)));
}

function openPaymentDrawer(inv, onSaved) {
  const balance = Math.max(0, +inv.grand_total - +inv.paid_amount).toFixed(2);
  openDrawer('Record payment', `
    <p class="lede" style="margin-top:0">Outstanding balance: <span class="amt">${money(balance)}</span></p>
    <div class="form-row cols-2">
      <div class="field"><label>Amount *</label><input id="p-amt" type="number" step="0.01" value="${balance}" data-testid="pay-amt"></div>
      <div class="field"><label>Date</label><input type="date" id="p-date" value="${todayISO()}"></div>
    </div>
    <div class="form-row cols-2">
      <div class="field"><label>Method</label>
        <select id="p-method">
          ${['cash','bank_transfer','upi','cheque','card','other'].map(m => `<option value="${m}">${m}</option>`).join('')}
        </select>
      </div>
      <div class="field"><label>Reference #</label><input id="p-ref" placeholder="UTR / cheque #"></div>
    </div>
    <div class="field"><label>Notes</label><textarea id="p-notes"></textarea></div>
  `, [
    { label: 'Cancel', cls: 'pill-btn', action: closeDrawer },
    { label: 'Record', cls: 'pill-btn btn-primary', action: async (btn) => {
      const amt = +g('p-amt'); if (amt <= 0) return toast('Amount required', 'error');
      btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Saving';
      try {
        await api('invoice_payment', {
          invoice_id: inv.id, amount: amt, payment_date: g('p-date'),
          method: g('p-method'), reference_no: g('p-ref'), notes: g('p-notes'),
        });
        toast('Payment recorded'); closeDrawer(); onSaved?.();
      } catch (e) { toast(e.message, 'error'); btn.disabled = false; btn.innerHTML = 'Record'; }
    }},
  ]);
}

/* ============================================================
   Reports / GST Summary
   ============================================================ */
async function renderReports() {
  setCrumb('Reports & GST');
  setTopActions('');
  const from = new Date(); from.setDate(from.getDate() - 30);
  const fromS = from.toISOString().slice(0,10);
  const toS = todayISO();
  setPage(`
    <h2>GST Summary &amp; P&amp;L</h2>
    <p class="lede">HSN-wise GST summary (GSTR-1 Table-12 style) + stock P&amp;L impact.</p>
    <div class="toolbar">
      <label>From <input type="date" id="r-from" value="${fromS}"></label>
      <label>To <input type="date" id="r-to" value="${toS}"></label>
      <button class="pill-btn btn-primary" id="r-go"><i class="fa-solid fa-magnifying-glass-chart"></i> Build</button>
      <div class="grow"></div>
      <button class="pill-btn" id="r-csv"><i class="fa-solid fa-file-csv"></i> Export</button>
    </div>
    <div id="r-result"><div class="empty"><i class="fa-solid fa-chart-line"></i><h4>Pick a date range and click Build</h4></div></div>
  `);

  async function build() {
    const r = document.getElementById('r-result');
    r.innerHTML = `<div class="empty"><i class="fa-solid fa-spinner fa-spin"></i></div>`;
    const fromV = g('r-from'), toV = g('r-to');
    const [gst, pnl] = await Promise.all([
      api('invoice_gst_summary', { from: fromV, to: toV }),
      api('stock_pnl', { from: fromV, to: toV }),
    ]);
    r.innerHTML = `
      <div class="kpis">
        <div class="kpi good"><div class="label">B2B revenue</div><div class="value">${money(gst.summary.b2b)}</div></div>
        <div class="kpi info"><div class="label">B2C revenue</div><div class="value">${money(gst.summary.b2c)}</div></div>
        <div class="kpi violet"><div class="label">Tax collected</div><div class="value">${money(gst.summary.total_tax)}</div></div>
        <div class="kpi bad"><div class="label">Stock losses (P&L)</div><div class="value">${money(pnl.totals.realised_loss)}</div></div>
      </div>

      <div class="card">
        <div class="card-h"><h3>HSN-wise tax summary</h3></div>
        ${gst.hsn.length ? `
          <table class="tbl"><thead><tr>
            <th>HSN</th><th class="num">Lines</th><th class="num">Qty</th>
            <th class="num">Taxable</th><th class="num">CGST</th><th class="num">SGST</th><th class="num">IGST</th>
          </tr></thead><tbody>
            ${gst.hsn.map(h => `<tr>
              <td class="font-mono"><strong>${esc(h.hsn_code || '—')}</strong></td>
              <td class="num">${esc(h.lines)}</td>
              <td class="num">${esc(h.qty)}</td>
              <td class="num">${money(h.taxable_value)}</td>
              <td class="num">${money(h.cgst)}</td>
              <td class="num">${money(h.sgst)}</td>
              <td class="num">${money(h.igst)}</td>
            </tr>`).join('')}
          </tbody></table>
        ` : '<div class="empty"><i class="fa-solid fa-receipt"></i><p>No issued invoices in this range</p></div>'}
      </div>

      <div class="card">
        <div class="card-h"><h3>Stock losses breakdown</h3></div>
        ${pnl.breakdown.length ? `
          <table class="tbl"><thead><tr>
            <th>Type</th><th class="num">Count</th><th class="num">Quantity</th><th class="num">Value impact</th>
          </tr></thead><tbody>
            ${pnl.breakdown.map(b => `<tr>
              <td><span class="pill ${b.adj_type}">${esc(b.adj_type)}</span></td>
              <td class="num">${esc(b.cnt)}</td>
              <td class="num">${esc(b.total_qty)}</td>
              <td class="num"><span class="amt ${['damage','shortage','loss','wastage','theft','return_out'].includes(b.adj_type) ? 'loss' : ''}">${money(b.total_value)}</span></td>
            </tr>`).join('')}
          </tbody></table>
        ` : '<div class="empty"><i class="fa-solid fa-check"></i><p>No adjustments in this range</p></div>'}
      </div>
    `;
  }
  document.getElementById('r-go').addEventListener('click', build);
  document.getElementById('r-csv').addEventListener('click', () => {
    const fromV = g('r-from'), toV = g('r-to');
    // Build CSV from current view
    const rows = [...document.querySelectorAll('#r-result table.tbl tr')].map(tr => [...tr.children].map(td => `"${(td.innerText || '').replace(/"/g,'""')}"`).join(','));
    if (!rows.length) return toast('Build the report first', 'warn');
    const blob = new Blob([rows.join('\n')], { type: 'text/csv' });
    const a = document.createElement('a'); a.href = URL.createObjectURL(blob);
    a.download = `gst-summary-${fromV}-to-${toV}.csv`; a.click();
  });
  build();
}

/* ============================================================
   Drawer + Lightbox
   ============================================================ */
function openDrawer(title, bodyHtml, actions = []) {
  document.getElementById('drawer-overlay').classList.add('show');
  const d = document.getElementById('drawer');
  d.innerHTML = `
    <div class="drawer-h"><h3>${esc(title)}</h3><button class="close" data-close-drawer><i class="fa-solid fa-xmark"></i> Close</button></div>
    <div class="drawer-b">${bodyHtml}</div>
    <div class="drawer-f">${actions.map((a, i) => `<button class="${a.cls}" data-drawer-action="${i}"${a.testid ? ` data-testid="${a.testid}"` : ''}>${esc(a.label)}</button>`).join('')}</div>
  `;
  d.classList.add('show');
  d.querySelector('[data-close-drawer]').addEventListener('click', closeDrawer);
  actions.forEach((a, i) => d.querySelector(`[data-drawer-action="${i}"]`).addEventListener('click', (e) => a.action?.(e.currentTarget)));
}
function closeDrawer() {
  document.getElementById('drawer-overlay').classList.remove('show');
  document.getElementById('drawer').classList.remove('show');
}
function openLightbox(html) {
  document.getElementById('lb-content').innerHTML = html;
  document.getElementById('lightbox').classList.add('show');
}
function closeLightbox() {
  document.getElementById('lightbox').classList.remove('show');
  document.getElementById('lb-content').innerHTML = '';
}

/* ============================================================
   Helpers
   ============================================================ */
function g(id) { return document.getElementById(id)?.value || ''; }
function fileToBase64(file) {
  return new Promise((resolve, reject) => {
    const r = new FileReader();
    r.onerror = () => reject(new Error('Read failed'));
    r.onload = () => resolve({ data: r.result, name: file.name });
    r.readAsDataURL(file);
  });
}
function wireRowLinks() {
  document.querySelectorAll('[data-go]').forEach(el => {
    el.addEventListener('click', () => location.hash = el.dataset.go);
  });
  document.querySelectorAll('a[data-nav]').forEach(el => {
    // already real anchor with hash href — no-op
  });
}

/* ============================================================
   Bootstrap
   ============================================================ */
async function bootstrap() {
  token = readToken();
  if (!token) { location.href = '/aetherV2/chat.php?signed_out=1'; return; }
  try {
    const [orgRes, statesRes] = await Promise.all([api('ref_org'), api('ref_states')]);
    me = orgRes.user; me.role = orgRes.role;
    org = orgRes.org;
    stateList = statesRes.states;
  } catch (e) {
    document.getElementById('app').innerHTML = `<div class="empty" style="padding-top:140px"><i class="fa-solid fa-circle-exclamation"></i><h4>Could not authenticate</h4><p>${esc(e.message)}</p><a class="pill-btn" href="/aetherV2/chat.php" style="margin-top:14px">Back to Aether</a></div>`;
    return;
  }
  renderShell();
  window.addEventListener('hashchange', route);
  route();
}

document.addEventListener('DOMContentLoaded', bootstrap);
})();
