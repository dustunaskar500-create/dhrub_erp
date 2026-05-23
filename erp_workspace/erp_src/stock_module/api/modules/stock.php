<?php
/**
 * ERP — Stock items + movements + adjustments
 *
 * Item master = inventory_items (extended with sku/hsn/gst etc.)
 * Movements   = inventory_transactions (existing) plus GRN-driven entries
 * Adjustments = erp_stock_adjustments (new)
 *
 * Profit & Loss view: realised loss = sum(adjustments where adj_type IN
 * (damage,shortage,loss,wastage,theft) and direction=out) * unit_cost
 */

function erp_stock_dispatch(string $action, array $user): void {
    $db = erp_db();
    $body = erp_body();

    switch ($action) {
        case 'stock_items': {
            $q = trim((string)($body['q'] ?? ''));
            $low = !empty($body['low_stock']);
            $where = "WHERE 1=1";
            $params = [];
            if ($q !== '') {
                $where .= " AND (item_name LIKE ? OR sku LIKE ? OR barcode LIKE ? OR hsn_code LIKE ?)";
                $like = "%$q%"; $params = [$like,$like,$like,$like];
            }
            if ($low) $where .= " AND quantity <= min_stock";
            $stmt = $db->prepare("SELECT id, sku, item_name, description, category, hsn_code, gst_rate,
                                          quantity, unit, min_stock, reorder_qty, cost_price, sale_price,
                                          location, image_path, is_active, barcode, updated_at
                                   FROM inventory_items $where
                                   ORDER BY item_name ASC LIMIT 500");
            $stmt->execute($params);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // KPIs
            $kpi = $db->query("SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN quantity <= min_stock THEN 1 ELSE 0 END) AS low,
                SUM(CASE WHEN quantity = 0 THEN 1 ELSE 0 END) AS out_of_stock,
                COALESCE(SUM(quantity * cost_price), 0) AS stock_value
                FROM inventory_items WHERE is_active = 1")->fetch(PDO::FETCH_ASSOC);
            erp_json(['ok'=>true, 'items'=>$items, 'kpi'=>$kpi]);
            break;
        }

        case 'stock_get': {
            $id = (int)($body['id'] ?? 0);
            if (!$id) erp_error('id required');
            $s = $db->prepare("SELECT * FROM inventory_items WHERE id=?");
            $s->execute([$id]);
            $item = $s->fetch(PDO::FETCH_ASSOC);
            if (!$item) erp_error('Not found', 404);
            // recent transactions
            $tx = $db->prepare("SELECT t.*, u.full_name AS by_name
                                FROM inventory_transactions t
                                LEFT JOIN users u ON u.id = t.created_by
                                WHERE t.item_id = ?
                                ORDER BY t.id DESC LIMIT 50");
            $tx->execute([$id]);
            // recent adjustments
            $adj = $db->prepare("SELECT a.*, u.full_name AS by_name
                                 FROM erp_stock_adjustments a
                                 LEFT JOIN users u ON u.id = a.created_by
                                 WHERE a.item_id = ?
                                 ORDER BY a.id DESC LIMIT 30");
            $adj->execute([$id]);
            erp_json([
                'ok'=>true,
                'item'=>$item,
                'transactions'=>$tx->fetchAll(PDO::FETCH_ASSOC),
                'adjustments'=>$adj->fetchAll(PDO::FETCH_ASSOC),
            ]);
            break;
        }

        case 'stock_save': {
            if (!in_array($user['role'], ['super_admin','admin','manager'], true)) erp_error('Forbidden', 403);
            $id = (int)($body['id'] ?? 0);
            $name = trim((string)($body['item_name'] ?? ''));
            if (!$name) erp_error('item_name required');
            $cat = (string)($body['category'] ?? 'other');
            $allowedCats = ['food','clothing','medical','educational','household','equipment','other'];
            if (!in_array($cat, $allowedCats, true)) $cat = 'other';

            $fields = [
                'sku'        => trim((string)($body['sku'] ?? '')) ?: null,
                'item_name'  => $name,
                'description'=> trim((string)($body['description'] ?? '')) ?: null,
                'category'   => $cat,
                'hsn_code'   => trim((string)($body['hsn_code'] ?? '')) ?: null,
                'gst_rate'   => (float)($body['gst_rate'] ?? 0),
                'quantity'   => isset($body['quantity']) ? (int)$body['quantity'] : 0,
                'unit'       => trim((string)($body['unit'] ?? 'pcs')) ?: 'pcs',
                'min_stock'  => isset($body['min_stock']) ? (int)$body['min_stock'] : 0,
                'reorder_qty'=> isset($body['reorder_qty']) ? (int)$body['reorder_qty'] : 0,
                'cost_price' => (float)($body['cost_price'] ?? 0),
                'sale_price' => (float)($body['sale_price'] ?? 0),
                'barcode'    => trim((string)($body['barcode'] ?? '')) ?: null,
                'location'   => trim((string)($body['location'] ?? '')) ?: null,
                'is_active'  => isset($body['is_active']) ? (int)(bool)$body['is_active'] : 1,
            ];

            // optional image upload (base64)
            if (!empty($body['image_data'])) {
                $up = erp_save_base64((string)$body['image_data'], (string)($body['image_name'] ?? 'item.jpg'),
                                      'items', ['image/*']);
                $fields['image_path'] = $up['url'];
            } elseif (isset($body['image_path'])) {
                $fields['image_path'] = (string)$body['image_path'] ?: null;
            }

            if ($id > 0) {
                $sets = []; $params = [];
                foreach ($fields as $k=>$v) { $sets[] = "$k = ?"; $params[] = $v; }
                $params[] = $id;
                $db->prepare("UPDATE inventory_items SET " . implode(', ', $sets) . " WHERE id=?")
                   ->execute($params);
                erp_log_audit('item_update', "Item #$id updated: $name", ['by'=>$user['id']], 'low', $user['id']);
            } else {
                $cols = array_keys($fields);
                $place = implode(',', array_fill(0, count($cols), '?'));
                $db->prepare("INSERT INTO inventory_items (" . implode(',', $cols) . ") VALUES ($place)")
                   ->execute(array_values($fields));
                $id = (int)$db->lastInsertId();
                erp_log_audit('item_create', "Item created: $name (#$id)", ['by'=>$user['id']], 'low', $user['id']);
            }
            $s = $db->prepare("SELECT * FROM inventory_items WHERE id=?");
            $s->execute([$id]);
            erp_json(['ok'=>true, 'item'=>$s->fetch(PDO::FETCH_ASSOC), 'id'=>$id]);
            break;
        }

        case 'stock_movements': {
            $itemId = (int)($body['item_id'] ?? 0);
            $from = (string)($body['from'] ?? '');
            $to   = (string)($body['to']   ?? '');
            $where = "WHERE 1=1";
            $params = [];
            if ($itemId) { $where .= " AND t.item_id = ?"; $params[] = $itemId; }
            if ($from)   { $where .= " AND t.transaction_date >= ?"; $params[] = $from; }
            if ($to)     { $where .= " AND t.transaction_date <= ?"; $params[] = $to; }
            $sql = "SELECT t.*, i.item_name, i.sku, i.unit, u.full_name AS by_name
                    FROM inventory_transactions t
                    JOIN inventory_items i ON i.id = t.item_id
                    LEFT JOIN users u ON u.id = t.created_by
                    $where ORDER BY t.id DESC LIMIT 200";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            erp_json(['ok'=>true, 'movements'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        }

        case 'stock_adjust_list': {
            $status = (string)($body['status'] ?? '');
            $type   = (string)($body['adj_type'] ?? '');
            $where = "WHERE 1=1";
            $params = [];
            if ($status) { $where .= " AND a.status = ?"; $params[] = $status; }
            if ($type)   { $where .= " AND a.adj_type = ?"; $params[] = $type; }
            $stmt = $db->prepare("SELECT a.*, i.item_name, i.sku, i.unit, u.full_name AS by_name,
                                          ap.full_name AS approver_name
                                   FROM erp_stock_adjustments a
                                   JOIN inventory_items i ON i.id = a.item_id
                                   LEFT JOIN users u ON u.id = a.created_by
                                   LEFT JOIN users ap ON ap.id = a.approved_by
                                   $where ORDER BY a.id DESC LIMIT 200");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // P&L impact
            $pl = $db->query("SELECT
                COALESCE(SUM(CASE WHEN adj_type IN ('damage','shortage','loss','wastage','theft','return_out') THEN value_impact ELSE 0 END),0) AS total_loss,
                COALESCE(SUM(CASE WHEN adj_type IN ('excess','found','return_in') THEN value_impact ELSE 0 END),0) AS total_gain,
                COUNT(*) AS total_adjustments
                FROM erp_stock_adjustments WHERE status='approved'")->fetch(PDO::FETCH_ASSOC);
            erp_json(['ok'=>true, 'adjustments'=>$rows, 'pl'=>$pl]);
            break;
        }

        case 'stock_adjust_create': {
            if (!in_array($user['role'], ['super_admin','admin','manager','accountant'], true)) erp_error('Forbidden', 403);
            $itemId = (int)($body['item_id'] ?? 0);
            $type   = (string)($body['adj_type'] ?? '');
            $qty    = (float)($body['qty'] ?? 0);
            if (!$itemId || !$type || $qty <= 0) erp_error('item_id, adj_type, qty required');
            $allowed = ['damage','shortage','excess','wastage','loss','found','theft','return_in','return_out','correction'];
            if (!in_array($type, $allowed, true)) erp_error('Invalid adj_type');

            $item = $db->prepare("SELECT * FROM inventory_items WHERE id=?");
            $item->execute([$itemId]);
            $itemRow = $item->fetch(PDO::FETCH_ASSOC);
            if (!$itemRow) erp_error('Item not found', 404);

            $unitCost = isset($body['unit_cost']) ? (float)$body['unit_cost'] : (float)$itemRow['cost_price'];
            $direction = in_array($type, ['excess','found','return_in'], true) ? 'in' : 'out';
            $valueImpact = $qty * $unitCost;

            // optional evidence upload (base64)
            $evidencePath = null;
            if (!empty($body['evidence_data'])) {
                $up = erp_save_base64((string)$body['evidence_data'],
                                      (string)($body['evidence_name'] ?? 'evidence'),
                                      'adjustments', ['image/*','video/*','application/pdf']);
                $evidencePath = $up['url'];
            }

            $adjNo = erp_next_doc_no('adj');
            // super_admin/admin/manager auto-approve; others -> pending
            $autoApprove = in_array($user['role'], ['super_admin','admin','manager'], true);
            $status = $autoApprove ? 'approved' : 'pending';

            $stmt = $db->prepare("INSERT INTO erp_stock_adjustments
                (adj_number, item_id, adj_type, qty, unit_cost, value_impact, direction, reason,
                 evidence_path, grn_id, status, created_by, approved_by, approved_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $adjNo, $itemId, $type, $qty, $unitCost, $valueImpact, $direction,
                trim((string)($body['reason'] ?? '')) ?: null,
                $evidencePath,
                isset($body['grn_id']) ? (int)$body['grn_id'] : null,
                $status, $user['id'],
                $autoApprove ? $user['id'] : null,
                $autoApprove ? date('Y-m-d H:i:s') : null,
            ]);
            $adjId = (int)$db->lastInsertId();

            // if approved, apply to stock
            if ($autoApprove) {
                erp_apply_adjustment_to_stock($db, $itemId, $direction, $qty, $type, $adjId, (int)$user['id']);
            }

            erp_log_audit('stock_adjust', "Stock $type adjustment $adjNo for item #$itemId ($qty $itemRow[unit])",
                          ['adj_id'=>$adjId, 'value_impact'=>$valueImpact], 'medium', $user['id']);

            $s = $db->prepare("SELECT * FROM erp_stock_adjustments WHERE id=?");
            $s->execute([$adjId]);
            erp_json(['ok'=>true, 'adjustment'=>$s->fetch(PDO::FETCH_ASSOC), 'id'=>$adjId]);
            break;
        }

        case 'stock_adjust_approve': {
            if (!in_array($user['role'], ['super_admin','admin','manager'], true)) erp_error('Forbidden', 403);
            $id = (int)($body['id'] ?? 0);
            $decision = (string)($body['decision'] ?? 'approve'); // approve|reject
            if (!$id) erp_error('id required');
            $row = $db->prepare("SELECT * FROM erp_stock_adjustments WHERE id=?");
            $row->execute([$id]);
            $adj = $row->fetch(PDO::FETCH_ASSOC);
            if (!$adj) erp_error('Not found', 404);
            if ($adj['status'] !== 'pending') erp_error('Already ' . $adj['status']);
            if ($decision === 'approve') {
                $db->prepare("UPDATE erp_stock_adjustments SET status='approved', approved_by=?, approved_at=NOW() WHERE id=?")
                   ->execute([$user['id'], $id]);
                erp_apply_adjustment_to_stock($db, (int)$adj['item_id'], $adj['direction'],
                    (float)$adj['qty'], $adj['adj_type'], $id, (int)$user['id']);
                erp_log_audit('stock_adjust_approve', "Approved stock adjustment #$id", ['by'=>$user['id']], 'medium', $user['id']);
            } else {
                $db->prepare("UPDATE erp_stock_adjustments SET status='rejected', approved_by=?, approved_at=NOW() WHERE id=?")
                   ->execute([$user['id'], $id]);
                erp_log_audit('stock_adjust_reject', "Rejected stock adjustment #$id", ['by'=>$user['id']], 'medium', $user['id']);
            }
            erp_json(['ok'=>true, 'id'=>$id, 'status'=> $decision === 'approve' ? 'approved' : 'rejected']);
            break;
        }

        case 'stock_pnl': {
            $from = (string)($body['from'] ?? date('Y-m-01'));
            $to   = (string)($body['to'] ?? date('Y-m-d'));
            // Loss buckets
            $s = $db->prepare("SELECT adj_type,
                                      COUNT(*) AS cnt,
                                      SUM(qty)          AS total_qty,
                                      SUM(value_impact) AS total_value
                               FROM erp_stock_adjustments
                               WHERE status='approved' AND DATE(created_at) BETWEEN ? AND ?
                               GROUP BY adj_type");
            $s->execute([$from, $to]);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
            $loss = 0; $gain = 0;
            foreach ($rows as $r) {
                if (in_array($r['adj_type'], ['damage','shortage','loss','wastage','theft','return_out'], true)) $loss += (float)$r['total_value'];
                else $gain += (float)$r['total_value'];
            }
            // GRN-side losses (damage/short noted at receiving)
            $grn = $db->prepare("SELECT COALESCE(SUM(value_loss),0) AS grn_loss,
                                       COALESCE(SUM(total_qty_damaged),0) AS dmg,
                                       COALESCE(SUM(total_qty_short),0) AS sh
                                FROM erp_grns
                                WHERE status='posted' AND DATE(received_date) BETWEEN ? AND ?");
            $grn->execute([$from, $to]);
            $grnRow = $grn->fetch(PDO::FETCH_ASSOC);
            erp_json(['ok'=>true, 'from'=>$from, 'to'=>$to,
                'breakdown'=>$rows,
                'totals'=>[
                    'realised_loss' => round($loss + (float)$grnRow['grn_loss'], 2),
                    'realised_gain' => round($gain, 2),
                    'net_impact'    => round($gain - $loss - (float)$grnRow['grn_loss'], 2),
                ],
                'grn_summary'=>$grnRow,
            ]);
            break;
        }

        default:
            erp_error("Unknown stock action: $action", 400);
    }
}

/**
 * Apply an approved adjustment to inventory_items.quantity and write into
 * inventory_transactions for full audit trail.
 */
function erp_apply_adjustment_to_stock(PDO $db, int $itemId, string $direction, float $qty, string $type, int $adjId, int $userId): void {
    $sign = $direction === 'in' ? '+' : '-';
    $db->prepare("UPDATE inventory_items SET quantity = GREATEST(0, quantity {$sign} ?) WHERE id = ?")
       ->execute([(int)$qty, $itemId]);
    $db->prepare("INSERT INTO inventory_transactions (item_id, transaction_type, quantity, transaction_date, reference, notes, created_by)
                  VALUES (?,?,?,?,?,?,?)")
       ->execute([
           $itemId,
           $direction === 'in' ? 'in' : 'out',
           (int)$qty,
           date('Y-m-d'),
           "ADJ#$adjId",
           "Stock adjustment ($type)",
           $userId,
       ]);
}
