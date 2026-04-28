"""Aether v2 (self-contained /aetherV2/) backend test suite.
Tests all endpoints, schema_diff, PDF receipts, notifier, role gating,
self-containment, adaptive schema, action plans, audit, chat intents.
"""
import os
import json
import subprocess
import pytest
import requests

BASE_URL = os.environ.get("REACT_APP_BACKEND_URL", "https://aether-adaptive.preview.emergentagent.com").rstrip("/")
V2_URL = f"{BASE_URL}/aetherV2/api/aether.php"
LOGIN_URL = f"{BASE_URL}/api/auth/login"

ADMIN_EMAIL = "sbrata9843@gmail.com"
ADMIN_PASS = "admin123"
NONADMIN_EMAIL = "accountant@dhrubfoundation.org"
NONADMIN_PASS = "admin123"

MYSQL = ["mysql", "-u", "aether", "-pAetherDev2026!", "dhrub_erp", "-N", "-B", "-e"]


def _login(email, password):
    r = requests.post(LOGIN_URL, json={"email": email, "password": password}, timeout=15)
    assert r.status_code == 200, f"login failed {r.status_code} {r.text}"
    return r.json()["access_token"]


def _call(token, action, **payload):
    payload["action"] = action
    r = requests.post(V2_URL, json=payload, headers={"Authorization": f"Bearer {token}"}, timeout=30)
    return r


def _mysql(sql):
    return subprocess.run(MYSQL + [sql], capture_output=True, text=True, timeout=15)


@pytest.fixture(scope="session")
def admin_token():
    return _login(ADMIN_EMAIL, ADMIN_PASS)


@pytest.fixture(scope="session")
def nonadmin_token():
    try:
        return _login(NONADMIN_EMAIL, NONADMIN_PASS)
    except Exception:
        pytest.skip("Non-admin user unavailable")


# ---------- Basic action coverage ----------
@pytest.mark.parametrize("action", [
    "identity", "dashboard", "schema_sync", "schema_changes", "schema_diff",
    "knowledge_summary", "knowledge_search", "health", "issues",
    "audit", "learning_stats", "list_plans", "history", "tick",
])
def test_basic_actions_return_200(admin_token, action):
    payload = {}
    if action == "knowledge_search":
        payload["query"] = "donor"
    r = _call(admin_token, action, **payload)
    assert r.status_code == 200, f"{action} -> {r.status_code} {r.text[:300]}"
    data = r.json()
    assert data.get("action") == action or "ok" in data or "error" not in data, f"unexpected payload for {action}: {data}"


# ---------- NEW: schema_diff ----------
def test_schema_diff_shape(admin_token):
    # ensure baseline
    _call(admin_token, "schema_sync")
    r = _call(admin_token, "schema_diff")
    assert r.status_code == 200, r.text
    data = r.json()
    assert "snapshots" in data, f"missing snapshots: {data}"
    assert "changes" in data, f"missing changes: {data}"
    snaps = data["snapshots"]
    assert isinstance(snaps, list)
    if snaps:
        s = snaps[0]
        for k in ("id", "fingerprint", "table_count", "column_count", "fk_count", "taken_at"):
            assert k in s, f"snapshot missing {k}: {s}"
    if data["changes"]:
        c = data["changes"][0]
        for k in ("change_type", "object_type", "object_name"):
            assert k in c, f"change missing {k}: {c}"
        # impact_level OR impact accepted
        assert ("impact_level" in c) or ("impact" in c), f"impact missing: {c}"


# ---------- NEW: PDF receipt ----------
def test_pdf_receipt_download(admin_token):
    # ensure at least one donation exists - reuse existing or one from previous test
    out = _mysql("SELECT id FROM donations ORDER BY id DESC LIMIT 1;")
    did = out.stdout.strip().split("\n")[0]
    if not did:
        pytest.skip("no donations to download")
    url = f"{V2_URL}?action=download_receipt&donation_id={did}"
    r = requests.get(url, headers={"Authorization": f"Bearer {admin_token}"}, timeout=30)
    assert r.status_code == 200, f"{r.status_code}: {r.text[:300]}"
    assert "application/pdf" in r.headers.get("Content-Type", "").lower(), \
        f"content-type wrong: {r.headers.get('Content-Type')}"
    assert r.content[:4] == b"%PDF", f"PDF magic bytes missing: {r.content[:20]}"
    assert len(r.content) > 5_000, f"PDF too small: {len(r.content)} bytes"


def test_pdf_payslip_download(admin_token):
    out = _mysql("SELECT id FROM payroll ORDER BY id DESC LIMIT 1;")
    pid = out.stdout.strip().split("\n")[0]
    if not pid:
        pytest.skip("no payroll rows")
    url = f"{V2_URL}?action=download_payslip&payroll_id={pid}"
    r = requests.get(url, headers={"Authorization": f"Bearer {admin_token}"}, timeout=30)
    assert r.status_code == 200, f"{r.status_code}: {r.text[:300]}"
    assert "application/pdf" in r.headers.get("Content-Type", "").lower()
    assert r.content[:4] == b"%PDF"
    assert len(r.content) > 3_000


# ---------- NEW: Notifier shouldNotify ----------
def test_notifier_should_notify_threshold():
    """Run via PHP CLI - shouldNotify('critical')==true, shouldNotify('info')==false."""
    code = (
        "require '/app/aetherV2/api/bootstrap.php';"
        "require '/app/aetherV2/api/notifier.php';"
        "echo (AetherNotifier::shouldNotify('critical')?'1':'0');"
        "echo (AetherNotifier::shouldNotify('info')?'1':'0');"
    )
    out = subprocess.run(["php", "-r", code], capture_output=True, text=True, timeout=15)
    assert out.returncode == 0, f"php failed: {out.stderr}"
    assert out.stdout.strip().endswith("10"), f"unexpected: stdout={out.stdout!r} stderr={out.stderr!r}"


# ---------- NEW: Self-contained (no /app/includes/auth.php) ----------
def test_self_contained_no_includes_auth(admin_token):
    """If /app/includes/auth.php exists, temporarily rename and confirm aetherV2 still works."""
    auth_path = "/app/includes/auth.php"
    backup = "/app/includes/auth.php.bak_test"
    renamed = False
    if os.path.exists(auth_path):
        try:
            os.rename(auth_path, backup)
            renamed = True
        except Exception:
            pytest.skip("cannot rename includes/auth.php (permissions)")
    try:
        r = _call(admin_token, "identity")
        assert r.status_code == 200, f"aetherV2 broken without includes/auth.php: {r.status_code} {r.text[:300]}"
        d = r.json()
        assert d.get("action") == "identity" and d.get("user", {}).get("role") == "super_admin"
    finally:
        if renamed and os.path.exists(backup):
            os.rename(backup, auth_path)


# ---------- Adaptive schema ----------
def test_schema_adaptive_create_alter_drop(admin_token):
    _call(admin_token, "schema_sync")
    _mysql("CREATE TABLE IF NOT EXISTS aether_test_adapt (id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(50));")
    r = _call(admin_token, "schema_sync")
    changes = r.json().get("changes", [])
    assert any("aether_test_adapt" in (c.get("object_name") or "") and ("created" in (c.get("change_type") or "") or "added" in (c.get("change_type") or "")) for c in changes), \
        f"create not detected: {changes[:5]}"

    _mysql("ALTER TABLE aether_test_adapt ADD COLUMN extra_col VARCHAR(20);")
    r = _call(admin_token, "schema_sync")
    changes = r.json().get("changes", [])
    assert any("extra_col" in json.dumps(c) for c in changes), f"col add not detected: {changes[:5]}"

    _mysql("ALTER TABLE aether_test_adapt DROP COLUMN extra_col;")
    _mysql("DROP TABLE IF EXISTS aether_test_adapt;")
    r = _call(admin_token, "schema_sync")
    changes = r.json().get("changes", [])
    assert any("aether_test_adapt" in (c.get("object_name") or "") and ("dropped" in (c.get("change_type") or "") or "removed" in (c.get("change_type") or "")) for c in changes), \
        f"drop not detected: {changes[:5]}"


# ---------- Chat intents ----------
@pytest.mark.parametrize("msg, expected_intents", [
    ("hello", ["greeting", "smalltalk", "help"]),
    ("show dashboard", ["dashboard"]),
    ("top donors", ["top_donors", "donors"]),
    ("forecast donations", ["forecast", "forecast_donations"]),
    ("low stock", ["low_stock", "inventory"]),
    ("describe employees", ["schema_info", "describe"]),
    ("recent audit", ["audit_recent", "audit"]),
    ("health", ["health_status", "health"]),
])
def test_chat_intents(admin_token, msg, expected_intents):
    r = _call(admin_token, "chat", message=msg)
    assert r.status_code == 200, r.text
    data = r.json()
    reply = data.get("reply") or data.get("text") or ""
    intent = (data.get("intent") or data.get("plan", {}).get("intent") or "").lower() if data else ""
    assert reply, f"empty reply for '{msg}': {data}"
    assert any(e in intent for e in expected_intents), \
        f"'{msg}' got intent='{intent}', expected one of {expected_intents}; data={json.dumps(data)[:300]}"


def test_forecast_includes_valid_month(admin_token):
    """Forecast reply must contain a valid YYYY-MM token (not blank)."""
    import re
    r = _call(admin_token, "chat", message="forecast donations")
    data = r.json()
    reply = (data.get("reply") or "") + json.dumps(data)
    assert re.search(r"20\d{2}-(0[1-9]|1[0-2])", reply), f"no YYYY-MM in forecast: {reply[:300]}"


# ---------- Action plan workflow ----------
def test_donation_plan_approve_workflow(admin_token):
    r = _call(admin_token, "chat", message='record a donation of Rs 5000 from "NewTest Donor"')
    assert r.status_code == 200, r.text
    data = r.json()
    assert data.get("mode") == "plan", f"expected plan, got {data}"
    plan_id = (data.get("plan") or {}).get("id")
    assert plan_id, f"no plan id: {data}"

    r = _call(admin_token, "approve_plan", plan_id=plan_id)
    assert r.status_code == 200, r.text
    ap = r.json()
    result = ap.get("result") or {}
    assert result.get("ok") is True or ap.get("ok") is True, f"approve not ok: {ap}"

    out = _mysql("SELECT d.amount, dn.name FROM donations d JOIN donors dn ON d.donor_id=dn.id WHERE dn.name='NewTest Donor' ORDER BY d.id DESC LIMIT 1;")
    assert "NewTest Donor" in out.stdout and "5000" in out.stdout, f"donation not persisted: {out.stdout}"


# ---------- Role gating ----------
def test_nonadmin_blocked_self_heal(nonadmin_token):
    r = _call(nonadmin_token, "self_heal")
    assert r.status_code == 403, f"expected 403, got {r.status_code} {r.text[:200]}"


def test_nonadmin_blocked_schema_sync(nonadmin_token):
    r = _call(nonadmin_token, "schema_sync")
    assert r.status_code == 403, f"expected 403, got {r.status_code} {r.text[:200]}"


# ---------- Audit log ----------
def test_audit_log_events(admin_token):
    r = _call(admin_token, "audit", limit=200)
    assert r.status_code == 200
    events = r.json().get("events") or r.json().get("items") or []
    assert events
    types = {e.get("event_type") for e in events}
    expected_any = {"plan_proposed", "plan_executed", "plan_rejected", "schema_change", "self_heal", "health_run"}
    assert types & expected_any, f"none of {expected_any} in {types}"


# ---------- Cleanup ----------
def test_zz_cleanup():
    """Cleanup test data created during this run."""
    _mysql("DELETE FROM donations WHERE donor_id IN (SELECT id FROM donors WHERE name IN ('NewTest Donor','PanelTest','AutoTest Donor'));")
    _mysql("DELETE FROM donors WHERE name IN ('NewTest Donor','PanelTest','AutoTest Donor');")
    _mysql("DELETE FROM aether_action_plans WHERE intent_summary LIKE '%NewTest Donor%' OR intent_summary LIKE '%PanelTest%';")
    _mysql("DROP TABLE IF EXISTS aether_test_adapt;")
