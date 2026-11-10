<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class DocumentController extends Controller
{
    public function orderInvoice(Request $request, int $order): Response
    {
        return $this->orderDocument($request, $order, 'invoice');
    }

    public function orderReceipt(Request $request, int $order): Response
    {
        return $this->orderDocument($request, $order, 'receipt');
    }

    public function posReceipt(Request $request, int $sale): Response
    {
        $user = $request->user();
        abort_if($user->customer_id !== null, 403, 'Receipts are available to clinic staff only.');
        abort_unless($user->can('pos.receipt.view'), 403);

        $saleRecord = DB::table('pos_sales')
            ->where('organization_id', $user->organization_id)
            ->where('id', $sale)
            ->first();
        abort_if($saleRecord === null, 404);

        $lines = DB::table('pos_sale_lines')->where('sale_id', $saleRecord->id)->get()
            ->map(static fn (object $line): array => [
                'name' => $line->item_name,
                'sku' => $line->item_code,
                'quantity' => (float) $line->quantity,
                'unit_price' => (float) $line->unit_price,
                'line_total' => (float) $line->line_total,
            ])->all();
        $payments = DB::table('pos_sale_payments')->where('sale_id', $saleRecord->id)->get()
            ->map(static fn (object $payment): array => [
                'method' => ucfirst((string) $payment->tender_type),
                'reference' => $payment->reference,
                'amount' => (float) $payment->amount,
            ])->all();

        return $this->render([
            'docType' => 'Receipt',
            'docNumber' => (string) $saleRecord->receipt_number,
            'issuedAt' => $saleRecord->sold_at,
            'status' => ucfirst((string) $saleRecord->status),
            'organization' => Organization::query()->find($saleRecord->organization_id),
            'branch' => Branch::query()->find($saleRecord->branch_id),
            'customer' => (object) [
                'name' => $saleRecord->customer_name_snapshot ?: 'Walk-in customer',
                'email' => null,
                'phone' => null,
            ],
            'meta' => [
                'Receipt number' => $saleRecord->receipt_number,
                'Cashier' => DB::table('users')->where('id', $saleRecord->cashier_id)->value('name'),
            ],
            'lines' => $lines,
            'payments' => $payments,
            'totals' => [
                'Subtotal' => (float) $saleRecord->subtotal,
                'Discount' => (float) $saleRecord->discount_amount,
                'Tax' => (float) $saleRecord->tax_amount,
                'Total' => (float) $saleRecord->total_amount,
                'Refunded' => (float) $saleRecord->refunded_amount,
            ],
            'currency' => (string) $saleRecord->currency_code,
            'footnote' => 'Thank you for visiting Oshel. Keep this receipt for your records.',
            'filename' => 'receipt-'.$saleRecord->receipt_number.'.pdf',
        ]);
    }

    public function appointmentConfirmation(Request $request, int $appointment): Response
    {
        $user = $request->user();
        $record = DB::table('appointments')
            ->where('organization_id', $user->organization_id)
            ->where('id', $appointment)
            ->first();
        abort_if($record === null, 404);
        if ($user->customer_id !== null) {
            abort_unless((int) $record->customer_id === (int) $user->customer_id, 404);
        } else {
            abort_unless($user->can('appointments.view'), 403);
        }
        $service = DB::table('services')->where('id', $record->service_id)->first();
        $customer = Customer::query()->find($record->customer_id);

        return $this->render([
            'docType' => 'Appointment confirmation',
            'docNumber' => (string) $record->appointment_number,
            'issuedAt' => $record->created_at,
            'status' => ucfirst((string) $record->status),
            'organization' => Organization::query()->find($record->organization_id),
            'branch' => Branch::query()->find($record->branch_id),
            'customer' => $customer,
            'meta' => [
                'Appointment' => $record->appointment_number,
                'Service' => $service?->name,
                'Starts' => $this->formatDateTime($record->starts_at),
                'Ends' => $this->formatDateTime($record->ends_at),
                'Deposit status' => str_replace('_', ' ', (string) $record->deposit_status),
            ],
            'lines' => [[
                'name' => $service?->name ?? 'Clinic appointment',
                'sku' => null,
                'quantity' => 1,
                'unit_price' => (float) $record->service_price,
                'line_total' => (float) $record->service_price,
            ]],
            'payments' => [],
            'totals' => [
                'Service price' => (float) $record->service_price,
                'Deposit due' => (float) $record->deposit_due,
            ],
            'currency' => (string) $record->currency_code,
            'footnote' => 'Please arrive a few minutes early. Contact the clinic if you need to reschedule.',
            'filename' => 'appointment-'.$record->appointment_number.'.pdf',
        ]);
    }

    private function orderDocument(Request $request, int $orderId, string $type): Response
    {
        $user = $request->user();
        $order = DB::table('ecommerce_orders')->where('id', $orderId)->first();
        abort_if($order === null, 404);
        if ($user->customer_id !== null) {
            abort_unless((int) $order->customer_id === (int) $user->customer_id, 404);
        } else {
            abort_unless((int) $order->organization_id === (int) $user->organization_id, 404);
            abort_unless($user->can('ecommerce.orders.view'), 403);
        }

        $customer = Customer::query()->find($order->customer_id);
        $lines = DB::table('ecommerce_order_lines')->where('order_id', $order->id)->get()
            ->map(static fn (object $line): array => [
                'name' => $line->name_snapshot,
                'sku' => $line->sku_snapshot,
                'quantity' => (float) $line->quantity,
                'unit_price' => (float) $line->unit_price,
                'line_total' => (float) $line->line_total,
            ])->all();
        $payments = DB::table('payment_transactions')
            ->where('order_id', $order->id)
            ->orderBy('created_at')
            ->get()
            ->map(static fn (object $payment): array => [
                'method' => ucfirst((string) ($payment->method ?: $payment->provider)),
                'reference' => $payment->merchant_reference,
                'amount' => (float) $payment->amount,
                'status' => (string) $payment->status,
            ])->all();
        $paid = (float) DB::table('payment_transactions')
            ->where('order_id', $order->id)
            ->where('status', 'paid')
            ->sum('amount');
        $delivery = in_array($type, ['invoice', 'receipt'], true) ? (float) $order->delivery_fee : 0.0;
        $suffix = $type === 'invoice' ? 'INV' : 'RCPT';

        return $this->render([
            'docType' => $type === 'invoice' ? 'Invoice' : 'Payment receipt',
            'docNumber' => $order->order_number.'-'.$suffix,
            'issuedAt' => $type === 'invoice' ? $order->created_at : ($order->updated_at ?? $order->created_at),
            'status' => ucfirst((string) $order->status),
            'organization' => Organization::query()->find($order->organization_id),
            'branch' => Branch::query()->find($order->branch_id),
            'customer' => $customer,
            'meta' => [
                'Order' => $order->order_number,
                'Fulfilment' => ucfirst(str_replace('_', ' ', (string) $order->fulfillment_type)),
                'Payment status' => ucfirst((string) $order->payment_status),
                'Order status' => ucfirst(str_replace('_', ' ', (string) $order->status)),
            ],
            'lines' => $lines,
            'payments' => $payments,
            'totals' => [
                'Subtotal' => (float) $order->subtotal,
                'Delivery' => $delivery,
                'Total' => (float) $order->total_amount,
                'Amount paid' => $paid,
                'Balance due' => max(0, (float) $order->total_amount - $paid),
            ],
            'currency' => (string) $order->currency_code,
            'footnote' => $type === 'invoice'
                ? 'This invoice is issued by Oshel. Payment is only confirmed after verification by the clinic.'
                : 'This receipt confirms payment recorded against the order above.',
            'filename' => strtolower($type).'-'.$order->order_number.'.pdf',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function render(array $data): Response
    {
        $html = view('documents.document', $data)->render();

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$data['filename'].'"',
        ]);
    }

    private function formatDateTime(?string $value): ?string
    {
        return $value === null ? null : \Carbon\CarbonImmutable::parse($value, 'UTC')
            ->setTimezone('Africa/Nairobi')->format('D, j M Y H:i').' EAT';
    }
}
