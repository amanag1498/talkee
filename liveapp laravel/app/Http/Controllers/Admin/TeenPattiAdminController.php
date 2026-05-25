<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TeenPattiBet;
use App\Models\TeenPattiRound;
use App\Services\TeenPattiService;
use Illuminate\Http\Request;

class TeenPattiAdminController extends Controller
{
    public function __construct(private TeenPattiService $teenPatti)
    {
    }

    public function dashboard()
    {
        return view('admin.games.teen-patti.dashboard', [
            'payload' => $this->teenPatti->adminDashboardPayload(),
        ]);
    }

    public function report(Request $request)
    {
        return view('admin.games.user-performance-report', [
            'report' => $this->teenPatti->adminUserReportPayload($request->all()),
            'gameName' => 'Teen Patti',
            'gameDescription' => 'Per-user betting, payout, refund, and profit reporting for Teen Patti.',
            'dashboardRoute' => 'admin.games.teen-patti.dashboard',
            'reportRoute' => 'admin.games.teen-patti.report',
        ]);
    }

    public function rounds(Request $request)
    {
        $query = $this->teenPatti->roundsQuery();
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

        return view('admin.games.teen-patti.rounds', [
            'rounds' => $query->paginate(30)->withQueryString(),
        ]);
    }

    public function bets(Request $request)
    {
        $query = $this->teenPatti->betsQuery();
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

        return view('admin.games.teen-patti.bets', [
            'bets' => $query->paginate(40)->withQueryString(),
        ]);
    }

    public function payouts(Request $request)
    {
        $query = $this->teenPatti->payoutsQuery();
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

        return view('admin.games.teen-patti.payouts', [
            'payouts' => $query->paginate(40)->withQueryString(),
        ]);
    }

    public function tick(Request $request)
    {
        $round = null;
        if ($request->filled('round_id')) {
            $round = TeenPattiRound::query()->findOrFail((int) $request->integer('round_id'));
        }

        $result = $this->teenPatti->tick($round);

        return back()->with('ok', "Teen Patti tick completed for {$result->round_key} ({$result->status}).");
    }

    public function reconcile(TeenPattiRound $round)
    {
        $report = $this->teenPatti->reconcileRound($round);

        return back()->with('ok', "Round {$round->round_key} reconciled. Next round ready: " . ($report['next_round_ready'] ? 'yes' : 'no'));
    }

    public function refund(Request $request, TeenPattiBet $bet)
    {
        $data = $request->validate([
            'note' => 'nullable|string|max:500',
        ]);

        $refunded = $this->teenPatti->refundBet($bet, $data['note'] ?? null);

        return back()->with('ok', "Bet #{$refunded->id} refunded successfully.");
    }
}
