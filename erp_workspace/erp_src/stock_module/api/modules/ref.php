<?php
/**
 * ERP — Reference data (states, settings, current org)
 */

function erp_ref_dispatch(string $action, array $user): void {
    $db = erp_db();
    $body = erp_body();
    switch ($action) {
        case 'ref_states': {
            $rows = $db->query("SELECT code, name FROM erp_state_codes ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
            erp_json(['ok'=>true, 'states'=>$rows]);
            break;
        }
        case 'ref_org': {
            $keys = ['org_name','org_address','org_phone','org_email','org_gstin','org_pan',
                    'org_state','org_state_code','org_bank_name','org_bank_account','org_bank_ifsc',
                    'org_bank_branch','org_upi_id','currency_symbol','invoice_prefix','invoice_terms',
                    'grn_prefix','po_prefix','adj_prefix','org_80g_number','org_tagline'];
            $out = [];
            foreach ($keys as $k) $out[$k] = erp_setting($k);
            erp_json(['ok'=>true, 'org'=>$out, 'role'=>$user['role'], 'user'=>[
                'id'=>$user['id'], 'name'=>$user['full_name'] ?: $user['username'], 'email'=>$user['email']
            ]]);
            break;
        }
        case 'ref_update_org': {
            if (!in_array($user['role'], ['super_admin','admin'], true)) erp_error('Forbidden', 403);
            $kv = $body['settings'] ?? [];
            if (!is_array($kv) || empty($kv)) erp_error('settings required');
            $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            foreach ($kv as $k => $v) $stmt->execute([(string)$k, (string)$v]);
            erp_log_audit('erp_settings_update', 'Org settings updated', ['by'=>$user['id'], 'keys'=>array_keys($kv)], 'medium', $user['id']);
            erp_json(['ok'=>true, 'updated'=>count($kv)]);
            break;
        }
        default:
            erp_error("Unknown ref action: $action", 400);
    }
}
