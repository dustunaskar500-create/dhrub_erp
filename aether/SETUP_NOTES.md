# Aether — Setup Notes (Self-Sufficient Edition)

## ✅ No External AI API Required
Aether now runs entirely on your own server — no OpenAI, no Anthropic, no external AI calls.
The intelligence is powered by **AetherBrain** (`api/aether-brain.php`), a pure PHP NLP engine.

---

## Files Overview

| File | Purpose |
|---|---|
| `api/aether.php` | Main endpoint — routes requests through AetherBrain |
| `api/aether-brain.php` | 🧠 The AI engine — intent detection, entity extraction, ERP actions |
| `api/config.php` | Loads .env (no OpenAI key needed) |
| `api/helpers.php` | PDF, Email, SMS, WhatsApp helpers (optional features) |
| `api/auth-check.php` | JWT validation for widget visibility |
| `api/ping.php` | Diagnostic — delete after setup |
| `aether-widget.js` | Drop-in widget for your ERP HTML |
| `AetherChat.tsx` | React component alternative |

---

## .env Configuration

```
DB_HOST=localhost
DB_NAME=your_db_name
DB_USER=your_db_user
DB_PASS=your_db_password
SMTP_HOST=smtp.yourhost.com
SMTP_PORT=587
SMTP_USERNAME=your@email.com
SMTP_PASSWORD=yourpassword
SMTP_FROM_NAME=Your Organization
FAST2SMS_API_KEY=your-key         # for SMS
EVOLUTION_API_URL=http://localhost:8080   # for WhatsApp
EVOLUTION_INSTANCE_NAME=default
EVOLUTION_API_KEY=your-key
```

> **No OPENAI_API_KEY needed!** You can safely remove it.

---

## Optional: PDF generation (mPDF)

Only needed for payslip/receipt generation:

```bash
composer require mpdf/mpdf
```

Or download manually and place in `vendor/mpdf/`.

---

## Optional: Email (PHPMailer)

Only needed for email sending:

```bash
composer require phpmailer/phpmailer
```

---

## Optional: SMS

Sign up at [Fast2SMS](https://fast2sms.com) and add your API key to `.env`.

---

## Database table

The `aether_memory` table is auto-created on first request. No manual SQL needed.

---

## After Setup

1. Run `api/ping.php` to verify all components are loaded
2. Delete `api/ping.php` and `api/debug.php` from the server
3. Set `.env` permissions: `chmod 600 .env`

---

## What AetherBrain understands

| Query Type | Example |
|---|---|
| Dashboard | "Show me the dashboard" / "Give me a summary" |
| Donations | "List last 10 donations" / "Record ₹5000 donation from Ramesh via UPI" |
| Donors | "Show all donors" / "Find donor Priya" / "Add new donor" |
| Expenses | "List this month's expenses" / "Add travel expense ₹1500" |
| Employees | "List all staff" / "Show employee details for Anita" |
| Salary | "Update salary for employee #5 to ₹25000" |
| Payslip | "Generate payslip for Rahul for April 2025" |
| Receipt | "Generate donation receipt for Sunita for ₹10,000" |
| Email | "Send email to info@example.com" |
| SMS | "Send SMS to 9876543210" |
| WhatsApp | "Send WhatsApp to 919876543210" |
| Help | "What can you do?" / "help" |

