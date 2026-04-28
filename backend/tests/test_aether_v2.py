"""Aether v2 backend test suite.
Tests all v2 endpoints, adaptive schema sync, action plan workflow, self-heal,
role-based access, audit log, knowledge graph.
"""
import os
import json
import time
import subprocess
import pytest
import requests

BASE_URL = os.environ.get("REACT_APP_BACKEND_URL", "https://aether-adaptive.preview.emergentagent.com").rstrip("/")
V2_URL = f"{BASE_URL}/aether/api/v2/aether.php"
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


@pytest.fixture(scope="session")
def admin_token():
    return _login(ADMIN_EMAIL, ADMIN_PASS)


@pytest.fixture(scope="session")
def nonadmin_token():
    try:
        return _login(NONADMIN_EMAIL, NONADMIN_PASS)
    except Exception:
        pytest.skip("Non-admin user unavailable")


# ---------- basic actions ----------
@pytest.mark.parametrize("action", [
    "identity", "dashboard", "schema_sync", "schema_changes", "knowledge_summary",
    "knowledge_search", "health", "issues", "audit", "learning_stats", "list_plans",
    "history", "tick",
])
def test_basic_actions_return_200(admin_token, action):
    payload = {}
    if action == "knowledge_search":
        payload["query"] = "donor"
    r = _call(admin_token, action, **payload)
    assert r.status_code == 200, f"{action} -> {r.status_code} {r.text[:300]}"
    data = r.json()
    assert data.get("action") == action or "ok" in data or "error" not in data, f"unexpected payload for {action}: {data}"


def test_describe_employees(admin_token):
    r = _call(admin_token, "describe", table="employees")
    assert r.status_code == 200, r.text
    data = r.json()
    assert "columns" in data and isinstance(data["columns"], list) and len(data["columns"]) > 0
    has_pk = any(
        c.get("is_primary") or c.get("primary_key") or c.get("pk")
        or c.get("key") == "PRI" or c.get("COLUMN_KEY") == "PRI" or c.get("Key") == "PRI"
        for c in data["columns"]
    )
    assert has_pk, f"No primary key info in describe: {data['columns'][:3]}"


def test_knowledge_search_donor(admin_token):
    r = _call(admin_token, "knowledge_search", query="donations")
    assert r.status_code == 200
    data = r.json()
    results = data.get("matches") or data.get("results") or data.get("entities") or []
    text = json.dumps(results).lower()
    assert "donations" in text, f"donations not found in knowledge_search results: {text[:300]}"
    # Should return at least one table-typed result
    assert any(m.get("entity_type") == "table" for m in results), f"no table-type entity: {results[:2]}"


# ---------- adaptive schema awareness ----------
def _mysql(sql):
    return subprocess.run(MYSQL + [sql], capture_output=True, text=True, timeout=15)


def test_schema_adaptive_create_alter_drop(admin_token):
    # Baseline sync
    r = _call(admin_token, "schema_sync")
    assert r.status_code == 200
    # Create table
    out = _mysql("CREATE TABLE IF NOT EXISTS aether_test_adapt (id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(50));")
    assert out.returncode == 0, out.stderr
    r = _call(admin_token, "schema_sync")
    assert r.status_code == 200
    data = r.json()
    changes = data.get("changes", [])
    types = [(c.get("change_type"), c.get("object_type"), c.get("object_name", "")) for c in changes]
    assert any(("created" in (t[0] or "") or "added" in (t[0] or "")) and "aether_test_adapt" in (t[2] or "") for t in types), \
        f"create not detected: {types}"

    # Add column
    _mysql("ALTER TABLE aether_test_adapt ADD COLUMN extra_col VARCHAR(20);")
    r = _call(admin_token, "schema_sync")
    data = r.json()
    changes = data.get("changes", [])
    found_col = any("extra_col" in json.dumps(c) and ("added" in (c.get("change_type") or "") or "created" in (c.get("change_type") or "")) for c in changes)
    assert found_col, f"column add not detected: {changes}"

    # Drop column
    _mysql("ALTER TABLE aether_test_adapt DROP COLUMN extra_col;")
    r = _call(admin_token, "schema_sync")
    data = r.json()
    changes = data.get("changes", [])
    found_drop = any("extra_col" in json.dumps(c) and ("dropped" in (c.get("change_type") or "") or "removed" in (c.get("change_type") or "")) for c in changes)
    assert found_drop, f"column drop not detected: {changes}"

    # Drop table
    _mysql("DROP TABLE IF EXISTS aether_test_adapt;")
    r = _call(admin_token, "schema_sync")
    data = r.json()
    changes = data.get("changes", [])
    found_table_drop = any("aether_test_adapt" in (c.get("object_name") or "") and ("dropped" in (c.get("change_type") or "") or "removed" in (c.get("change_type") or "")) for c in changes)
    assert found_table_drop, f"table drop not detected: {changes}"

    # validate impact info present
    if changes:
        assert any(("impact_level" in c) or ("impact" in c) for c in changes), f"impact missing: {changes[0]}"


# ---------- chat intents ----------
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
    assert any(e in intent for e in expected_intents), f"'{msg}' got intent='{intent}', expected one of {expected_intents}; data={json.dumps(data)[:300]}"


# ---------- action plan workflow ----------
def test_donation_plan_approve_workflow(admin_token):
    # propose
    r = _call(admin_token, "chat", message='record a donation of Rs 5000 from "AutoTest Donor"')
    assert r.status_code == 200, r.text
    data = r.json()
    assert data.get("mode") == "plan", f"expected mode=plan, got {data}"
    plan = data.get("plan") or {}
    plan_id = plan.get("id")
    assert plan_id, f"no plan id: {data}"
    assert plan.get("status") == "proposed"

    # approve
    r = _call(admin_token, "approve_plan", plan_id=plan_id)
    assert r.status_code == 200, r.text
    ap = r.json()
    result = ap.get("result") or {}
    assert result.get("ok") is True or ap.get("ok") is True, f"approve not ok: {ap}"

    # verify donation row
    out = _mysql("SELECT d.amount, dn.name FROM donations d JOIN donors dn ON d.donor_id=dn.id WHERE dn.name='AutoTest Donor' ORDER BY d.id DESC LIMIT 1;")
    assert "AutoTest Donor" in out.stdout and "5000" in out.stdout, f"donation not persisted: {out.stdout} / {out.stderr}"

    # list_plans executed
    r = _call(admin_token, "list_plans", status="executed")
    assert r.status_code == 200
    plans = r.json().get("plans") or r.json().get("items") or []
    assert any(p.get("id") == plan_id for p in plans), f"plan {plan_id} not in executed list"


def test_expense_plan_reject_workflow(admin_token):
    r = _call(admin_token, "chat", message="log expense Rs 200 for stationery")
    assert r.status_code == 200, r.text
    data = r.json()
    assert data.get("mode") == "plan", f"expected plan mode: {data}"
    plan_id = (data.get("plan") or {}).get("id")
    assert plan_id

    r = _call(admin_token, "reject_plan", plan_id=plan_id)
    assert r.status_code == 200
    rj = r.json()
    # status rejected
    r2 = _call(admin_token, "list_plans", status="rejected")
    plans = r2.json().get("plans") or r2.json().get("items") or []
    assert any(p.get("id") == plan_id for p in plans), f"plan {plan_id} not in rejected list"

    # no expense row should be created
    out = _mysql("SELECT COUNT(*) FROM expenses WHERE description='stationery' AND amount=200 AND created_at >= NOW() - INTERVAL 5 MINUTE;")
    # Allow 0 count
    cnt = int(out.stdout.strip() or "0")
    assert cnt == 0, f"rejected expense should not be persisted; count={cnt}"


# ---------- self-heal ----------
def test_self_heal_salary_mismatch(admin_token):
    # find an existing employee
    out = _mysql("SELECT id FROM employees ORDER BY id LIMIT 1;")
    emp_id = out.stdout.strip().split("\n")[0]
    if not emp_id:
        pytest.skip("No employees")
    # save original
    orig = _mysql(f"SELECT basic_salary, net_salary FROM employees WHERE id={emp_id};").stdout.strip()
    _mysql(f"UPDATE employees SET basic_salary=10000, net_salary=999 WHERE id={emp_id};")

    r = _call(admin_token, "health")
    data = r.json()
    salary_check = next((c for c in data.get("checks", []) if c.get("code") == "employee_salary_mismatch"), None)
    assert salary_check and salary_check.get("status") != "ok", f"salary mismatch not detected: {salary_check}"

    r = _call(admin_token, "self_heal")
    assert r.status_code == 200, r.text
    hd = r.json()
    healed = hd.get("healed_count", 0) or sum(c.get("healed_count", 0) for c in hd.get("checks", []) if isinstance(c, dict))
    assert healed >= 1, f"healed_count not >= 1: {hd}"

    # verify net_salary now equals computed
    cur = _mysql(f"SELECT basic_salary, net_salary FROM employees WHERE id={emp_id};").stdout.strip()
    bs, ns = cur.split()
    assert ns != "999", f"net_salary not healed: {cur}"

    # restore
    if orig:
        bs0, ns0 = orig.split()
        _mysql(f"UPDATE employees SET basic_salary={bs0}, net_salary={ns0} WHERE id={emp_id};")


def test_self_heal_requires_admin(nonadmin_token):
    r = _call(nonadmin_token, "self_heal")
    assert r.status_code == 403, f"self_heal should be 403 for non-admin, got {r.status_code} {r.text[:200]}"


# ---------- audit log ----------
def test_audit_log_events(admin_token):
    r = _call(admin_token, "audit", limit=200)
    assert r.status_code == 200
    data = r.json()
    events = data.get("events") or data.get("items") or data.get("entries") or []
    assert events, "no audit events"
    sample = events[0]
    for k in ("event_type", "severity", "summary", "created_at"):
        assert k in sample, f"audit entry missing {k}: {sample}"
    types = {e.get("event_type") for e in events}
    expected_any = {"plan_proposed", "plan_executed", "plan_rejected", "schema_change", "self_heal", "health_run"}
    assert types & expected_any, f"none of {expected_any} present in {types}"


# ---------- feedback ----------
def test_feedback_recorded(admin_token):
    r = _call(admin_token, "feedback", message="hello", rating=1, intent="greeting")
    assert r.status_code == 200, r.text
