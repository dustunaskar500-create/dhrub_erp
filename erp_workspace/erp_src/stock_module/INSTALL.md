# Aether Stock & GST Module — Installation Guide

Drop-in stock receiving + GST invoicing module for the **dhrub_erp** ecosystem.
Mirrors Indian standard receiving practice (GRN with photo/video evidence) and
generates GST-compliant tax invoices with proper CGST/SGST/IGST split.

---

## What you get

| Capability | Where |
|---|---|
| 🏪 Stock items with HSN + GST | Stock Items page |
| 🚚 Vendor master with GSTIN validation | Vendors page |
| 📦 Goods Receipt Note (GRN) with photo/video evidence uploads | Goods Receipt page |
| 🪜 Stock adjustments — damage / shortage / excess / loss / theft / found | Adjustments page |
| 💰 Tax Invoice / Bill of Supply / Credit & Debit Notes | Tax Invoices page |
| 🧾 GST PDF generation (mPDF) with **Amount in words** in Indian format | Invoice → PDF |
| 📊 GSTR-1 Table-12 style HSN summary + Stock P&L | Reports & GST page |
| 🤖 Aether AI integration | Natural-language queries through your existing Aether chat |
| 🎨 Light / Dark theme | Toggle in the top-right |

---

## Installation (Hostinger / cPanel / any LAMP host)

### Step 1 — Upload files

Copy this entire `stock_module/` folder to your dhrub_erp root.
Final structure should look like:

```
public_html/
├── index.php              ← your existing ERP router
├── app.html               ← your existing React app
├── config/database.php
├── includes/db.php
├── stock_module/          ← THIS FOLDER
│   ├── index.php          ← SPA entry
│   ├── api/
│   │   ├── router.php
│   │   ├── auth_bridge.php
│   │   ├── shared_helpers.php
│   │   └── modules/...
│   ├── static/...
│   ├── migrations/001_stock_gst.sql
│   └── uploads/           ← chmod 0775, must be writable
```

### Step 2 — Run the migration

From the cPanel SQL panel **or** SSH:

```bash
mysql -u DB_USER -p DB_NAME < stock_module/migrations/001_stock_gst.sql
```

The migration is **idempotent** — safe to re-run. It:
- Extends `inventory_items` with `sku`, `hsn_code`, `gst_rate`, `cost_price`, `sale_price`, `barcode`, `reorder_qty`, `image_path` (additive — does NOT touch existing data).
- Adds 11 new tables: `erp_vendors`, `erp_purchase_orders`, `erp_po_items`,
  `erp_grns`, `erp_grn_items`, `erp_grn_attachments`, `erp_stock_adjustments`,
  `erp_tax_invoices`, `erp_tax_invoice_items`, `erp_invoice_payments`,
  `erp_state_codes`, `erp_doc_counters`.
- Seeds the 39 Indian state codes.

### Step 3 — Configure org GSTIN

Open your ERP **Settings** and add:
- `org_gstin` — your organisation's GSTIN (e.g. `19AABCD1234E1Z5`)
- `org_state` — e.g. `West Bengal`
- `org_state_code` — e.g. `19`
- `org_bank_name`, `org_bank_account`, `org_bank_ifsc`, `org_bank_branch` — for invoice footer
- `invoice_prefix` — default `INV`
- `grn_prefix` — default `GRN`
- `po_prefix` — default `PO`
- `adj_prefix` — default `ADJ`

You can also do this from inside the Stock Module → click the cog → "Org settings".

### Step 4 — (Optional) Install mPDF for PDF invoices

If you want PDF output (recommended), from SSH inside your ERP root:

```bash
composer require mpdf/mpdf
```

If mPDF is not installed, the module gracefully falls back to a printable
HTML page that any browser can save as PDF using its built-in print dialog.

### Step 5 — Set PHP upload limits

Add to your `.htaccess` (or `php.ini`):

```ini
php_value upload_max_filesize 64M
php_value post_max_size 96M
php_value max_execution_time 180
```

### Step 6 — Link from your main ERP

You have two options:

**Option A — Quick:** add a link in your topbar / sidebar:
```html
<a href="/stock_module/">Stock &amp; GST</a>
```

**Option B — React app:** add a navigation entry in your existing routes:
```jsx
{ path: '/stock', label: 'Stock & GST', external: true, href: '/stock_module/' }
```

That's it. Open `https://yourdomain.com/stock_module/` and the SPA will pick up
your existing JWT from `localStorage` automatically.

---

## Aether integration

The Aether butler already knows about the stock module. Natural-language queries
like these will work out of the box (Aether reads the same DB tables):

- "What's our stock value?"
- "Which items are running low?"
- "Are there any outstanding invoices?"
- "How much GST have we collected this month?"
- "Any damages or losses recently?"
- "How many vendors do we have?"
- "Show me pending goods receipts."

Aether will reply with butler-formatted answers **and** deep-link to the
relevant module page.

---

## Authentication

The stock module re-uses your existing dhrub_erp JWT. The `auth_bridge.php`
file does HS256 token validation against the same secret you use in
`includes/auth.php` (it discovers the secret from environment, `.env`, or
the `JWT_SECRET` constant).

If your JWT secret is somewhere else, set it as an environment variable:

```bash
export JWT_SECRET="your-secret"
```

Or expose it as a constant in `config/database.php`:

```php
define('JWT_SECRET', 'your-secret');
```

---

## Role permissions

| Action | super_admin | admin | manager | accountant | editor | viewer |
|--------|:-:|:-:|:-:|:-:|:-:|:-:|
| List / read | ✓ | ✓ | ✓ | ✓ | ✗ | ✓ |
| Save vendor | ✓ | ✓ | ✓ | ✓ | ✗ | ✗ |
| Save item | ✓ | ✓ | ✓ | ✗ | ✗ | ✗ |
| Save GRN draft | ✓ | ✓ | ✓ | ✓ | ✗ | ✗ |
| Post GRN | ✓ | ✓ | ✓ | ✗ | ✗ | ✗ |
| Save / Issue invoice | ✓ | ✓ | ✓ | ✓ | ✗ | ✗ |
| Cancel invoice | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ |
| Approve adjustment | ✓ | ✓ | ✓ | ✗ | ✗ | ✗ |

---

## API surface (for your devs)

`POST /stock_module/api/router.php?action=<NAME>` with `Authorization: Bearer <JWT>`.

| Group | Actions |
|---|---|
| `ref_*`        | `ref_org`, `ref_states`, `ref_update_org` |
| `dash_*`       | `dash_overview` |
| `vendor_*`     | `vendor_list`, `vendor_get`, `vendor_save`, `vendor_delete` |
| `stock_*`      | `stock_items`, `stock_get`, `stock_save`, `stock_movements`, `stock_adjust_list`, `stock_adjust_create`, `stock_adjust_approve`, `stock_pnl` |
| `po_*`         | `po_list`, `po_get`, `po_save`, `po_cancel` |
| `grn_*`        | `grn_list`, `grn_get`, `grn_save`, `grn_post`, `grn_upload_attachment` (multipart), `grn_delete_attachment` |
| `invoice_*`    | `invoice_list`, `invoice_get`, `invoice_save`, `invoice_issue`, `invoice_cancel`, `invoice_pdf`, `invoice_payment`, `invoice_gst_summary`, `invoice_from_grn` |
| `seed_demo`    | One-time demo data |

---

## Troubleshooting

| Issue | Fix |
|---|---|
| "Authentication required" on every action | Your JWT isn't being read — open the SPA from a tab where you're already logged in to dhrub_erp. Check `localStorage.getItem('access_token')` in DevTools. |
| Invoice PDF errors with "Class Mpdf not found" | Install via `composer require mpdf/mpdf` — falls back to printable HTML otherwise. |
| GRN evidence upload fails | Raise `upload_max_filesize` / `post_max_size`. Verify `uploads/` folder is writable. |
| GSTIN format error | Must be exactly 15 chars: `[2-digit state][5 letters PAN][4 digits][1 letter][1 alphanumeric]Z[1 alphanumeric]`. |
| State code wrong for inter-state vs intra-state | Make sure `org_state_code` in settings matches your registered state. |

---

Built with care by Aether's craftsmen, for the foundation's bookkeeping.
