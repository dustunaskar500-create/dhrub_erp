# Aether v2 — PRD

## Original Problem Statement
Design and develop **Aether** — an advanced, self-sufficient AI brain integrated with the Dhrub Foundation ERP. Must:
- Run with **zero external API dependency** (fully on-premise)
- **Adapt** to any structural/logical change in the ERP (schema, modules, business rules)
- Detect **errors / inconsistencies** and self-heal where safe
- **Learn** from interactions and improve over time
- Provide **both a Command-Centre dashboard AND a floating panel** so users can converse, run reports, and approve writes from any ERP screen
- Match existing ERP theme (emerald/slate/white)
- **Auth-gated** (panel only visible when logged in)
- **Dashboard restricted to super_admin**, others use the floating panel
- **Capable of executing manpower-level tasks** through chat-driven planners
- **Auto-receipt**: PDF + thank-you SMS to donors on donation approval (one-click)
- **Module reports** for each ERP module
- **Read attachments** to add gallery images
- **Suggest writing** for image captions / blog posts

## Architecture
- **Self-contained PHP module** at `/aetherV2/` — drop-in for Hostinger/cPanel
- MySQL (existing ERP DB extended with `aether_*` tables only)
- Rule-based + TF-IDF + regex NLP, knowledge-graph entity linking
- mPDF (vendor-shipped, 95 MB)
- SMTP (raw socket, supports attachments) + Fast2SMS for notifications/receipts
- Background heartbeat (`php heartbeat.php --once` for cron, daemon-mode for VPS)

## User Personas + Permissions
| Role        | Chat | Reports | Receipts | Dashboard | Schema sync | Self-heal | Write planners |
|-------------|------|---------|----------|-----------|-------------|-----------|----------------|
| super_admin | ✓    | ✓       | ✓        | ✓         | ✓           | ✓         | ALL            |
| admin       | ✓    | ✓       | ✓        | —         | ✓           | ✓         | most           |
| manager     | ✓    | ✓       | ✓        | —         | —           | —         | donations, expenses, inventory, programs, volunteers, msg |
| accountant  | ✓    | ✓       | ✓        | —         | —           | —         | donations, expenses, msg |
| hr          | ✓    | ✓       | ✓        | —         | —           | —         | salary, volunteers |
| editor      | ✓    | ✓       | ✓        | —         | —           | —         | donors, donations, blog, gallery, msg |
| viewer      | ✓    | ✓       | view     | —         | —           | —         | none           |

## Features (cumulative across iterations)

### Iteration 1: Core (97% backend / 100% frontend)
- 22 API actions, 9 `aether_*` tables, schema watcher, knowledge graph, NLP, reasoner, error monitor, learning engine, audit log, heartbeat, dashboard, floating panel.

### Iteration 2: Self-contained + visual schema diff (100% / 100%)
- Moved everything to `/aetherV2/` standalone (own JWT decoder + DB class).
- Added schema-diff visual viewer, light theme matching ERP, role-gated `self_heal`.
- mPDF receipts (with `tempDir` fix) + SMTP notifier + Fast2SMS structure.

### Iteration 3: Auto-receipt + 8 new write planners + module reports + auth gate + new logo (100% backend / 100% frontend after fixes)
- **Auto-receipt**: donation plans auto-fire `AetherNotifier::sendDonationReceipt()` on approve → PDF email + Fast2SMS thank-you. Failure reasons logged to audit.
- **8 new write planners**: create_donor, create_volunteer, approve_expense, adjust_inventory, add_inventory_item, create_program, create_blog_post, send_message (custom email/SMS).
- **Module reports** (8 modules: donations, expenses, hr, inventory, programs, volunteers, cms, audit) — text + KPI cards.
- **Image upload** (`upload_image` action) with gallery row insert + `suggest_caption` for descriptions/alt-text + `suggest_blog` scaffolding.
- **New logo**: SVG with concentric arcs (perceive · reason · act layers), orbital nodes, breathing animation.
- **Auth gate**: panel.js doesn't inject launcher until JWT present; auto-removes on logout via `storage` event.
- **Dashboard role-gated to super_admin only**: friendly overlay + "SUPER ADMIN ONLY" badge, with link back to ERP.
- **Color-coded UI**: KPIs by category (donations=emerald, expenses=amber, hr=blue, inventory=cyan, cms=violet), highlighted ₹ amounts in chat (`.amount` class with primary tint).
- **Reports tab** in floating panel with 8 module buttons.
- **Attachment uploader** in panel: paperclip → file picker → preview → upload + caption suggestions.
- **Test reporting fixes**: blog_posts category='story', inventory_items category='other', adjust_inventory NLP regex tightened.

## Files in `/app/aetherV2/`
```
aetherV2/
├── api/
│   ├── aether.php          (24 actions)
│   ├── bootstrap.php       (self-contained AetherJWT + AetherDB)
│   ├── config.php          (manual .env parser)
│   ├── migrate.php         (9 aether_* tables)
│   ├── audit-log.php       (auto-dispatches notifier)
│   ├── notifier.php        (SMTP raw socket + attachments + Fast2SMS + sendDonationReceipt)
│   ├── pdf-receipt.php     (mPDF receipts + payslips + renderReceiptString)
│   ├── module-reports.php  (8 modules)
│   ├── schema-watcher.php
│   ├── knowledge-graph.php
│   ├── nlp-engine.php      (with regex overrides for new intents)
│   ├── reasoner.php        (13 write planners + auto-receipt)
│   ├── error-monitor.php
│   ├── learning-engine.php
│   ├── heartbeat.php       (--once for cron)
│   └── .htaccess
├── vendor/                 (mPDF pre-installed)
├── dashboard.php           (super_admin only, color-coded)
├── panel.js                (auth-gated, attachment uploader, Reports tab)
├── style.css               (light theme + new SVG logo + color hierarchy)
├── logo.svg                (Aether mark)
├── .env / .env.example
├── INSTALL.md              (Hostinger guide with SMTP + cron)
├── ERP_FILES_TO_CHANGE.md  (one ERP file edit)
└── README.md
```

## Test results
- iteration_1: 97% backend, 100% frontend
- iteration_2: 100% / 100%
- iteration_3: 93% backend (3 minor planner bugs) → fixed → verified 100% via direct curl tests
- iteration_4: 100% backend (21/21 new tests) / 100% frontend (all 6 dashboard tabs + assign modal + reports hub verified)
- iteration_5: 100% backend (31/31: 18 iter5 + 13 extra) / 100% frontend (7 tabs + KPI drill-down + compliance + custom dates + floating import panel)

## ERP changes required (still only 1 file)
- **`app.html`** — add `<script src="/aetherV2/panel.js" defer></script>` before `</body>`
- Hostinger production path: **`public_html/dhrub_erp/aetherV2/`** (the `aetherV2/` folder lives inside your existing dhrub_erp web root).

## Iteration 5 (Apr 28 2026) — RBAC + Compliance + KPI drill-down + Custom dates
- **Role-Based Access Control** (`rbac.php`, NEW): per-role module access + field redaction. super_admin = full; admin = full data; manager = ops without HR salaries / donor PII; accountant = donations + expenses (incl donor contacts) but no salaries; hr = HR + volunteers, donations only as counts; editor = donor names + CMS + gallery, no financials; viewer = aggregate counts only. Applied to chat list endpoints (donations, donors, expenses, employees), kpi_details, compliance_report, csv_import.
- **Indian govt compliance audit reports** (`compliance.php`, NEW): 5 statutory sections — **80G** (donor + receipt register, cash >₹2K flagged, PAN missing for receipts ≥₹10K), **12A** (income & expenditure with 85% application rule), **FCRA** (foreign contribution Form FC-4 with Indian fiscal quarters Apr–Mar), **Form 10B** (auditor's report with checklist), **CSR** (Sec 135 corporate donations) + Combined pack + Overview snapshot. Custom date range required. CSV export wired.
- **Clickable KPI drill-down** (`kpi-details.php`, NEW): every KPI card on Overview now opens a modal with full record table + per-method/category breakdown + Export CSV (super_admin/admin only — RBAC enforced).
- **Custom date ranges**: Reports tab gets new "Custom range…" period that reveals date-pickers, plus FY 2025-26 / FY 2024-25 presets that auto-fill 1 Apr → 31 Mar.
- **Compliance tab** with section dropdown (7 sections), date-pickers, Quick FY selector, Build, Export CSV, Print / Save PDF.
- **Floating panel "Import" tab** for super_admin: 7 module rows (Donors / Donations / Expenses / Employees / Volunteers / Inventory / Programs), each with Sample download + Upload buttons. Role-gated visually + at API. Banner explains what's allowed for the current role.
- **Module reports with custom dates**: `module_report` and `report_export` now accept `from`/`to` (YYYY-MM-DD) and label the period accordingly. CSV export includes role tag in header.
- **CSV importer hardening**: `insertGeneric()` now filters column list against `information_schema` so extra/unknown columns in the CSV are silently dropped (was throwing SQLSTATE before).
- **Hostinger paths updated**: INSTALL.md + ERP_FILES_TO_CHANGE.md now reference `public_html/dhrub_erp/aetherV2/`. Cron snippet: `*/2 * * * * php /home/uXXXXXXXX/public_html/dhrub_erp/aetherV2/api/heartbeat.php --once`.
- **New backend endpoints**: `rbac_info`, `kpi_details`, `compliance_report`, `compliance_export`. All RBAC-aware.
- **Bug fix**: panel.js Tasks tab now actually loads (`loadTasks()`); paperclip detects CSV vs image; "Tasks" badge in dashboard tabs reflects live count.
- **Container reset recovery**: rebuilt Apache + MariaDB + PHP toolchain; restored DB from `/app/erp_workspace/dhrub_erp.sql`. All non-admin user passwords reset to `admin123`.

## Iteration 4 (Apr 28 2026) — Phase 1 complete + dashboard 2.0
- **Multi-turn slot filling** (`reasoner.php` + `pending-intents.php`): `record_donation`, `create_donor`, `create_expense`, `update_salary`, `add_inventory_item`, `adjust_inventory`, `create_program`, `create_blog_post`, `send_message`, `approve_expense`, `impact_report`, `csv_import` — Aether now asks follow-up questions when info is missing.
- **Bulk CSV importer** (`csv-importer.php`): per-module sample CSVs (donors, donations, expenses, employees, volunteers, inventory, programs); preview+execute pipeline with unknown-column filtering and validation; integrated into floating panel via paperclip.
- **Year-end Impact Reports** (`impact-report.php`): personalised one-page PDF per donor + email + Fast2SMS dispatch as a single plan.
- **Donation Reminders** (`reminders.php`): 30/60/90+ inactivity buckets with tone-appropriate emails; 90+ cohort escalates to admin.
- **My Tasks** (`my-tasks.php`): role-aware task list (super_admin sees schema diffs, critical issues, lapsed donors; accountant/manager sees pending expenses; HR sees missing contact info; editor sees blog drafts/gallery captions; manager sees low stock).
- **Task Assignments** (`task-assignments.php`, NEW): super_admin can re-assign any pending plan to any user with a note; assigned users see plans in their floating-panel Tasks tab.
- **Reports History + Export** (`reports-history.php`, NEW): timeline of every report Aether has generated (impact reports, reminders, audit-derived module reports); CSV export for all 8 modules.
- **Dashboard 2.0** (rewritten): six tabs — Overview, Pending Tasks (super-admin all-user view + filters + approve/reject/assign), Reports (live module preview + export + history), Schema Diff, Knowledge Graph (entity search), Audit Trail (filterable, exportable). Assign-task modal, dynamic period/module dropdowns, print-to-PDF report view.
- **Panel.js fixes**: `loadTasks()` for the Tasks tab; CSV detection in attachment handler; `renderCsvPreview()` flow; "Assigned to me" rendering inside Tasks tab.
- **New backend endpoints**: `my_tasks`, `all_pending_plans`, `assign_plan`, `assigned_to_me`, `users_list`, `reports_history`, `report_export`, `csv_import_preview`, `csv_import_execute`, `csv_template`, `reminders_scan`.
- **New DB tables**: `aether_pending_intents`, `aether_csv_imports`, `aether_task_assignments`.

## Backlog
- P2 — Force-directed knowledge-graph viewer (entity-relationship visualisation)
- P2 — Customisable health-check rules from the dashboard UI
- P3 — Anomaly detection on time-series (donation drops, expense spikes)
- P3 — Vision-based caption suggestions (would need local Ollama+LLaVA — out of scope)
- P3 — Multi-language NLP exemplars
- P3 — Refactor `reasoner.php` (>1100 LOC) into per-intent planner classes
