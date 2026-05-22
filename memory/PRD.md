# Aether V3 + ERP — Product Requirements Document

## Original problem statement
Design and develop an advanced, self-sufficient AI system named **Aether** integrated with a PHP/MySQL ERP ecosystem. Aether V3 is a hybrid AI (local rule-based engine + Claude Sonnet 4.5 fallback) featuring a Classic British Butler persona. It includes a Command Centre dashboard (super-admin), a standalone UI (`chat.php`), and a resizable floating panel. Capabilities include multi-turn data collection, bulk CSV import, Indian compliance reports (80G, FCRA), task assignment, RBAC, and voice STT/TTS interaction.

## Latest user request (Feb 2026)
> "enhance both aether & erp system, also include a stock tracking system in erp according to general standard stock receiving process including receiving docs, photos & video evidences upload for & future shortage damage excess profit & loss tracking, update proper tax invoice & bill generation process for later GST related accounting work"

## What has been built

### Aether V3 core (previous sessions)
- Hybrid AI router (local rules + Claude Sonnet 4.5 fallback via Emergent LLM key)
- Standalone Aether UI at `/aetherV2/chat.php` with inline login, voice STT/TTS, mobile responsive
- Command Centre dashboard with 6 tabs and KPI drill-downs (`/aetherV2/dashboard.php`)
- Floating panel for ERP integration with resize + voice toggle (`panel.js`)
- ERP race-condition fixes in `app.html.production` + `erp-updates/.htaccess`
- Indian Compliance reports (80G/12A/FCRA/CSR/Form 10B)
- RBAC across 6 roles, audit log, chat memory, donation reminders

### Stock Tracking + GST Module (this session) — NEW
**Database** (additive migration at `/app/aetherV2/erp/migrations/001_stock_gst.sql`):
- Extended `inventory_items` with `sku, hsn_code, gst_rate, cost_price, sale_price, barcode, reorder_qty, image_path`
- New tables: `erp_vendors`, `erp_purchase_orders`, `erp_po_items`, `erp_grns`, `erp_grn_items`, `erp_grn_attachments`, `erp_stock_adjustments`, `erp_tax_invoices`, `erp_tax_invoice_items`, `erp_invoice_payments`, `erp_state_codes` (39 Indian states), `erp_doc_counters`

**Backend** (`/app/aetherV2/erp/api/`):
- `router.php` — unified dispatcher (`?action=stock_*|vendor_*|po_*|grn_*|invoice_*|ref_*|dash_*|seed_*`)
- `common.php` — auth + helpers: `erp_next_doc_no()`, `erp_gstin_state_code()`, `erp_save_upload()`, `erp_number_to_words()` (Indian crore/lakh)
- `stock.php`, `vendors.php`, `purchase.php`, `grn.php`, `invoice.php`, `ref.php`, `dashboard.php`, `seed.php`

**Frontend** (`/app/aetherV2/erp/`):
- `index.php` — SPA shell
- `static/erp.js` — vanilla-JS hash-routed SPA (Dashboard, Stock, Vendors, GRN, Adjustments, Invoices, Reports)
- `static/erp.css` — dark theme matching Aether aesthetic (emerald + violet), responsive

**Aether intelligence enhancements** (`/app/aetherV2/api/`):
- `reasoner.php` — added `erpQuickAnswer()` method recognising 6 new ERP intents:
  - "stock value / inventory worth"
  - "low stock / running low"
  - "outstanding invoices / receivables"
  - "GST collected / tax paid / CGST/SGST/IGST"
  - "damages / shortages / losses (last 90 days)"
  - "pending GRN / goods receipts"
  - "vendor count / suppliers"
- `persona.php` — added `stock_module` capability so LLM is aware of the new module
- `chat.php` — added "Stock & GST" link in topbar

### Key features delivered
1. **Vendor management** with GSTIN format validation, state code auto-derivation, banking details
2. **Goods Receipt (GRN)** — standard receiving workflow with:
   - PO-linked or direct receipt
   - Supplier invoice + vehicle + driver + gate pass tracking
   - Multi-line items with ordered/received/damaged/short/excess qty
   - **Photo & video evidence uploads** (up to 64 MB) via drag-drop or click
   - **Enforced**: cannot post a GRN with discrepancy unless ≥1 attachment exists
   - Auto-creates damage/shortage/excess adjustments on posting
   - Updates inventory_items.quantity automatically
3. **Stock adjustments** for damage/shortage/excess/wastage/loss/theft/found/return — with evidence upload, P&L value tracking, approval workflow
4. **GST tax invoice generation**:
   - Tax Invoice, Bill of Supply, Credit/Debit Note, Proforma
   - Automatic CGST+SGST split (intra-state) vs IGST (inter-state) based on seller/buyer state codes
   - HSN code on every line, configurable GST rate per item
   - Round-off + Indian "Amount in words" (crore/lakh)
   - Invoice number format: `INV/2025-26/0001` (FY-scoped sequential)
   - **PDF generation via mPDF** — A4, header with org GSTIN/PAN/bank, line-item table with IGST or CGST/SGST columns, amount in words, signature blocks, footer
   - Payment recording with auto status (unpaid/partial/paid)
5. **GST Reports (GSTR-1 Table-12 style)**:
   - HSN-wise tax summary
   - B2B / B2C breakdown
   - Stock P&L breakdown (damage/shortage/etc.)
   - CSV export
6. **Document numbering** — atomic FY-scoped counters (INV-FY-NNNN, GRN-FY-NNNN, etc.)
7. **Dashboard KPIs** — stock value, low stock count, revenue (30d), tax collected, GRN discrepancies, realised stock losses

### File map
```
/app/aetherV2/erp/
├── index.php                       (SPA shell, login-gated)
├── api/
│   ├── router.php                  (dispatcher)
│   ├── common.php                  (helpers, auth)
│   ├── stock.php                   (items, movements, adjustments, P&L)
│   ├── vendors.php                 (vendor CRUD with GSTIN validation)
│   ├── purchase.php                (purchase orders)
│   ├── grn.php                     (goods receipts + multipart uploads)
│   ├── invoice.php                 (GST tax invoices + PDF via mPDF)
│   ├── ref.php                     (states, org settings)
│   ├── dashboard.php               (KPIs)
│   └── seed.php                    (demo data: 3 vendors, 8 items)
├── static/
│   ├── erp.css                     (dark theme, responsive)
│   └── erp.js                      (vanilla JS, hash routing)
└── migrations/
    └── 001_stock_gst.sql           (additive schema)

/app/uploads/erp/                   (file storage, mode 0775, owner www-data)
├── grn/{grn_id}/...                (photos/videos)
├── adjustments/...
├── invoices/...                    (generated PDFs)
└── items/...                       (item images)
```

### Authorisation matrix (Stock + GST module)
| Action group       | super_admin | admin | manager | accountant | editor | viewer |
|--------------------|:-:|:-:|:-:|:-:|:-:|:-:|
| List/read          | ✓ | ✓ | ✓ | ✓ | ✗ | ✓ |
| Save vendor        | ✓ | ✓ | ✓ | ✓ | ✗ | ✗ |
| Save item          | ✓ | ✓ | ✓ | ✗ | ✗ | ✗ |
| Save GRN draft     | ✓ | ✓ | ✓ | ✓ | ✗ | ✗ |
| Post GRN           | ✓ | ✓ | ✓ | ✗ | ✗ | ✗ |
| Adjust stock       | ✓ | ✓ | ✓ | ✓ | ✗ | ✗ |
| Approve adjustment | ✓ | ✓ | ✓ | ✗ | ✗ | ✗ |
| Save/Issue invoice | ✓ | ✓ | ✓ | ✓ | ✗ | ✗ |
| Cancel invoice     | ✓ | ✓ | ✗ | ✗ | ✗ | ✗ |
| Record payment     | ✓ | ✓ | ✗ | ✓ | ✗ | ✗ |

## Testing status
- `/app/test_reports/iteration_6.json` — **29/29 backend tests pass**, frontend smoke (login + 6 routes + mobile) verified.
- One SQL reserved-word bug (`lines` alias in invoice_gst_summary) auto-fixed by testing agent — verified working with backticks.

## Backlog / Future

### P1 — ERP host integration
- Update remaining ERP source code (`_dhrub_erp`) to use the new Stock & GST APIs directly (replace any older ad-hoc inventory pages).
- Push final ZIP to user's Hostinger when requested.

### P2 — Polish & extensions
- e-Invoice IRN integration (placeholder fields exist: `irn`, `ack_no`, `ack_date`, `qr_payload`)
- Purchase order PDF (template ready, just needs the PHP page)
- Customer master (currently buyer details typed per-invoice)
- Multi-warehouse / location support (column exists; UI not exposed)
- TDS/TCS support (not yet)
- Recurring invoices
- GSTR-3B export (currently only GSTR-1 HSN summary done)
- Customer/vendor portal (read-only public links)
- Webhook events to Aether on GRN post / invoice issued (so the butler can proactively notify the user)
- SMTP/Fast2SMS credentials (still mocked/skipped per earlier user direction)

### P3 — Future
- Barcode scanning camera (PWA)
- Tally / Zoho Books export
- Audit trail differential view (already log all actions to aether_audit_log; just needs a viewer)
- e-Way bill generation
- Composition scheme variant
