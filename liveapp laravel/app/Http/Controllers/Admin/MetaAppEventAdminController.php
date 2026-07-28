<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MetaAppEvent;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;

class MetaAppEventAdminController extends Controller
{
    private const EVENT_NAMES = [
        'app_launch',
        'login',
        'complete_registration',
        'advertiser_tracking_consent',
        'purchase',
    ];

    public function index(Request $request)
    {
        $activeTab = in_array($request->string('tab')->toString(), ['overview', 'events', 'setup'], true)
            ? $request->string('tab')->toString()
            : 'overview';

        if (! Schema::hasTable('meta_app_events')) {
            return view('admin.meta-app-events.index', [
                'events' => new LengthAwarePaginator(
                    [],
                    0,
                    50,
                    max(1, $request->integer('page', 1)),
                    ['path' => $request->url(), 'query' => $request->query()],
                ),
                'summary' => [
                    'events' => 0,
                    'registrations' => 0,
                    'purchases' => 0,
                    'revenue' => 0,
                ],
                'eventNames' => self::EVENT_NAMES,
                'eventBreakdown' => collect(),
                'platformBreakdown' => collect(),
                'consent' => ['allowed' => 0, 'declined' => 0],
                'activeTab' => 'setup',
                'setup' => $this->setupStatus(false),
            ]);
        }

        $query = MetaAppEvent::query()
            ->with(['user:id,name,email', 'paymentOrder:id,order_id,status'])
            ->when(
                $request->filled('event_name'),
                fn ($query) => $query->where('event_name', $request->string('event_name')->toString()),
            )
            ->when(
                $request->filled('platform'),
                fn ($query) => $query->where('platform', $request->string('platform')->toString()),
            )
            ->when(
                $request->filled('from'),
                fn ($query) => $query->whereDate('occurred_at', '>=', $request->date('from')->toDateString()),
            )
            ->when(
                $request->filled('to'),
                fn ($query) => $query->whereDate('occurred_at', '<=', $request->date('to')->toDateString()),
            );

        $summary = [
            'events' => (clone $query)->count(),
            'registrations' => (clone $query)->where('event_name', 'complete_registration')->count(),
            'purchases' => (clone $query)->where('event_name', 'purchase')->count(),
            'revenue' => (float) (clone $query)->where('event_name', 'purchase')->sum('value'),
        ];

        $eventBreakdown = (clone $query)
            ->selectRaw('event_name, COUNT(*) as event_count')
            ->groupBy('event_name')
            ->orderByDesc('event_count')
            ->get();
        $platformBreakdown = (clone $query)
            ->selectRaw("COALESCE(platform, 'server') as platform_name, COUNT(*) as event_count")
            ->groupBy('platform_name')
            ->orderByDesc('event_count')
            ->get();
        $consent = [
            'allowed' => (clone $query)
                ->where('event_name', 'advertiser_tracking_consent')
                ->where('advertiser_tracking_enabled', true)
                ->count(),
            'declined' => (clone $query)
                ->where('event_name', 'advertiser_tracking_consent')
                ->where('advertiser_tracking_enabled', false)
                ->count(),
        ];

        return view('admin.meta-app-events.index', [
            'events' => $query->latest('occurred_at')->paginate(50)->withQueryString(),
            'summary' => $summary,
            'eventNames' => self::EVENT_NAMES,
            'eventBreakdown' => $eventBreakdown,
            'platformBreakdown' => $platformBreakdown,
            'consent' => $consent,
            'activeTab' => $activeTab,
            'setup' => $this->setupStatus(true),
        ]);
    }

    private function setupStatus(bool $databaseReady): array
    {
        return [
            'database_ready' => $databaseReady,
            'app_id' => (string) config('services.meta.app_id', ''),
            'client_token_configured' => filled(config('services.meta.client_token')),
            'ad_account_id' => (string) config('services.meta.ad_account_id', ''),
            'business_id' => (string) config('services.meta.business_id', ''),
            'last_event_at' => $databaseReady ? MetaAppEvent::query()->max('occurred_at') : null,
            'server_events' => $databaseReady
                ? MetaAppEvent::query()->where('source', 'server')->count()
                : 0,
            'app_events' => $databaseReady
                ? MetaAppEvent::query()->where('source', 'app')->count()
                : 0,
        ];
    }
}
