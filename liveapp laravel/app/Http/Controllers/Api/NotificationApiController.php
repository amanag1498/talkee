<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserNotification;
use Illuminate\Http\Request;

class NotificationApiController extends Controller
{
    /**
     * GET /api/notifications
     * Supports:
     *  - page/per_page      (classic pagination)
     *  - before_id/after_id (keyset pagination; takes precedence when provided)
     * Returns: { ok, data: [...], meta: {...} } ; includes ETag header to allow 304 caching.
     */
    public function index(Request $request)
    {
        $userId   = $request->user()->id;
        $perPage  = max(1, min(50, (int) $request->query('per_page', $request->query('limit', 20))));
        $page     = max(1, (int) $request->query('page', 1));
        $afterId  = (int) $request->query('after_id', 0);
        $beforeId = (int) $request->query('before_id', 0);

        // Optional: cheap ETag based on newest id and unread count
        $lastId      = (int) (UserNotification::where('user_id', $userId)->max('id') ?? 0);
        $unreadCount = (int) UserNotification::where('user_id',$userId)->whereNull('read_at')->count();
        $etag        = sha1($lastId.'|'.$unreadCount);
        if ($request->headers->get('If-None-Match') === $etag && !$afterId && !$beforeId && $page === 1) {
            return response('', 304)->header('ETag', $etag);
        }

        $q = UserNotification::where('user_id', $userId);

        // Keyset pagination (preferred for infinite scroll)
        if ($afterId > 0)  $q->where('id', '>', $afterId);
        if ($beforeId > 0) $q->where('id', '<', $beforeId);

        $q->orderByDesc('id');

        if ($afterId || $beforeId) {
            $items = $q->limit($perPage)->get();
            return response()
                ->json([
                    'ok'   => true,
                    'data' => $items,
                    'meta' => [
                        'mode'      => 'keyset',
                        'per_page'  => $perPage,
                        'count'     => $items->count(),
                        'has_more'  => $items->count() === $perPage,
                    ],
                ])
                ->header('ETag', $etag);
        }

        // Classic pagination
        $items = $q->paginate($perPage, ['*'], 'page', $page);
        return response()
            ->json([
                'ok'   => true,
                'data' => $items->items(),
                'meta' => [
                    'mode'         => 'page',
                    'current_page' => $items->currentPage(),
                    'per_page'     => $items->perPage(),
                    'total'        => $items->total(),
                    'last_page'    => $items->lastPage(),
                    'has_more'     => $items->hasMorePages(),
                ],
            ])
            ->header('ETag', $etag);
    }

    /** GET /api/notifications/unread-count -> { ok, count } */
    public function unreadCount(Request $request)
    {
        $count = UserNotification::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->count();

        return response()->json(['ok' => true, 'count' => (int) $count]);
    }

    /** POST /api/notifications/{id}/read -> { ok } */
    public function markRead(Request $request, $id)
    {
        $userId = $request->user()->id;

        $n = UserNotification::where('user_id', $userId)->findOrFail($id);
        if (is_null($n->read_at)) {
            $n->read_at = now();
            $n->save();
        }

        return response()->json(['ok' => true]);
    }

    /** POST /api/notifications/read (bulk) -> { ok, updated } */
    public function markManyRead(Request $request)
    {
        $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $userId  = $request->user()->id;
        $updated = UserNotification::where('user_id', $userId)
            ->whereIn('id', $request->input('ids'))
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true, 'updated' => (int) $updated]);
    }

    /** POST /api/notifications/read-all -> { ok, updated } */
    public function markAllRead(Request $request)
    {
        $userId  = $request->user()->id;
        $updated = UserNotification::where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true, 'updated' => (int) $updated]);
    }
}
