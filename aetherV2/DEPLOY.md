# Aether v2 — Complete Deployment Guide
*Last updated: May 2026 · Hostinger / cPanel / any LAMP host*

This is the **single source of truth** for deploying Aether to your production
ERP. Follow these steps once and Aether will live alongside `dhrub_erp` on
your server with full features:

- 🎩 **British butler persona** powered by Claude Sonnet 4.5
- 💬 **Two entry points**: embedded in ERP **and** standalone at `/aetherV2/chat.php`
- 🎙️ **Voice in + out** (Web Speech API — no extra dependencies)
- 📊 **7-tab Command Centre** dashboard with KPI drill-down, Indian compliance reports, task assignment
- 🗂️ **Resizable** floating panel
- 🔐 **Strict RBAC** — Aether refuses to share data above a user's role
- ⏱️ **Heartbeat cron** — schema drift detection, reminders, self-healing

---

## 0 · What you need before you start

> 💡 **Important fact about the database**: Aether shares the **same MariaDB
> database** as your ERP (`DB_NAME=u135884328_dhrub_erp` or whatever you've
> named it). It does NOT need a separate DB. Aether's own state lives in
> tables prefixed `aether_*` (audit log, knowledge graph, action plans,
> chat memory, etc.) and it reads/writes your existing `donations`,
> `donors`, `expenses`, `employees`, etc. tables directly. This is why
> reports, KPI drill-downs, and compliance reports are always **live** —
> there's no sync layer between Aether and the ERP.

| Requirement | How to verify |
|---|---|
| PHP 8.0+ with `pdo_mysql`, `mbstring`, `curl`, `gd`, `xml`, `openssl` | SSH: `php -v && php -m` |
| MariaDB / MySQL 5.7+ | hPanel → Databases |
| SSH access to your host | hPanel → Advanced → SSH Access |
| Your `dhrub_erp` already running with users + login | Open the ERP, sign in as super_admin |
| The `aetherV2/` folder you'll upload | Already on your laptop or in the zip |

---

## 1 · Upload `aetherV2/` to the right place

Aether lives **inside** your ERP folder so it shares the same login session.
The path depends on whether you use a subdomain or a sub-folder:

| Layout | Aether's location |
|---|---|
| `erp.dhrubfoundation.org` (subdomain) | `/home/uXXXXXXXX/domains/erp.dhrubfoundation.org/public_html/aetherV2/` |
| `dhrubfoundation.org/dhrub_erp/` (sub-folder) | `/home/uXXXXXXXX/public_html/dhrub_erp/aetherV2/` |

In **hPanel → File Manager**, upload the entire `aetherV2/` folder via either:
- "Upload file" (one ZIP) → "Extract" once uploaded, OR
- "Upload folder" if File Manager supports it

> 💡 **Tip**: After uploading, click the gear icon ⚙️ → **Show hidden files**
> so the `.env`, `.htaccess`, and `.user.ini` files become visible.

---

## 2 · Configure `.env`

Inside `aetherV2/` create a file called exactly `.env` (with the leading dot)
and paste this — **replace placeholders with your real values**:

```env
# ─── ERP database (use Hostinger's values) ───
DB_HOST=localhost
DB_NAME=u135884328_dhrub_erp
DB_USER=u135884328_dhrub
DB_PASS=your-mysql-password
DB_CHARSET=utf8mb4

# ─── JWT (MUST match what dhrub_erp uses) ───
# Find this in: public_html/dhrub_erp/config/database.php → JWT_SECRET
JWT_SECRET=dhrub-foundation-erp-jwt-secret-2024

# ─── Aether AI brain (Claude Sonnet 4.5 via Emergent universal key) ───
EMERGENT_LLM_KEY=sk-emergent-a7082BcF3E370608d8
AETHER_LLM_ENDPOINT=https://integrations.emergentagent.com/llm/chat/completions
AETHER_LLM_MODEL=claude-sonnet-4-6
AETHER_LLM_FALLBACK_MODEL=claude-haiku-4-5-20251001
AETHER_LLM_THRESHOLD=0.55
AETHER_LLM_MAX_TOKENS=1200

# ─── Notifications (leave empty to disable until you have keys) ───
AETHER_NOTIFY_EMAIL=
AETHER_NOTIFY_SMS=
SMTP_HOST=smtp.hostinger.com
SMTP_PORT=587
SMTP_USERNAME=
SMTP_PASSWORD=
SMTP_FROM_NAME=Dhrub Foundation
SMTP_FROM_EMAIL=
FAST2SMS_API_KEY=
FAST2SMS_SENDER=AETHR

TIMEZONE=Asia/Kolkata
```

Then SSH into your host and run:

```bash
cd ~/domains/erp.dhrubfoundation.org/public_html/aetherV2   # or wherever you uploaded
chmod 644 .env
```

---

## 3 · Run migrations (one-time)

Open this URL in your browser **while signed in as super_admin**:

```
https://erp.dhrubfoundation.org/aetherV2/api/migrate.php
```

You should see:

```
Aether v2 migrations done. New: aether_schema_snapshots, aether_schema_changes,
aether_knowledge, aether_health_checks, aether_audit_log, aether_issues,
aether_learning, aether_intent_weights, aether_action_plans,
aether_pending_intents, aether_task_assignments, aether_chat_memory,
aether_csv_imports
```

> ✅ This creates 13 new tables in your existing `dhrub_erp` database. It is
> idempotent — re-running it never breaks anything.

---

## 4 · Add the floating-panel script to your ERP

This is the only file you ever modify outside `aetherV2/`. Open
`public_html/dhrub_erp/app.html` and add **one line** just before `</body>`:

```html
<script src="aetherV2/panel.js" defer></script>
```

> Use the **relative path** (no leading `/`). This works on both subdomain
> and sub-folder mounts without code changes.

Refresh your ERP — you should see a **green Aether button** in the bottom-right
of every page.

---

## 5 · Set up the heartbeat cron

The heartbeat runs every 2 minutes and:
- Re-scans your schema (so if you add a new ERP table, Aether learns it automatically)
- Runs 10 health checks + auto-heal where allowed
- Sweeps lapsed donors and queues reminder plans
- Logs everything to the audit trail

### Step 1 — verify with the doctor

SSH in and run:

```bash
/usr/bin/php /home/uXXXXXXXX/domains/erp.dhrubfoundation.org/public_html/aetherV2/api/cron-doctor.php
```

(Replace `uXXXXXXXX` with your actual user ID, visible in the File Manager URL.)

The doctor prints a coloured report — pass/fail/warn for every prerequisite.
You should see `All systems nominal` at the bottom. If anything fails, fix it
**before** the next step.

### Step 2 — add the cron job

In **hPanel → Advanced → Cron Jobs**:

- **Command**: paste **only this** (Hostinger auto-prepends `/usr/bin/php /home/uXXXXXXXX/`):
  ```
  domains/erp.dhrubfoundation.org/public_html/aetherV2/api/heartbeat.php --once
  ```
- **Schedule**: pick "Every 2 minutes" from **Common Options**

Save. After ~3 minutes, SSH in and verify it's firing:

```bash
tail -10 ~/domains/erp.dhrubfoundation.org/public_html/aetherV2/storage/heartbeat.log
```

You should see entries like:
```
[2026-05-22 11:24:02] Schema changes: 0 — knowledge graph rebuilt
[2026-05-22 11:26:02] Schema changes: 0 — knowledge graph rebuilt
```

---

## 6 · Verify all entry points

### 🅐 Embedded panel (from any ERP page)

1. Open `https://erp.dhrubfoundation.org/` (your ERP)
2. Sign in as any role
3. The green **Aether** button appears bottom-right
4. Click it — you should see *"At your service, **Mr. Lastname**…"*
5. **Resize** by dragging the top-left corner ↖
6. Open **panel options bar** — Voice toggle 🔊, Fullscreen ⛶, Dashboard ↗, Clear 🗑, Close ✕
7. Try a query: *"Brief me on this month's donations"* → Claude streams the reply

### 🅑 Standalone Aether at its own URL

1. Open `https://erp.dhrubfoundation.org/aetherV2/chat.php`
2. If you're NOT signed in to the ERP, you see the auth wall with **"CONFIDENTIAL DATA — ROLE VERIFICATION REQUIRED"**
3. Once signed in, the full ChatGPT-style layout opens with:
   - Sidebar with conversation history (saved per-browser in localStorage)
   - Role-aware suggestion cards on the welcome screen
   - Mic 🎙 for voice input
   - "Voice on/off" toggle for auto-TTS replies
   - Per-message "Speak" button

### 🅒 Command Centre (super_admin only)

`https://erp.dhrubfoundation.org/aetherV2/dashboard.php`

7 tabs: Overview · Pending Tasks · Reports · Compliance (Indian govt audit
sections) · Schema Diff · Knowledge · Audit Trail.

---

## 7 · Role permissions reference

| Role | Sees | Cannot see |
|---|---|---|
| `super_admin` | Everything | (nothing restricted) |
| `admin` | All data | System-level settings (in some places) |
| `manager` | Donations, expenses, programmes, inventory | Salaries, donor PAN/contacts |
| `accountant` | Donations + expenses + donor contacts | Salaries, HR data |
| `hr` | Employees, payroll, volunteers | Donation/expense detail |
| `editor` | Donor names, blog, gallery | Financials, HR |
| `viewer` | Aggregate counts only | All PII, all amounts |

Aether enforces this at three layers: data queries, LLM system prompt, and CSV import permissions.

---

## 8 · Troubleshooting cheat-sheet

| Symptom | Cause | Fix |
|---|---|---|
| Aether button doesn't appear in ERP | `panel.js` path wrong | Make sure `<script src="aetherV2/panel.js"…>` is relative (no leading `/`) |
| Floating panel says "Sign in to ERP first" | JWT not in localStorage | Sign in to ERP, hard refresh |
| Standalone chat.php asks for login | Same as above | Sign in to `/dhrub_erp/` first, then open chat.php |
| Claude replies say "brain is offline" | `EMERGENT_LLM_KEY` empty or wrong | Check `.env`, rerun `cron-doctor.php` |
| Cron not firing | Wrong path | SSH: `find ~ -name heartbeat.php` to get the real path |
| `Could not open input file` | Path mismatch on Hostinger | Path must start with `domains/…` for subdomains |
| Voice/mic doesn't work | Browser doesn't support Web Speech API | Use Chrome/Edge; Safari supports limited; Firefox needs flag |
| Receipt PDF missing | `vendor/` not uploaded | Re-upload the `aetherV2/vendor/` folder (mPDF lives there) |
| All checks marked "warn" | New schema, run health re-evaluate | Click "Self-heal" on the dashboard |

---

## 9 · Day-2 maintenance

- **Logs**: `aetherV2/storage/heartbeat.log` (rotated nightly)
- **Audit trail**: dashboard → Audit Trail tab (filterable, exportable)
- **Add a new ERP table**: Aether detects it automatically on the next heartbeat (2 min). The Knowledge Graph tab shows newly-discovered entities.
- **Update Aether**: re-upload the changed files. `.env` and database survive.
- **Disable Aether without uninstalling**: comment out the `<script src="aetherV2/panel.js">` line in `app.html`.

---

## 10 · Production checklist (sign-off)

Run through this before declaring the deploy "done":

- [ ] `aetherV2/` uploaded to the correct path
- [ ] `.env` filled in with real DB + JWT_SECRET + EMERGENT_LLM_KEY
- [ ] `migrate.php` returned the table list
- [ ] One line added to `app.html`
- [ ] Aether button visible on every ERP page
- [ ] Standalone `/aetherV2/chat.php` opens and shows your name + role
- [ ] `cron-doctor.php` shows **All systems nominal**
- [ ] Cron job saved in hPanel
- [ ] `heartbeat.log` shows entries every 2 minutes
- [ ] Dashboard 7 tabs all open
- [ ] Compliance → 80G report builds for FY 2025-26
- [ ] Voice toggle reads a reply aloud
- [ ] Panel can be resized by dragging the top-left corner

---

**You're done.** The estate is in Aether's care.
