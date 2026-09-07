<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SevenUpDownBet;
use App\Models\SevenUpDownRound;
use App\Services\AdminAuditService;
use App\Services\SevenUpDownService;
use Illuminate\Http\Request;

class SevenUpDownAdminController extends Controller
{
    public function __construct(
        private SevenUpDownService $seven_up_down,
        private AdminAuditService $audits,
    ) {}

    public function dashboard()
    {
        return view('admin.games.seven-up-down.dashboard', [
            'payload' => $this->seven_up_down->adminDashboardPayload(),
        ]);
    }

    public function report(Request $request)
    {
        return view('admin.games.user-performance-report', [
            'report' => $this->seven_up_down->adminUserReportPayload($request->all()),
            'gameName' => 'Lucky 7',
            'gameDescription' => 'Per-user betting, payout, refund, and profit reporting for Lucky 7.',
            'dashboardRoute' => 'admin.games.seven-up-down.dashboard',
            'reportRoute' => 'admin.games.seven-up-down.report',
        ]);
    }

    public function rounds(Request $request)
    {
        $query = $this->seven_up_down->roundsQuery();
        if ($q = trim((string) $request->string('q'))) {
            $query->where(function ($builder) use ($q) {
                $builder
                    ->where('round_key', 'like', "%{$q}%")
                    ->orWhere('id', is_numeric($q) ? (int) $q : 0);
            });
        }
        if ($status = trim((string) $request->string('status'))) {
            $query->where('status', $status);
        }
        if ($pot = strtoupper(trim((string) $request->string('winning_pot')))) {
            $query->where('winning_pot', $pot);
        }
        if ($request->filled('dice_total')) {
            $query->where('dice_total', max(2, min(12, (int) $request->integer('dice_total'))));
        }
        $this->applyDateFilters($query, $request, 'created_at');

        return view('admin.games.seven-up-down.rounds', [
            'rounds' => $query->paginate(30)->withQueryString(),
        ]);
    }

    public function bets(Request $request)
    {
        $query = $this->seven_up_down->betsQuery();
        if ($q = trim((string) $request->string('q'))) {
            $query->where(function ($builder) use ($q) {
                $builder
                    ->where('id', is_numeric($q) ? (int) $q : 0)
                    ->orWhereHas('user', function ($userQuery) use ($q) {
                        $userQuery
                            ->where('name', 'like', "%{$q}%")
                            ->orWhere('email', 'like', "%{$q}%")
                            ->orWhere('id', is_numeric($q) ? (int) $q : 0);
                    })
                    ->orWhereHas('round', function ($roundQuery) use ($q) {
                        $roundQuery->where('round_key', 'like', "%{$q}%");
                    });
            });
        }
        if ($status = trim((string) $request->string('status'))) {
            $query->where('status', $status);
        }
        if ($pot = trim((string) $request->string('pot'))) {
            $query->where('pot', strtoupper($pot));
        }
        $this->applyDateFilters($query, $request, 'placed_at');

        return view('admin.games.seven-up-down.bets', [
            'bets' => $query->paginate(40)->withQueryString(),
        ]);
    }

    public function payouts(Request $request)
    {
        $query = $this->seven_up_down->payoutsQuery();
        if ($q = trim((string) $request->string('q'))) {
            $query->where(function ($builder) use ($q) {
                $builder
                    ->where('id', is_numeric($q) ? (int) $q : 0)
                    ->orWhereHas('user', function ($userQuery) use ($q) {
                        $userQuery
                            ->where('name', 'like', "%{$q}%")
                            ->orWhere('email', 'like', "%{$q}%")
                            ->orWhere('id', is_numeric($q) ? (int) $q : 0);
                    })
                    ->orWhereHas('round', function ($roundQuery) use ($q) {
                        $roundQuery->where('round_key', 'like', "%{$q}%");
                    });
            });
        }
        if ($status = trim((string) $request->string('status'))) {
            $query->where('status', $status);
        }
        $this->applyDateFilters($query, $request, 'settled_at');

        return view('admin.games.seven-up-down.payouts', [
            'payouts' => $query->paginate(40)->withQueryString(),
        ]);
    }

    public function tick(Request $request)
    {
        $round = null;
        if ($request->filled('round_id')) {
            $round = SevenUpDownRound::query()->findOrFail((int) $request->integer('round_id'));
        }

        $result = $this->seven_up_down->tick($round);
        $this->audits->log(
            'games',
            'seven_up_down_tick',
            $request->user(),
            entity: $result,
            after: $result->fresh()->toArray(),
        );

        return back()->with('ok', "Lucky 7 tick completed for {$result->round_key} ({$result->status}).");
    }

    public function reconcile(SevenUpDownRound $round)
    {
        $before = $round->toArray();
        $report = $this->seven_up_down->reconcileRound($round);
        $this->audits->log(
            'games',
            'seven_up_down_round_reconciled',
            request()->user(),
            entity: $round,
            before: $before,
            after: $round->fresh()->toArray(),
            meta: ['next_round_ready' => (bool) $report['next_round_ready']],
        );

        return back()->with('ok', "Round {$round->round_key} reconciled. Next round ready: ".($report['next_round_ready'] ? 'yes' : 'no'));
    }

    public function refund(Request $request, SevenUpDownBet $bet)
    {
        $data = $request->validate([
            'note' => 'nullable|string|max:500',
        ]);

        $before = $bet->toArray();
        $refunded = $this->seven_up_down->refundBet($bet, $data['note'] ?? null);
        $this->audits->log(
            'games',
            'seven_up_down_bet_refunded',
            $request->user(),
            $refunded->user,
            $refunded,
            $before,
            $refunded->toArray(),
            $data['note'] ?? null,
        );

        return back()->with('ok', "Bet #{$refunded->id} refunded successfully.");
    }

    private function applyDateFilters($query, Request $request, string $column): void
    {
        if ($from = trim((string) $request->string('date_from'))) {
            $query->where($column, '>=', $from.' 00:00:00');
        }
        if ($to = trim((string) $request->string('date_to'))) {
            $query->where($column, '<=', $to.' 23:59:59');
        }
    }
}
