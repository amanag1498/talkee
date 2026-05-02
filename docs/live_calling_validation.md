# Live Calling Validation

## Implemented Feature Summary

- Laravel is the source of truth for host availability, call sessions, wallet debits, earning ledgers, and reporting.
- Redis is used only as a post-commit event bridge.
- Node.js delivers Socket.IO realtime events on `/calls` and keeps `/presence` and `/rooms` intact.
- Flutter uses Laravel APIs, Socket.IO, and LiveKit for the live users and calling experience.
- Fresh database bootstrap is now deterministic through seeders for roles, admin, agency, host, and viewers.
- Firebase auth now returns clearer setup and token-verification errors instead of opaque server failures.
- Wallet and billing paths use stronger idempotency and row-level locking protections.
- Reconciliation and stale-availability cleanup commands are available for operator checks.

## DB Tables Added

- `host_availabilities`
- `call_sessions`
- `call_earning_ledgers`
- foreign key migration for `host_availabilities.current_call_session_id`
- reliability indexes for `call_sessions`, `wallet_transactions`, and `host_availabilities`

## API Routes Added

- `GET /api/live-users`
- `POST /api/host/status/toggle`
- `GET /api/host/status`
- `POST /api/calls/request`
- `POST /api/calls/{call}/accept`
- `POST /api/calls/{call}/reject`
- `POST /api/calls/{call}/end`
- `GET /api/calls/history`
- `GET /api/calls/{call}/token`
- `GET /api/admin/calls`
- `GET /api/admin/calls/summary`
- `GET /api/admin/calls/export`
- `GET /api/agency/calls`
- `GET /api/agency/calls/summary`
- `GET /api/agency/calls/export`
- `GET /api/host/calls`
- `GET /api/host/calls/summary`
- `POST /api/ws/presence`

## Redis Events

Channels:

- `users:availability`
- `calls:events`

Events:

- `user_availability_updated`
- `incoming_call`
- `call_accepted`
- `call_rejected`
- `call_missed`
- `call_ended`
- `call_failed`

## Reliability Improvements

- Better Firebase auth failure codes:
  - `firebase_service_account_missing`
  - `firebase_project_id_missing`
  - `firebase_init_failed`
  - `firebase_token_invalid`
  - `firebase_email_missing`
- `GET /api/health/ready` now includes:
  - DB
  - Redis
  - Firebase
  - queue
  - cache
  - storage
  - bootstrap summary
- Seeders now create:
  - roles
  - admin user
  - agency owner + agency
  - host user + host profile
  - viewer test users
  - wallet balances
- Call request duplicate taps now return the existing active call instead of creating a second one.
- Call accept/reject/end are safer to retry on terminal calls.
- Disconnect handling now closes or settles active/ringing calls more explicitly.
- Billing reference format is now `call_billing:{call_id}`.
- Wallet transactions use stronger row locking in `WalletService`.
- Admin wallet area now shows reconciliation counters and filtered wallet ledger views.

## Node Socket Events

Namespaces:

- `/presence`
- `/rooms`
- `/calls`

Realtime events:

- `incoming_call`
- `call_accepted`
- `call_rejected`
- `call_missed`
- `call_ended`
- `call_failed`
- `user_availability_updated`
- structured logs for duplicate sockets, emitted call events, stale presence, and `/calls` connect/disconnect

## Flutter Screens Added

- `/live-users`
- `/incoming-call`
- `/outgoing-call`
- `/active-call`
- `/call-history`

Main files:

- `liveapp flutter/lib/services/call_service.dart`
- `liveapp flutter/lib/services/call_socket_service.dart`
- `liveapp flutter/lib/modules/calls/controllers/call_controller.dart`
- `liveapp flutter/lib/modules/calls/controllers/live_users_controller.dart`
- `liveapp flutter/lib/modules/calls/views/live_users_view.dart`
- `liveapp flutter/lib/modules/calls/views/incoming_call_screen.dart`
- `liveapp flutter/lib/modules/calls/views/outgoing_call_screen.dart`
- `liveapp flutter/lib/modules/calls/views/active_call_screen.dart`
- `liveapp flutter/lib/modules/calls/views/call_history_view.dart`

## Admin / Agency Reports Added

Admin tabs:

- All Calls
- Active Calls
- Completed Calls
- Missed/Rejected Calls
- Host Earnings
- Agency Earnings

Agency tabs:

- Calls to My Hosts
- Active Calls
- Completed Calls
- Host-wise Earnings

Host tabs:

- My Calls
- My Earnings

## Manual Testing Checklist

1. Host toggles online.
2. Host toggles offline.
3. User sees host realtime.
4. User requests audio call.
5. User requests video call.
6. Receiver gets incoming call.
7. Receiver accepts.
8. Both join LiveKit room.
9. Caller ends call.
10. Coins deducted correctly.
11. Earning ledger created.
12. Admin sees call.
13. Agency sees own host call.
14. Rejected call works.
15. Missed call works.
16. Busy host cannot receive second call.
17. Low balance caller cannot call.
18. CSV export works.
19. Socket reconnect works.
20. Logout cleans socket.

## Commands To Run

- `cd "liveapp laravel" && composer install`
- `cd "liveapp laravel" && php artisan migrate --seed`
- `cd "liveapp laravel" && php artisan migrate:fresh --seed`
- `cd "liveapp laravel" && php artisan route:list`
- `cd "liveapp laravel" && php artisan calls:timeout-missed`
- `cd "liveapp laravel" && php artisan calls:cleanup-stale-availability`
- `cd "liveapp laravel" && php artisan calls:reconcile-billing`
- `cd "liveapp laravel" && php artisan queue:work-safe`
- `cd "live-presence-ws nodejs" && npm install`
- `cd "live-presence-ws nodejs" && node server.js`
- `cd "liveapp flutter" && flutter pub get`
- `cd "liveapp flutter" && flutter analyze`

## Deployment Notes

- Flutter API/socket host values can be overridden with:
  `--dart-define=APP_HOST=your-host --dart-define=APP_API_PORT=8000 --dart-define=APP_WS_PORT=3001 --dart-define=APP_SCHEME=http`
- Laravel `php artisan migrate:status` requires a reachable configured database.
- Local MySQL/XAMPP example:
  - `DB_CONNECTION=mysql`
  - `DB_HOST=127.0.0.1`
  - `DB_PORT=3306`
  - `DB_DATABASE=livestream`
  - `DB_USERNAME=root`
  - `DB_PASSWORD=`
- Firebase setup:
  - place `firebase-admin.json` at `liveapp laravel/storage/app/firebase-admin.json`
  - or set `FIREBASE_CREDENTIALS=/absolute/path/to/firebase-admin.json`
  - set `FIREBASE_PROJECT_ID=your-project-id`
- LiveKit setup:
  - set `LIVEKIT_WS_URL`
  - set `LK_API_KEY`
  - set `LK_API_SECRET`
