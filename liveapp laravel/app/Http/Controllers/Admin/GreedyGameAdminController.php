<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GreedyBet;
use App\Models\GreedyRound;
use App\Services\GreedyGameService;
use Illuminate\Http\Request;

class GreedyGameAdminController extends Controller
{
    public function __construct(private GreedyGameService $greedy)
    {
    }

    public function dashboard()
    {
        return view('admin.games.greedy.dashboard', [
            'payload' => $this->greedy->adminDashboardPayload(),
        ]);
    }

    public function report(Request $request)
    {
        return view('admin.games.user-performance-report', [
            'report' => $this->greedy->adminUserReportPayload($request->all()),
            'gameName' => 'Greedy',
            'gameDescription' => 'Per-user betting, payout, refund, and profit reporting for Greedy.',
            'dashboardRoute' => 'admin.games.greedy.dashboard',
            'reportRoute' => 'admin.games.greedy.report',
        ]);
    }

    public function rounds(Request $request)
    {
        $query = $this->greedy->roundsQuery();
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

        return view('admin.games.greedy.rounds', [
            'rounds' => $query->paginate(30)->withQueryString(),
        ]);
    }

    public function bets(Request $request)
    {
        $query = $this->greedy->betsQuery();
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

        return view('admin.games.greedy.bets', [
            'bets' => $query->paginate(40)->withQueryString(),
        ]);
    }

    public function payouts(Request $request)
    {
        $query = $this->greedy->payoutsQuery();
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

        return view('admin.games.greedy.payouts', [
            'payouts' => $query->paginate(40)->withQueryString(),
        ]);
    }

    public function tick(Request $request)
    {
        $round = null;
        if ($request->filled('round_id')) {
            $round = GreedyRound::query()->findOrFail((int) $request->integer('round_id'));
        }

        $result = $this->greedy->tick($round);

        return back()->with('ok', "Greedy tick completed for {$result->round_key} ({$result->status}).");
    }

    public function reconcile(GreedyRound $round)
    {
        $report = $this->greedy->reconcileRound($round);

        return back()->with('ok', "Round {$round->round_key} reconciled. Next round ready: " . ($report['next_round_ready'] ? 'yes' : 'no'));
    }

    public function refund(Request $request, GreedyBet $bet)
    {
        $data = $request->validate([
            'note' => 'nullable|string|max:500',
        ]);

        $refunded = $this->greedy->refundBet($bet, $data['note'] ?? null);

        return back()->with('ok', "Bet #{$refunded->id} refunded successfully.");
    }
}
