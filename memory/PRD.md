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

## ERP changes required (still only 1 file)
- **`app.html`** — add `<script src="/aetherV2/panel.js" defer></script>` before `</body>`

## Backlog
- P2 — Multi-turn data collection (e.g. Aether asks follow-up questions when amount missing)
- P2 — Customisable health-check rules from UI
- P2 — Bulk import CSV via chat
- P2 — Force-directed knowledge-graph viewer
- P3 — Anomaly detection on time-series
- P3 — Vision-based caption suggestions (would need local Ollama+LLaVA — out of scope)
- P3 — Multi-language NLP exemplars
