# Files in your ERP that need to change

> **Short answer**: only **one** file. Optionally a second to clean up the legacy widget.
> Aether is a fully self-contained module — drop the `aetherV2/` folder in, edit `.env`, edit one HTML file, you're done.

---

## ✅ MUST change — 1 file

### `app.html` (or whichever HTML file your ERP serves)

This is the static HTML shell that loads your ERP front-end. In Dhrub Foundation ERP it's `/public_html/dhrub_erp/app.html`.

**Add one line** before the closing `</body>` tag:

```html
    <!-- ── Aether v2 — autonomous brain ── -->
    <script src="/aetherV2/panel.js" defer></script>
</body>
```

That's it. No PHP edits, no API edits, no `composer install`, no DB migrations to run by hand. Aether bootstraps itself on its first request.

---

## 🟡 OPTIONAL — clean-up (1 file)

### `app.html` — hide the legacy Aether v1 widget

If your ERP still has the old Aether v1 floating button (the gradient one), Aether v2 will appear next to it. Hide v1 by adding to the same file's `<style>` block:

```css
#aether-fab, #aether-window { display: none !important; }
```

You can leave v1's PHP code in place — it doesn't conflict with v2.

---

## 🚫 Files you should NOT change

These ERP files **stay exactly as they are**:

| File / folder           | Why we don't touch it                                |
| ----------------------- | ---------------------------------------------------- |
| `index.php`             | Your existing API router stays intact.               |
| `config/database.php`   | Aether reads its own credentials from `aetherV2/.env`. |
| `includes/auth.php`     | Aether has its own JWT decoder using your `JWT_SECRET`. |
| `includes/db.php`       | Aether has its own PDO connection.                   |
| `aether/` (legacy v1)   | Untouched — leave it as a fallback.                  |
| Your `.htaccess`        | Aether's API has its own `.htaccess` inside `aetherV2/`. |
| Database (existing)     | Aether **never alters** existing tables. It only adds new `aether_*` tables. |

---

## 🔐 The one-time `.env` setup (inside `aetherV2/`, not the ERP)

Inside the new folder, copy `.env.example` → `.env` and set:

```ini
DB_HOST=localhost
DB_NAME=u135884328_dhrub_erp        # SAME as your ERP's DB
DB_USER=u135884328_admin_erp
DB_PASS=<your password>

JWT_SECRET=<MUST match your ERP's JWT_SECRET>   # find in /config/database.php

# Optional — leave blank if not using
AETHER_NOTIFY_EMAIL=admin@dhrubfoundation.org
SMTP_HOST=smtp.hostinger.com
SMTP_USERNAME=alerts@dhrubfoundation.org
SMTP_PASSWORD=<password>
FAST2SMS_API_KEY=<your key>
```

> **CRITICAL**: `JWT_SECRET` must equal the value in your ERP's `/config/database.php`. Otherwise users' login tokens won't validate against Aether and every request returns 401.

---

## Verification checklist

After upload + edit, test these in your browser:

1. **`https://yourerp.com/`** → log in normally → green Aether launcher appears bottom-right.
2. **`https://yourerp.com/aetherV2/dashboard.php`** → loads the Command Centre with KPIs / health / audit / schema diff.
3. Click launcher → panel opens → ask **"show top donors"** → returns formatted list.
4. Ask **"record donation of ₹5000 from 'Test'"** → action plan card appears with Approve/Reject buttons.

If any of those fail, see the **Troubleshooting** section in `aetherV2/INSTALL.md`.

---

That's the complete list. Aether is designed to be a **drop-in upgrade**, not a refactor.
