<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentOrder;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class RechargeAuditAdminController extends Controller
{
    public function index(Request $request): View
    {
        $selectedMonth = $this->resolveMonth($request->string('month')->toString());
        $monthlyBase = PaymentOrder::query();
        $monthKeyExpression = $this->monthKeyExpression();

        $monthTabs = (clone $monthlyBase)
            ->selectRaw("{$monthKeyExpression} as month_key")
            ->selectRaw('COUNT(*) as order_count')
            ->selectRaw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count")
            ->selectRaw('SUM(amount_rupees) as rupees_total')
            ->selectRaw('SUM(total_coins) as coins_total')
            ->groupBy('month_key')
            ->orderByDesc('month_key')
            ->limit(12)
            ->get();

        $query = $this->filteredOrdersQuery($request, $selectedMonth);
        $orders = (clone $query)
            ->with(['user:id,name,email', 'rechargePlan:id,title'])
            ->latest('created_at')
            ->paginate(30)
            ->withQueryString();
        $this->appendGatewayMetadata($orders);

        $summary = (clone $query)
            ->selectRaw('COUNT(*) as total_orders')
            ->selectRaw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful_orders")
            ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_orders")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_orders")
            ->selectRaw("SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders")
            ->selectRaw('COALESCE(SUM(amount_rupees), 0) as rupees_total')
            ->selectRaw('COALESCE(SUM(total_coins), 0) as coins_total')
            ->first();
        $summary = $this->withGstBreakdown($summary);

        $gatewayBreakdown = (clone $query)
            ->selectRaw("COALESCE(NULLIF(gateway, ''), 'manual') as gateway_name")
            ->selectRaw('COUNT(*) as order_count')
            ->selectRaw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count")
            ->selectRaw('SUM(amount_rupees) as rupees_total')
            ->groupBy('gateway_name')
            ->orderByDesc('order_count')
            ->get();

        return view('admin.recharge-audit.index', [
            'orders' => $orders,
            'summary' => $summary,
            'gatewayBreakdown' => $gatewayBreakdown,
            'monthTabs' => $monthTabs,
            'selectedMonth' => $selectedMonth,
            'selectedMonthKey' => $selectedMonth->format('Y-m'),
        ]);
    }

    public function downloadMonthlyPdf(Request $request, string $month)
    {
        $selectedMonth = $this->resolveMonth($month);
        $query = $this->filteredOrdersQuery($request, $selectedMonth);

        $orders = (clone $query)
            ->with(['user:id,name,email', 'rechargePlan:id,title'])
            ->latest('created_at')
            ->get();
        $orders = $this->mapGatewayMetadata($orders);

        $summary = (clone $query)
            ->selectRaw('COUNT(*) as total_orders')
            ->selectRaw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful_orders")
            ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_orders")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_orders")
            ->selectRaw("SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders")
            ->selectRaw('COALESCE(SUM(amount_rupees), 0) as rupees_total')
            ->selectRaw('COALESCE(SUM(total_coins), 0) as coins_total')
            ->first();
        $summary = $this->withGstBreakdown($summary);

        $data = [
            'orders' => $orders,
            'summary' => $summary,
            'selectedMonth' => $selectedMonth,
            'selectedMonthKey' => $selectedMonth->format('Y-m'),
            'filters' => $request->only([
                'status',
                'gateway',
                'q',
                'payment_method',
                'vpa',
                'rrn',
                'contact',
                'email',
                'signature_verified',
            ]),
        ];

        if (class_exists(Pdf::class) || app()->bound('dompdf.wrapper')) {
            $pdf = app('dompdf.wrapper');
            $pdf->loadView('admin.recharge-audit.pdf', $data)
                ->setPaper('a4', 'landscape');

            $filename = "recharge-audit-{$selectedMonth->format('Y-m')}.pdf";

            return response($pdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'X-Download-Options' => 'noopen',
                'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
                'Pragma' => 'public',
            ]);
        }

        return response()
            ->view('admin.recharge-audit.pdf', $data)
            ->header('X-Recharge-Audit-Fallback', 'print-view');
    }

    private function filteredOrdersQuery(Request $request, Carbon $selectedMonth): Builder
    {
        $query = PaymentOrder::query()
            ->whereBetween('created_at', [
                $selectedMonth->copy()->startOfMonth(),
                $selectedMonth->copy()->endOfMonth(),
            ]);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('gateway')) {
            $query->where('gateway', $request->string('gateway')->toString());
        }

        if ($request->filled('payment_method')) {
            $method = strtolower(trim($request->string('payment_method')->toString()));
            $query->whereRaw(
                "LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(gateway_response, '$.payment.method')), '')) = ?",
                [$method]
            );
        }

        if ($request->filled('vpa')) {
            $vpa = trim($request->string('vpa')->toString());
            $query->where(function (Builder $builder) use ($vpa) {
                $builder
                    ->whereRaw(
                        "JSON_UNQUOTE(JSON_EXTRACT(gateway_response, '$.payment.vpa')) LIKE ?",
                        ["%{$vpa}%"]
                    )
                    ->orWhereRaw(
                        "JSON_UNQUOTE(JSON_EXTRACT(gateway_response, '$.payment.upi.vpa')) LIKE ?",
                        ["%{$vpa}%"]
                    );
            });
        }

        if ($request->filled('rrn')) {
            $rrn = trim($request->string('rrn')->toString());
            $query->whereRaw(
                "JSON_UNQUOTE(JSON_EXTRACT(gateway_response, '$.payment.acquirer_data.rrn')) LIKE ?",
                ["%{$rrn}%"]
            );
        }

        if ($request->filled('contact')) {
            $contact = trim($request->string('contact')->toString());
            $query->whereRaw(
                "JSON_UNQUOTE(JSON_EXTRACT(gateway_response, '$.payment.contact')) LIKE ?",
                ["%{$contact}%"]
            );
        }

        if ($request->filled('email')) {
            $email = trim($request->string('email')->toString());
            $query->whereRaw(
                "JSON_UNQUOTE(JSON_EXTRACT(gateway_response, '$.payment.email')) LIKE ?",
                ["%{$email}%"]
            );
        }

        if ($request->filled('signature_verified')) {
            $signatureVerified = $request->string('signature_verified')->toString();
            if (in_array($signatureVerified, ['1', '0'], true)) {
                $expected = $signatureVerified === '1' ? 'true' : 'false';
                $query->whereRaw(
                    "LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(gateway_response, '$.signature_verified')), 'false')) = ?",
                    [$expected]
                );
            }
        }

        if ($search = trim($request->string('q')->toString())) {
            $query->where(function (Builder $builder) use ($search) {
                $builder->where('order_id', 'like', "%{$search}%")
                    ->orWhere('gateway_order_id', 'like', "%{$search}%")
                    ->orWhere('gateway_payment_id', 'like', "%{$search}%")
                    ->orWhereRaw(
                        "JSON_UNQUOTE(JSON_EXTRACT(gateway_response, '$.payment.acquirer_data.rrn')) LIKE ?",
                        ["%{$search}%"]
                    )
                    ->orWhereRaw(
                        "JSON_UNQUOTE(JSON_EXTRACT(gateway_response, '$.payment.vpa')) LIKE ?",
                        ["%{$search}%"]
                    )
                    ->orWhereRaw(
                        "JSON_UNQUOTE(JSON_EXTRACT(gateway_response, '$.payment.contact')) LIKE ?",
                        ["%{$search}%"]
                    )
                    ->orWhereHas('user', function (Builder $userQuery) use ($search) {
                        $userQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        return $query;
    }

    private function resolveMonth(?string $month): Carbon
    {
        if (is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            return Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        }

        return now()->startOfMonth();
    }

    private function withGstBreakdown(object $summary): object
    {
        $grossAmount = (float) ($summary->rupees_total ?? 0);
        $taxableAmount = round($grossAmount / 1.18, 2);
        $gstAmount = round($grossAmount - $taxableAmount, 2);

        $summary->taxable_total = $taxableAmount;
        $summary->gst_total = $gstAmount;

        return $summary;
    }

    private function monthKeyExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m', created_at)",
            'pgsql' => "to_char(created_at, 'YYYY-MM')",
            default => "DATE_FORMAT(created_at, '%Y-%m')",
        };
    }

    private function appendGatewayMetadata(LengthAwarePaginator $orders): void
    {
        $orders->setCollection($this->mapGatewayMetadata($orders->getCollection()));
    }

    private function mapGatewayMetadata(Collection $orders): Collection
    {
        return $orders->map(function (PaymentOrder $order) {
            $payload = is_array($order->gateway_response) ? $order->gateway_response : [];
            $payment = is_array($payload['payment'] ?? null) ? $payload['payment'] : [];
            $upi = is_array($payment['upi'] ?? null) ? $payment['upi'] : [];
            $acquirerData = is_array($payment['acquirer_data'] ?? null) ? $payment['acquirer_data'] : [];

            $order->audit_meta = [
                'payment_status' => $payment['status'] ?? null,
                'method' => $payment['method'] ?? null,
                'vpa' => $payment['vpa'] ?? ($upi['vpa'] ?? null),
                'upi_flow' => $upi['flow'] ?? null,
                'payer_account_type' => $upi['payer_account_type'] ?? null,
                'contact' => $payment['contact'] ?? null,
                'email' => $payment['email'] ?? null,
                'bank' => $payment['bank'] ?? null,
                'provider' => $payment['provider'] ?? null,
                'rrn' => $acquirerData['rrn'] ?? null,
                'gateway_fee' => $payment['fee'] ?? null,
                'gateway_tax' => $payment['tax'] ?? null,
                'captured' => $payment['captured'] ?? null,
                'international' => $payment['international'] ?? null,
                'signature_verified' => $payload['signature_verified'] ?? null,
                'gateway_created_at' => $payment['created_at'] ?? null,
                'error_code' => $payment['error_code'] ?? null,
                'error_description' => $payment['error_description'] ?? null,
            ];

            return $order;
        });
    }
}
