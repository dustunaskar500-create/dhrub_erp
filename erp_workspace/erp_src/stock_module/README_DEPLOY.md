# Dhrub ERP — Stock & GST integration bundle

This bundle replaces / supplements the existing `dhrub_erp/` files on your
Hostinger account. It does **NOT** rebuild your React app — instead it
**injects** Stock & GST features alongside the existing ERP so you keep all
your current Inventory/Donations/Programs/etc. fully intact.

## Hostinger layout (matches what you described)

```
public_html/                            (your erp.dhrubfoundation.org root)
├── app.html                            ← REPLACE with the new one
├── index.php                           ← keep your existing one (unchanged)
├── static/                             ← keep React build files unchanged
├── config/, includes/                  ← keep
├── cms/                                ← keep
├── aether/                             ← Aether subfolder (your existing one)
│   ├── chat.php
│   ├── panel.js                        ← floating brain
│   └── ...
└── stock_module/                       ← NEW — drop this folder in
    ├── index.php
    ├── api/
    ├── static/
    ├── migrations/001_stock_gst.sql
    └── uploads/                        (writable, chmod 0775)
```

## Installation steps

### 1) Upload the new files

Via cPanel File Manager or SFTP, upload:
- `app.html` — replace the existing one in `public_html/`
- The entire `stock_module/` folder — drop into `public_html/`

### 2) Run the database migration (once)

cPanel → phpMyAdmin → select your `dhrub_erp` database → SQL tab → paste and
run the contents of `stock_module/migrations/001_stock_gst.sql`.

The migration is **idempotent** and only ADDS columns/tables — it never
touches existing data.

### 3) Make `stock_module/uploads/` writable

cPanel File Manager → right-click `stock_module/uploads/` → Permissions →
set to **0775** (owner: write, group: read+execute, others: read+execute).

### 4) (Optional) Install mPDF for real PDF invoices

If you have SSH or Git Version Control:
```bash
cd public_html
composer require mpdf/mpdf
```

Without mPDF the module falls back to "print to PDF" via browser — works
fine but no embedded vendor styles.

### 5) Set your organisation's GSTIN

Visit `https://erp.dhrubfoundation.org/stock_module/` once, log in with any
admin user, click the cog → org settings, and fill in:
- `org_gstin` (e.g. `19AABCD1234E1Z5`)
- `org_state` (e.g. `West Bengal`)
- `org_state_code` (e.g. `19`)
- bank details for invoice footer

## What's new in the ERP UI

After the React app loads, you'll automatically see:

| Element | Where | Purpose |
|---|---|---|
| **Theme toggle** (🌙 / ☀️) | top-right corner | Switches FAB + Stock module between dark/light. Persists in browser. |
| **Stock & GST** FAB | bottom-right (green) | One-tap access to the new module |
| **Ask Aether** FAB | bottom-right (purple) | Quick chat with the AI butler |
| **CMS** FAB | bottom-right (dark green) | Your existing CMS link |
| **Sidebar items** (auto-injected) | after "Inventory" | Goods Receipt · Tax Invoices · Stock Adjustments · Reports & GSTR |
| **Auto GRN nudge** | popup modal | When a new inventory IN transaction is recorded, Aether asks "Shall I draft a GRN for this?" with pre-filled item + qty |

## What's new in the Stock module

Visit `/stock_module/` (or click the green FAB):

- **Dashboard** — KPI overview of stock value, GRN discrepancies, sales (30d), tax collected, realised losses
- **Stock Items** — full HSN + GST rate per item, low-stock filter, search
- **Vendors** — GSTIN-validated supplier master with state code auto-detect
- **Goods Receipt (GRN)** — standard receiving workflow with:
  - PO link, supplier invoice/vehicle/driver/gate-pass fields
  - Ordered / Received / Damaged / Short / Excess per line
  - **Photo & video evidence uploads** (drag-drop or click, up to 64 MB)
  - Posting auto-creates damage/shortage adjustments + updates stock
- **Stock Adjustments** — damage / shortage / excess / wastage / loss / theft / found / return
- **Tax Invoices** — Tax Invoice / Bill of Supply / Credit & Debit Notes / Proforma
  - Auto CGST+SGST (intra-state) or IGST (inter-state)
  - HSN code per line, Indian "Amount in words"
  - PDF generation (mPDF) with org GSTIN + bank details
  - Payment recording + status (unpaid / partial / paid)
- **Reports** — 5 reports with full filtering, **CSV + PDF export**, **CSV upload**:
  - Stock (item value, low-stock filter, category filter)
  - Purchase / GRN (date range, vendor, status)
  - Sales (date range, payment status, invoice type)
  - Stock P&L (loss / gain breakdown)
  - GSTR-1 HSN summary (Table-12 ready)
- **CSV Import** — bulk upload stock items & vendors from a CSV template

## Aether integration

Aether's chat already understands your stock data. Try asking:

- "What's our stock value?"
- "Which items are running low?"
- "Outstanding invoices?"
- "How much GST collected this month?"
- "Any damages this week?"

Aether reads the same database tables — no extra config needed.

## Verifying the install

1. Open `https://erp.dhrubfoundation.org/` (or wherever your ERP lives)
2. You should see three FABs (CMS · Stock & GST · Ask Aether) and a theme toggle (top-right)
3. The sidebar should automatically gain "Goods Receipt · Tax Invoices · Stock Adjustments · Reports & GSTR" entries below "Inventory" (give it ~1 second to inject after the React app boots)
4. Click "Stock & GST" → opens the module → log in if needed (re-uses your existing JWT)
5. Record a test inventory IN via the React app → you'll see Aether's "Shall I draft a GRN?" nudge popup

## Files in this bundle

| File | Purpose |
|------|---------|
| `app.html` | NEW — replaces your current `app.html`. Adds theme, FABs, sidebar injection, Aether auto-nudge |
| `stock_module/` | NEW — entire feature module (PHP + vanilla JS + CSS, no build step) |
| `stock_module/migrations/001_stock_gst.sql` | Schema migration |
| `stock_module/INSTALL.md` | Detailed install guide (this one is a quick-start) |

## Need to roll back?

- Restore the previous `app.html` from your cPanel backup
- Drop `stock_module/` (data stays in `dhrub_erp` MySQL but the UI disappears)
- The `erp_*` tables can be dropped via phpMyAdmin if you also want to remove the schema

## Support

Built by Aether's craftsmen with care for the foundation's bookkeeping.
For issues, check the **Activity Log** in your existing ERP — every action in
the stock module logs there too.
