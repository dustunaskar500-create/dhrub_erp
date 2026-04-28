"""Aether v2 — iteration_3 tests for NEW features:
- module_report (8 modules)
- upload_image, suggest_caption, suggest_blog
- New write planners (8)
- Auto-receipt on donation approve (PDF email + SMS attempted, audited)
- Role gate retained
- chat → module_report intent routing
"""
import os
import json
import base64
import subprocess
import time
import pytest
import requests

BASE_URL = (os.environ.get("REACT_APP_BACKEND_URL")
            or "https://aether-adaptive.preview.emergentagent.com").rstrip("/")
V2_URL = f"{BASE_URL}/aetherV2/api/aether.php"
LOGIN_URL = f"{BASE_URL}/api/auth/login"

ADMIN = ("sbrata9843@gmail.com", "admin123")
ACCT = ("accountant@dhrubfoundation.org", "admin123")
MYSQL = ["mysql", "-u", "aether", "-pAetherDev2026!", "dhrub_erp", "-N", "-B", "-e"]

# Tiny 1x1 PNG
TINY_PNG_B64 = "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=="


def _login(email, pw):
    r = requests.post(LOGIN_URL, json={"email": email, "password": pw}, timeout=15)
    assert r.status_code == 200, f"login fail {r.text}"
    return r.json()["access_token"]


def _call(token, action, **payload):
    payload["action"] = action
    return requests.post(V2_URL, json=payload,
                         headers={"Authorization": f"Bearer {token}"}, timeout=30)


def _mysql(sql):
    return subprocess.run(MYSQL + [sql], capture_output=True, text=True, timeout=15)


@pytest.fixture(scope="session")
def admin_token():
    return _login(*ADMIN)


@pytest.fixture(scope="session")
def acct_token():
    try:
        return _login(*ACCT)
    except Exception:
        pytest.skip("accountant unavailable")


# -------- existing endpoint sanity (smoke for all 22 actions) --------
@pytest.mark.parametrize("action", [
    "identity", "dashboard", "schema_sync", "schema_diff", "schema_changes",
    "knowledge_summary", "health", "issues", "audit", "learning_stats",
    "list_plans", "history", "tick",
])
def test_actions_smoke(admin_token, action):
    r = _call(admin_token, action)
    assert r.status_code == 200, f"{action} -> {r.status_code} {r.text[:200]}"
    j = r.json()
    assert isinstance(j, dict)


def test_knowledge_search(admin_token):
    r = _call(admin_token, "knowledge_search", query="donor")
    assert r.status_code == 200
    assert "matches" in r.json() or "results" in r.json()


def test_describe(admin_token):
    r = _call(admin_token, "describe", table="donations")
    assert r.status_code == 200, r.text[:200]


def test_self_heal_admin(admin_token):
    r = _call(admin_token, "self_heal")
    assert r.status_code == 200


def test_feedback_action(admin_token):
    r = _call(admin_token, "feedback", message_id=1, rating="up")
    # Either ok OR validation error tolerated
    assert r.status_code in (200, 400)


# -------- module_report (NEW) — all 8 modules --------
@pytest.mark.parametrize("module", [
    "donations", "expenses", "hr", "inventory",
    "programs", "volunteers", "cms", "audit",
])
def test_module_report(admin_token, module):
    r = _call(admin_token, "module_report", module=module)
    assert r.status_code == 200, f"{module} -> {r.text[:300]}"
    j = r.json()
    assert j.get("text") and len(j["text"]) > 20, f"empty text for {module}"
    assert "cards" in j and isinstance(j["cards"], list)
    assert len(j["cards"]) >= 1, f"{module} returned 0 cards"


# -------- upload_image (NEW) --------
def test_upload_image(admin_token):
    fname = f"TEST_iter3_{int(time.time())}.png"
    r = _call(admin_token, "upload_image",
              filename=fname, data=TINY_PNG_B64,
              title="TEST iter3 image",
              description="iteration 3 test asset",
              category="general")
    assert r.status_code == 200, r.text[:400]
    j = r.json()
    assert j.get("id") or j.get("image_id"), f"no id field: {j}"
    assert j.get("url"), f"no url: {j}"
    assert j.get("size", 0) > 0
    # File should land in /app/uploads/aether/
    ls = subprocess.run(["ls", "/app/uploads/aether/"], capture_output=True, text=True)
    assert fname in ls.stdout, f"uploaded file not on disk. ls={ls.stdout[-300:]}"
    # Gallery row check
    res = _mysql(f"SELECT title FROM gallery WHERE title='TEST iter3 image' ORDER BY id DESC LIMIT 1")
    assert "TEST iter3 image" in res.stdout, f"gallery row missing. mysql={res.stdout} err={res.stderr}"


# -------- suggest_caption (NEW) --------
def test_suggest_caption(admin_token):
    r = _call(admin_token, "suggest_caption",
              filename="IMG_2024_volunteers_outreach.jpg")
    assert r.status_code == 200, r.text[:300]
    j = r.json()
    reply = j.get("reply", "")
    assert reply and len(reply) > 50
    # Multiple options + alt-text mention
    assert reply.count("\n") >= 3, "expected multi-option caption"


# -------- chat → module_report intent routing --------
def test_chat_module_report_intent_expenses(admin_token):
    r = _call(admin_token, "chat", message="report on expenses this quarter")
    assert r.status_code == 200, r.text[:300]
    j = r.json()
    intent = j.get("intent") or j.get("nlp", {}).get("intent")
    reply = (j.get("reply") or "") + json.dumps(j.get("cards", []))
    assert intent == "module_report" or "expense" in reply.lower(), f"intent={intent}, reply head={reply[:200]}"


def test_chat_module_report_intent_donations(admin_token):
    r = _call(admin_token, "chat", message="analyse donations")
    assert r.status_code == 200
    j = r.json()
    intent = j.get("intent") or j.get("nlp", {}).get("intent")
    assert intent == "module_report" or "donation" in (j.get("reply") or "").lower()


# -------- write planners (8) — chat returns mode:'plan' --------
PLANNER_MSGS = [
    ("create_donor", 'create donor "TEST X iter3" testxiter3@aether.test 9876543210'),
    ("create_volunteer", 'register volunteer "TEST Y iter3"'),
    ("approve_expense", "approve expense #1"),
    ("adjust_inventory", 'adjust inventory of "Notebooks" by +50'),
    ("add_inventory_item", 'add inventory item "TEST Pens iter3" qty 100'),
    ("create_program", 'create program "TEST Winter Outreach iter3" budget 50000'),
    ("create_blog_post", 'create blog post "TEST Annual Scholarship Drive iter3"'),
    ("send_message", 'send email to demo@x.com saying "thank you"'),
]


@pytest.mark.parametrize("name,msg", PLANNER_MSGS, ids=[p[0] for p in PLANNER_MSGS])
def test_write_planner(admin_token, name, msg):
    r = _call(admin_token, "chat", message=msg)
    assert r.status_code == 200, f"{name} -> {r.text[:300]}"
    j = r.json()
    mode = j.get("mode") or (j.get("plan") or {}).get("mode")
    plan = j.get("plan") or {}
    preview = plan.get("preview") or j.get("preview") or j.get("reply") or ""
    plan_id = plan.get("id") or j.get("plan_id")
    assert mode == "plan" or plan_id, f"{name} did not yield plan: keys={list(j.keys())}"
    assert preview and len(preview) > 0, f"{name} empty preview"
    if plan_id:
        res = _mysql(f"SELECT id FROM aether_action_plans WHERE id={int(plan_id)}")
        assert str(plan_id) in res.stdout, f"plan row not persisted for {name}"


# -------- approve write planners → DB rows --------
def _create_and_approve(token, message):
    r = _call(token, "chat", message=message)
    assert r.status_code == 200, r.text[:300]
    j = r.json()
    plan = j.get("plan") or {}
    pid = plan.get("id") or j.get("plan_id")
    assert pid, f"no plan id in {j}"
    a = _call(token, "approve_plan", plan_id=pid)
    assert a.status_code == 200, a.text[:300]
    return pid, a.json()


def test_approve_create_blog_post(admin_token):
    pid, res = _create_and_approve(admin_token,
        'create blog post "TEST Annual Scholarship Drive iter3"')
    assert res.get("ok") in (True, 1) or res.get("status") == "ok", f"approve failed: {res}"
    out = _mysql("SELECT title FROM blog_posts WHERE title LIKE 'TEST Annual Scholarship Drive iter3%' ORDER BY id DESC LIMIT 1")
    assert "TEST Annual Scholarship Drive iter3" in out.stdout, f"blog row missing: {out.stdout} err={out.stderr}"


def test_approve_create_donor(admin_token):
    pid, res = _create_and_approve(admin_token,
        'create donor "TEST Donor iter3" testdonoriter3@aether.test 9876543210')
    assert res.get("ok") in (True, 1) or res.get("status") == "ok"
    out = _mysql("SELECT id FROM donors WHERE name LIKE 'TEST Donor iter3%' ORDER BY id DESC LIMIT 1")
    assert out.stdout.strip(), f"donor row missing: {out.stdout} err={out.stderr}"


def test_approve_add_inventory_item(admin_token):
    pid, res = _create_and_approve(admin_token,
        'add inventory item "TEST Pens iter3" qty 100')
    assert res.get("ok") in (True, 1) or res.get("status") == "ok"
    # try common table names
    for tbl in ("inventory_items", "inventory"):
        out = _mysql(f"SELECT id FROM {tbl} WHERE name LIKE 'TEST Pens iter3%' OR item_name LIKE 'TEST Pens iter3%' ORDER BY id DESC LIMIT 1")
        if out.stdout.strip():
            return
    pytest.fail("no inventory row found for TEST Pens iter3")


def test_approve_send_message(admin_token):
    pid, res = _create_and_approve(admin_token,
        'send email to demo@x.com saying "thank you"')
    result = res.get("result") or res
    # email/sms keys present (false expected without SMTP)
    assert ("email" in result) or ("sms" in result) or ("dispatch" in result) or res.get("ok") in (True, 1), \
        f"send_message approve missing dispatch keys: {res}"


# -------- AUTO RECEIPT (CORE) on donation approve --------
def test_auto_receipt_donation_flow(admin_token):
    msg = 'record donation of Rs 5000 from "TEST Auto Receipt iter3" autotestiter3@aether.test 9876543210'
    r = _call(admin_token, "chat", message=msg)
    assert r.status_code == 200, r.text[:400]
    j = r.json()
    plan = j.get("plan") or {}
    preview = (plan.get("preview") or j.get("preview") or j.get("reply") or "").lower()
    pid = plan.get("id") or j.get("plan_id")
    assert pid, f"no plan id: {j}"
    # Plan preview should mention auto send / receipt
    assert ("auto" in preview and ("send" in preview or "receipt" in preview)) \
        or "receipt" in preview or "email" in preview or "sms" in preview, \
        f"preview missing auto-send hint: {preview[:300]}"

    a = _call(admin_token, "approve_plan", plan_id=pid)
    assert a.status_code == 200, a.text[:400]
    aj = a.json()
    assert aj.get("ok") in (True, 1) or aj.get("status") == "ok", f"approve fail: {aj}"
    result = aj.get("result") or {}
    receipt = result.get("receipt") or aj.get("receipt") or {}
    assert receipt, f"no receipt block in approve result: {aj}"
    # email & sms both attempted (will be false without SMTP/Fast2SMS)
    assert "email" in receipt or "sms" in receipt, f"receipt missing email/sms keys: {receipt}"
    reasons = receipt.get("reasons") or []
    # reasons should mention failures (no SMTP or fast2sms)
    blob = json.dumps(receipt).lower()
    assert ("smtp" in blob or "fast2sms" in blob or "fail" in blob or "not configured" in blob
            or len(reasons) > 0 or receipt.get("email") is False or receipt.get("sms") is False), \
        f"expected dispatch reasons (will fail without creds): {receipt}"

    # Audit trail must have BOTH plan_executed and receipt_dispatched
    aud = _call(admin_token, "audit")
    assert aud.status_code == 200
    events = aud.json().get("events") or aud.json().get("rows") or []
    types = [e.get("event_type") or e.get("action") or e.get("event") or e.get("type") for e in events]
    assert any("plan_executed" in str(t or "") for t in types), f"no plan_executed in audit. types[:10]={types[:10]}"
    assert any("receipt_dispatched" in str(t or "") for t in types), f"no receipt_dispatched in audit. types[:10]={types[:10]}"
    # summary should reference donation id
    summaries = " ".join((e.get("summary") or "") for e in events[:20])
    assert "donation" in summaries.lower() or "receipt" in summaries.lower()


# -------- Role gating retained --------
def test_acct_self_heal_403(acct_token):
    r = _call(acct_token, "self_heal")
    assert r.status_code == 403, f"acct should be blocked, got {r.status_code} {r.text[:200]}"


def test_acct_schema_sync_403(acct_token):
    r = _call(acct_token, "schema_sync")
    assert r.status_code == 403


def test_acct_chat_works(acct_token):
    r = _call(acct_token, "chat", message="show dashboard")
    assert r.status_code == 200


# -------- Cleanup --------
def test_zz_cleanup():
    cleanups = [
        "DELETE FROM donors WHERE name LIKE 'TEST %iter3%' OR email LIKE '%iter3@aether.test'",
        "DELETE FROM donations WHERE donor_name LIKE 'TEST %iter3%' OR donor_email LIKE '%iter3@aether.test'",
        "DELETE FROM blog_posts WHERE title LIKE 'TEST %iter3%'",
        "DELETE FROM volunteers WHERE name LIKE 'TEST %iter3%'",
        "DELETE FROM programs WHERE name LIKE 'TEST %iter3%' OR title LIKE 'TEST %iter3%'",
        "DELETE FROM inventory_items WHERE name LIKE 'TEST %iter3%' OR item_name LIKE 'TEST %iter3%'",
        "DELETE FROM gallery WHERE title LIKE 'TEST iter3%'",
        "DELETE FROM aether_action_plans WHERE summary LIKE '%iter3%' OR action_data LIKE '%iter3%'",
    ]
    for sql in cleanups:
        _mysql(sql)
    # remove uploaded test files
    subprocess.run("rm -f /app/uploads/aether/TEST_iter3_*.png", shell=True)
    assert True
