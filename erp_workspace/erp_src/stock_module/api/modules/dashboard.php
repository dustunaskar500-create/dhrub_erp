<?php
/**
 * ERP — Dashboard aggregates + Aether intent hooks
 */

function erp_dash_dispatch(string $action, array $user): void {
    $db = erp_db();
    $body = erp_body();

    switch ($action) {
        case 'dash_overview': {
            $today = date('Y-m-d');
            $thirty = date('Y-m-d', strtotime('-30 days'));
            $stockKpi = $db->query("SELECT COUNT(*) AS items, COALESCE(SUM(quantity*cost_price),0) AS value,
                                           SUM(CASE WHEN quantity<=min_stock THEN 1 ELSE 0 END) AS low_stock,
                                           SUM(CASE WHEN quantity=0 THEN 1 ELSE 0 END) AS out_of_stock
                                    FROM inventory_items WHERE is_active=1")->fetch(PDO::FETCH_ASSOC);
            $grnKpi = $db->query("SELECT COUNT(*) AS total,
                                         SUM(CASE WHEN status='draft' THEN 1 ELSE 0 END) AS pending,
                                         SUM(CASE WHEN status='posted' THEN 1 ELSE 0 END) AS posted,
                                         SUM(CASE WHEN has_discrepancy=1 THEN 1 ELSE 0 END) AS discrepancies
                                  FROM erp_grns")->fetch(PDO::FETCH_ASSOC);
            $invKpi = $db->prepare("SELECT COUNT(*) AS total,
                                           COALESCE(SUM(grand_total),0) AS revenue,
                                           COALESCE(SUM(total_cgst+total_sgst+total_igst),0) AS tax_collected,
                                           COALESCE(SUM(CASE WHEN payment_status!='paid' THEN grand_total-paid_amount ELSE 0 END),0) AS outstanding
                                    FROM erp_tax_invoices
                                    WHERE invoice_date >= ? AND status IN ('issued','paid','partial','overdue')");
            $invKpi->execute([$thirty]);
            $invKpiRow = $invKpi->fetch(PDO::FETCH_ASSOC);
            $adjKpi = $db->query("SELECT
                                    COALESCE(SUM(CASE WHEN adj_type IN ('damage','shortage','loss','wastage','theft') THEN value_impact ELSE 0 END),0) AS realised_loss,
                                    COALESCE(SUM(CASE WHEN adj_type IN ('excess','found','return_in') THEN value_impact ELSE 0 END),0) AS realised_gain,
                                    COUNT(*) AS total
                                  FROM erp_stock_adjustments WHERE status='approved'")->fetch(PDO::FETCH_ASSOC);
            $vendors = (int)$db->query("SELECT COUNT(*) FROM erp_vendors WHERE is_active=1")->fetchColumn();
            $recentGrns = $db->query("SELECT g.id, g.grn_number, g.received_date, g.status, g.has_discrepancy,
                                             v.name AS vendor_name
                                      FROM erp_grns g JOIN erp_vendors v ON v.id=g.vendor_id
                                      ORDER BY g.id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
            $recentInvoices = $db->query("SELECT id, invoice_number, invoice_date, buyer_name, grand_total, status, payment_status
                                          FROM erp_tax_invoices ORDER BY id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
            $lowItems = $db->query("SELECT id, item_name, sku, quantity, min_stock, unit
                                    FROM inventory_items
                                    WHERE is_active=1 AND quantity<=min_stock
                                    ORDER BY (quantity-min_stock) ASC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
            erp_json(['ok'=>true,
                'stock'=>$stockKpi,
                'grn'=>$grnKpi,
                'invoice'=>$invKpiRow,
                'adjustments'=>$adjKpi,
                'vendor_count'=>$vendors,
                'recent_grns'=>$recentGrns,
                'recent_invoices'=>$recentInvoices,
                'low_stock_items'=>$lowItems,
            ]);
            break;
        }
        default:
            erp_error("Unknown dash action: $action", 400);
    }
}
