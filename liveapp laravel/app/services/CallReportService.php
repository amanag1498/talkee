<?php

namespace App\Services;

use App\Models\Agency;
use App\Models\CallEarningLedger;
use App\Models\CallSession;
use App\Models\Host;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class CallReportService
{
    public function baseQuery(Request $request): Builder
    {
        return CallSession::query()
            ->with(['caller', 'receiver', 'host.user', 'agency'])
            ->when($request->string('tab')->toString() === 'active', fn ($q) => $q->where('status', 'accepted'))
            ->when($request->string('tab')->toString() === 'completed', fn ($q) => $q->where('status', 'ended'))
            ->when($request->string('tab')->toString() === 'missed_rejected', fn ($q) => $q->whereIn('status', ['missed', 'rejected', 'failed']))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('host_id'), fn ($q) => $q->where('host_id', $request->integer('host_id')))
            ->when($request->filled('agency_id'), fn ($q) => $q->where('agency_id', $request->integer('agency_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->string('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->string('date_to')))
            ->latest('id');
    }

    public function forAdmin(Request $request): array
    {
        if (!$this->schemaReady()) {
            return $this->emptyResponse(true);
        }

        $query = $this->baseQuery($request);
        return $this->buildResponse($query, true, $request);
    }

    public function forAgency(Request $request, Agency $agency): array
    {
        if (!$this->schemaReady()) {
            return $this->emptyResponse(false);
        }

        $query = $this->baseQuery($request)->where('agency_id', $agency->id);
        return $this->buildResponse($query, false, $request, $agency->id);
    }

    public function forHost(Request $request, Host $host): array
    {
        if (!$this->schemaReady()) {
            return $this->emptyResponse(false);
        }

        $query = $this->baseQuery($request)->where('host_id', $host->id);
        return $this->buildResponse($query, false, $request, null, $host->id);
    }

    public function forUserHistory(Request $request, User $user): array
    {
        if (!$this->schemaReady()) {
            return $this->emptyResponse(false);
        }

        $query = $this->baseQuery($request)
            ->where(function ($builder) use ($user) {
                $builder->where('caller_id', $user->id)->orWhere('receiver_id', $user->id);
            });

        return $this->buildResponse($query, false, $request);
    }

    private function buildResponse(
        Builder $query,
        bool $includeFilters,
        Request $request,
        ?int $agencyId = null,
        ?int $hostId = null,
    ): array
    {
        $summaryBase = clone $query;
        $calls = $query->paginate(20)->withQueryString();
        $earnings = $this->buildEarnings($request, $agencyId, $hostId);
        $summary = [
            'total_calls' => (clone $summaryBase)->count(),
            'active_calls' => (clone $summaryBase)->where('status', 'accepted')->count(),
            'completed_calls' => (clone $summaryBase)->where('status', 'ended')->count(),
            'missed_rejected_calls' => (clone $summaryBase)->whereIn('status', ['missed', 'rejected', 'failed'])->count(),
            'total_minutes' => (int) (clone $summaryBase)->sum('billable_minutes'),
            'total_coins_charged' => (int) (clone $summaryBase)->sum('total_coins_charged'),
            'total_host_earnings' => (int) (clone $summaryBase)->sum('host_earning'),
            'total_agency_earnings' => (int) (clone $summaryBase)->sum('agency_earning'),
            'total_platform_earnings' => (int) (clone $summaryBase)->sum('platform_earning'),
        ];

        return [
            'calls' => $calls,
            'summary' => $summary,
            'earnings' => $earnings,
            'schema_ready' => true,
            'setup_message' => null,
            'filters' => $includeFilters ? [
                'hosts' => Host::with('user')->orderBy('id', 'desc')->get(),
                'agencies' => Agency::orderBy('id', 'desc')->get(),
            ] : null,
        ];
    }

    private function buildEarnings(Request $request, ?int $agencyId = null, ?int $hostId = null): array
    {
        $hostEarnings = CallEarningLedger::query()
            ->selectRaw('host_id, SUM(total_coins) as total_coins, SUM(host_earning) as host_earning, SUM(duration_seconds) as duration_seconds, SUM(billable_minutes) as billable_minutes')
            ->with('host.user')
            ->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId))
            ->when($hostId, fn ($q) => $q->where('host_id', $hostId))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->string('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->string('date_to')))
            ->groupBy('host_id')
            ->orderByDesc('host_earning')
            ->get();

        $agencyEarnings = CallEarningLedger::query()
            ->selectRaw('agency_id, SUM(total_coins) as total_coins, SUM(agency_earning) as agency_earning, SUM(duration_seconds) as duration_seconds, SUM(billable_minutes) as billable_minutes')
            ->with('agency')
            ->whereNotNull('agency_id')
            ->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->string('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->string('date_to')))
            ->groupBy('agency_id')
            ->orderByDesc('agency_earning')
            ->get();

        return [
            'hosts' => $hostEarnings,
            'agencies' => $agencyEarnings,
        ];
    }

    public function schemaReady(): bool
    {
        return Schema::hasTable('call_sessions')
            && Schema::hasTable('call_earning_ledgers')
            && Schema::hasTable('host_availabilities');
    }

    private function emptyResponse(bool $includeFilters): array
    {
        return [
            'calls' => new LengthAwarePaginator([], 0, 20),
            'summary' => [
                'total_calls' => 0,
                'active_calls' => 0,
                'completed_calls' => 0,
                'missed_rejected_calls' => 0,
                'total_minutes' => 0,
                'total_coins_charged' => 0,
                'total_host_earnings' => 0,
                'total_agency_earnings' => 0,
                'total_platform_earnings' => 0,
            ],
            'earnings' => [
                'hosts' => new Collection(),
                'agencies' => new Collection(),
            ],
            'schema_ready' => false,
            'setup_message' => 'Call reporting tables are not available yet. Run php artisan migrate to create call_sessions, call_earning_ledgers, and host_availabilities.',
            'filters' => $includeFilters ? [
                'hosts' => Host::with('user')->orderBy('id', 'desc')->get(),
                'agencies' => Agency::orderBy('id', 'desc')->get(),
            ] : null,
        ];
    }
}
