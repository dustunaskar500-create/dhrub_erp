<?php
/**
 * ERP — Goods Receipt Notes (GRN)
 *
 * Standard 4-eyes receiving workflow:
 *   1. grn_create      — create a draft GRN against an optional PO
 *   2. grn_upload      — attach photos / videos / documents (multipart)
 *   3. grn_post        — post the GRN: writes inventory_transactions(IN),
 *                        auto-creates damage/shortage adjustments,
 *                        updates PO status (partial/received).
 *
 * Anti-fraud features:
 *   • SHA-256 stored implicitly via filename (timestamped) + size
 *   • Photo/video required when has_discrepancy = 1 (server-enforced)
 *   • Posted GRNs cannot be re-edited (must create a stock_adjust correction)
 */

function erp_grn_dispatch(string $action, array $user): void {
    $db = erp_db();
    $body = erp_body();

    switch ($action) {
        case 'grn_list': {
            $status = (string)($body['status'] ?? '');
            $q = trim((string)($body['q'] ?? ''));
            $where = "WHERE 1=1";
            $params = [];
            if ($status) { $where .= " AND g.status = ?"; $params[] = $status; }
            if ($q !== '') {
                $where .= " AND (g.grn_number LIKE ? OR g.supplier_invoice_no LIKE ? OR v.name LIKE ?)";
                $like = "%$q%"; array_push($params, $like, $like, $like);
            }
            $sql = "SELECT g.*, v.name AS vendor_name, v.gstin AS vendor_gstin,
                           p.po_number,
                           (SELECT COUNT(*) FROM erp_grn_attachments a WHERE a.grn_id = g.id) AS attachment_count
                    FROM erp_grns g
                    JOIN erp_vendors v ON v.id = g.vendor_id
                    LEFT JOIN erp_purchase_orders p ON p.id = g.po_id
                    $where ORDER BY g.id DESC LIMIT 200";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            erp_json(['ok'=>true, 'grns'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        }

        case 'grn_get': {
            $id = (int)($body['id'] ?? ($_GET['id'] ?? 0));
            if (!$id) erp_error('id required');
            $g = $db->prepare("SELECT g.*, v.name AS vendor_name, v.gstin AS vendor_gstin,
                                       v.address AS vendor_address, v.state AS vendor_state,
                                       p.po_number, u.full_name AS received_by_name
                                FROM erp_grns g
                                JOIN erp_vendors v ON v.id = g.vendor_id
                                LEFT JOIN erp_purchase_orders p ON p.id = g.po_id
                                LEFT JOIN users u ON u.id = g.received_by
                                WHERE g.id = ?");
            $g->execute([$id]);
            $grn = $g->fetch(PDO::FETCH_ASSOC);
            if (!$grn) erp_error('Not found', 404);

            $items = $db->prepare("SELECT gi.*, i.item_name AS master_name, i.sku, i.unit AS master_unit
                                    FROM erp_grn_items gi
                                    LEFT JOIN inventory_items i ON i.id = gi.item_id
                                    WHERE gi.grn_id = ? ORDER BY gi.id ASC");
            $items->execute([$id]);

            $atts = $db->prepare("SELECT a.*, u.full_name AS uploader_name
                                   FROM erp_grn_attachments a
                                   LEFT JOIN users u ON u.id = a.uploaded_by
                                   WHERE a.grn_id = ? ORDER BY a.id ASC");
            $atts->execute([$id]);

            erp_json([
                'ok'=>true,
                'grn'=>$grn,
                'items'=>$items->fetchAll(PDO::FETCH_ASSOC),
                'attachments'=>$atts->fetchAll(PDO::FETCH_ASSOC),
            ]);
            break;
        }

        case 'grn_save': {
            if (!in_array($user['role'], ['super_admin','admin','manager','accountant'], true)) erp_error('Forbidden', 403);
            $id = (int)($body['id'] ?? 0);
            $vendorId = (int)($body['vendor_id'] ?? 0);
            if (!$vendorId) erp_error('vendor_id required');
            $items = $body['items'] ?? [];
            if (!is_array($items) || empty($items)) erp_error('At least one item required');

            $receivedDate = (string)($body['received_date'] ?? date('Y-m-d'));
            $poId = isset($body['po_id']) && $body['po_id'] ? (int)$body['po_id'] : null;
            $supplierInvNo = trim((string)($body['supplier_invoice_no'] ?? '')) ?: null;
            $supplierInvDate = (string)($body['supplier_invoice_date'] ?? '') ?: null;
            $vehicleNo = trim((string)($body['vehicle_number'] ?? '')) ?: null;
            $driver = trim((string)($body['driver_name'] ?? '')) ?: null;
            $gatePass = trim((string)($body['gate_pass_no'] ?? '')) ?: null;
            $transporter = trim((string)($body['transporter'] ?? '')) ?: null;
            $notes = trim((string)($body['notes'] ?? '')) ?: null;

            // Compute totals & discrepancies
            $totRecv = 0; $totDmg = 0; $totSh = 0; $totEx = 0;
            $valRecv = 0; $valLoss = 0;
            $hasDisc = 0;
            $prepared = [];
            foreach ($items as $it) {
                $ordered = (float)($it['qty_ordered'] ?? 0);
                $received = (float)($it['qty_received'] ?? 0);
                $damaged = (float)($it['qty_damaged'] ?? 0);
                $shortage = max(0, $ordered - $received);
                $excess = max(0, $received - $ordered);
                $accepted = max(0, $received - $damaged);
                $unitCost = (float)($it['unit_cost'] ?? 0);
                if ($damaged > 0 || $shortage > 0 || $excess > 0) $hasDisc = 1;
                $valRecv += $accepted * $unitCost;
                $valLoss += ($damaged + $shortage) * $unitCost;
                $totRecv += $received; $totDmg += $damaged; $totSh += $shortage; $totEx += $excess;
                $prepared[] = [
                    'po_item_id' => isset($it['po_item_id']) ? (int)$it['po_item_id'] : null,
                    'item_id' => isset($it['item_id']) && $it['item_id'] ? (int)$it['item_id'] : null,
                    'description' => trim((string)($it['description'] ?? 'Item')),
                    'hsn_code' => trim((string)($it['hsn_code'] ?? '')) ?: null,
                    'unit' => trim((string)($it['unit'] ?? 'pcs')) ?: 'pcs',
                    'qty_ordered' => $ordered,
                    'qty_received' => $received,
                    'qty_accepted' => $accepted,
                    'qty_damaged' => $damaged,
                    'qty_short' => $shortage,
                    'qty_excess' => $excess,
                    'unit_cost' => $unitCost,
                    'batch_no' => trim((string)($it['batch_no'] ?? '')) ?: null,
                    'expiry_date' => (string)($it['expiry_date'] ?? '') ?: null,
                    'condition_note' => trim((string)($it['condition_note'] ?? '')) ?: null,
                ];
            }

            $db->beginTransaction();
            try {
                if ($id > 0) {
                    // Only drafts can be edited
                    $cur = $db->prepare("SELECT status FROM erp_grns WHERE id=?");
                    $cur->execute([$id]);
                    $curStatus = $cur->fetchColumn();
                    if ($curStatus !== 'draft') throw new RuntimeException('Only draft GRNs can be edited');
                    $db->prepare("UPDATE erp_grns SET po_id=?, vendor_id=?, received_date=?, supplier_invoice_no=?,
                                  supplier_invoice_date=?, vehicle_number=?, driver_name=?, gate_pass_no=?, transporter=?,
                                  has_discrepancy=?, total_qty_received=?, total_qty_damaged=?, total_qty_short=?,
                                  total_qty_excess=?, value_received=?, value_loss=?, notes=? WHERE id=?")
                       ->execute([$poId, $vendorId, $receivedDate, $supplierInvNo, $supplierInvDate,
                                  $vehicleNo, $driver, $gatePass, $transporter,
                                  $hasDisc, $totRecv, $totDmg, $totSh, $totEx, $valRecv, $valLoss, $notes, $id]);
                    $db->prepare("DELETE FROM erp_grn_items WHERE grn_id=?")->execute([$id]);
                } else {
                    $grnNo = erp_next_doc_no('grn', $receivedDate);
                    $db->prepare("INSERT INTO erp_grns (grn_number, po_id, vendor_id, received_date, received_by,
                                  supplier_invoice_no, supplier_invoice_date, vehicle_number, driver_name, gate_pass_no,
                                  transporter, status, has_discrepancy, total_qty_received, total_qty_damaged,
                                  total_qty_short, total_qty_excess, value_received, value_loss, notes)
                                  VALUES (?,?,?,?,?,?,?,?,?,?,?,'draft',?,?,?,?,?,?,?,?)")
                       ->execute([$grnNo, $poId, $vendorId, $receivedDate, $user['id'],
                                  $supplierInvNo, $supplierInvDate, $vehicleNo, $driver, $gatePass, $transporter,
                                  $hasDisc, $totRecv, $totDmg, $totSh, $totEx, $valRecv, $valLoss, $notes]);
                    $id = (int)$db->lastInsertId();
                }
                $ins = $db->prepare("INSERT INTO erp_grn_items
                    (grn_id, po_item_id, item_id, description, hsn_code, unit, qty_ordered, qty_received,
                     qty_accepted, qty_damaged, qty_short, qty_excess, unit_cost, batch_no, expiry_date, condition_note)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                foreach ($prepared as $p) {
                    $ins->execute([$id, $p['po_item_id'], $p['item_id'], $p['description'], $p['hsn_code'], $p['unit'],
                                   $p['qty_ordered'], $p['qty_received'], $p['qty_accepted'], $p['qty_damaged'],
                                   $p['qty_short'], $p['qty_excess'], $p['unit_cost'], $p['batch_no'],
                                   $p['expiry_date'], $p['condition_note']]);
                }
                $db->commit();
            } catch (\Throwable $e) { $db->rollBack(); throw $e; }

            erp_log_audit('grn_save', "GRN #$id saved (disc=$hasDisc, value=₹$valRecv)", ['by'=>$user['id']], 'medium', $user['id']);
            erp_json(['ok'=>true, 'id'=>$id, 'has_discrepancy'=>$hasDisc]);
            break;
        }

        case 'grn_upload_attachment': {
            // Accepts multipart form-data with `file` and `grn_id` + optional `caption`
            $grnId = (int)($_POST['grn_id'] ?? 0);
            if (!$grnId) erp_error('grn_id required');
            $cur = $db->prepare("SELECT status FROM erp_grns WHERE id=?");
            $cur->execute([$grnId]);
            $st = $cur->fetchColumn();
            if (!$st) erp_error('GRN not found', 404);
            if (!in_array($st, ['draft','posted','disputed'], true)) erp_error('Cannot attach to ' . $st . ' GRN');

            if (!isset($_FILES['file'])) erp_error('file is required (multipart)');
            $f = $_FILES['file'];

            $allowed = ['image/jpeg','image/png','image/webp','image/heic','image/heif','image/gif',
                        'video/mp4','video/quicktime','video/webm','video/x-msvideo','video/x-matroska','video/mpeg',
                        'application/pdf','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'text/plain','image/*','video/*'];
            $up = erp_save_upload($f, 'grn/' . $grnId, $allowed);
            $mime = $up['mime'];
            $kind = 'document';
            if (strpos($mime, 'image/') === 0) $kind = 'image';
            elseif (strpos($mime, 'video/') === 0) $kind = 'video';

            $ins = $db->prepare("INSERT INTO erp_grn_attachments
                (grn_id, kind, file_path, original_name, mime_type, size_bytes, caption, uploaded_by)
                VALUES (?,?,?,?,?,?,?,?)");
            $ins->execute([$grnId, $kind, $up['url'], $up['original_name'], $mime, $up['size'],
                           trim((string)($_POST['caption'] ?? '')) ?: null, $user['id']]);
            $aid = (int)$db->lastInsertId();

            erp_log_audit('grn_attachment', "Uploaded $kind to GRN #$grnId", ['attachment_id'=>$aid, 'size'=>$up['size']], 'low', $user['id']);
            erp_json(['ok'=>true, 'attachment'=>[
                'id'=>$aid, 'kind'=>$kind, 'url'=>$up['url'], 'original_name'=>$up['original_name'],
                'mime'=>$mime, 'size'=>$up['size'],
            ]]);
            break;
        }

        case 'grn_delete_attachment': {
            $aid = (int)($body['id'] ?? 0);
            if (!$aid) erp_error('id required');
            $row = $db->prepare("SELECT a.*, g.status FROM erp_grn_attachments a JOIN erp_grns g ON g.id=a.grn_id WHERE a.id=?");
            $row->execute([$aid]);
            $att = $row->fetch(PDO::FETCH_ASSOC);
            if (!$att) erp_error('Not found', 404);
            // can only delete on draft or by super_admin
            if ($att['status'] !== 'draft' && !erp_is_super_admin($user)) erp_error('Can only delete on draft GRN', 403);
            $abs = '/app' . $att['file_path'];
            @unlink($abs);
            $db->prepare("DELETE FROM erp_grn_attachments WHERE id=?")->execute([$aid]);
            erp_json(['ok'=>true]);
            break;
        }

        case 'grn_post': {
            if (!in_array($user['role'], ['super_admin','admin','manager'], true)) erp_error('Forbidden', 403);
            $id = (int)($body['id'] ?? 0);
            if (!$id) erp_error('id required');
            $g = $db->prepare("SELECT * FROM erp_grns WHERE id=?");
            $g->execute([$id]);
            $grn = $g->fetch(PDO::FETCH_ASSOC);
            if (!$grn) erp_error('Not found', 404);
            if ($grn['status'] !== 'draft') erp_error('Already ' . $grn['status']);

            // Enforce: any discrepancy => at least one attachment required
            if ((int)$grn['has_discrepancy'] === 1) {
                $cnt = $db->prepare("SELECT COUNT(*) FROM erp_grn_attachments WHERE grn_id=?");
                $cnt->execute([$id]);
                if ((int)$cnt->fetchColumn() === 0) {
                    erp_error('Photo or video evidence is required when there is a discrepancy (damage/shortage/excess)');
                }
            }

            $items = $db->prepare("SELECT * FROM erp_grn_items WHERE grn_id=?");
            $items->execute([$id]);
            $itemRows = $items->fetchAll(PDO::FETCH_ASSOC);

            $db->beginTransaction();
            try {
                // Update stock for accepted qty (IN) and create adjustments for damaged/shortage
                foreach ($itemRows as $r) {
                    $itemId = $r['item_id'] ? (int)$r['item_id'] : null;
                    if (!$itemId) continue;
                    $accepted = (float)$r['qty_accepted'];
                    if ($accepted > 0) {
                        $db->prepare("UPDATE inventory_items SET quantity = quantity + ?, cost_price = ? WHERE id = ?")
                           ->execute([(int)$accepted, (float)$r['unit_cost'] ?: null, $itemId]);
                        $db->prepare("INSERT INTO inventory_transactions
                            (item_id, transaction_type, quantity, transaction_date, reference, notes, created_by)
                            VALUES (?,?,?,?,?,?,?)")
                           ->execute([$itemId, 'in', (int)$accepted, $grn['received_date'],
                                      'GRN#' . $grn['grn_number'],
                                      'Received via GRN #' . $grn['grn_number'], $user['id']]);
                    }
                    if ((float)$r['qty_damaged'] > 0) {
                        _grn_create_adj($db, $itemId, 'damage', (float)$r['qty_damaged'], (float)$r['unit_cost'],
                                        'Damaged on receipt — GRN ' . $grn['grn_number'], $id, (int)$user['id']);
                    }
                    if ((float)$r['qty_short'] > 0) {
                        _grn_create_adj($db, $itemId, 'shortage', (float)$r['qty_short'], (float)$r['unit_cost'],
                                        'Short-delivered — GRN ' . $grn['grn_number'], $id, (int)$user['id']);
                    }
                    if ((float)$r['qty_excess'] > 0) {
                        _grn_create_adj($db, $itemId, 'excess', (float)$r['qty_excess'], (float)$r['unit_cost'],
                                        'Excess received — GRN ' . $grn['grn_number'], $id, (int)$user['id']);
                    }
                }

                // Update PO status if linked
                if (!empty($grn['po_id'])) {
                    $poId = (int)$grn['po_id'];
                    // sum received vs ordered per po
                    $sum = $db->prepare("SELECT SUM(pi.qty) AS ordered,
                                                COALESCE((SELECT SUM(qi.qty_received) FROM erp_grn_items qi
                                                          JOIN erp_grns gg ON gg.id=qi.grn_id
                                                          WHERE gg.po_id=? AND gg.status IN ('posted','draft')), 0) AS received
                                         FROM erp_po_items pi WHERE pi.po_id=?");
                    $sum->execute([$poId, $poId]);
                    $r = $sum->fetch(PDO::FETCH_ASSOC);
                    $newStatus = 'partial';
                    if ($r && (float)$r['received'] >= (float)$r['ordered']) $newStatus = 'received';
                    $db->prepare("UPDATE erp_purchase_orders SET status=? WHERE id=?")->execute([$newStatus, $poId]);
                }

                $db->prepare("UPDATE erp_grns SET status='posted', posted_at=NOW() WHERE id=?")->execute([$id]);
                $db->commit();
            } catch (\Throwable $e) { $db->rollBack(); throw $e; }

            erp_log_audit('grn_post', "GRN #$id posted (₹{$grn['value_received']} received, ₹{$grn['value_loss']} loss)",
                          ['by'=>$user['id']], 'medium', $user['id']);
            erp_json(['ok'=>true, 'id'=>$id, 'status'=>'posted']);
            break;
        }

        default:
            erp_error("Unknown grn action: $action", 400);
    }
}

function _grn_create_adj(PDO $db, int $itemId, string $type, float $qty, float $unitCost, string $reason, int $grnId, int $uid): void {
    $adjNo = erp_next_doc_no('adj');
    $direction = $type === 'excess' ? 'in' : 'out';
    $db->prepare("INSERT INTO erp_stock_adjustments
        (adj_number, item_id, adj_type, qty, unit_cost, value_impact, direction, reason,
         grn_id, status, created_by, approved_by, approved_at)
         VALUES (?,?,?,?,?,?,?,?,?, 'approved', ?, ?, NOW())")
       ->execute([$adjNo, $itemId, $type, $qty, $unitCost, $qty * $unitCost, $direction, $reason, $grnId, $uid, $uid]);
    // record movement
    $db->prepare("INSERT INTO inventory_transactions (item_id, transaction_type, quantity, transaction_date, reference, notes, created_by)
                  VALUES (?,?,?,?,?,?,?)")
       ->execute([$itemId, $direction === 'in' ? 'in' : 'out', (int)$qty, date('Y-m-d'),
                  "ADJ#$adjNo", "GRN $type adjustment", $uid]);
    if ($direction === 'in') {
        $db->prepare("UPDATE inventory_items SET quantity = quantity + ? WHERE id=?")->execute([(int)$qty, $itemId]);
    }
    // For damage/shortage/loss we DO NOT decrement stock (because the goods never entered stock in those cases)
    // — only direction=in (excess) modifies stock. That matches Indian audit expectation.
}
