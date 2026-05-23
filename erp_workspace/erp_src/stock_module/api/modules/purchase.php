<?php
/**
 * ERP — Purchase Orders
 *
 * Workflow: draft -> sent -> partial/received -> closed
 * Linked to GRN via po_id.
 */

function erp_po_dispatch(string $action, array $user): void {
    $db = erp_db();
    $body = erp_body();

    switch ($action) {
        case 'po_list': {
            $status = (string)($body['status'] ?? '');
            $where = "WHERE 1=1";
            $params = [];
            if ($status) { $where .= " AND p.status = ?"; $params[] = $status; }
            $stmt = $db->prepare("SELECT p.*, v.name AS vendor_name, v.gstin AS vendor_gstin
                                   FROM erp_purchase_orders p
                                   JOIN erp_vendors v ON v.id = p.vendor_id
                                   $where ORDER BY p.id DESC LIMIT 200");
            $stmt->execute($params);
            erp_json(['ok'=>true, 'pos'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        }
        case 'po_get': {
            $id = (int)($body['id'] ?? 0);
            if (!$id) erp_error('id required');
            $s = $db->prepare("SELECT p.*, v.name AS vendor_name, v.gstin AS vendor_gstin,
                                       v.address AS vendor_address, v.email AS vendor_email, v.phone AS vendor_phone
                               FROM erp_purchase_orders p
                               JOIN erp_vendors v ON v.id = p.vendor_id
                               WHERE p.id = ?");
            $s->execute([$id]);
            $po = $s->fetch(PDO::FETCH_ASSOC);
            if (!$po) erp_error('Not found', 404);
            $items = $db->prepare("SELECT pi.*, i.item_name AS item_master_name, i.sku
                                    FROM erp_po_items pi
                                    LEFT JOIN inventory_items i ON i.id = pi.item_id
                                    WHERE pi.po_id = ? ORDER BY pi.id ASC");
            $items->execute([$id]);
            $grns = $db->prepare("SELECT id, grn_number, received_date, status FROM erp_grns WHERE po_id = ? ORDER BY id DESC");
            $grns->execute([$id]);
            erp_json(['ok'=>true, 'po'=>$po, 'items'=>$items->fetchAll(PDO::FETCH_ASSOC), 'grns'=>$grns->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        }
        case 'po_save': {
            if (!in_array($user['role'], ['super_admin','admin','manager','accountant'], true)) erp_error('Forbidden', 403);
            $id = (int)($body['id'] ?? 0);
            $vendorId = (int)($body['vendor_id'] ?? 0);
            if (!$vendorId) erp_error('vendor_id required');
            $items = $body['items'] ?? [];
            if (!is_array($items) || empty($items)) erp_error('At least one item required');

            $poDate = (string)($body['po_date'] ?? date('Y-m-d'));
            $expDate = (string)($body['expected_date'] ?? '') ?: null;
            $place = trim((string)($body['place_of_supply'] ?? '')) ?: null;
            $notes = trim((string)($body['notes'] ?? '')) ?: null;
            $status = (string)($body['status'] ?? 'draft');

            // compute totals
            $subtotal = 0; $tax = 0;
            $prepared = [];
            foreach ($items as $it) {
                $qty = (float)($it['qty'] ?? 0);
                $rate = (float)($it['unit_price'] ?? 0);
                $gst = (float)($it['gst_rate'] ?? 0);
                $taxable = $qty * $rate;
                $taxAmt = $taxable * $gst / 100;
                $lineTotal = $taxable + $taxAmt;
                $subtotal += $taxable;
                $tax += $taxAmt;
                $prepared[] = [
                    'item_id' => isset($it['item_id']) ? (int)$it['item_id'] : null,
                    'description' => trim((string)($it['description'] ?? '')) ?: ($it['item_name'] ?? 'Item'),
                    'hsn_code' => trim((string)($it['hsn_code'] ?? '')) ?: null,
                    'qty' => $qty,
                    'unit' => trim((string)($it['unit'] ?? 'pcs')) ?: 'pcs',
                    'unit_price' => $rate,
                    'gst_rate' => $gst,
                    'taxable_value' => $taxable,
                    'tax_amount' => $taxAmt,
                    'line_total' => $lineTotal,
                ];
            }
            $grand = round($subtotal + $tax, 2);

            $db->beginTransaction();
            try {
                if ($id > 0) {
                    $db->prepare("UPDATE erp_purchase_orders SET vendor_id=?, po_date=?, expected_date=?, status=?,
                                  subtotal=?, tax_total=?, grand_total=?, place_of_supply=?, notes=? WHERE id=?")
                       ->execute([$vendorId, $poDate, $expDate, $status, $subtotal, $tax, $grand, $place, $notes, $id]);
                    $db->prepare("DELETE FROM erp_po_items WHERE po_id = ?")->execute([$id]);
                } else {
                    $poNumber = erp_next_doc_no('po', $poDate);
                    $db->prepare("INSERT INTO erp_purchase_orders (po_number, vendor_id, po_date, expected_date, status,
                                  subtotal, tax_total, grand_total, place_of_supply, notes, created_by)
                                  VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                       ->execute([$poNumber, $vendorId, $poDate, $expDate, $status, $subtotal, $tax, $grand,
                                  $place, $notes, $user['id']]);
                    $id = (int)$db->lastInsertId();
                }
                $ins = $db->prepare("INSERT INTO erp_po_items
                    (po_id, item_id, description, hsn_code, qty, unit, unit_price, gst_rate, taxable_value, tax_amount, line_total)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                foreach ($prepared as $p) {
                    $ins->execute([$id, $p['item_id'], $p['description'], $p['hsn_code'], $p['qty'], $p['unit'],
                                   $p['unit_price'], $p['gst_rate'], $p['taxable_value'], $p['tax_amount'], $p['line_total']]);
                }
                $db->commit();
            } catch (\Throwable $e) { $db->rollBack(); throw $e; }

            erp_log_audit('po_save', "PO #$id saved (total ₹$grand)", ['by'=>$user['id']], 'low', $user['id']);
            erp_json(['ok'=>true, 'id'=>$id]);
            break;
        }
        case 'po_cancel': {
            if (!in_array($user['role'], ['super_admin','admin','manager'], true)) erp_error('Forbidden', 403);
            $id = (int)($body['id'] ?? 0);
            if (!$id) erp_error('id required');
            $db->prepare("UPDATE erp_purchase_orders SET status='cancelled' WHERE id=?")->execute([$id]);
            erp_log_audit('po_cancel', "PO #$id cancelled", ['by'=>$user['id']], 'low', $user['id']);
            erp_json(['ok'=>true]);
            break;
        }
        default:
            erp_error("Unknown po action: $action", 400);
    }
}
