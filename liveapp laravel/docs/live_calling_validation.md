# Live Calling Validation

## Implemented Feature Summary

- Laravel owns call state, wallet deduction, earning ledgers, and reporting.
- Redis is used only to publish post-commit availability and call events.
- Node.js delivers realtime Socket.IO events for `/presence`, `/rooms`, and `/calls`.
- Flutter uses Laravel APIs plus Socket.IO events for live users, incoming/outgoing call flow, active LiveKit sessions, and call history.

## DB Tables Added

- `host_availabilities`
- `call_sessions`
- `call_earning_ledgers`
- foreign key migration for `host_availabilities.current_call_session_id`

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

## Node Socket Events

Namespaces:

- `/presence`
- `/rooms`
- `/calls`

Call events delivered by Node:

- `incoming_call`
- `call_accepted`
- `call_rejected`
- `call_missed`
- `call_ended`
- `call_failed`
- `user_availability_updated`

## Flutter Screens Added

- `/live-users`
- `/incoming-call`
- `/outgoing-call`
- `/active-call`
- `/call-history`

Main files:

- `lib/services/call_service.dart`
- `lib/services/call_socket_service.dart`
- `lib/modules/calls/controllers/call_controller.dart`
- `lib/modules/calls/controllers/live_users_controller.dart`
- `lib/modules/calls/views/live_users_view.dart`
- `lib/modules/calls/views/incoming_call_screen.dart`
- `lib/modules/calls/views/outgoing_call_screen.dart`
- `lib/modules/calls/views/active_call_screen.dart`
- `lib/modules/calls/views/call_history_view.dart`

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

## Commands To Run After Deployment

- `cd "/Users/amanagarwal/Desktop/New Live App/liveapp laravel" && composer install`
- `cd "/Users/amanagarwal/Desktop/New Live App/liveapp laravel" && php artisan migrate`
- `cd "/Users/amanagarwal/Desktop/New Live App/liveapp laravel" && php artisan route:list`
- `cd "/Users/amanagarwal/Desktop/New Live App/liveapp laravel" && php artisan calls:timeout-missed`
- `cd "/Users/amanagarwal/Desktop/New Live App/live-presence-ws nodejs" && npm install`
- `cd "/Users/amanagarwal/Desktop/New Live App/live-presence-ws nodejs" && node server.js`
- `cd "/Users/amanagarwal/Desktop/New Live App/liveapp flutter" && flutter pub get`
- `cd "/Users/amanagarwal/Desktop/New Live App/liveapp flutter" && flutter analyze`

## Notes

- Flutter host and scheme can be overridden with dart defines:
  `--dart-define=APP_HOST=your-host --dart-define=APP_API_PORT=8000 --dart-define=APP_WS_PORT=3001 --dart-define=APP_SCHEME=http`
- `php artisan migrate:status` requires working DB connectivity for the configured Laravel connection.
