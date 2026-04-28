# Aether v2 — Installation on Hostinger (or any cPanel / shared PHP host)

> **TL;DR** — copy `aetherV2/` into your web root, fill in `.env` (especially **JWT_SECRET** + **SMTP**), add **one line** to your ERP HTML, and Aether is live.

---

## 📦 1. What's inside this folder

```
aetherV2/
├── api/
│   ├── aether.php          ◀ unified router (24 actions)
│   ├── bootstrap.php       ◀ self-contained AetherJWT + AetherDB
│   ├── config.php          ◀ reads .env (manual parser, handles symbols)
│   ├── migrate.php         ◀ auto-creates aether_* tables (idempotent)
│   ├── audit-log.php       ◀ append-only + auto-dispatches notifications
│   ├── notifier.php        ◀ SMTP + Fast2SMS dispatcher
│   ├── pdf-receipt.php     ◀ donation receipts + payslips (mPDF)
│   ├── module-reports.php  ◀ on-demand analytical reports per module
│   ├── schema-watcher.php  ◀ snapshot, diff, FK + column tracking
│   ├── knowledge-graph.php ◀ entity semantics + module mapping
│   ├── nlp-engine.php      ◀ tokenize, vectorize, TF-IDF, regex, entities
│   ├── reasoner.php        ◀ read intents + 13 write planners
│   ├── error-monitor.php   ◀ 10 health checks + auto-healers
│   ├── learning-engine.php ◀ feedback, weight reinforcement
│   ├── heartbeat.php       ◀ background CLI worker (cron-friendly)
│   └── .htaccess           ◀ Authorization passthrough
├── vendor/                 ◀ composer-installed mPDF (95 MB, pre-shipped)
├── dashboard.php           ◀ Command Centre (super_admin only)
├── panel.js                ◀ floating panel (auto-hidden when logged out)
├── style.css               ◀ light theme matching ERP
├── logo.svg                ◀ Aether mark
├── .env.example            ◀ template
├── INSTALL.md              ◀ this file
├── ERP_FILES_TO_CHANGE.md  ◀ ERP edits required (just one!)
└── README.md
```

---

## 🚀 2. Upload (Hostinger steps)

1. In Hostinger File Manager, navigate to your ERP's web root (`public_html/`).
2. Upload the entire `aetherV2/` folder there. Final path: `public_html/aetherV2/`.
3. Permissions:
   - `aetherV2/` and subfolders: **`755`**
   - All `.php`, `.js`, `.css`, `.htaccess`: **`644`**
   - **`aetherV2/.env`: `600`** (only the web user can read it)

---

## 🔐 3. Configure `.env` (the only file you must edit)

Rename `.env.example` → `.env`. Fill in the values below. Comments next to each show where to find / how to use them.

```ini
# ── Database (must match your ERP's DB) ────────────────────────────────
DB_HOST=localhost
DB_NAME=u135884328_dhrub_erp        # your existing ERP database
DB_USER=u135884328_admin_erp
DB_PASS=<your-actual-mysql-password>

# ── JWT — CRITICAL ─────────────────────────────────────────────────────
# Must EXACTLY match the JWT_SECRET your ERP uses to sign tokens.
# Find it in /public_html/config/database.php (look for: define('JWT_SECRET', '...'))
JWT_SECRET=dhrub-foundation-erp-jwt-secret-2024

# ── Notifications — high-severity audit alerts ─────────────────────────
AETHER_NOTIFY_THRESHOLD=high                 # info|low|medium|high|critical
AETHER_NOTIFY_EMAIL=admin@dhrubfoundation.org,founder@dhrubfoundation.org
AETHER_NOTIFY_SMS=919876543210,917000000000  # comma-sep, country code only

# ── SMTP (email — used for both alerts AND donor thank-you receipts) ───
SMTP_HOST=smtp.hostinger.com                 # Hostinger: smtp.hostinger.com
SMTP_PORT=587                                # 587 (STARTTLS) or 465 (SSL)
SMTP_USERNAME=alerts@dhrubfoundation.org     # any mailbox you own on the domain
SMTP_PASSWORD=<your-mailbox-password>
SMTP_FROM_NAME=Aether (Dhrub Foundation)
SMTP_FROM_EMAIL=alerts@dhrubfoundation.org

# ── Fast2SMS (SMS — for donor thank-you SMS) ───────────────────────────
# Get key from https://www.fast2sms.com/dashboard/dev-api → "API Key"
FAST2SMS_API_KEY=<your-fast2sms-key>
FAST2SMS_SENDER=AETHR

TIMEZONE=Asia/Kolkata
```

> **⚠️ Most common gotcha**: if `JWT_SECRET` doesn't match your ERP's, every Aether request returns 401 "Authentication required". Always copy-paste the exact value from `config/database.php`.

> **💡 Hostinger-specific tip**: For SMTP you can use any email address you've created in **hPanel → Emails → Email Accounts**. The mailbox password = your SMTP password. Host is always `smtp.hostinger.com`, port `587` for STARTTLS or `465` for SSL.

---

## 🎯 4. Wire it into your ERP — **only one file changes**

Open the HTML file your ERP serves (the same one that loads your React/Vue/static front-end). For Dhrub ERP this is **`/public_html/app.html`**. Find the closing `</body>` tag and add one line above it:

```html
    <!-- ── Aether v2 — autonomous brain ── -->
    <script src="/aetherV2/panel.js" defer></script>
</body>
```

**That's the only ERP file that needs to change.** The script auto-hides the launcher when nobody is logged in (security gate is built in), and auto-creates the database tables on its first request.

### Optional — hide the legacy v1 widget

If your ERP still has the old Aether v1 floating button:

```css
#aether-fab, #aether-window { display: none !important; }
```

---

## ⏱ 5. Set up the heartbeat (background scanner)

Aether re-syncs schema + runs health checks every time someone uses it, but for **continuous adaptive monitoring** you want the heartbeat running in the background.

### Option A — Hostinger Cron (easiest)

In Hostinger hPanel → **Advanced → Cron Jobs**, add:

| Schedule              | Command                                                                          |
| --------------------- | -------------------------------------------------------------------------------- |
| `*/2 * * * *`         | `php /home/uXXXXXXXX/public_html/aetherV2/api/heartbeat.php --once`              |

Replace `uXXXXXXXX` with your actual Hostinger user ID (visible in hPanel → File Manager URL).

The `--once` flag tells Aether to do a single pass and exit — perfect for cron.

### Option B — VPS / dedicated server (long-running daemon)

```ini
# /etc/supervisor/conf.d/aether-heartbeat.conf
[program:aether-heartbeat]
command=/usr/bin/php /var/www/html/aetherV2/api/heartbeat.php
user=www-data
autostart=true
autorestart=true
environment=AETHER_TICK_INTERVAL=120
stdout_logfile=/var/log/aether-heartbeat.log
stderr_logfile=/var/log/aether-heartbeat.err.log
```

```bash
sudo supervisorctl reread && sudo supervisorctl update
```

---

## 🧪 6. First-use verification

1. Visit your ERP and **log in** normally.
2. The **green Aether launcher** should appear bottom-right of every ERP page.
3. **Sign out** → the launcher disappears (auth gate).
4. Sign back in → launcher reappears. Click it → panel opens. Try:
   - "show top donors"
   - "report on expenses this quarter"
   - "low stock"
   - "record donation of ₹5000 from \"Test Donor\" test@x.com 9876543210" → action plan card
5. As **super_admin**, also visit `yourdomain.com/aetherV2/dashboard.php` → full Command Centre.
6. As any other role, visit the same URL → friendly **"Super-admin access only"** overlay (panel still works on every page).

### Test the auto-receipt flow

1. As any plan-capable role, ask Aether: `record donation of ₹5000 from "Receipt Test" testdonor@yourdomain.com 9876543210`
2. Click **Approve** in the plan card.
3. The donor should receive:
   - 📧 **PDF receipt as email attachment** (rendered with mPDF, your org details from `settings`)
   - 📱 **Fast2SMS thank-you message** with the receipt code
4. Check `/aetherV2/api/aether.php?action=audit` for two events: `plan_executed` + `receipt_dispatched`.

If email/SMS shows `email=no, sms=no`, check the audit `receipt_dispatched` event's payload — Aether records exactly which credential failed (e.g. `"FAST2SMS_API_KEY not configured"`, `"email send failed (check SMTP_*)"`).

---

## 🛂 7. Role permissions inside Aether

| Role        | Chat | Reports | Receipts/PDFs | Dashboard | Schema sync | Self-heal | Write actions      |
|-------------|------|---------|---------------|-----------|-------------|-----------|--------------------|
| super_admin | ✓    | ✓       | ✓             | ✓         | ✓           | ✓         | ALL                |
| admin       | ✓    | ✓       | ✓             | ✗         | ✓           | ✓         | most               |
| manager     | ✓    | ✓       | ✓             | ✗         | ✗           | ✗         | donations, expenses, inventory, programs, volunteers, custom messages |
| accountant  | ✓    | ✓       | ✓             | ✗         | ✗           | ✗         | donations, expenses, custom messages |
| hr          | ✓    | ✓       | ✓             | ✗         | ✗           | ✗         | salary updates, volunteers |
| editor      | ✓    | ✓       | ✓             | ✗         | ✗           | ✗         | donors, donations, blog posts, gallery, custom messages |
| viewer      | ✓    | ✓       | ✓ (read)      | ✗         | ✗           | ✗         | none               |

---

## 📊 8. What Aether can do for you (quick reference)

**Conversational reports** — "report on donations this quarter" / "expense breakdown" / "HR report" / "inventory health"
**Recording donations** — "record ₹5000 from \"Jane Doe\" jane@x.com 9876543210" → plan with auto-receipt
**Logging expenses** — "log expense ₹2500 for stationery"
**HR** — "update salary of \"Anita\" to ₹45000" / "register volunteer \"Ravi Kumar\""
**Inventory** — "adjust inventory of \"Notebooks\" by +50" / "add inventory item \"Pens\" qty 100"
**Content** — "draft blog about \"our scholarship drive\"" / drag image into chat → caption suggestions + auto-upload
**Communications** — "send email to ravi@x.com saying \"thank you for your support\""
**Operations** — "approve expense #42" / "create program \"Winter Outreach\" budget ₹50000"
**System** — "health" / "self-heal" / "describe employees" / "recent audit"

Every write action is **proposed as a plan** with a preview — you click Approve once, Aether executes and (for donations) auto-emails the donor + sends a thank-you SMS.

---

## 🔧 9. Troubleshooting

| Symptom                                            | Fix                                                                                |
| -------------------------------------------------- | ---------------------------------------------------------------------------------- |
| Launcher doesn't appear at all                     | Not logged in (auth gate working). Sign in normally to your ERP.                  |
| `401 Authentication required` in console          | `JWT_SECRET` in `.env` doesn't match the ERP's. Check `config/database.php`.    |
| `500 Aether DB connection failed`                  | Wrong `DB_*` credentials in `.env`. Test with: `mysql -u<user> -p<DB_NAME>`.   |
| Receipt says `email send failed (check SMTP_*)`    | SMTP_HOST/PORT/USERNAME/PASSWORD wrong. Hostinger: try port 465 + SSL.            |
| Receipt says `FAST2SMS_API_KEY not configured`    | Add the key from https://www.fast2sms.com/dashboard/dev-api                       |
| `403 Admin only` on `schema_sync` / `self_heal`   | Only `super_admin` / `admin` can do these. Log in with one or use chat instead.  |
| Heartbeat not running                              | Check Hostinger cron job logs in hPanel.                                           |
| Panel shows but Reports tab fails                  | The `aether_*` tables aren't created — load any chat first to trigger migration.  |

---

That's it. Aether is live, learning from every interaction, ready to be your second pair of hands.
