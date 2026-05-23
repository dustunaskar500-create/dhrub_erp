<?php
/**
 * ERP — Vendor / Supplier management
 */

function erp_vendor_dispatch(string $action, array $user): void {
    $db = erp_db();
    $body = erp_body();
    switch ($action) {
        case 'vendor_list': {
            $q = trim((string)($body['q'] ?? ''));
            $where = "WHERE 1=1";
            $params = [];
            if ($q !== '') { $where .= " AND (name LIKE ? OR vendor_code LIKE ? OR gstin LIKE ? OR email LIKE ?)";
                $like = "%$q%"; $params = [$like,$like,$like,$like]; }
            $stmt = $db->prepare("SELECT * FROM erp_vendors $where ORDER BY name ASC LIMIT 200");
            $stmt->execute($params);
            erp_json(['ok'=>true, 'vendors'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        }
        case 'vendor_get': {
            $id = (int)($body['id'] ?? 0);
            if (!$id) erp_error('id required');
            $stmt = $db->prepare("SELECT * FROM erp_vendors WHERE id=?");
            $stmt->execute([$id]);
            $v = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$v) erp_error('Vendor not found', 404);
            erp_json(['ok'=>true, 'vendor'=>$v]);
            break;
        }
        case 'vendor_save': {
            if (!in_array($user['role'], ['super_admin','admin','manager','accountant'], true)) erp_error('Forbidden', 403);
            $id = (int)($body['id'] ?? 0);
            $name = trim((string)($body['name'] ?? ''));
            if (!$name) erp_error('name required');
            $gstin = strtoupper(trim((string)($body['gstin'] ?? '')));
            $stateCode = null;
            if ($gstin) {
                $stateCode = erp_gstin_state_code($gstin);
                if (!$stateCode) erp_error('Invalid GSTIN format');
            }
            $fields = [
                'name'           => $name,
                'gstin'          => $gstin ?: null,
                'pan'            => strtoupper(trim((string)($body['pan'] ?? ''))) ?: null,
                'contact_person' => trim((string)($body['contact_person'] ?? '')) ?: null,
                'email'          => trim((string)($body['email'] ?? '')) ?: null,
                'phone'          => trim((string)($body['phone'] ?? '')) ?: null,
                'address'        => trim((string)($body['address'] ?? '')) ?: null,
                'city'           => trim((string)($body['city'] ?? '')) ?: null,
                'state'          => trim((string)($body['state'] ?? '')) ?: null,
                'state_code'     => $stateCode ?? (trim((string)($body['state_code'] ?? '')) ?: null),
                'pincode'        => trim((string)($body['pincode'] ?? '')) ?: null,
                'bank_account'   => trim((string)($body['bank_account'] ?? '')) ?: null,
                'bank_ifsc'      => strtoupper(trim((string)($body['bank_ifsc'] ?? ''))) ?: null,
                'payment_terms'  => trim((string)($body['payment_terms'] ?? '')) ?: null,
                'is_active'      => isset($body['is_active']) ? (int)(bool)$body['is_active'] : 1,
                'notes'          => trim((string)($body['notes'] ?? '')) ?: null,
            ];
            if ($id > 0) {
                $sets = [];
                $params = [];
                foreach ($fields as $k => $v) { $sets[] = "$k = ?"; $params[] = $v; }
                $params[] = $id;
                $db->prepare("UPDATE erp_vendors SET " . implode(', ', $sets) . " WHERE id = ?")
                   ->execute($params);
                erp_log_audit('vendor_update', "Vendor #$id updated: $name", ['by'=>$user['id']], 'low', $user['id']);
            } else {
                $fields['vendor_code'] = trim((string)($body['vendor_code'] ?? '')) ?: ('VND-' . strtoupper(bin2hex(random_bytes(3))));
                $fields['created_by']  = (int)$user['id'];
                $cols = array_keys($fields);
                $place = implode(',', array_fill(0, count($cols), '?'));
                $db->prepare("INSERT INTO erp_vendors (" . implode(',', $cols) . ") VALUES ($place)")
                   ->execute(array_values($fields));
                $id = (int)$db->lastInsertId();
                erp_log_audit('vendor_create', "Vendor created: $name (#$id)", ['by'=>$user['id']], 'low', $user['id']);
            }
            $stmt = $db->prepare("SELECT * FROM erp_vendors WHERE id=?");
            $stmt->execute([$id]);
            erp_json(['ok'=>true, 'vendor'=>$stmt->fetch(PDO::FETCH_ASSOC), 'id'=>$id]);
            break;
        }
        case 'vendor_delete': {
            if (!in_array($user['role'], ['super_admin','admin'], true)) erp_error('Forbidden', 403);
            $id = (int)($body['id'] ?? 0);
            if (!$id) erp_error('id required');
            // soft-delete via is_active=0 (we keep FK refs intact for history)
            $db->prepare("UPDATE erp_vendors SET is_active = 0 WHERE id = ?")->execute([$id]);
            erp_log_audit('vendor_archive', "Vendor #$id archived", ['by'=>$user['id']], 'low', $user['id']);
            erp_json(['ok'=>true, 'archived'=>$id]);
            break;
        }
        default:
            erp_error("Unknown vendor action: $action", 400);
    }
}
