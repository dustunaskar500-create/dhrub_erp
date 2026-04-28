"""
Aether v2 — Iteration 5 backend regression tests.
Covers: RBAC scoping, KPI drill-down, Indian-compliance reports (80G/12A/FCRA/CSR),
custom-date module reports, and CSV import unknown-column tolerance.
"""
import os, json, base64, requests, pytest

BASE = os.environ.get('AETHER_BASE', 'http://localhost:8001')
API  = f'{BASE}/aetherV2/api/aether.php'
LOGIN = f'{BASE}/api/auth/login'

USERS = {
    'super_admin': ('sbrata9843@gmail.com', 'admin123'),
    'manager':     ('manager@dhrubfoundation.org', 'admin123'),
    'accountant':  ('accountant@dhrubfoundation.org', 'admin123'),
    'editor':      ('editor@dhrubfoundation.org', 'admin123'),
    'viewer':      ('viewer@dhrubfoundation.org', 'admin123'),
}

def token(role):
    email, pw = USERS[role]
    r = requests.post(LOGIN, json={'email': email, 'password': pw}, timeout=8)
    r.raise_for_status()
    return r.json()['access_token']

def call(role, action, **body):
    headers = {'Authorization': f'Bearer {token(role)}', 'Content-Type': 'application/json'}
    body['action'] = action
    return requests.post(API, json=body, headers=headers, timeout=15).json()

# ─── RBAC info ────────────────────────────────────────────────────────────
def test_rbac_info_super_admin():
    r = call('super_admin', 'rbac_info')
    assert r['role'] == 'super_admin'
    assert len(r['csv_modules']) == 7

def test_rbac_info_editor_limited_csv_modules():
    r = call('editor', 'rbac_info')
    assert r['role'] == 'editor'
    assert set(r['csv_modules']) == {'donors', 'volunteers'}

def test_rbac_info_viewer_no_csv():
    r = call('viewer', 'rbac_info')
    assert r['csv_modules'] == []

# ─── RBAC field redaction ─────────────────────────────────────────────────
def test_editor_donors_email_phone_redacted():
    r = call('editor', 'chat', message='list donors')
    assert 'redacted' in r['reply']

def test_super_admin_donors_full():
    r = call('super_admin', 'chat', message='list donors')
    assert 'redacted' not in r['reply']

# ─── KPI drill-down ──────────────────────────────────────────────────────
def test_kpi_details_donations():
    r = call('super_admin', 'kpi_details', kpi='total_donations')
    assert r['ok'] is True
    assert r['kind'] == 'donations'
    assert 'aggregates' in r and 'rows' in r

def test_kpi_details_donors_aggregates():
    r = call('super_admin', 'kpi_details', kpi='donors')
    assert r['ok']
    assert 'top_giver_amount' in r['aggregates']
    assert 'inactive_donors_90d' in r['aggregates']

def test_kpi_details_blocked_for_editor():
    r = call('editor', 'kpi_details', kpi='expenses')
    assert r['ok'] is False
    assert 'role' in r.get('error', '').lower() or 'cannot' in r.get('error', '').lower()

# ─── Indian compliance reports ────────────────────────────────────────────
def test_compliance_80g_with_cash_flag():
    r = call('super_admin', 'compliance_report', section='80g',
             **{'from': '2025-04-01', 'to': '2026-03-31'})
    assert r['ok']
    totals = r['data']['totals']
    assert totals['count'] >= 0
    # Cash > 2,000 detection wired
    assert 'cash_above_2k' in totals
    assert isinstance(totals['cash_above_2k'], list)

def test_compliance_12a_application_pct():
    r = call('super_admin', 'compliance_report', section='12a',
             **{'from': '2025-04-01', 'to': '2026-03-31'})
    assert r['ok']
    d = r['data']
    assert 'income_total' in d
    assert 'application_pct' in d
    assert 'compliance_85' in d
    assert isinstance(d['compliance_85'], bool)

def test_compliance_fcra_quarters():
    r = call('super_admin', 'compliance_report', section='fcra',
             **{'from': '2025-04-01', 'to': '2026-03-31'})
    assert r['ok']
    q = r['data']['quarters']
    assert set(q.keys()) == {'Q1', 'Q2', 'Q3', 'Q4'}

def test_compliance_csr():
    r = call('super_admin', 'compliance_report', section='csr',
             **{'from': '2025-04-01', 'to': '2026-03-31'})
    assert r['ok']
    assert 'by_company' in r['data']

def test_compliance_overview_returns_all_sections():
    r = call('super_admin', 'compliance_report', section='overview',
             **{'from': '2025-04-01', 'to': '2026-03-31'})
    assert r['ok']
    assert set(r['data'].keys()) >= {'80g', '12a', 'fcra', 'csr'}

def test_compliance_blocked_for_editor():
    r = call('editor', 'compliance_report', section='80g',
             **{'from': '2025-04-01', 'to': '2026-03-31'})
    assert r['ok'] is False

def test_compliance_invalid_dates_rejected():
    r = call('super_admin', 'compliance_report', section='80g',
             **{'from': 'not-a-date', 'to': '2026-03-31'})
    assert r['ok'] is False

def test_compliance_export_csv():
    headers = {'Authorization': f'Bearer {token("super_admin")}'}
    url = f'{API}?action=compliance_export&section=80g&from=2025-04-01&to=2026-03-31'
    r = requests.get(url, headers=headers, timeout=10)
    assert r.status_code == 200
    assert b'# Compliance Report' in r.content
    assert b'80G' in r.content

# ─── Module report with custom dates ──────────────────────────────────────
def test_module_report_custom_dates():
    r = call('super_admin', 'module_report', module='donations',
             **{'from': '2025-10-01', 'to': '2026-03-31'})
    assert r['action'] == 'module_report'
    assert r['period']['label'] == '2025-10-01 to 2026-03-31'
    assert isinstance(r['cards'], list) and len(r['cards']) >= 1

# ─── CSV unknown-column tolerance ─────────────────────────────────────────
def test_csv_import_drops_unknown_columns():
    csv_bytes = b"name,email,phone,donor_type,bogus_field\nIter5_TestA,a5@example.com,9000000005,individual,xx\n"
    b64 = base64.b64encode(csv_bytes).decode()
    prev = call('super_admin', 'csv_import_preview', module='donors',
                filename='iter5.csv', data=b64)
    assert prev['ok']
    assert prev['rows_ok'] == 1
    exec_r = call('super_admin', 'csv_import_execute', import_id=prev['import_id'])
    assert exec_r['ok']
    assert exec_r['inserted'] == 1
    # cleanup
    import subprocess
    subprocess.run(['mysql', '-uaether', '-pAetherDev2026!', 'dhrub_erp',
                    '-e', "DELETE FROM donors WHERE name LIKE 'Iter5_Test%'"],
                   check=False, capture_output=True)
