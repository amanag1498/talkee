<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentOrder;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class RechargeAuditAdminController extends Controller
{
    public function index(Request $request): View
    {
        $selectedMonth = $this->resolveMonth($request->string('month')->toString());
        $monthlyBase = PaymentOrder::query();

        $monthTabs = (clone $monthlyBase)
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month_key")
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

        $summary = (clone $query)
            ->selectRaw('COUNT(*) as total_orders')
            ->selectRaw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful_orders")
            ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_orders")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_orders")
            ->selectRaw("SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders")
            ->selectRaw('SUM(amount_rupees) as rupees_total')
            ->selectRaw('ROUND(SUM(amount_rupees) / 1.18, 2) as taxable_total')
            ->selectRaw('ROUND(SUM(amount_rupees) - (SUM(amount_rupees) / 1.18), 2) as gst_total')
            ->selectRaw('SUM(total_coins) as coins_total')
            ->first();

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

        $summary = (clone $query)
            ->selectRaw('COUNT(*) as total_orders')
            ->selectRaw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful_orders")
            ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_orders")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_orders")
            ->selectRaw("SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders")
            ->selectRaw('SUM(amount_rupees) as rupees_total')
            ->selectRaw('SUM(total_coins) as coins_total')
            ->first();

        $data = [
            'orders' => $orders,
            'summary' => $summary,
            'selectedMonth' => $selectedMonth,
            'selectedMonthKey' => $selectedMonth->format('Y-m'),
            'filters' => $request->only(['status', 'gateway', 'q']),
        ];

        if (class_exists(Pdf::class) || app()->bound('dompdf.wrapper')) {
            $pdf = app('dompdf.wrapper');
            $pdf->loadView('admin.recharge-audit.pdf', $data)
                ->setPaper('a4', 'landscape');

            return $pdf->download("recharge-audit-{$selectedMonth->format('Y-m')}.pdf");
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

        if ($search = trim($request->string('q')->toString())) {
            $query->where(function (Builder $builder) use ($search) {
                $builder->where('order_id', 'like', "%{$search}%")
                    ->orWhere('gateway_order_id', 'like', "%{$search}%")
                    ->orWhere('gateway_payment_id', 'like', "%{$search}%")
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
}
