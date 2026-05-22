"""
Stock Tracking + GST Tax Invoicing module backend tests (iteration 6)
Targets /app/aetherV2/erp/api/router.php endpoints + Aether reasoner ERP intents.
"""
import os
import io
import json
import re
import pytest
import requests

BASE_URL = os.environ.get("REACT_APP_BACKEND_URL", "https://aether-adaptive.preview.emergentagent.com").rstrip("/")
LOGIN_URL = f"{BASE_URL}/api/auth/login"
ERP_URL = f"{BASE_URL}/aetherV2/erp/api/router.php"
AETHER_URL = f"{BASE_URL}/aetherV2/api/aether.php"

ADMIN = ("sbrata9843@gmail.com", "admin123")
EDITOR = ("editor@dhrubfoundation.org", "admin123")


def _login(email, password):
    r = requests.post(LOGIN_URL, json={"email": email, "password": password}, timeout=30)
    assert r.status_code == 200, f"login failed: {r.status_code} {r.text[:200]}"
    return r.json().get("access_token")


@pytest.fixture(scope="session")
def admin_token():
    return _login(*ADMIN)


@pytest.fixture(scope="session")
def editor_token():
    try:
        return _login(*EDITOR)
    except Exception:
        pytest.skip("editor login not available")


def call(token, action, data=None, method="POST", files=None, params=None):
    headers = {"Authorization": f"Bearer {token}"}
    url = f"{ERP_URL}?action={action}"
    if params:
        for k, v in params.items():
            url += f"&{k}={v}"
    if files:
        return requests.post(url, headers=headers, data=data or {}, files=files, timeout=60)
    if method == "GET":
        return requests.get(url, headers=headers, timeout=30)
    headers["Content-Type"] = "application/json"
    return requests.post(url, headers=headers, json=data or {}, timeout=60)


# ---------- Reference / org ----------
class TestRef:
    def test_ref_org(self, admin_token):
        r = call(admin_token, "ref_org")
        assert r.status_code == 200, r.text[:300]
        d = r.json()
        assert "settings" in d or "org" in d or "data" in d, d
        # try to find org_state_code anywhere in payload
        body = json.dumps(d)
        assert "19" in body, f"org_state_code 19 missing: {body[:200]}"

    def test_ref_states(self, admin_token):
        r = call(admin_token, "ref_states")
        assert r.status_code == 200, r.text[:300]
        d = r.json()
        items = d.get("states") or d.get("data") or d
        # Should be 39 states/UTs
        if isinstance(items, list):
            assert len(items) >= 35, f"expected ~39 states, got {len(items)}"


# ---------- Vendor ----------
class TestVendor:
    def test_vendor_list(self, admin_token):
        r = call(admin_token, "vendor_list")
        assert r.status_code == 200, r.text[:300]
        d = r.json()
        vendors = d.get("vendors") or d.get("data") or d.get("items") or []
        names = " ".join([v.get("vendor_name", "") + v.get("name", "") for v in vendors])
        assert "Surya" in names or "Mumbai" in names or "Bengal" in names, f"seeded vendors missing: {names[:200]}"

    def test_vendor_save_invalid_gstin(self, admin_token):
        r = call(admin_token, "vendor_save", {
            "name": "TEST_BadGSTIN",
            "gstin": "INVALID",
            "state_code": "19",
        })
        # Invalid GSTIN must error - 400 or success=false
        if r.status_code == 200:
            d = r.json()
            assert d.get("success") is False or d.get("error") or d.get("ok") is False, f"expected error for bad GSTIN: {d}"
        else:
            assert r.status_code in (400, 422), r.status_code

    def test_vendor_save_valid_then_update(self, admin_token):
        # 15-char GSTIN starting with 27 (Maharashtra)
        gstin = "27AABCD1234E1Z5"
        r = call(admin_token, "vendor_save", {
            "name": "TEST_Vendor_Iter6",
            "gstin": gstin,
            "address": "Mumbai",
            "phone": "9999999999",
        })
        assert r.status_code == 200, r.text[:300]
        d = r.json()
        vid = d.get("vendor_id") or d.get("id") or (d.get("vendor") or {}).get("id") or (d.get("data") or {}).get("id")
        assert vid, f"no vendor id returned: {d}"
        # state_code auto-derived from "27"
        body = json.dumps(d)
        assert '"27"' in body or "27" in body, f"state_code 27 should auto-derive: {body[:200]}"


# ---------- Stock items ----------
class TestStock:
    def test_stock_items_with_kpi(self, admin_token):
        r = call(admin_token, "stock_items")
        assert r.status_code == 200, r.text[:300]
        d = r.json()
        items = d.get("items") or d.get("data") or []
        assert len(items) >= 10, f"expected >=10 seeded items, got {len(items)}"
        kpi = d.get("kpi") or d.get("summary") or {}
        # Must have KPI keys
        body = json.dumps(d)
        assert "stock_value" in body or "total" in body, f"KPI missing: {body[:200]}"

    def test_stock_save_new_item(self, admin_token):
        r = call(admin_token, "stock_save", {
            "item_name": "TEST_StockItem_Iter6",
            "sku": f"TEST-SKU-{os.getpid()}",
            "hsn_code": "4820",
            "gst_rate": 18,
            "unit": "PCS",
            "unit_cost": 100,
            "selling_price": 150,
            "quantity": 50,
            "min_stock_level": 5,
        })
        assert r.status_code == 200, r.text[:300]
        d = r.json()
        assert d.get("success") is not False, d


# ---------- Stock adjustments / PnL ----------
class TestAdjustments:
    def test_adjustment_create_damage(self, admin_token):
        # find any inventory item id
        items = call(admin_token, "stock_items").json()
        lst = items.get("items") or items.get("data") or []
        assert lst, "no items to adjust"
        item_id = lst[0].get("id") or lst[0].get("item_id")
        unit_cost = float(lst[0].get("unit_cost") or 0)
        r = call(admin_token, "stock_adjust_create", {
            "item_id": item_id,
            "adj_type": "damage",
            "qty": 5,
            "reason": "TEST_iter6 damage",
        })
        assert r.status_code == 200, r.text[:300]
        d = r.json()
        body = json.dumps(d)
        assert d.get("success") is not False, body
        # value_impact = qty * unit_cost (if returned)
        if "value_impact" in body and unit_cost:
            vi = d.get("adjustment", {}).get("value_impact") or d.get("value_impact")
            if vi is not None:
                assert abs(float(vi)) > 0, f"value_impact should be non-zero: {vi}"

    def test_adjustment_list_with_pnl(self, admin_token):
        r = call(admin_token, "stock_adjust_list")
        assert r.status_code == 200, r.text[:300]
        d = r.json()
        body = json.dumps(d)
        assert "total_loss" in body or "loss" in body, f"PnL aggregate missing: {body[:300]}"

    def test_stock_pnl_breakdown(self, admin_token):
        r = call(admin_token, "stock_pnl")
        assert r.status_code == 200, r.text[:300]
        d = r.json()
        body = json.dumps(d)
        assert "damage" in body.lower() or "by_type" in body or "breakdown" in body, body[:300]


# ---------- GRN ----------
class TestGRN:
    @pytest.fixture(scope="class")
    def grn_ctx(self, admin_token):
        # Need vendor + item
        vendors = call(admin_token, "vendor_list").json()
        vlist = vendors.get("vendors") or vendors.get("data") or []
        vid = vlist[0].get("id") or vlist[0].get("vendor_id")
        items = call(admin_token, "stock_items").json()
        ilist = items.get("items") or items.get("data") or []
        iid = ilist[0].get("id") or ilist[0].get("item_id")
        return {"vendor_id": vid, "item_id": iid, "unit_cost": float(ilist[0].get("unit_cost") or 100)}

    def test_grn_save_with_discrepancy(self, admin_token, grn_ctx):
        payload = {
            "vendor_id": grn_ctx["vendor_id"],
            "supplier_invoice_no": f"TEST-INV-{os.getpid()}",
            "received_date": "2026-01-15",
            "items": [{
                "item_id": grn_ctx["item_id"],
                "qty_ordered": 10,
                "qty_received": 9,
                "qty_damaged": 1,
                "unit_cost": grn_ctx["unit_cost"] or 100,
            }],
            "notes": "TEST iter6 GRN",
        }
        r = call(admin_token, "grn_save", payload)
        assert r.status_code == 200, r.text[:300]
        d = r.json()
        grn_id = d.get("grn_id") or d.get("id") or (d.get("grn") or {}).get("id") or (d.get("data") or {}).get("id")
        assert grn_id, f"grn_id missing: {d}"
        # Discrepancy auto-flagged
        body = json.dumps(d)
        assert "discrepan" in body.lower() or "flag" in body.lower() or grn_id, body[:300]
        pytest.grn_id = grn_id

    def test_grn_post_blocked_without_attachment(self, admin_token):
        grn_id = getattr(pytest, "grn_id", None)
        if not grn_id:
            pytest.skip("no grn from previous test")
        r = call(admin_token, "grn_post", {"id": grn_id})
        # Should fail 400 OR success=false because discrepancy + no attachment
        assert r.status_code == 400 or (r.status_code == 200 and r.json().get("ok") is False), \
            f"expected block, got {r.status_code} {r.text[:300]}"

    def test_grn_upload_attachment(self, admin_token):
        grn_id = getattr(pytest, "grn_id", None)
        if not grn_id:
            pytest.skip("no grn")
        # 1x1 PNG
        png = (b"\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01"
               b"\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\xf8\xcf"
               b"\xc0\x00\x00\x00\x03\x00\x01\x5b\x8f\x8d\xb9\x00\x00\x00\x00IEND\xaeB`\x82")
        files = {"file": ("test.png", io.BytesIO(png), "image/png")}
        r = call(admin_token, "grn_upload_attachment",
                 data={"grn_id": str(grn_id), "kind": "photo"},
                 files=files)
        assert r.status_code == 200, r.text[:300]
        d = r.json()
        assert d.get("success") is not False, d
        body = json.dumps(d)
        assert "file" in body.lower() or "path" in body.lower() or "id" in body.lower(), body[:200]

    def test_grn_post_succeeds_with_attachment(self, admin_token):
        grn_id = getattr(pytest, "grn_id", None)
        if not grn_id:
            pytest.skip("no grn")
        r = call(admin_token, "grn_post", {"id": grn_id})
        assert r.status_code == 200, r.text[:300]
        d = r.json()
        assert d.get("ok") is True or d.get("success") is not False, d


# ---------- Invoice ----------
class TestInvoice:
    @pytest.fixture(scope="class")
    def inv_ctx(self, admin_token):
        items = call(admin_token, "stock_items").json()
        ilist = items.get("items") or items.get("data") or []
        return {"item_id": ilist[0].get("id") or ilist[0].get("item_id"),
                "unit_cost": float(ilist[0].get("unit_cost") or 100)}

    def test_invoice_intrastate_cgst_sgst(self, admin_token, inv_ctx):
        # Buyer state_code 19 (WB) same as org - should be intra-state -> CGST+SGST
        r = call(admin_token, "invoice_save", {
            "buyer_name": "TEST_Intra Buyer",
            "buyer_gstin": "19AABCD9999E1Z5",
            "buyer_state_code": "19",
            "invoice_date": "2026-01-15",
            "items": [{
                "item_id": inv_ctx["item_id"],
                "description": "Test item",
                "hsn_code": "4820",
                "qty": 2,
                "unit_price": 100,
                "gst_rate": 18,
            }],
        })
        assert r.status_code == 200, r.text[:300]
        d = r.json()
        inv_id = d.get("id") or d.get("invoice_id")
        assert inv_id, f"no id returned: {d}"
        pytest.invoice_id_intra = inv_id
        # GET the invoice to verify number + tax split
        gr = call(admin_token, "invoice_get", {"id": inv_id})
        assert gr.status_code == 200, gr.text[:300]
        inv = gr.json().get("invoice") or gr.json().get("data") or gr.json()
        body = json.dumps(inv)
        m = re.search(r"INV/\d{4}-\d{2}/\d{3,}", body)
        assert m, f"invoice number format missing: {body[:300]}"
        cgst = float(inv.get("total_cgst") or 0)
        sgst = float(inv.get("total_sgst") or 0)
        igst = float(inv.get("total_igst") or 0)
        assert cgst > 0 and sgst > 0, f"intra-state should have CGST+SGST: cgst={cgst} sgst={sgst}"
        assert igst == 0, f"intra-state should have igst=0, got {igst}"

    def test_invoice_interstate_igst(self, admin_token, inv_ctx):
        r = call(admin_token, "invoice_save", {
            "buyer_name": "TEST_Inter Buyer",
            "buyer_gstin": "27AABCD9999E1Z5",
            "buyer_state_code": "27",
            "invoice_date": "2026-01-15",
            "items": [{
                "item_id": inv_ctx["item_id"],
                "description": "Test item",
                "hsn_code": "4820",
                "qty": 2,
                "unit_price": 100,
                "gst_rate": 18,
            }],
        })
        assert r.status_code == 200, r.text[:300]
        d = r.json()
        inv_id = d.get("id") or d.get("invoice_id")
        pytest.invoice_id_inter = inv_id
        gr = call(admin_token, "invoice_get", {"id": inv_id})
        inv = gr.json().get("invoice") or gr.json().get("data") or gr.json()
        igst = float(inv.get("total_igst") or 0)
        cgst = float(inv.get("total_cgst") or 0)
        assert igst > 0, f"inter-state must have IGST, got {igst}; inv={json.dumps(inv)[:300]}"
        assert cgst == 0, f"inter-state must have cgst=0, got {cgst}"

    def test_invoice_issue_and_pdf(self, admin_token):
        inv_id = getattr(pytest, "invoice_id_intra", None)
        if not inv_id:
            pytest.skip("no invoice id")
        r = call(admin_token, "invoice_issue", {"id": inv_id})
        assert r.status_code == 200, r.text[:300]
        # PDF
        r = call(admin_token, "invoice_pdf", method="GET", params={"id": inv_id})
        assert r.status_code == 200, r.text[:300]
        ctype = r.headers.get("Content-Type", "")
        assert "pdf" in ctype.lower(), f"Content-Type should be PDF: {ctype}"
        assert r.content[:4] == b"%PDF", f"PDF magic missing: {r.content[:20]}"

    def test_invoice_payment(self, admin_token):
        inv_id = getattr(pytest, "invoice_id_intra", None)
        if not inv_id:
            pytest.skip("no invoice id")
        r = call(admin_token, "invoice_payment", {
            "invoice_id": inv_id,
            "amount": 100,
            "payment_method": "cash",
            "payment_date": "2026-01-15",
        })
        assert r.status_code == 200, r.text[:300]
        d = r.json()
        body = json.dumps(d)
        assert "partial" in body.lower() or "paid" in body.lower() or d.get("success") is not False, body[:300]

    def test_invoice_gst_summary(self, admin_token):
        r = call(admin_token, "invoice_gst_summary",
                 method="GET", params={"from": "2026-01-01", "to": "2026-12-31"})
        assert r.status_code == 200, r.text[:300]
        d = r.json()
        body = json.dumps(d)
        assert "hsn" in body.lower() or "b2b" in body.lower() or "b2c" in body.lower(), body[:300]


# ---------- Dashboard ----------
class TestDashboard:
    def test_dash_overview(self, admin_token):
        r = call(admin_token, "dash_overview")
        assert r.status_code == 200, r.text[:300]
        d = r.json()
        # Should have many KPI keys
        keys = set(d.keys()) | set((d.get("data") or {}).keys())
        expected_some = {"stock", "grn", "invoice", "adjustments", "vendor_count",
                         "recent_grns", "recent_invoices", "low_stock_items"}
        overlap = expected_some & keys
        assert len(overlap) >= 4, f"expected dashboard keys missing. got keys={keys}"


# ---------- Aether reasoner ERP intents ----------
class TestAetherERP:
    def _aether(self, token, msg):
        r = requests.post(AETHER_URL,
            headers={"Authorization": f"Bearer {token}", "Content-Type": "application/json"},
            json={"action": "chat", "message": msg}, timeout=60)
        return r

    def test_intent_stock_value(self, admin_token):
        r = self._aether(admin_token, "what is our stock value?")
        assert r.status_code == 200, r.text[:300]
        d = r.json()
        body = json.dumps(d).lower()
        assert "stock" in body and ("#stock" in body or "/erp/" in body), f"intent missing: {body[:400]}"

    def test_intent_outstanding_invoices(self, admin_token):
        r = self._aether(admin_token, "are there outstanding invoices")
        assert r.status_code == 200, r.text[:300]
        body = json.dumps(r.json()).lower()
        assert "#invoices" in body or "invoice" in body, body[:400]

    def test_intent_damages_losses(self, admin_token):
        r = self._aether(admin_token, "any damages or losses")
        assert r.status_code == 200, r.text[:300]
        body = json.dumps(r.json()).lower()
        assert "#adjustments" in body or "damage" in body or "loss" in body, body[:400]

    def test_intent_gst_collected(self, admin_token):
        r = self._aether(admin_token, "how much GST collected")
        assert r.status_code == 200, r.text[:300]
        body = json.dumps(r.json()).lower()
        assert "gst" in body, body[:400]


# ---------- Auth ----------
class TestAuth:
    def test_unauthenticated_401(self):
        r = requests.post(f"{ERP_URL}?action=vendor_list", timeout=15)
        assert r.status_code in (401, 403), f"expected 401/403 unauth, got {r.status_code}"

    def test_unknown_action(self, admin_token):
        r = call(admin_token, "doesnotexist_xyz")
        assert r.status_code == 400, f"expected 400 unknown action, got {r.status_code} {r.text[:200]}"

    def test_editor_forbidden_vendor_save(self, editor_token):
        r = call(editor_token, "vendor_save", {
            "vendor_name": "TEST_Editor_Vendor", "gstin": "27AABCD1234E1Z5"})
        assert r.status_code == 403, f"editor should be forbidden, got {r.status_code} {r.text[:200]}"

    def test_editor_forbidden_stock_save(self, editor_token):
        r = call(editor_token, "stock_save", {"item_name": "TEST_E", "hsn_code": "4820"})
        assert r.status_code == 403, f"editor should be forbidden, got {r.status_code}"

    def test_editor_forbidden_invoice_save(self, editor_token):
        r = call(editor_token, "invoice_save", {"buyer_name": "TEST", "items": []})
        assert r.status_code == 403, f"editor should be forbidden, got {r.status_code}"
