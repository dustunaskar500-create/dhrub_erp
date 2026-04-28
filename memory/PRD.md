# Aether v2 — PRD

## Original Problem Statement
Design and develop **Aether** — an advanced, self-sufficient AI brain integrated with the Dhrub Foundation ERP. Must:
- Run with **zero external API dependency** (fully on-premise)
- **Adapt** to any structural/logical change in the ERP (schema, modules, business rules)
- Detect **errors / inconsistencies** and self-heal where safe
- **Learn** from interactions and improve over time
- Provide **both a Command-Centre dashboard AND a floating panel** so users can converse, run reports, and approve writes from any ERP screen
- Keep existing ERP theme intact, no Emergent references in code

## Architecture (selected by user)
- Pure PHP module under `/app/aether/`
- MySQL (existing ERP DB `dhrub_erp` extended with `aether_*` tables)
- Rule-based intent parser + TF-IDF cosine similarity + regex patterns + KG-driven entity linking
- Apache 2 + PHP 8.2 + MariaDB 10.11 (in-container)
- Background heartbeat process (`aether-heartbeat`, supervisor-managed) every 120 s

## User Personas
1. **Super-admin / admin** — full Aether access: schema sync, self-heal, view audit, approve all plans
2. **Manager / accountant / HR / editor** — chat, read-only views, role-appropriate write plans (e.g. only HR can plan salary updates)
3. **Viewer** — read-only chat assistance

## Core Modules Implemented (first session, Jan 2026)
- `aether/api/v2/bootstrap.php` — auth, DB, JSON helpers
- `aether/api/v2/migrate.php` — auto-creates 9 `aether_*` tables, idempotent
- `aether/api/v2/schema-watcher.php` — snapshot, diff, FK + column tracking
- `aether/api/v2/knowledge-graph.php` — table/column/relationship semantics, module mapping, synonym auto-gen
- `aether/api/v2/nlp-engine.php` — tokenize, vectorize, TF-IDF cosine, regex overrides, entity extraction (money/date/email/phone/quoted)
- `aether/api/v2/reasoner.php` — read intents (dashboard, top donors, forecast, schema info, audit, etc.) + write planners (donation, expense, salary update) with preview + approval flow
- `aether/api/v2/error-monitor.php` — 10 health checks (orphan FKs, negative values, salary mismatch, low stock, admin presence, duplicates, stale plans, etc.) + self-heal
- `aether/api/v2/learning-engine.php` — feedback recording, weight reinforcement/decay, success-rate stats
- `aether/api/v2/audit-log.php` — append-only event log
- `aether/api/v2/heartbeat.php` — background scheduler (supervisor program)
- `aether/api/v2/aether.php` — unified API router (18 actions)
- `aether/dashboard.php` — Command-Centre UI (KPIs, health, audit, KG modules, schema, learning)
- `aether/v2-panel.js` — floating panel widget (chat + tabs + plan approvals + quick chips)
- `app.html` — ERP shell modified to inject v2 panel and hide v1 widget

## Tested & Verified (testing agent + main agent)
- ✓ All 18 v2 API actions return HTTP 200 with valid JWT
- ✓ Adaptive schema awareness: detects CREATE TABLE / ADD COLUMN / DROP TABLE / DROP COLUMN with correct change_type, object_type, impact level (info/low/medium/high/critical)
- ✓ Knowledge graph rebuilds automatically on schema change (49 tables, 488 columns, 26 relationships from current ERP)
- ✓ Action plan workflow: propose → preview → approve (writes) | reject (no write) | natural language "approve" / "reject"
- ✓ Self-heal repairs salary mismatches; orphan-FK / negative-value / low-stock detectors all firing
- ✓ Audit log captures schema_change, knowledge_rebuild, plan_proposed, plan_executed, plan_rejected, health_run, self_heal, learning_feedback
- ✓ Role-based access: non-admins get 403 on `self_heal` / `schema_sync` (verified with accountant role); chat allowed for all
- ✓ Dashboard renders 8 KPIs, 10 health checks, audit feed, schema fingerprint, learning stats — auth-gated
- ✓ Floating panel: launcher / 3 tabs (Chat / Health / Plans) / quick chips / plan approval cards / feedback thumbs
- ✓ Background heartbeat: every 120 s syncs schema + runs health checks
- ✓ Existing ERP unchanged (legacy v1 widget hidden via CSS, not deleted)

## Backlog (not blocking)
- P1 — Add visual schema diff UI on the dashboard (currently text-based audit row)
- P1 — Plan approval queue email/SMS notification (helpers.php already supports SMTP/Fast2SMS)
- P2 — Customisable health-check rules through UI
- P2 — Export audit log as CSV / PDF
- P2 — Multi-language NLP exemplars (currently English-only intents)
- P2 — Visual KG graph viewer (force-directed layout)
- P3 — Anomaly detection on time-series (donation spikes, expense surges)

## Pre-existing ERP issues (out of scope)
- Recharts width(-1)/height(-1) console warning on `/` (Donation Trends chart) — pre-existing, not Aether-related
