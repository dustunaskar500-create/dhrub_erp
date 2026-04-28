# Aether v2 — Installation on Hostinger (or any cPanel / shared PHP host)

> **TL;DR** — copy the `aetherV2/` folder to `public_html/aetherV2/`, edit `.env`, add **one line** to your ERP's HTML, and you're done.

---

## 1. What's inside this folder

```
aetherV2/
├── api/                    # all PHP API code (self-contained)
│   ├── aether.php          ◀ unified router (POST endpoint)
│   ├── bootstrap.php       ◀ JWT decoder + DB connection
│   ├── config.php          ◀ reads .env
│   ├── migrate.php         ◀ auto-creates aether_* tables (idempotent)
│   ├── audit-log.php
│   ├── schema-watcher.php
│   ├── knowledge-graph.php
│   ├── nlp-engine.php
│   ├── reasoner.php
│   ├── error-monitor.php
│   ├── learning-engine.php
│   ├── notifier.php        ◀ SMTP + Fast2SMS for high-severity alerts
│   ├── pdf-receipt.php     ◀ donation receipts + payslips (mPDF)
│   ├── heartbeat.php       ◀ background CLI worker (cron-friendly)
│   └── .htaccess           ◀ passes Authorization header to PHP
├── vendor/                 # composer dependencies (mPDF) — pre-shipped
├── dashboard.php           ◀ Command Centre UI
├── panel.js                ◀ Floating panel widget (drop into ERP HTML)
├── style.css               ◀ light-theme stylesheet (matches ERP)
├── .env.example            ◀ template — copy to .env
└── INSTALL.md              ◀ this file
```

The folder is **fully self-contained** — no dependency on your ERP's `includes/` files.

---

## 2. Upload (Hostinger steps)

1. In Hostinger File Manager, navigate to your ERP's web root: `public_html/`.
2. Upload the entire `aetherV2/` folder there. Final path: `public_html/aetherV2/`.
3. Permissions:
   - `aetherV2/` and subfolders: `755`
   - All `.php`, `.js`, `.css`, `.htaccess`: `644`
   - **`aetherV2/.env`: `600`** (so only the web user can read it)

## 3. Configure environment

Rename `.env.example` → `.env` and fill in the values:

```ini
DB_HOST=localhost
DB_NAME=u135884328_dhrub_erp           # your existing ERP DB
DB_USER=u135884328_admin_erp
DB_PASS=your_actual_password

# CRITICAL — must match your ERP's JWT_SECRET so existing user tokens work.
# Look in /public_html/config/database.php (or wherever your ERP defines JWT_SECRET).
JWT_SECRET=dhrub-foundation-erp-jwt-secret-2024

# Notifications (optional — leave blank to disable)
AETHER_NOTIFY_THRESHOLD=high                # info|low|medium|high|critical
AETHER_NOTIFY_EMAIL=admin@dhrubfoundation.org
AETHER_NOTIFY_SMS=919876543210              # comma-sep, country code only

# SMTP (used for high-severity alert emails)
SMTP_HOST=smtp.hostinger.com
SMTP_PORT=587
SMTP_USERNAME=alerts@dhrubfoundation.org
SMTP_PASSWORD=your_smtp_password
SMTP_FROM_NAME=Aether (Dhrub Foundation)
SMTP_FROM_EMAIL=alerts@dhrubfoundation.org

# Fast2SMS — Get key from https://www.fast2sms.com/dashboard/dev-api
FAST2SMS_API_KEY=
FAST2SMS_SENDER=AETHR

TIMEZONE=Asia/Kolkata
```

> **Heads-up** — if `JWT_SECRET` doesn't match your ERP's, every Aether request will be rejected with 401. The default value above matches the ERP we received; check `/config/database.php` in your ERP to confirm.

---

## 4. Wire it into your ERP — **only one file changes**

Open the HTML file your ERP serves (the same one that loads your React/Vue/static front-end). For Dhrub ERP this is **`/public_html/app.html`**. Find the closing `</body>` tag and add **one line** above it:

```html
    <!-- ── Aether v2 — autonomous brain ── -->
    <script src="/aetherV2/panel.js" defer></script>
</body>
```

**That's the only ERP file that needs to change.** No PHP edits, no API changes, no DB migrations to run by hand — Aether's first request to `/aetherV2/api/aether.php` triggers the migration automatically.

### Optional — hide the legacy v1 widget

If your ERP still has the old Aether v1 floating button, add this to the same file's `<style>` block:

```css
#aether-fab, #aether-window { display: none !important; }
```

---

## 5. Background heartbeat (recommended)

For continuous schema-watching + health-checks, set up a **cron job** in Hostinger's hPanel → Advanced → Cron Jobs:

| Schedule          | Command                                                   |
| ----------------- | --------------------------------------------------------- |
| Every 2 minutes   | `php /home/uXXXXXX/public_html/aetherV2/api/heartbeat.php --once` |

> The heartbeat script supports an optional `--once` argument (single-run for cron). If you can run it as a long-lived process (VPS), use the supervisord-style config at the bottom of this file.

If you can't run cron, that's fine — Aether also re-syncs whenever a user opens the dashboard, opens the panel, or sends a chat message.

---

## 6. Login & first use

1. Visit your ERP and log in normally — JWT is stored in browser `localStorage`.
2. Open any ERP page → the green Aether launcher appears bottom-right.
3. Click it → the floating panel slides in. Try:
   - "show top donors"
   - "low stock"
   - "forecast donations"
   - "record donation of ₹5000 from 'Jane Doe'"  ← creates an action plan that needs your approval
4. Or go to **`yourdomain.com/aetherV2/dashboard.php`** for the full Command Centre.

---

## 7. Generating PDF receipts / payslips

Aether ships with mPDF pre-installed in `vendor/`. Once a donation exists, hit:

```
GET  /aetherV2/api/aether.php?action=download_receipt&donation_id=42
GET  /aetherV2/api/aether.php?action=download_payslip&payroll_id=10
```

(both require a Bearer token). The PDF is rendered server-side from your live ERP data with your organization details from the `settings` table.

---

## 8. Notifications

When a high-severity audit event fires (e.g. data integrity failure, plan execution failure, missing admin user), Aether sends:

- **Email** — beautifully formatted HTML alert to every address in `AETHER_NOTIFY_EMAIL`
- **SMS** — short summary via Fast2SMS to every number in `AETHER_NOTIFY_SMS`

Tune the threshold via `AETHER_NOTIFY_THRESHOLD` (default `high`). Set to `critical` to receive only the most severe events; `medium` for more verbosity.

---

## 9. Database tables Aether creates (auto-migration)

All start with `aether_` prefix and are created on first request:

| Table                       | Purpose                                |
| --------------------------- | -------------------------------------- |
| `aether_schema_snapshots`   | Historical schema fingerprints         |
| `aether_schema_changes`     | Detected diffs between snapshots       |
| `aether_knowledge`          | Internal model of every ERP entity     |
| `aether_health_checks`      | Registered health-check definitions    |
| `aether_issues`             | Open / healed / closed issues          |
| `aether_audit_log`          | Append-only event log                  |
| `aether_learning`           | Per-interaction intent + outcome       |
| `aether_intent_weights`     | Adaptive token-intent weights          |
| `aether_action_plans`       | Proposed write actions awaiting approval |
| `aether_memory`             | Conversation history (existed already) |

**Aether never modifies your existing ERP tables** — read access only, plus controlled writes via approved action plans.

---

## 10. Troubleshooting

| Symptom                                            | Fix                                                                                |
| -------------------------------------------------- | ---------------------------------------------------------------------------------- |
| `401 Authentication required` on every call       | `JWT_SECRET` in `.env` doesn't match the ERP's. Check `/config/database.php`.    |
| `500 Aether DB connection failed`                  | Wrong `DB_*` credentials in `.env`, or the DB user lacks privileges on the ERP DB. |
| Floating panel doesn't show                        | `<script src="/aetherV2/panel.js">` not added to your HTML, or path is wrong.     |
| Chat replies "Empty message"                       | Browser ad-blocker stripped the request body — add `aetherV2/` to the allow-list. |
| Schema sync says "Admin only"                      | Only `super_admin` / `admin` roles can sync schema. Log in as one.                 |
| Heartbeat not running                              | Check the cron job, or run manually: `php aetherV2/api/heartbeat.php`.            |

---

## 11. Long-running heartbeat (VPS / dedicated server)

If you have shell access and want a real long-lived process:

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

That's it. Aether is now embedded in your ERP, fully self-hosted, learning from every interaction, and ready to be your second pair of eyes.
