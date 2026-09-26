<?php

namespace App\Services\Reporting;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseOrder;
use Illuminate\Support\Carbon;

/**
 * "Purchase reports should be generated from purchase order and goods
 * received" — this reads both, never a separately-maintained total: each
 * PO's "ordered" value comes from its own line items (PurchaseOrderItem::
 * line_total), and its "received" value comes from the goods-receipt lines
 * actually posted against it (GoodsReceiptItem::line_total, matched via
 * purchase_order_item_id) — the same two ledgers PurchaseOrderController
 * and GoodsReceiptController already write to, so this report can never
 * show a number that disagrees with what those screens themselves record.
 * Ad-hoc receipts (no PO at all) are reported separately since they have
 * no "ordered" side to compare against.
 */
class PurchaseReportService
{
    /**
     * @return array{
     *     purchase_orders: \Illuminate\Support\Collection,
     *     ad_hoc_receipts: \Illuminate\Support\Collection,
     *     totals: array{po_count: int, ordered_value: float, received_value_from_pos: float, outstanding_value: float, ad_hoc_value: float, total_received_value: float},
     * }
     */
    public function forRange(Carbon $start, Carbon $end): array
    {
        $range = [$start->copy()->startOfDay(), $end->copy()->endOfDay()];

        $purchaseOrders = PurchaseOrder::with(['supplier', 'items'])
            ->whereBetween('created_at', $range)
            ->orderByDesc('id')
            ->get()
            ->map(function (PurchaseOrder $po) {
                $orderedValue = (float) $po->items->sum('line_total');
                $poItemIds = $po->items->pluck('id');
                $receivedValue = $poItemIds->isEmpty()
                    ? 0.0
                    : (float) GoodsReceiptItem::whereIn('purchase_order_item_id', $poItemIds)->sum('line_total');

                return [
                    'po' => $po,
                    'ordered_value' => round($orderedValue, 2),
                    'received_value' => round($receivedValue, 2),
                    'outstanding_value' => round($orderedValue - $receivedValue, 2),
                ];
            });

        $adHocReceipts = GoodsReceipt::whereNull('purchase_order_id')
            ->whereBetween('received_at', $range)
            ->with(['supplier', 'receivedBy', 'items'])
            ->orderByDesc('id')
            ->get();

        $adHocValue = (float) $adHocReceipts->sum(fn (GoodsReceipt $r) => (float) $r->items->sum('line_total'));
        $orderedTotal = (float) $purchaseOrders->sum('ordered_value');
        $receivedFromPosTotal = (float) $purchaseOrders->sum('received_value');

        return [
            'purchase_orders' => $purchaseOrders,
            'ad_hoc_receipts' => $adHocReceipts,
            'totals' => [
                'po_count' => $purchaseOrders->count(),
                'ordered_value' => round($orderedTotal, 2),
                'received_value_from_pos' => round($receivedFromPosTotal, 2),
                'outstanding_value' => round($orderedTotal - $receivedFromPosTotal, 2),
                'ad_hoc_value' => round($adHocValue, 2),
                'total_received_value' => round($receivedFromPosTotal + $adHocValue, 2),
            ],
        ];
    }
}
