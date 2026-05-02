<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\NotifyUser;
use Illuminate\Http\Request;

class AdminUserNotificationController extends Controller
{
    // TAB: Recent (global)
    public function index(Request $req)
    {
        $q = UserNotification::with('user')->latest('id');

        if ($uid = $req->query('user_id'))     $q->where('user_id', $uid);
        if ($type = $req->query('type'))       $q->where('type', $type);
        if ($req->boolean('unread'))           $q->whereNull('read_at');
        if ($from = $req->query('created_from')) $q->where('created_at', '>=', $from);
        if ($to   = $req->query('created_to'))   $q->where('created_at', '<=', $to);

        $items = $q->paginate(25)->withQueryString();

        // For the filter dropdowns (optional)
        $types = UserNotification::select('type')->whereNotNull('type')->distinct()->pluck('type');

        return view('admin.notifications.index', compact('items', 'types'));
    }

    // TAB: Compose (supports optional prefill via query params)
    public function compose(Request $req)
    {
        // You can pass ?title=...&body=...&type=...&screen=...&room_id=...&meta=...
        // to prefill the form (used by "Resend" links).
        return view('admin.notifications.compose');
    }

    // POST: Send (push / persist)
    public function send(Request $req)
    {
        $data = $req->validate([
            'audience' => 'required|in:user,role,all',
            'user_id'  => 'nullable|integer|exists:users,id',
            'role'     => 'nullable|string',
            'type'     => 'nullable|string|max:64',
            'title'    => 'required|string|max:160',
            'body'     => 'nullable|string|max:2000',
            'meta'     => 'nullable|string',     // JSON
            'screen'   => 'nullable|string|max:64', // e.g. 'notifications' | 'room'
            'room_id'  => 'nullable|string|max:255',
            // Checkboxes come as 0/1; validate as in-list for clearer errors
            'push'     => 'required|in:0,1',
            'persist'  => 'required|in:0,1',
        ], [
            'meta.string'     => 'Meta must be a JSON string.',
            'push.in'         => 'Push must be 0 or 1.',
            'persist.in'      => 'Persist must be 0 or 1.',
            'audience.in'     => 'Audience must be one of user, role, or all.',
            'title.required'  => 'Title is required.',
        ]);

        // Coerce safely to booleans
        $push    = $req->boolean('push');
        $persist = $req->boolean('persist');

        // Validate & decode meta JSON if present
        $metaRaw = $data['meta'] ?? null;
        $metaArr = null;
        if ($metaRaw) {
            $metaArr = json_decode($metaRaw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return back()
                    ->withErrors(['meta' => 'Meta must be valid JSON.'])
                    ->withInput();
            }
        }

        $payload = [
            'type'    => $data['type'] ?? null,
            'title'   => $data['title'],
            'body'    => $data['body'] ?? null,
            'meta'    => $metaArr,
            'screen'  => $data['screen'] ?? null,   // NEW
            'room_id' => $data['room_id'] ?? null,  // NEW
        ];

        $opts = [
            'push'    => $push,
            'persist' => $persist,
        ];

        $aud = match ($data['audience']) {
            'all'  => 'all',
            'role' => ['role' => $data['role'] ?: 'user'],
            'user' => (int) $data['user_id'],
        };

        NotifyUser::sendMany($aud, $payload, $opts);

        return redirect()
            ->route('admin.notifications.index')
            ->with('ok', 'Notification sent.');
    }

    // Per-user page (from Users table) + filters
    public function userNotifications(User $user, Request $req)
    {
        $q = UserNotification::where('user_id', $user->id)->latest('id');

        if ($type = $req->query('type'))         $q->where('type', $type);
        if ($req->boolean('unread'))             $q->whereNull('read_at');
        if ($from = $req->query('created_from')) $q->where('created_at', '>=', $from);
        if ($to   = $req->query('created_to'))   $q->where('created_at', '<=', $to);

        $items = $q->paginate(25)->withQueryString();

        $types = UserNotification::select('type')->whereNotNull('type')
            ->where('user_id', $user->id)->distinct()->pluck('type');

        return view('admin.notifications.user', compact('user', 'items', 'types'));
    }
}
