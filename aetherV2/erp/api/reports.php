<?php
/**
 * Stock & GST — Reports module
 *
 * Endpoints:
 *   POST  reports_stock           — itemised stock list / movements with filters
 *   POST  reports_purchase        — purchase / GRN report with filters
 *   POST  reports_sales           — tax invoices report with filters
 *   POST  reports_pnl             — stock losses / gains P&L report
 *   POST  reports_gstr1           — GSTR-1 HSN-wise summary (Table-12)
 *   POST  reports_export          — export any report to CSV / PDF
 *   POST  reports_import_csv      — bulk upload (stock items / vendors)
 */

function erp_reports_dispatch(string $action, array $user): void {
    $db = erp_db();
    $body = erp_body();
    switch ($action) {

        case 'reports_stock': {
            $q       = trim((string)($body['q'] ?? ''));
            $cat     = (string)($body['category'] ?? '');
            $low     = !empty($body['low_stock']);
            $minVal  = (float)($body['min_value'] ?? 0);
            $where = "WHERE i.is_active = 1";
            $params = [];
            if ($q !== '') {
                $where .= " AND (i.item_name LIKE ? OR i.sku LIKE ? OR i.hsn_code LIKE ? OR i.barcode LIKE ?)";
                $like = "%$q%"; array_push($params, $like, $like, $like, $like);
            }
            if ($cat) { $where .= " AND i.category = ?"; $params[] = $cat; }
            if ($low) $where .= " AND i.quantity <= i.min_stock";
            if ($minVal > 0) { $where .= " AND (i.quantity * i.cost_price) >= ?"; $params[] = $minVal; }

            $sql = "SELECT i.id, i.sku, i.item_name, i.category, i.hsn_code, i.gst_rate,
                           i.quantity, i.unit, i.min_stock, i.reorder_qty,
                           i.cost_price, i.sale_price,
                           (i.quantity * i.cost_price) AS stock_value,
                           (i.quantity * i.sale_price) AS sale_value,
                           ((i.quantity * i.sale_price) - (i.quantity * i.cost_price)) AS potential_margin,
                           i.location, i.updated_at
                    FROM inventory_items i
                    $where
                    ORDER BY stock_value DESC";
            $st = $db->prepare($sql); $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            $totals = [
                'items'         => count($rows),
                'total_units'   => array_sum(array_map(fn($r) => (float)$r['quantity'], $rows)),
                'total_value'   => array_sum(array_map(fn($r) => (float)$r['stock_value'], $rows)),
                'total_potential'=> array_sum(array_map(fn($r) => (float)$r['sale_value'], $rows)),
                'margin'        => array_sum(array_map(fn($r) => (float)$r['potential_margin'], $rows)),
            ];
            erp_json(['ok'=>true, 'rows'=>$rows, 'totals'=>$totals]);
            break;
        }

        case 'reports_purchase': {
            $from   = (string)($body['from'] ?? date('Y-m-01'));
            $to     = (string)($body['to'] ?? date('Y-m-d'));
            $vendor = (int)($body['vendor_id'] ?? 0);
            $status = (string)($body['status'] ?? '');
            $where = "WHERE g.received_date BETWEEN ? AND ?";
            $params = [$from, $to];
            if ($vendor) { $where .= " AND g.vendor_id = ?"; $params[] = $vendor; }
            if ($status) { $where .= " AND g.status = ?"; $params[] = $status; }

            $sql = "SELECT g.id, g.grn_number, g.received_date, g.supplier_invoice_no,
                           v.name AS vendor_name, v.gstin AS vendor_gstin,
                           g.value_received, g.value_loss, g.status, g.has_discrepancy,
                           g.total_qty_received, g.total_qty_damaged, g.total_qty_short
                    FROM erp_grns g
                    JOIN erp_vendors v ON v.id = g.vendor_id
                    $where
                    ORDER BY g.received_date DESC, g.id DESC";
            $st = $db->prepare($sql); $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $totals = [
                'count' => count($rows),
                'value_received' => array_sum(array_map(fn($r) => (float)$r['value_received'], $rows)),
                'value_loss'     => array_sum(array_map(fn($r) => (float)$r['value_loss'], $rows)),
                'qty_received'   => array_sum(array_map(fn($r) => (float)$r['total_qty_received'], $rows)),
                'qty_damaged'    => array_sum(array_map(fn($r) => (float)$r['total_qty_damaged'], $rows)),
                'qty_short'      => array_sum(array_map(fn($r) => (float)$r['total_qty_short'], $rows)),
            ];
            erp_json(['ok'=>true, 'rows'=>$rows, 'totals'=>$totals, 'from'=>$from, 'to'=>$to]);
            break;
        }

        case 'reports_sales': {
            $from   = (string)($body['from'] ?? date('Y-m-01'));
            $to     = (string)($body['to'] ?? date('Y-m-d'));
            $payst  = (string)($body['payment_status'] ?? '');
            $itype  = (string)($body['invoice_type'] ?? '');
            $where = "WHERE invoice_date BETWEEN ? AND ? AND status NOT IN ('cancelled','draft')";
            $params = [$from, $to];
            if ($payst) { $where .= " AND payment_status = ?"; $params[] = $payst; }
            if ($itype) { $where .= " AND invoice_type = ?"; $params[] = $itype; }

            $sql = "SELECT id, invoice_number, invoice_date, invoice_type,
                           buyer_name, buyer_gstin, is_interstate,
                           taxable_value, total_cgst, total_sgst, total_igst,
                           grand_total, paid_amount, payment_status, status, due_date
                    FROM erp_tax_invoices
                    $where
                    ORDER BY invoice_date DESC, id DESC";
            $st = $db->prepare($sql); $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $totals = [
                'count'        => count($rows),
                'taxable'      => array_sum(array_map(fn($r) => (float)$r['taxable_value'], $rows)),
                'cgst'         => array_sum(array_map(fn($r) => (float)$r['total_cgst'], $rows)),
                'sgst'         => array_sum(array_map(fn($r) => (float)$r['total_sgst'], $rows)),
                'igst'         => array_sum(array_map(fn($r) => (float)$r['total_igst'], $rows)),
                'grand_total'  => array_sum(array_map(fn($r) => (float)$r['grand_total'], $rows)),
                'paid'         => array_sum(array_map(fn($r) => (float)$r['paid_amount'], $rows)),
                'outstanding'  => array_sum(array_map(fn($r) => max(0, (float)$r['grand_total'] - (float)$r['paid_amount']), $rows)),
            ];
            erp_json(['ok'=>true, 'rows'=>$rows, 'totals'=>$totals, 'from'=>$from, 'to'=>$to]);
            break;
        }

        case 'reports_pnl': {
            $from = (string)($body['from'] ?? date('Y-m-01'));
            $to   = (string)($body['to'] ?? date('Y-m-d'));
            $st = $db->prepare("SELECT a.adj_type, COUNT(*) cnt, SUM(a.qty) total_qty,
                                       SUM(a.value_impact) total_value, a.direction
                                FROM erp_stock_adjustments a
                                WHERE a.status='approved' AND DATE(a.created_at) BETWEEN ? AND ?
                                GROUP BY a.adj_type, a.direction");
            $st->execute([$from, $to]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $loss = $gain = 0;
            foreach ($rows as $r) {
                if (in_array($r['adj_type'], ['damage','shortage','loss','wastage','theft','return_out'], true))
                    $loss += (float)$r['total_value'];
                else $gain += (float)$r['total_value'];
            }
            erp_json(['ok'=>true, 'rows'=>$rows,
                'totals'=>['loss'=>round($loss,2),'gain'=>round($gain,2),'net'=>round($gain - $loss, 2)],
                'from'=>$from, 'to'=>$to]);
            break;
        }

        case 'reports_gstr1': {
            $from = (string)($body['from'] ?? date('Y-m-01'));
            $to   = (string)($body['to'] ?? date('Y-m-d'));
            $st = $db->prepare("SELECT ti.hsn_code,
                                       COUNT(DISTINCT ti.invoice_id) `inv_count`,
                                       COUNT(*) `lines`,
                                       SUM(ti.qty) qty,
                                       SUM(ti.taxable_value) taxable_value,
                                       SUM(ti.cgst) cgst,
                                       SUM(ti.sgst) sgst,
                                       SUM(ti.igst) igst,
                                       SUM(ti.line_total) line_total,
                                       ti.gst_rate
                                FROM erp_tax_invoice_items ti
                                JOIN erp_tax_invoices i ON i.id = ti.invoice_id
                                WHERE i.invoice_date BETWEEN ? AND ? AND i.status IN ('issued','paid','partial','overdue')
                                GROUP BY ti.hsn_code, ti.gst_rate
                                ORDER BY taxable_value DESC");
            $st->execute([$from, $to]);
            $hsn = $st->fetchAll(PDO::FETCH_ASSOC);
            $b = $db->prepare("SELECT
                    SUM(CASE WHEN buyer_gstin IS NOT NULL AND buyer_gstin <> '' THEN grand_total ELSE 0 END) b2b,
                    SUM(CASE WHEN buyer_gstin IS NULL OR buyer_gstin = '' THEN grand_total ELSE 0 END) b2c,
                    SUM(CASE WHEN is_interstate=1 THEN grand_total ELSE 0 END) interstate,
                    SUM(CASE WHEN is_interstate=0 THEN grand_total ELSE 0 END) intrastate,
                    SUM(grand_total) total, COUNT(*) cnt
                  FROM erp_tax_invoices
                  WHERE invoice_date BETWEEN ? AND ? AND status IN ('issued','paid','partial','overdue')");
            $b->execute([$from, $to]);
            erp_json(['ok'=>true, 'hsn'=>$hsn, 'summary'=>$b->fetch(PDO::FETCH_ASSOC), 'from'=>$from, 'to'=>$to]);
            break;
        }

        case 'reports_export': {
            // body: { kind: 'stock'|'purchase'|'sales'|'pnl'|'gstr1', format: 'csv'|'pdf', filters: {...} }
            $kind   = (string)($body['kind'] ?? 'stock');
            $format = (string)($body['format'] ?? 'csv');
            $filters= (array)($body['filters'] ?? []);

            // Override body for the upcoming fetcher so it reads $filters
            $GLOBALS['__erp_body_override'] = $filters;
            $data = _erp_fetch_report($kind, $user);
            unset($GLOBALS['__erp_body_override']);

            if ($format === 'csv') _erp_export_csv($kind, $data);
            else _erp_export_pdf($kind, $data);
            break;
        }

        case 'reports_import_csv': {
            if (!in_array($user['role'], ['super_admin','admin','manager','accountant'], true)) erp_error('Forbidden', 403);
            $kind = (string)($body['kind'] ?? 'stock');   // stock | vendors
            $csv  = (string)($body['csv'] ?? '');
            if (!$csv) erp_error('csv (string) required');
            // Accept: data URI, raw base64, or plain CSV text
            if (preg_match('/^data:[^;]*;base64,(.*)$/', $csv, $m)) {
                $csv = base64_decode($m[1]) ?: '';
            } elseif (!str_contains($csv, ',') && !str_contains($csv, "\n")) {
                // Probably base64 with no prefix
                $decoded = base64_decode($csv, true);
                if ($decoded !== false && str_contains($decoded, ',')) $csv = $decoded;
            }
            $lines = preg_split('/\r\n|\n|\r/', $csv);
            if (count($lines) < 2) erp_error('CSV is empty');
            $header = str_getcsv(array_shift($lines));
            $imported = 0; $skipped = 0; $errors = [];

            foreach ($lines as $idx => $line) {
                if (!trim($line)) continue;
                $row = str_getcsv($line);
                $rec = array_combine($header, array_pad($row, count($header), null));
                try {
                    if ($kind === 'stock') {
                        if (empty($rec['item_name'])) throw new \Exception("Missing item_name");
                        $sql = "INSERT INTO inventory_items
                                (sku, item_name, description, category, hsn_code, gst_rate, quantity, unit, min_stock, reorder_qty, cost_price, sale_price, barcode, location, is_active)
                                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)
                                ON DUPLICATE KEY UPDATE
                                  item_name=VALUES(item_name), hsn_code=VALUES(hsn_code), gst_rate=VALUES(gst_rate),
                                  cost_price=VALUES(cost_price), sale_price=VALUES(sale_price),
                                  min_stock=VALUES(min_stock), reorder_qty=VALUES(reorder_qty)";
                        $db->prepare($sql)->execute([
                            $rec['sku'] ?? null, $rec['item_name'], $rec['description'] ?? null,
                            $rec['category'] ?? 'other', $rec['hsn_code'] ?? null,
                            (float)($rec['gst_rate'] ?? 0), (int)($rec['quantity'] ?? 0),
                            $rec['unit'] ?? 'pcs', (int)($rec['min_stock'] ?? 0),
                            (int)($rec['reorder_qty'] ?? 0), (float)($rec['cost_price'] ?? 0),
                            (float)($rec['sale_price'] ?? 0), $rec['barcode'] ?? null,
                            $rec['location'] ?? null,
                        ]);
                        $imported++;
                    } elseif ($kind === 'vendors') {
                        if (empty($rec['name'])) throw new \Exception("Missing name");
                        $gstin = strtoupper(trim((string)($rec['gstin'] ?? '')));
                        $sc = $gstin ? erp_gstin_state_code($gstin) : null;
                        $code = $rec['vendor_code'] ?? ('VND-' . strtoupper(bin2hex(random_bytes(3))));
                        $sql = "INSERT INTO erp_vendors
                                (vendor_code, name, gstin, pan, contact_person, email, phone, address, city, state, state_code, pincode, is_active)
                                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1)
                                ON DUPLICATE KEY UPDATE
                                  name=VALUES(name), gstin=VALUES(gstin), email=VALUES(email)";
                        $db->prepare($sql)->execute([
                            $code, $rec['name'], $gstin ?: null, $rec['pan'] ?? null,
                            $rec['contact_person'] ?? null, $rec['email'] ?? null,
                            $rec['phone'] ?? null, $rec['address'] ?? null,
                            $rec['city'] ?? null, $rec['state'] ?? null,
                            $sc ?? ($rec['state_code'] ?? null), $rec['pincode'] ?? null,
                        ]);
                        $imported++;
                    } else {
                        throw new \Exception("Unsupported kind: $kind");
                    }
                } catch (\Throwable $e) {
                    $skipped++;
                    $errors[] = "Row " . ($idx + 2) . ": " . $e->getMessage();
                }
            }
            erp_log_audit('reports_import', "CSV import: $kind, imported=$imported, skipped=$skipped",
                ['by'=>$user['id']], 'medium', $user['id']);
            erp_json(['ok'=>true, 'imported'=>$imported, 'skipped'=>$skipped,
                     'errors'=>array_slice($errors, 0, 20)]);
            break;
        }

        case 'reports_csv_template': {
            $kind = (string)($body['kind'] ?? ($_GET['kind'] ?? 'stock'));
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $kind . '_template.csv"');
            if ($kind === 'stock') {
                echo "sku,item_name,description,category,hsn_code,gst_rate,quantity,unit,min_stock,reorder_qty,cost_price,sale_price,barcode,location\n";
                echo "SKU-EXAMPLE,Sample Item,Optional description,other,1234,18,100,pcs,10,20,50.00,75.00,,Main Warehouse\n";
            } elseif ($kind === 'vendors') {
                echo "vendor_code,name,gstin,pan,contact_person,email,phone,address,city,state,state_code,pincode\n";
                echo "VND-001,Sample Vendor Pvt Ltd,19AABCD1234E1Z5,AABCD1234E,Mr. Test,test@vendor.in,+919876543210,123 Test Street,Kolkata,West Bengal,19,700001\n";
            }
            exit;
        }

        default:
            erp_error("Unknown reports action: $action", 400);
    }
}

/* ────────── CSV export ────────── */
function _erp_export_csv(string $kind, array $data): void {
    header('Content-Type: text/csv; charset=utf-8');
    $filename = "$kind-report-" . date('Y-m-d') . ".csv";
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    if (empty($data['rows'])) { fputcsv($out, ['No data']); fclose($out); exit; }
    fputcsv($out, array_keys($data['rows'][0]));
    foreach ($data['rows'] as $r) fputcsv($out, array_map(fn($v) => is_array($v) ? json_encode($v) : (string)$v, $r));
    if (!empty($data['totals'])) {
        fputcsv($out, []);
        fputcsv($out, ['TOTALS']);
        foreach ($data['totals'] as $k => $v) fputcsv($out, [$k, $v]);
    }
    fclose($out);
    exit;
}

/* ────────── PDF export (mPDF if available, else printable HTML) ────────── */
function _erp_export_pdf(string $kind, array $data): void {
    $title = ['stock'=>'Stock Report','purchase'=>'Purchase Report','sales'=>'Sales Report',
              'pnl'=>'P&L Report','gstr1'=>'GSTR-1 Summary'][$kind] ?? 'Report';
    $org = erp_setting('org_name', 'Organisation');
    $orgGstin = erp_setting('org_gstin', '');
    $period = '';
    if (!empty($data['from'])) $period = "Period: " . htmlspecialchars($data['from']) . " to " . htmlspecialchars($data['to'] ?? '');

    $rows = $data['rows'] ?? $data['hsn'] ?? [];
    $headers = $rows ? array_keys($rows[0]) : [];

    ob_start(); ?>
    <html><head><style>
        body { font-family: 'DejaVuSans', sans-serif; font-size: 9pt; color: #1f2937; }
        .header { border-bottom: 3px solid #10b981; padding-bottom: 10px; margin-bottom: 15px; }
        .org { font-size: 14pt; font-weight: bold; color: #10b981; }
        .title { font-size: 12pt; font-weight: 600; margin-top: 4px; }
        .meta { font-size: 9pt; color: #6b7280; margin-top: 3px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 8.5pt; }
        th { background: #10b981; color: white; padding: 6px 8px; text-align: left; font-size: 8pt; text-transform: uppercase; }
        td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; }
        tr:nth-child(even) td { background: #f9fafb; }
        .totals { background: #ecfdf5; border: 1px solid #10b981; border-radius: 6px; padding: 10px; margin-top: 15px; }
        .totals h3 { margin: 0 0 6px; color: #065f46; font-size: 10pt; }
        .totals .row { display: inline-block; margin-right: 18px; }
        .totals .label { font-size: 8pt; color: #6b7280; text-transform: uppercase; }
        .totals .value { font-weight: bold; color: #065f46; font-size: 11pt; }
        .footer { font-size: 7pt; color: #9ca3af; margin-top: 20px; text-align: center; }
    </style></head><body>
    <div class="header">
        <div class="org"><?= htmlspecialchars($org) ?></div>
        <?php if ($orgGstin): ?><div class="meta">GSTIN: <?= htmlspecialchars($orgGstin) ?></div><?php endif; ?>
        <div class="title"><?= htmlspecialchars($title) ?></div>
        <div class="meta"><?= $period ?> · Generated: <?= date('d M Y, H:i') ?></div>
    </div>

    <?php if (!empty($data['totals'])): ?>
    <div class="totals">
        <h3>Summary</h3>
        <?php foreach ($data['totals'] as $k => $v): ?>
            <div class="row">
                <div class="label"><?= htmlspecialchars(ucwords(str_replace('_',' ',$k))) ?></div>
                <div class="value"><?= is_numeric($v) ? '₹ ' . number_format((float)$v, 2) : htmlspecialchars((string)$v) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($data['summary'])): ?>
    <div class="totals">
        <h3>Summary</h3>
        <?php foreach ($data['summary'] as $k => $v): ?>
            <div class="row">
                <div class="label"><?= htmlspecialchars(ucwords(str_replace('_',' ',$k))) ?></div>
                <div class="value"><?= is_numeric($v) ? '₹ ' . number_format((float)$v, 2) : htmlspecialchars((string)$v) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($rows): ?>
    <table>
        <thead><tr><?php foreach ($headers as $h): ?><th><?= htmlspecialchars(ucwords(str_replace('_',' ',$h))) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr><?php foreach ($headers as $h):
                $v = $r[$h] ?? '';
                $disp = is_numeric($v) && (strpos($h, 'value') !== false || strpos($h, 'total') !== false || strpos($h, 'cgst') !== false || strpos($h, 'sgst') !== false || strpos($h, 'igst') !== false || strpos($h, 'price') !== false || strpos($h, 'margin') !== false || strpos($h, 'paid') !== false || strpos($h, 'taxable') !== false)
                        ? '₹ ' . number_format((float)$v, 2)
                        : htmlspecialchars((string)$v);
            ?><td><?= $disp ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <p style="color:#6b7280;text-align:center;padding:30px;">No data for the selected filters.</p>
    <?php endif; ?>

    <div class="footer">Generated by <?= htmlspecialchars($org) ?> · Aether Stock & GST Module</div>
    </body></html>
    <?php
    $html = ob_get_clean();

    if (class_exists('\Mpdf\Mpdf')) {
        $tmp = sys_get_temp_dir() . '/mpdf_' . bin2hex(random_bytes(4));
        @mkdir($tmp, 0775, true);
        $mpdf = new \Mpdf\Mpdf(['mode'=>'utf-8', 'format'=>'A4', 'tempDir'=>$tmp,
            'margin_top'=>12, 'margin_bottom'=>12, 'margin_left'=>10, 'margin_right'=>10]);
        $mpdf->WriteHTML($html);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $kind . '-report-' . date('Y-m-d') . '.pdf"');
        echo $mpdf->Output('', 'S');
        exit;
    }
    // Fallback: printable HTML
    header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><html><head><title>$title</title>";
    echo "<script>window.onload = () => setTimeout(() => window.print(), 400);</script>";
    echo "</head><body>" . $html . "</body></html>";
    exit;
}

/* Helper used by reports_export to read filters from the global override */
function _erp_body_overridable(): array {
    if (!empty($GLOBALS['__erp_body_override'])) return (array)$GLOBALS['__erp_body_override'];
    return erp_body();
}

/* Programmatic report fetcher — returns the same structure each report sends */
function _erp_fetch_report(string $kind, array $user): array {
    $db = erp_db();
    $body = _erp_body_overridable();

    if ($kind === 'stock') {
        $q = trim((string)($body['q'] ?? ''));
        $cat = (string)($body['category'] ?? '');
        $low = !empty($body['low_stock']);
        $minVal = (float)($body['min_value'] ?? 0);
        $where = "WHERE i.is_active = 1"; $params = [];
        if ($q !== '') { $where .= " AND (i.item_name LIKE ? OR i.sku LIKE ? OR i.hsn_code LIKE ?)"; $like = "%$q%"; array_push($params, $like, $like, $like); }
        if ($cat) { $where .= " AND i.category = ?"; $params[] = $cat; }
        if ($low) $where .= " AND i.quantity <= i.min_stock";
        if ($minVal > 0) { $where .= " AND (i.quantity * i.cost_price) >= ?"; $params[] = $minVal; }
        $sql = "SELECT i.id, i.sku, i.item_name, i.category, i.hsn_code, i.gst_rate,
                       i.quantity, i.unit, i.min_stock, i.cost_price, i.sale_price,
                       (i.quantity * i.cost_price) AS stock_value,
                       (i.quantity * i.sale_price) AS sale_value,
                       ((i.quantity * i.sale_price) - (i.quantity * i.cost_price)) AS potential_margin,
                       i.location
                FROM inventory_items i $where ORDER BY stock_value DESC";
        $st = $db->prepare($sql); $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return ['rows'=>$rows, 'totals'=>[
            'items'=>count($rows),
            'total_value'=>array_sum(array_map(fn($r)=>(float)$r['stock_value'],$rows)),
            'total_potential'=>array_sum(array_map(fn($r)=>(float)$r['sale_value'],$rows)),
            'margin'=>array_sum(array_map(fn($r)=>(float)$r['potential_margin'],$rows)),
        ]];
    }
    if ($kind === 'purchase') {
        $from = (string)($body['from'] ?? date('Y-m-01'));
        $to = (string)($body['to'] ?? date('Y-m-d'));
        $vendor = (int)($body['vendor_id'] ?? 0); $status = (string)($body['status'] ?? '');
        $where = "WHERE g.received_date BETWEEN ? AND ?"; $params = [$from,$to];
        if ($vendor) { $where .= " AND g.vendor_id = ?"; $params[] = $vendor; }
        if ($status) { $where .= " AND g.status = ?"; $params[] = $status; }
        $sql = "SELECT g.grn_number, g.received_date, g.supplier_invoice_no,
                       v.name AS vendor_name, v.gstin AS vendor_gstin,
                       g.value_received, g.value_loss, g.status,
                       g.total_qty_received, g.total_qty_damaged, g.total_qty_short
                FROM erp_grns g JOIN erp_vendors v ON v.id=g.vendor_id
                $where ORDER BY g.received_date DESC";
        $st = $db->prepare($sql); $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return ['rows'=>$rows, 'from'=>$from, 'to'=>$to, 'totals'=>[
            'count'=>count($rows),
            'value_received'=>array_sum(array_map(fn($r)=>(float)$r['value_received'],$rows)),
            'value_loss'=>array_sum(array_map(fn($r)=>(float)$r['value_loss'],$rows)),
        ]];
    }
    if ($kind === 'sales') {
        $from = (string)($body['from'] ?? date('Y-m-01'));
        $to = (string)($body['to'] ?? date('Y-m-d'));
        $where = "WHERE invoice_date BETWEEN ? AND ? AND status NOT IN ('cancelled','draft')"; $params = [$from,$to];
        if (!empty($body['payment_status'])) { $where .= " AND payment_status = ?"; $params[] = $body['payment_status']; }
        $sql = "SELECT invoice_number, invoice_date, invoice_type, buyer_name, buyer_gstin,
                       is_interstate, taxable_value, total_cgst, total_sgst, total_igst,
                       grand_total, paid_amount, payment_status, status
                FROM erp_tax_invoices $where ORDER BY invoice_date DESC";
        $st = $db->prepare($sql); $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return ['rows'=>$rows, 'from'=>$from, 'to'=>$to, 'totals'=>[
            'count'=>count($rows),
            'taxable'=>array_sum(array_map(fn($r)=>(float)$r['taxable_value'],$rows)),
            'cgst'=>array_sum(array_map(fn($r)=>(float)$r['total_cgst'],$rows)),
            'sgst'=>array_sum(array_map(fn($r)=>(float)$r['total_sgst'],$rows)),
            'igst'=>array_sum(array_map(fn($r)=>(float)$r['total_igst'],$rows)),
            'grand_total'=>array_sum(array_map(fn($r)=>(float)$r['grand_total'],$rows)),
            'paid'=>array_sum(array_map(fn($r)=>(float)$r['paid_amount'],$rows)),
            'outstanding'=>array_sum(array_map(fn($r)=>max(0,(float)$r['grand_total']-(float)$r['paid_amount']),$rows)),
        ]];
    }
    if ($kind === 'pnl') {
        $from = (string)($body['from'] ?? date('Y-m-01'));
        $to = (string)($body['to'] ?? date('Y-m-d'));
        $st = $db->prepare("SELECT adj_type, COUNT(*) cnt, SUM(qty) total_qty, SUM(value_impact) total_value, direction
                            FROM erp_stock_adjustments WHERE status='approved' AND DATE(created_at) BETWEEN ? AND ?
                            GROUP BY adj_type, direction");
        $st->execute([$from,$to]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $loss = $gain = 0;
        foreach ($rows as $r) {
            if (in_array($r['adj_type'], ['damage','shortage','loss','wastage','theft','return_out'], true)) $loss += (float)$r['total_value'];
            else $gain += (float)$r['total_value'];
        }
        return ['rows'=>$rows, 'from'=>$from, 'to'=>$to, 'totals'=>['loss'=>$loss,'gain'=>$gain,'net'=>$gain-$loss]];
    }
    if ($kind === 'gstr1') {
        $from = (string)($body['from'] ?? date('Y-m-01'));
        $to = (string)($body['to'] ?? date('Y-m-d'));
        $st = $db->prepare("SELECT ti.hsn_code, COUNT(*) `lines`, SUM(ti.qty) qty,
                                   SUM(ti.taxable_value) taxable_value,
                                   SUM(ti.cgst) cgst, SUM(ti.sgst) sgst, SUM(ti.igst) igst
                            FROM erp_tax_invoice_items ti
                            JOIN erp_tax_invoices i ON i.id = ti.invoice_id
                            WHERE i.invoice_date BETWEEN ? AND ? AND i.status IN ('issued','paid','partial','overdue')
                            GROUP BY ti.hsn_code ORDER BY taxable_value DESC");
        $st->execute([$from,$to]);
        return ['rows'=>$st->fetchAll(PDO::FETCH_ASSOC), 'from'=>$from, 'to'=>$to];
    }
    throw new \RuntimeException("Unknown report kind: $kind");
}
