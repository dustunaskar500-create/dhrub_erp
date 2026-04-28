# Aether v2 — PRD

## Original Problem Statement
Design and develop **Aether** — an advanced, self-sufficient AI brain integrated with the Dhrub Foundation ERP. Must:
- Run with **zero external API dependency** (fully on-premise)
- **Adapt** to any structural/logical change in the ERP (schema, modules, business rules)
- Detect **errors / inconsistencies** and self-heal where safe
- **Learn** from interactions and improve over time
- Provide **both a Command-Centre dashboard AND a floating panel** so users can converse, run reports, and approve writes from any ERP screen
- Match existing ERP theme (emerald/slate/white), no Emergent references in code

## Architecture (selected by user)
- **Self-contained PHP module** under `/aetherV2/` — drop-in for Hostinger/cPanel
- MySQL (existing ERP DB extended with `aether_*` tables only)
- Rule-based intent parser + TF-IDF cosine similarity + regex patterns + KG-driven entity linking
- mPDF (vendor-shipped) for donation receipts + payslips
- Background heartbeat process (`php heartbeat.php` — supports `--once` for cron)

## User Personas
1. **Super-admin / admin** — full Aether access: schema sync, self-heal, view audit, approve all plans
2. **Manager / accountant / HR / editor** — chat, read-only views, role-appropriate write plans (e.g. only HR can plan salary updates)
3. **Viewer** — read-only chat assistance

## Core Modules in `/app/aetherV2/`

```
aetherV2/
├── api/
│   ├── aether.php          ← unified router (22 actions)
│   ├── bootstrap.php       ← self-contained AetherJWT + AetherDB
│   ├── config.php          ← .env loader (manual parser, handles symbols)
│   ├── migrate.php         ← auto-creates 9 aether_* tables
│   ├── audit-log.php       ← append-only + dispatches AetherNotifier
│   ├── notifier.php        ← SMTP (raw socket) + Fast2SMS (HTTP GET)
│   ├── pdf-receipt.php     ← donation receipts + payslips via mPDF
│   ├── schema-watcher.php  ← snapshot, diff, FK/column tracking
│   ├── knowledge-graph.php ← entity semantics + module mapping
│   ├── nlp-engine.php      ← tokenize, vectorize, TF-IDF, regex, entities
│   ├── reasoner.php        ← read intents + write planners
│   ├── error-monitor.php   ← 10 health checks + healers
│   ├── learning-engine.php ← feedback, weight reinforcement
│   ├── heartbeat.php       ← CLI background (--once support)
│   └── .htaccess           ← Authorization passthrough
├── vendor/                 ← composer-installed mPDF (95 MB)
├── dashboard.php           ← Command Centre (light theme, schema-diff viewer)
├── panel.js                ← drop-in floating panel
├── style.css               ← emerald + slate light theme
├── .env / .env.example
├── INSTALL.md              ← Hostinger deployment guide
├── ERP_FILES_TO_CHANGE.md  ← lists which ERP files to edit (just one!)
├── README.md
└── composer.json/lock
```

## Tested & Verified

### Iteration 1 (legacy /aether/api/v2): 97% backend, 100% frontend
### Iteration 2 (new self-contained /aetherV2): 100% backend (34/34), 100% frontend

- ✓ All 22 API actions return HTTP 200 with valid JWT
- ✓ Self-contained: works even when /app/includes/auth.php is renamed
- ✓ Adaptive schema awareness: detects CREATE/ALTER/DROP TABLE, ADD/MODIFY/DROP COLUMN with correct impact level
- ✓ Visual schema-diff viewer: snapshots side-by-side + 5 categorised change cards
- ✓ Knowledge graph rebuilds automatically (49 tables, 488 columns, 26 relationships)
- ✓ Action plan workflow: propose → preview → approve | reject (also via natural language)
- ✓ Self-heal repairs salary mismatches; orphan-FK / negative-value detectors all firing
- ✓ Audit log captures every plan, fix, schema change, learning event
- ✓ **Notifier**: SMTP + Fast2SMS for high-severity events (threshold-driven, no-ops cleanly without creds)
- ✓ **PDF receipts**: Content-Type application/pdf, magic bytes verified, 31 KB receipt / 11 KB payslip
- ✓ Role-based access: accountant gets 403 on `schema_sync` and `self_heal`
- ✓ Forecast bug fixed (now produces "2026-05")
- ✓ Light-theme UI matching ERP: white cards, emerald primary (#10b981), slate borders, Outfit/Manrope fonts
- ✓ Floating panel on every ERP page; dashboard auth-gated

## ERP changes required (only 1 file)
- **`app.html`** — add `<script src="/aetherV2/panel.js" defer></script>` before `</body>`
- (Optional) hide legacy v1 widget via CSS

## Backlog (not blocking)
- P2 — Customisable health-check rules through UI
- P2 — Export audit log as CSV / PDF
- P2 — Multi-language NLP exemplars (currently English-only intents)
- P2 — Force-directed knowledge-graph viewer
- P3 — Anomaly detection on time-series (donation spikes, expense surges)
- P3 — Plain-text email fallback when SMTP fails
- P3 — Ollama/llama.cpp adapter (still local) for higher-quality long-form NLP

## Pre-existing ERP issues (out of scope)
- Recharts `width(-1)/height(-1)` console warning on `/` (Donation Trends chart)
