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

## ERP changes required (still only 1 file)
- **`app.html`** — add `<script src="/aetherV2/panel.js" defer></script>` before `</body>`

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
