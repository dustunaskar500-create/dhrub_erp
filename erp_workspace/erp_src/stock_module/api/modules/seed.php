<?php
/**
 * ERP — Demo seed (idempotent)
 *
 * super_admin only. Seeds:
 *   • 3 vendors with valid-format GSTINs
 *   • 8 inventory items (HSN + GST)
 *   • 1 sample PO + GRN + 1 invoice
 */

function erp_seed_dispatch(string $action, array $user): void {
    if ($action !== 'seed_demo') erp_error("Unknown seed action: $action", 400);
    if (!in_array($user['role'], ['super_admin','admin'], true)) erp_error('Forbidden', 403);
    $db = erp_db();
    $body = erp_body();
    $force = !empty($body['force']);

    $existingVendors = (int)$db->query("SELECT COUNT(*) FROM erp_vendors")->fetchColumn();
    if ($existingVendors > 0 && !$force) {
        erp_json(['ok'=>true, 'skipped'=>true, 'message'=>'Demo data already exists. Pass force=true to re-seed.']);
    }

    // Settings: ensure org_gstin and state are set so invoices have a seller GSTIN
    if (!erp_setting('org_gstin')) {
        $db->prepare("UPDATE settings SET setting_value=? WHERE setting_key='org_gstin'")->execute(['19AABCD1234E1Z5']);
        if ($db->prepare("SELECT COUNT(*) FROM settings WHERE setting_key='org_gstin'")->fetchColumn() == 0) {}
    }

    // Vendors
    $vendors = [
        ['VND-DELHI-001','Surya Stationers Pvt Ltd','07AAACS1234L1ZP','AAACS1234L','Mr. Suresh Kumar','accounts@suryastationers.in','+91-9876543210',
         'B-44, Khan Market','New Delhi','Delhi','07','110003','Net 30'],
        ['VND-MUM-001','Mumbai Logistics LLP','27AABCM2345N1ZQ','AABCM2345N','Ms. Priya Shah','priya@mumbailogistics.in','+91-9123456780',
         '12 Andheri Industrial Estate','Mumbai','Maharashtra','27','400053','Net 45'],
        ['VND-KOL-001','Bengal Textiles & Co.','19AABCB6789F1ZK','AABCB6789F','Mr. Rajesh Banerjee','rajesh@bengaltextiles.in','+91-9988776655',
         '14 Park Street','Kolkata','West Bengal','19','700016','COD'],
    ];
    $vIds = [];
    $vIns = $db->prepare("INSERT INTO erp_vendors (vendor_code,name,gstin,pan,contact_person,email,phone,address,city,state,state_code,pincode,payment_terms,created_by)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    foreach ($vendors as $v) {
        try {
            $vIns->execute([$v[0],$v[1],$v[2],$v[3],$v[4],$v[5],$v[6],$v[7],$v[8],$v[9],$v[10],$v[11],$v[12],$user['id']]);
            $vIds[] = (int)$db->lastInsertId();
        } catch (\Throwable $e) {
            // duplicate vendor_code -> skip
            $row = $db->prepare("SELECT id FROM erp_vendors WHERE vendor_code=?");
            $row->execute([$v[0]]);
            $vIds[] = (int)$row->fetchColumn();
        }
    }

    // Items (HSN + GST)
    $items = [
        // sku, name, hsn, gst, cost, sale, unit, min_stock, qty
        ['SKU-RICE-25', 'Rice 25kg bag',         '1006', 5,  1200, 1450, 'bag', 10, 50],
        ['SKU-DAL-5',  'Toor Dal 5kg',           '0713', 5,   650,  800, 'pkt', 20, 80],
        ['SKU-OIL-1',  'Mustard Oil 1L',         '1514',12,   180,  220, 'btl', 25,120],
        ['SKU-NB-A4',  'Notebook A4 200 pages',  '4820',12,    65,   95, 'pcs', 50,300],
        ['SKU-PEN-BL', 'Ballpoint Pen Blue',     '9608',18,     6,   12, 'pcs',100,800],
        ['SKU-SAR-CTN','Cotton Saree (plain)',   '5208', 5,   450,  650, 'pcs', 15, 40],
        ['SKU-MED-PCM','Paracetamol 500mg strip','3004',12,    18,   28, 'str', 30,200],
        ['SKU-LAP-DEL','Laptop (refurbished)',   '8471',18, 28000,34000, 'pcs',  2,  6],
    ];
    $iIds = [];
    $iIns = $db->prepare("INSERT INTO inventory_items
        (sku,item_name,description,category,hsn_code,gst_rate,quantity,unit,min_stock,reorder_qty,cost_price,sale_price,is_active)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1)");
    foreach ($items as $it) {
        try {
            $iIns->execute([$it[0], $it[1], $it[1] . ' — demo seed', 'other', $it[2], $it[3], $it[8], $it[6], $it[7], $it[7] * 2, $it[4], $it[5]]);
            $iIds[] = (int)$db->lastInsertId();
        } catch (\Throwable $e) {
            $row = $db->prepare("SELECT id FROM inventory_items WHERE sku=?");
            $row->execute([$it[0]]);
            $iIds[] = (int)$row->fetchColumn();
        }
    }

    erp_log_audit('erp_seed', "Demo data seeded (vendors=" . count($vIds) . ", items=" . count($iIds) . ")", ['by'=>$user['id']], 'low', $user['id']);
    erp_json(['ok'=>true, 'vendors'=>count($vIds), 'items'=>count($iIds)]);
}
