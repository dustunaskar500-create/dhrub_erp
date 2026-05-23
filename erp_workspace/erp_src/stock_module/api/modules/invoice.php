<?php
/**
 * ERP — GST Tax Invoices
 *
 * Supports:
 *   • Tax Invoice (taxable supplies, GSTIN-registered buyer or B2C)
 *   • Bill of Supply (exempt / composition)
 *   • Credit Note / Debit Note (linked via reference_invoice_id)
 *   • Proforma
 *
 * GST allocation:
 *   • Same state (seller state == buyer state) -> CGST + SGST split (half each)
 *   • Inter-state                              -> IGST (full GST rate)
 *
 * PDF generation uses mPDF. If mPDF is not vendored under stock_module/vendor/
 * we fall back to a printable HTML view (browser → print-to-PDF works fine).
 */

// Try to find an mPDF autoload — first the dhrub_erp parent vendor, then our own
$_mpdf_paths = [
    dirname(__DIR__, 3) . '/vendor/autoload.php',            // dhrub_erp root /vendor
    dirname(__DIR__, 2) . '/vendor/autoload.php',            // stock_module/vendor
];
foreach ($_mpdf_paths as $_p) { if (is_file($_p)) { require_once $_p; break; } }

function erp_invoice_dispatch(string $action, array $user): void {
    $db = erp_db();
    $body = erp_body();

    switch ($action) {
        case 'invoice_list': {
            $status = (string)($body['status'] ?? '');
            $from = (string)($body['from'] ?? '');
            $to   = (string)($body['to'] ?? '');
            $where = "WHERE 1=1";
            $params = [];
            if ($status) { $where .= " AND status = ?"; $params[] = $status; }
            if ($from)   { $where .= " AND invoice_date >= ?"; $params[] = $from; }
            if ($to)     { $where .= " AND invoice_date <= ?"; $params[] = $to; }
            $stmt = $db->prepare("SELECT id, invoice_number, invoice_date, invoice_type, buyer_name, buyer_gstin,
                                          taxable_value, total_cgst, total_sgst, total_igst, grand_total,
                                          status, payment_status, paid_amount, due_date, generated_pdf_path
                                   FROM erp_tax_invoices $where ORDER BY id DESC LIMIT 200");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // Aggregates for KPI
            $agg = $db->query("SELECT COUNT(*) AS total,
                                      COALESCE(SUM(grand_total),0) AS revenue,
                                      COALESCE(SUM(total_cgst+total_sgst+total_igst),0) AS tax_collected,
                                      COALESCE(SUM(CASE WHEN payment_status='paid' THEN grand_total ELSE 0 END),0) AS paid_amt,
                                      COALESCE(SUM(CASE WHEN payment_status!='paid' THEN grand_total-paid_amount ELSE 0 END),0) AS outstanding
                               FROM erp_tax_invoices WHERE status NOT IN ('draft','cancelled')")->fetch(PDO::FETCH_ASSOC);
            erp_json(['ok'=>true, 'invoices'=>$rows, 'agg'=>$agg]);
            break;
        }

        case 'invoice_get': {
            $id = (int)($body['id'] ?? ($_GET['id'] ?? 0));
            if (!$id) erp_error('id required');
            $inv = $db->prepare("SELECT * FROM erp_tax_invoices WHERE id=?");
            $inv->execute([$id]);
            $row = $inv->fetch(PDO::FETCH_ASSOC);
            if (!$row) erp_error('Not found', 404);
            $items = $db->prepare("SELECT * FROM erp_tax_invoice_items WHERE invoice_id=? ORDER BY id ASC");
            $items->execute([$id]);
            $pays = $db->prepare("SELECT p.*, u.full_name AS by_name FROM erp_invoice_payments p LEFT JOIN users u ON u.id=p.created_by WHERE invoice_id=? ORDER BY id DESC");
            $pays->execute([$id]);
            erp_json([
                'ok'=>true,
                'invoice'=>$row,
                'items'=>$items->fetchAll(PDO::FETCH_ASSOC),
                'payments'=>$pays->fetchAll(PDO::FETCH_ASSOC),
            ]);
            break;
        }

        case 'invoice_save': {
            if (!in_array($user['role'], ['super_admin','admin','accountant','manager'], true)) erp_error('Forbidden', 403);
            $id = (int)($body['id'] ?? 0);
            $invoiceType = (string)($body['invoice_type'] ?? 'tax_invoice');
            $allowed = ['tax_invoice','bill_of_supply','credit_note','debit_note','proforma'];
            if (!in_array($invoiceType, $allowed, true)) erp_error('Invalid invoice_type');

            $invDate = (string)($body['invoice_date'] ?? date('Y-m-d'));
            $buyerName = trim((string)($body['buyer_name'] ?? ''));
            if (!$buyerName) erp_error('buyer_name required');
            $buyerGstin = strtoupper(trim((string)($body['buyer_gstin'] ?? '')));
            $buyerStateCode = trim((string)($body['buyer_state_code'] ?? ''));
            if ($buyerGstin) {
                $sc = erp_gstin_state_code($buyerGstin);
                if (!$sc) erp_error('Invalid buyer GSTIN');
                $buyerStateCode = $sc;
            }
            $items = $body['items'] ?? [];
            if (!is_array($items) || empty($items)) erp_error('At least one item required');

            $sellerGstin = erp_setting('org_gstin');
            $sellerState = erp_setting('org_state');
            $sellerStateCode = erp_setting('org_state_code');
            $isInterstate = ($buyerStateCode && $sellerStateCode && $buyerStateCode !== $sellerStateCode) ? 1 : 0;
            // bill_of_supply ⇒ no tax
            $noTax = $invoiceType === 'bill_of_supply';

            // Calculate
            $subtotal = 0; $discountTotal = 0; $taxable = 0;
            $cgst = 0; $sgst = 0; $igst = 0;
            $prepared = [];
            foreach ($items as $it) {
                $qty = (float)($it['qty'] ?? 1);
                $rate = (float)($it['unit_price'] ?? 0);
                $discPct = (float)($it['discount_pct'] ?? 0);
                $gstRate = $noTax ? 0 : (float)($it['gst_rate'] ?? 0);
                $gross = $qty * $rate;
                $discAmt = round($gross * $discPct / 100, 2);
                $tv = round($gross - $discAmt, 2);
                $lineCgst = $lineSgst = $lineIgst = 0;
                if ($gstRate > 0) {
                    if ($isInterstate) {
                        $lineIgst = round($tv * $gstRate / 100, 2);
                    } else {
                        $lineCgst = round($tv * $gstRate / 200, 2);
                        $lineSgst = $lineCgst;
                    }
                }
                $lineTotal = round($tv + $lineCgst + $lineSgst + $lineIgst, 2);
                $subtotal += $gross;
                $discountTotal += $discAmt;
                $taxable += $tv;
                $cgst += $lineCgst; $sgst += $lineSgst; $igst += $lineIgst;
                $prepared[] = [
                    'item_id' => isset($it['item_id']) && $it['item_id'] ? (int)$it['item_id'] : null,
                    'description' => trim((string)($it['description'] ?? '')) ?: 'Item',
                    'hsn_code' => trim((string)($it['hsn_code'] ?? '')) ?: null,
                    'qty' => $qty,
                    'unit' => trim((string)($it['unit'] ?? 'pcs')) ?: 'pcs',
                    'unit_price' => $rate,
                    'discount_pct' => $discPct,
                    'discount_amt' => $discAmt,
                    'taxable_value' => $tv,
                    'gst_rate' => $gstRate,
                    'cgst' => $lineCgst, 'sgst' => $lineSgst, 'igst' => $lineIgst,
                    'cess' => 0,
                    'line_total' => $lineTotal,
                ];
            }
            $grandRaw = $taxable + $cgst + $sgst + $igst;
            $grandRounded = round($grandRaw);
            $roundOff = round($grandRounded - $grandRaw, 2);
            $words = erp_number_to_words($grandRounded);
            $fy = erp_fy_code($invDate);

            $fields = [
                'invoice_type' => $invoiceType,
                'invoice_date' => $invDate,
                'fy' => $fy,
                'buyer_name' => $buyerName,
                'buyer_gstin' => $buyerGstin ?: null,
                'buyer_pan' => strtoupper(trim((string)($body['buyer_pan'] ?? ''))) ?: null,
                'buyer_email' => trim((string)($body['buyer_email'] ?? '')) ?: null,
                'buyer_phone' => trim((string)($body['buyer_phone'] ?? '')) ?: null,
                'buyer_address' => trim((string)($body['buyer_address'] ?? '')) ?: null,
                'buyer_state' => trim((string)($body['buyer_state'] ?? '')) ?: null,
                'buyer_state_code' => $buyerStateCode ?: null,
                'place_of_supply' => trim((string)($body['place_of_supply'] ?? ($body['buyer_state'] ?? ''))) ?: null,
                'place_of_supply_code' => $buyerStateCode ?: null,
                'reverse_charge' => isset($body['reverse_charge']) ? (int)(bool)$body['reverse_charge'] : 0,
                'is_interstate' => $isInterstate,
                'seller_gstin' => $sellerGstin,
                'seller_state' => $sellerState,
                'seller_state_code' => $sellerStateCode,
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'taxable_value' => $taxable,
                'total_cgst' => $cgst,
                'total_sgst' => $sgst,
                'total_igst' => $igst,
                'total_cess' => 0,
                'round_off' => $roundOff,
                'grand_total' => $grandRounded,
                'amount_in_words' => $words,
                'due_date' => (string)($body['due_date'] ?? '') ?: null,
                'notes' => trim((string)($body['notes'] ?? '')) ?: null,
                'terms' => trim((string)($body['terms'] ?? '')) ?: erp_setting('invoice_terms', ''),
                'reference_invoice_id' => isset($body['reference_invoice_id']) && $body['reference_invoice_id']
                                          ? (int)$body['reference_invoice_id'] : null,
            ];

            $db->beginTransaction();
            try {
                if ($id > 0) {
                    $cur = $db->prepare("SELECT status FROM erp_tax_invoices WHERE id=?");
                    $cur->execute([$id]);
                    $st = $cur->fetchColumn();
                    if (!in_array($st, ['draft'], true) && !erp_is_super_admin($user)) {
                        throw new RuntimeException('Only drafts can be edited');
                    }
                    $sets = []; $params = [];
                    foreach ($fields as $k => $v) { $sets[] = "$k = ?"; $params[] = $v; }
                    $params[] = $id;
                    $db->prepare("UPDATE erp_tax_invoices SET " . implode(', ', $sets) . " WHERE id = ?")
                       ->execute($params);
                    $db->prepare("DELETE FROM erp_tax_invoice_items WHERE invoice_id = ?")->execute([$id]);
                } else {
                    $fields['invoice_number'] = erp_next_doc_no('invoice', $invDate);
                    $fields['status'] = 'draft';
                    $fields['created_by'] = (int)$user['id'];
                    $cols = array_keys($fields);
                    $place = implode(',', array_fill(0, count($cols), '?'));
                    $db->prepare("INSERT INTO erp_tax_invoices (" . implode(',', $cols) . ") VALUES ($place)")
                       ->execute(array_values($fields));
                    $id = (int)$db->lastInsertId();
                }
                $ins = $db->prepare("INSERT INTO erp_tax_invoice_items
                    (invoice_id, item_id, description, hsn_code, qty, unit, unit_price, discount_pct, discount_amt,
                     taxable_value, gst_rate, cgst, sgst, igst, cess, line_total)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                foreach ($prepared as $p) {
                    $ins->execute([$id, $p['item_id'], $p['description'], $p['hsn_code'], $p['qty'], $p['unit'],
                                   $p['unit_price'], $p['discount_pct'], $p['discount_amt'],
                                   $p['taxable_value'], $p['gst_rate'], $p['cgst'], $p['sgst'], $p['igst'],
                                   $p['cess'], $p['line_total']]);
                }
                $db->commit();
            } catch (\Throwable $e) { $db->rollBack(); throw $e; }

            erp_log_audit('invoice_save', "Invoice #$id saved (₹$grandRounded)", ['by'=>$user['id']], 'medium', $user['id']);
            erp_json(['ok'=>true, 'id'=>$id]);
            break;
        }

        case 'invoice_issue': {
            if (!in_array($user['role'], ['super_admin','admin','accountant','manager'], true)) erp_error('Forbidden', 403);
            $id = (int)($body['id'] ?? 0);
            if (!$id) erp_error('id required');
            $db->prepare("UPDATE erp_tax_invoices SET status='issued' WHERE id=? AND status='draft'")->execute([$id]);
            erp_log_audit('invoice_issue', "Invoice #$id issued", ['by'=>$user['id']], 'medium', $user['id']);
            // pre-generate PDF
            try { _invoice_render_pdf($db, $id, true); } catch (\Throwable $e) {}
            erp_json(['ok'=>true, 'id'=>$id, 'status'=>'issued']);
            break;
        }

        case 'invoice_cancel': {
            if (!in_array($user['role'], ['super_admin','admin'], true)) erp_error('Forbidden', 403);
            $id = (int)($body['id'] ?? 0);
            $db->prepare("UPDATE erp_tax_invoices SET status='cancelled' WHERE id=?")->execute([$id]);
            erp_log_audit('invoice_cancel', "Invoice #$id cancelled", ['by'=>$user['id']], 'high', $user['id']);
            erp_json(['ok'=>true]);
            break;
        }

        case 'invoice_pdf': {
            $id = (int)($body['id'] ?? ($_GET['id'] ?? 0));
            if (!$id) erp_error('id required');
            _invoice_render_pdf($db, $id, false);
            // _invoice_render_pdf calls exit
            break;
        }

        case 'invoice_payment': {
            if (!in_array($user['role'], ['super_admin','admin','accountant'], true)) erp_error('Forbidden', 403);
            $id = (int)($body['invoice_id'] ?? 0);
            $amt = (float)($body['amount'] ?? 0);
            if (!$id || $amt <= 0) erp_error('invoice_id + amount required');
            $stmt = $db->prepare("SELECT grand_total, paid_amount FROM erp_tax_invoices WHERE id=?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) erp_error('Invoice not found', 404);
            $db->prepare("INSERT INTO erp_invoice_payments (invoice_id, payment_date, amount, method, reference_no, notes, created_by)
                          VALUES (?,?,?,?,?,?,?)")
               ->execute([$id, (string)($body['payment_date'] ?? date('Y-m-d')), $amt,
                          (string)($body['method'] ?? 'bank_transfer'),
                          trim((string)($body['reference_no'] ?? '')) ?: null,
                          trim((string)($body['notes'] ?? '')) ?: null,
                          $user['id']]);
            $newPaid = round((float)$row['paid_amount'] + $amt, 2);
            $newStatus = 'partial';
            if ($newPaid >= (float)$row['grand_total'] - 0.5) $newStatus = 'paid';
            $db->prepare("UPDATE erp_tax_invoices SET paid_amount = ?, payment_status = ?,
                          status = CASE WHEN ? = 'paid' THEN 'paid' ELSE status END WHERE id = ?")
               ->execute([$newPaid, $newStatus, $newStatus, $id]);
            erp_log_audit('invoice_payment', "Payment ₹$amt for invoice #$id", ['by'=>$user['id']], 'medium', $user['id']);
            erp_json(['ok'=>true, 'paid'=>$newPaid, 'status'=>$newStatus]);
            break;
        }

        case 'invoice_gst_summary': {
            $from = (string)($body['from'] ?? date('Y-m-01'));
            $to   = (string)($body['to'] ?? date('Y-m-d'));
            // HSN-wise summary (GSTR-1 Table 12-style)
            $hsn = $db->prepare("SELECT ti.hsn_code,
                                        COUNT(*) AS `lines`,
                                        SUM(ti.qty) AS qty,
                                        SUM(ti.taxable_value) AS taxable_value,
                                        SUM(ti.cgst) AS cgst,
                                        SUM(ti.sgst) AS sgst,
                                        SUM(ti.igst) AS igst
                                 FROM erp_tax_invoice_items ti
                                 JOIN erp_tax_invoices i ON i.id = ti.invoice_id
                                 WHERE i.invoice_date BETWEEN ? AND ?
                                   AND i.status IN ('issued','paid','partial','overdue')
                                 GROUP BY ti.hsn_code
                                 ORDER BY taxable_value DESC");
            $hsn->execute([$from, $to]);
            // B2B vs B2C
            $b = $db->prepare("SELECT
                                 SUM(CASE WHEN buyer_gstin IS NOT NULL AND buyer_gstin<>'' THEN grand_total ELSE 0 END) AS b2b,
                                 SUM(CASE WHEN buyer_gstin IS NULL OR buyer_gstin='' THEN grand_total ELSE 0 END) AS b2c,
                                 SUM(total_cgst+total_sgst+total_igst) AS total_tax,
                                 SUM(grand_total) AS total_value,
                                 COUNT(*) AS total_invoices
                                FROM erp_tax_invoices
                                WHERE invoice_date BETWEEN ? AND ?
                                  AND status IN ('issued','paid','partial','overdue')");
            $b->execute([$from, $to]);
            erp_json(['ok'=>true, 'from'=>$from, 'to'=>$to,
                'hsn'=>$hsn->fetchAll(PDO::FETCH_ASSOC),
                'summary'=>$b->fetch(PDO::FETCH_ASSOC),
            ]);
            break;
        }

        case 'invoice_from_grn': {
            // helper: pre-fill invoice from a GRN
            $grnId = (int)($body['grn_id'] ?? 0);
            if (!$grnId) erp_error('grn_id required');
            $g = $db->prepare("SELECT g.*, v.* FROM erp_grns g JOIN erp_vendors v ON v.id=g.vendor_id WHERE g.id=?");
            $g->execute([$grnId]);
            $grn = $g->fetch(PDO::FETCH_ASSOC);
            if (!$grn) erp_error('GRN not found', 404);
            $items = $db->prepare("SELECT gi.*, i.gst_rate FROM erp_grn_items gi LEFT JOIN inventory_items i ON i.id=gi.item_id WHERE gi.grn_id=?");
            $items->execute([$grnId]);
            erp_json([
                'ok'=>true,
                'prefill'=>[
                    'buyer_name'=>$grn['name'],
                    'buyer_gstin'=>$grn['gstin'],
                    'buyer_address'=>$grn['address'],
                    'buyer_state'=>$grn['state'],
                    'buyer_state_code'=>$grn['state_code'],
                    'items'=>array_map(function ($r) {
                        return [
                            'item_id'=>$r['item_id'],
                            'description'=>$r['description'],
                            'hsn_code'=>$r['hsn_code'],
                            'qty'=>$r['qty_accepted'],
                            'unit'=>$r['unit'],
                            'unit_price'=>$r['unit_cost'],
                            'gst_rate'=>$r['gst_rate'] ?? 0,
                            'discount_pct'=>0,
                        ];
                    }, $items->fetchAll(PDO::FETCH_ASSOC)),
                ],
            ]);
            break;
        }

        default:
            erp_error("Unknown invoice action: $action", 400);
    }
}

/**
 * Render a GST-compliant tax invoice PDF and stream to browser.
 * If $persistOnly === true, just saves and returns nothing.
 */
function _invoice_render_pdf(PDO $db, int $id, bool $persistOnly = false): void {
    $stmt = $db->prepare("SELECT * FROM erp_tax_invoices WHERE id=?");
    $stmt->execute([$id]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inv) erp_error('Invoice not found', 404);
    $items = $db->prepare("SELECT * FROM erp_tax_invoice_items WHERE invoice_id=? ORDER BY id");
    $items->execute([$id]);
    $rows = $items->fetchAll(PDO::FETCH_ASSOC);

    $org = [
        'name' => erp_setting('org_name', 'Dhrub Foundation'),
        'address' => erp_setting('org_address', ''),
        'phone' => erp_setting('org_phone', ''),
        'email' => erp_setting('org_email', ''),
        'gstin' => erp_setting('org_gstin', ''),
        'pan' => erp_setting('org_pan', ''),
        'state' => erp_setting('org_state', ''),
        'state_code' => erp_setting('org_state_code', ''),
        'bank_name' => erp_setting('org_bank_name', ''),
        'bank_account' => erp_setting('org_bank_account', ''),
        'bank_ifsc' => erp_setting('org_bank_ifsc', ''),
        'bank_branch' => erp_setting('org_bank_branch', ''),
        'upi' => erp_setting('org_upi_id', ''),
    ];

    $title = strtoupper(str_replace('_', ' ', $inv['invoice_type']));
    $isInterstate = (int)$inv['is_interstate'] === 1;
    $cur = '₹';
    $fmt = fn($n) => $cur . number_format((float)$n, 2);

    ob_start();
    $logoPath = realpath(dirname(__DIR__, 2) . '/logo.svg');
    ?>
    <html><head><style>
        body { font-family: 'DejaVuSans', sans-serif; font-size: 10pt; color: #1f2937; }
        h1 { margin: 0; }
        .title-bar { display: flex; justify-content: space-between; align-items: center;
                     border-bottom: 2px solid #10b981; padding-bottom: 10px; margin-bottom: 12px; }
        .org-name { font-size: 16pt; font-weight: bold; color: #10b981; margin:0; }
        .org-meta { font-size: 9pt; color: #4b5563; }
        .doc-title { font-size: 13pt; font-weight: bold; color: #1f2937; text-align: right; }
        .doc-meta { font-size: 9pt; color: #4b5563; text-align: right; }
        .pill { background: #ecfdf5; color: #065f46; padding: 2px 8px; border-radius: 999px; font-size: 8.5pt; }
        .grid { width: 100%; }
        .grid td { vertical-align: top; padding: 4px 6px; }
        .panel { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px; }
        .label { font-size: 8.5pt; text-transform: uppercase; letter-spacing: 0.04em; color: #6b7280; margin-bottom: 3px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.items th { background: #10b981; color: white; padding: 6px; font-size: 8.5pt; text-align: left; }
        table.items td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; font-size: 9pt; }
        table.items td.num { text-align: right; }
        table.totals td { padding: 4px 8px; font-size: 9.5pt; }
        table.totals td.k { text-align: right; color: #4b5563; }
        table.totals td.v { text-align: right; font-weight: bold; }
        .grand { background: #10b981; color: white; }
        .grand td { color: white !important; font-weight: bold; font-size: 11pt !important; }
        .signature-box { margin-top: 22px; }
        .signature-box td { width: 50%; vertical-align: top; }
        .footer { font-size: 8pt; color: #6b7280; margin-top: 16px; border-top: 1px solid #e5e7eb; padding-top: 8px; }
        .amt-words { font-style: italic; color: #4b5563; margin: 8px 0; font-size: 9.5pt; }
    </style></head><body>

    <div class="title-bar">
      <div>
        <h1 class="org-name"><?= htmlspecialchars($org['name']) ?></h1>
        <div class="org-meta">
          <?= nl2br(htmlspecialchars($org['address'])) ?><br/>
          <?= $org['phone'] ? 'Tel: ' . htmlspecialchars($org['phone']) . ' · ' : '' ?>
          <?= $org['email'] ? htmlspecialchars($org['email']) : '' ?><br/>
          <?php if ($org['gstin']): ?>GSTIN: <strong><?= htmlspecialchars($org['gstin']) ?></strong> · State: <?= htmlspecialchars($org['state']) ?> (<?= htmlspecialchars($org['state_code']) ?>)<br/><?php endif; ?>
          <?php if ($org['pan']): ?>PAN: <?= htmlspecialchars($org['pan']) ?><?php endif; ?>
        </div>
      </div>
      <div>
        <div class="doc-title"><?= htmlspecialchars($title) ?></div>
        <div class="doc-meta">
          <strong><?= htmlspecialchars($inv['invoice_number']) ?></strong><br/>
          Date: <?= htmlspecialchars(date('d M Y', strtotime($inv['invoice_date']))) ?><br/>
          <?php if ($inv['due_date']): ?>Due: <?= htmlspecialchars(date('d M Y', strtotime($inv['due_date']))) ?><br/><?php endif; ?>
          <?php if ($inv['fy']): ?><span class="pill">FY <?= htmlspecialchars($inv['fy']) ?></span><?php endif; ?>
        </div>
      </div>
    </div>

    <table class="grid">
      <tr>
        <td style="width:50%">
          <div class="label">Bill To</div>
          <div class="panel">
            <strong><?= htmlspecialchars($inv['buyer_name']) ?></strong><br/>
            <?= nl2br(htmlspecialchars($inv['buyer_address'] ?? '')) ?><br/>
            <?php if ($inv['buyer_state']): ?>State: <?= htmlspecialchars($inv['buyer_state']) ?> (<?= htmlspecialchars($inv['buyer_state_code']) ?>)<br/><?php endif; ?>
            <?php if ($inv['buyer_gstin']): ?>GSTIN: <strong><?= htmlspecialchars($inv['buyer_gstin']) ?></strong><br/><?php endif; ?>
            <?php if ($inv['buyer_pan']): ?>PAN: <?= htmlspecialchars($inv['buyer_pan']) ?><br/><?php endif; ?>
            <?php if ($inv['buyer_phone']): ?>Tel: <?= htmlspecialchars($inv['buyer_phone']) ?><br/><?php endif; ?>
            <?php if ($inv['buyer_email']): ?>Email: <?= htmlspecialchars($inv['buyer_email']) ?><?php endif; ?>
          </div>
        </td>
        <td style="width:50%">
          <div class="label">Place of Supply</div>
          <div class="panel">
            <strong><?= htmlspecialchars($inv['place_of_supply'] ?? '—') ?></strong> (<?= htmlspecialchars($inv['place_of_supply_code'] ?? '—') ?>)<br/>
            Supply Type: <strong><?= $isInterstate ? 'Inter-state (IGST)' : 'Intra-state (CGST + SGST)' ?></strong><br/>
            <?php if ((int)$inv['reverse_charge']): ?>Reverse Charge: <strong>YES</strong><br/><?php endif; ?>
          </div>
        </td>
      </tr>
    </table>

    <table class="items">
      <thead>
        <tr>
          <th>#</th><th>Description</th><th>HSN</th><th>Qty</th><th>Rate</th>
          <th>Disc</th><th>Taxable</th>
          <?php if ($isInterstate): ?><th>IGST</th><?php else: ?><th>CGST</th><th>SGST</th><?php endif; ?>
          <th>Amount</th>
        </tr>
      </thead>
      <tbody>
        <?php $i = 1; foreach ($rows as $r): ?>
        <tr>
          <td><?= $i++ ?></td>
          <td><?= htmlspecialchars($r['description']) ?></td>
          <td><?= htmlspecialchars($r['hsn_code'] ?? '') ?></td>
          <td class="num"><?= number_format((float)$r['qty'], 2) ?> <?= htmlspecialchars($r['unit']) ?></td>
          <td class="num"><?= $fmt($r['unit_price']) ?></td>
          <td class="num"><?= $fmt($r['discount_amt']) ?></td>
          <td class="num"><?= $fmt($r['taxable_value']) ?></td>
          <?php if ($isInterstate): ?>
            <td class="num"><?= $fmt($r['igst']) ?> <small>(<?= (float)$r['gst_rate'] ?>%)</small></td>
          <?php else: ?>
            <td class="num"><?= $fmt($r['cgst']) ?> <small>(<?= (float)$r['gst_rate']/2 ?>%)</small></td>
            <td class="num"><?= $fmt($r['sgst']) ?> <small>(<?= (float)$r['gst_rate']/2 ?>%)</small></td>
          <?php endif; ?>
          <td class="num"><?= $fmt($r['line_total']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <table class="grid" style="margin-top:14px">
      <tr>
        <td style="width:55%">
          <div class="amt-words"><strong>Amount in words:</strong> <?= htmlspecialchars($inv['amount_in_words']) ?></div>
          <?php if ($org['bank_name']): ?>
          <div class="label" style="margin-top:8px">Bank Details</div>
          <div class="panel">
            <?= htmlspecialchars($org['bank_name']) ?><br/>
            A/C: <?= htmlspecialchars($org['bank_account']) ?> · IFSC: <?= htmlspecialchars($org['bank_ifsc']) ?><br/>
            Branch: <?= htmlspecialchars($org['bank_branch']) ?>
            <?php if ($org['upi']): ?> · UPI: <?= htmlspecialchars($org['upi']) ?><?php endif; ?>
          </div>
          <?php endif; ?>
        </td>
        <td style="width:45%">
          <table class="totals" width="100%">
            <tr><td class="k">Subtotal</td><td class="v"><?= $fmt($inv['subtotal']) ?></td></tr>
            <?php if ((float)$inv['discount_total'] > 0): ?>
              <tr><td class="k">Discount</td><td class="v">- <?= $fmt($inv['discount_total']) ?></td></tr>
            <?php endif; ?>
            <tr><td class="k">Taxable Value</td><td class="v"><?= $fmt($inv['taxable_value']) ?></td></tr>
            <?php if ($isInterstate): ?>
              <tr><td class="k">IGST</td><td class="v"><?= $fmt($inv['total_igst']) ?></td></tr>
            <?php else: ?>
              <tr><td class="k">CGST</td><td class="v"><?= $fmt($inv['total_cgst']) ?></td></tr>
              <tr><td class="k">SGST</td><td class="v"><?= $fmt($inv['total_sgst']) ?></td></tr>
            <?php endif; ?>
            <?php if ((float)$inv['round_off'] != 0): ?>
              <tr><td class="k">Round Off</td><td class="v"><?= $fmt($inv['round_off']) ?></td></tr>
            <?php endif; ?>
            <tr class="grand"><td>GRAND TOTAL</td><td class="v"><?= $fmt($inv['grand_total']) ?></td></tr>
            <?php if ((float)$inv['paid_amount'] > 0): ?>
              <tr><td class="k">Paid</td><td class="v"><?= $fmt($inv['paid_amount']) ?></td></tr>
              <tr><td class="k">Balance Due</td><td class="v"><?= $fmt(max(0, (float)$inv['grand_total'] - (float)$inv['paid_amount'])) ?></td></tr>
            <?php endif; ?>
          </table>
        </td>
      </tr>
    </table>

    <?php if ($inv['terms']): ?>
    <div class="label" style="margin-top:14px">Terms &amp; Conditions</div>
    <div style="font-size:9pt;color:#4b5563"><?= nl2br(htmlspecialchars($inv['terms'])) ?></div>
    <?php endif; ?>
    <?php if ($inv['notes']): ?>
    <div class="label" style="margin-top:10px">Notes</div>
    <div style="font-size:9pt;color:#4b5563"><?= nl2br(htmlspecialchars($inv['notes'])) ?></div>
    <?php endif; ?>

    <table class="signature-box" width="100%">
      <tr>
        <td>
          <div class="label">Customer Signature</div>
          <div style="height:50px;border-bottom:1px solid #d1d5db"></div>
        </td>
        <td style="text-align:right">
          <div class="label">For <?= htmlspecialchars($org['name']) ?></div>
          <div style="height:50px;border-bottom:1px solid #d1d5db"></div>
          <div style="font-size:9pt;color:#4b5563;margin-top:4px">Authorised Signatory</div>
        </td>
      </tr>
    </table>

    <div class="footer">
      This is a computer-generated <?= strtolower($title) ?>.
      Generated on <?= date('d M Y, H:i') ?> by <?= htmlspecialchars(erp_setting('org_name', '')) ?> ERP.
      <?php if ($inv['irn']): ?> · IRN: <?= htmlspecialchars($inv['irn']) ?><?php endif; ?>
    </div>

    </body></html>
    <?php
    $html = ob_get_clean();

    // Render via mPDF (graceful fallback to printable HTML if mPDF missing)
    if (class_exists('\Mpdf\Mpdf')) {
        $tmpDir = sys_get_temp_dir() . '/mpdf_' . bin2hex(random_bytes(4));
        @mkdir($tmpDir, 0775, true);
        $mpdf = new \Mpdf\Mpdf([
            'mode'           => 'utf-8',
            'format'         => 'A4',
            'tempDir'        => $tmpDir,
            'margin_top'     => 14,
            'margin_bottom'  => 14,
            'margin_left'    => 12,
            'margin_right'   => 12,
        ]);
        $mpdf->WriteHTML($html);

        $saveDir = erp_upload_dir('invoices');
        $filename = preg_replace('/[^A-Za-z0-9_-]/', '_', $inv['invoice_number']) . '.pdf';
        $absPath = $saveDir . '/' . $filename;
        $mpdf->Output($absPath, \Mpdf\Output\Destination::FILE);
        $db->prepare("UPDATE erp_tax_invoices SET generated_pdf_path=? WHERE id=?")->execute([erp_public_url($absPath), $id]);

        if ($persistOnly) return;
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        readfile($absPath);
        exit;
    }

    // Fallback: stream printable HTML
    if ($persistOnly) return;
    header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><html><head><title>" . htmlspecialchars($inv['invoice_number']) . "</title>";
    echo "<script>window.onload = () => setTimeout(() => window.print(), 400);</script>";
    echo "</head><body>" . $html . "</body></html>";
    exit;
}
