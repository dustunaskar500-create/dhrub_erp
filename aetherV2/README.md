# Aether v2 — Autonomous ERP Brain

Self-sufficient AI layer for any PHP/MySQL ERP. Pure-PHP, fully on-premise, **zero external API calls**.

## What it does
- 🧠 **Conversational reasoning** — local rule-based + TF-IDF NLP, no OpenAI/Anthropic.
- 🔁 **Adaptive** — auto-detects schema changes (new tables, columns, FK changes) and instantly knows them.
- ⚡ **Action plans** — write requests get a preview + approval step (no silent writes).
- 🩺 **Self-healing** — 10 built-in checks (orphan FKs, salary mismatches, low stock, etc.) with auto-fix.
- 📜 **Audit trail** — every plan, fix, schema change, decision is logged.
- 📈 **Learning** — every chat improves intent recognition over time.
- 📨 **Alerts** — SMTP + Fast2SMS for high-severity events.
- 🧾 **PDF receipts & payslips** via mPDF.

## Quick start

See **[INSTALL.md](INSTALL.md)** — copy folder, edit `.env`, add one `<script>` tag.

## Architecture

```
Browser (your ERP)
   │
   │ Bearer JWT (from your ERP's existing /api/auth/login)
   ▼
aetherV2/api/aether.php       ← unified router
   ├─ bootstrap.php            ← JWT decode + DB
   ├─ migrate.php              ← auto-creates aether_* tables
   ├─ schema-watcher.php       ← snapshot + diff
   ├─ knowledge-graph.php      ← internal model
   ├─ nlp-engine.php           ← intent + entities
   ├─ reasoner.php             ← read handlers + write planners
   ├─ error-monitor.php        ← health checks
   ├─ learning-engine.php      ← weight reinforcement
   ├─ audit-log.php            ← append-only events
   ├─ notifier.php             ← SMTP + Fast2SMS
   └─ pdf-receipt.php          ← mPDF receipts
   │
   └─ heartbeat.php            ← background CLI (cron-friendly)
```

## API surface (`POST /aetherV2/api/aether.php`)

| `action`            | What it does                                       | Auth        |
| ------------------- | -------------------------------------------------- | ----------- |
| `chat`              | Reason about a natural-language message            | any user    |
| `dashboard`         | KPIs + health + audit + KG + schema bundle         | any user    |
| `schema_sync`       | Snapshot + diff vs last + rebuild knowledge graph  | admin       |
| `schema_changes`    | Recent detected schema changes                     | any user    |
| `schema_diff`       | Two snapshots + categorised changes (for UI)       | any user    |
| `knowledge_summary` | Tables/columns/relationships summary               | any user    |
| `knowledge_search`  | Free-text search over the knowledge graph          | any user    |
| `describe`          | Show columns of a given table                      | any user    |
| `health`            | Run all health checks (no auto-heal)               | any user    |
| `self_heal`         | Run + apply auto-heals                             | admin       |
| `issues`            | List open / healed / closed issues                 | any user    |
| `audit`             | Recent audit events                                | any user    |
| `list_plans`        | Pending / executed action plans                    | any user    |
| `approve_plan`      | Execute a proposed plan                            | plan owner  |
| `reject_plan`       | Reject a proposed plan                             | plan owner  |
| `feedback`          | +1/-1/0 on the last interaction                    | any user    |
| `learning_stats`    | Aggregate learning metrics                         | any user    |
| `download_receipt`  | Stream PDF donation receipt                        | any user    |
| `download_payslip`  | Stream PDF payslip                                 | admin/hr    |
| `tick`              | One-shot background pass                           | any user    |
| `identity`          | Who am I                                           | any user    |

## License
Internal use — Dhrub Foundation. Built by Aether.
