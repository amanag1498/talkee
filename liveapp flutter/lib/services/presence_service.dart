// presence_service.dart
import 'dart:async';
import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:socket_io_client/socket_io_client.dart' as IO;
import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:liveapp/services/device_id_service.dart';
import 'package:liveapp/services/app_settings_service.dart';

String _ts() => DateTime.now().toIso8601String();
typedef ForceLogoutHandler = Future<void> Function(String reason);
typedef NotifyHandler = Future<void> Function(Map<String, dynamic> payload);

class PresenceService with WidgetsBindingObserver {
  static final PresenceService instance = PresenceService._();
  PresenceService._();

  IO.Socket? _sock;
  bool _connecting = false;
  bool _observerRegistered = false;
  bool _resumeInFlight = false;
  Timer? _hb;
  // Compatible with Stream<ConnectivityResult> and Stream<List<ConnectivityResult>>
  StreamSubscription<dynamic>? _connSub;

  String? _url;
  String? _token;
  String? _deviceId;
  ForceLogoutHandler? _onForceLogout;
  NotifyHandler? _onNotify;

  final _statusCtrl = StreamController<String>.broadcast();
  Stream<String> get onStatus => _statusCtrl.stream;

  bool get isConnected => _sock?.connected == true;

  Future<void> start({
    required String wsPresenceUrl,
    required String bearerToken,
    ForceLogoutHandler? onForceLogout,
    NotifyHandler? onNotify,
  }) async {
    final sameSession =
        _url == wsPresenceUrl && _token == bearerToken && _sock != null;
    _url = wsPresenceUrl;
    _token = bearerToken;
    _deviceId = await DeviceIdService.getAndroidId();
    _onForceLogout = onForceLogout;
    _onNotify = onNotify;

    debugPrint(
      '[presence][${_ts()}] start -> url=$_url token.len=${_token?.length ?? 0}',
    );
    if (!_observerRegistered) {
      WidgetsBinding.instance.addObserver(this);
      _observerRegistered = true;
    }

    _connSub ??= Connectivity().onConnectivityChanged.listen((dynamic event) async {
      final results = _normalizeConnectivity(event);
      final online = _isAnyOnline(results);
      debugPrint('[presence][${_ts()}] connectivity=$results online=$online');

      if (!online) return;

      if (_sock != null) {
        if (!isConnected) {
          try {
            _sock!.connect();
          } catch (_) {}
        }
      } else {
        await _connect();
      }
    });

    if (sameSession) {
      await _ensureConnected();
      return;
    }

    await _connect();
  }

  Future<void> stop() async {
    debugPrint('[presence][${_ts()}] stop()');
    try {
      _sock?.emit('presence:offline');
    } catch (_) {}
    _hb?.cancel();
    _hb = null;

    await _connSub?.cancel();
    _connSub = null;

    try {
      _sock?.dispose();
    } catch (_) {}
    _sock = null;

    if (_observerRegistered) {
      WidgetsBinding.instance.removeObserver(this);
      _observerRegistered = false;
    }
    _statusCtrl.add('stopped');
  }

  void _startHB() {
    _hb?.cancel();
    _hb = Timer.periodic(const Duration(seconds: 10), (_) {
      if (isConnected) {
        _sock?.emit('presence:ping');
      }
    });
    debugPrint('[presence][${_ts()}] heartbeat started (10s)');
  }

  Future<bool> _ensureConnected({
    Duration timeout = const Duration(seconds: 6),
  }) async {
    if (isConnected) return true;

    if (_sock != null) {
      try {
        _sock!.connect();
      } catch (_) {}
    } else {
      await _connect();
    }

    final completer = Completer<bool>();
    bool finished = false;

    late void Function(dynamic) ok;
    late void Function(dynamic) fail;

    ok = (_) {
      if (finished) return;
      finished = true;
      _sock?.off('connect', ok);
      _sock?.off('connect_error', fail);
      completer.complete(true);
    };
    fail = (e) {
      if (finished) return;
      finished = true;
      _sock?.off('connect', ok);
      _sock?.off('connect_error', fail);
      completer.complete(false);
    };

    _sock?.on('connect', ok);
    _sock?.on('connect_error', fail);

    return completer.future.timeout(
      timeout,
      onTimeout: () {
        _sock?.off('connect', ok);
        _sock?.off('connect_error', fail);
        return false;
      },
    );
  }

  Future<bool> ensureConnected({
    Duration timeout = const Duration(seconds: 6),
  }) async {
    return _ensureConnected(timeout: timeout);
  }

  Future<void> resumeOnline() async {
    if (_resumeInFlight) return;
    _resumeInFlight = true;
    final ok = await _ensureConnected();
    try {
      if (!ok) {
        debugPrint('[presence][${_ts()}] resumeOnline: failed to reconnect');
        return;
      }
      _sock?.emit('presence:online');
      _sock?.emit('presence:subscribe');
      debugPrint('[presence][${_ts()}] -> presence:online (manual resume)');
      _startHB();
    } finally {
      _resumeInFlight = false;
    }
  }

  Future<void> _connect() async {
    if (_url == null || _token == null) {
      debugPrint('[presence][${_ts()}] skip connect: url/token missing');
      return;
    }
    if (_connecting) {
      debugPrint('[presence][${_ts()}] skip connect: already connecting');
      return;
    }

    _connecting = true;
    try {
      if (_sock != null) {
        if (!isConnected) {
          try {
            _sock!.connect();
          } catch (_) {}
        }
        return;
      }

      final opts = <String, dynamic>{
        'transports': ['websocket'],
        'auth': {
          'token': _token,
          if ((_deviceId ?? '').isNotEmpty) 'device_id': _deviceId,
          'platform': AppSettingsService.androidPlatform,
          'app_version': AppSettingsService.appVersionName,
          'app_version_code': AppSettingsService.appVersionCode,
        },
        'forceNew': true,
        'reconnection': true,
        'reconnectionDelay': 1000,
        'reconnectionDelayMax': 5000,
        'timeout': 8000,
      };

      debugPrint('[presence][${_ts()}] connecting -> $_url');
      final s = IO.io(_url!, opts);
      _sock = s;

      s.on('connect', (_) {
        debugPrint('[presence][${_ts()}] connected (id=${s.id})');
        _statusCtrl.add('connected');
        s.emit('presence:online');
        s.emit('presence:subscribe');
        _startHB();
      });

      s.on('disconnect', (reason) {
        debugPrint('[presence][${_ts()}] disconnected: $reason');
        _statusCtrl.add('disconnected');
        _hb?.cancel();
        _hb = null;
      });

      s.on('connect_error', (e) async {
        final msg = e?.toString() ?? '';
        debugPrint('[presence] connect_error: $msg');
        if (_onForceLogout != null) {
          if (msg.contains('blocked'))
            await _onForceLogout!('blocked');
          else if (msg.contains('unauthorized'))
            await _onForceLogout!('unauthorized');
        }
      });

      s.on('error', (e) async {
        final msg = e?.toString() ?? (e is Map ? e['message']?.toString() : '');
        debugPrint('[presence] error: $msg');
        if (_onForceLogout != null) {
          if (msg!.contains('blocked'))
            await _onForceLogout!('blocked');
          else if (msg!.contains('unauthorized'))
            await _onForceLogout!('unauthorized');
        }
      });

      // runtime block
      // runtime logout (server kicks you because another device logged in)
      s.on('auth:logout', (data) async {
        debugPrint('[presence][${_ts()}] <auth:logout> $data');
        if (_onForceLogout != null) {
          final reason =
              (data is Map && data['reason'] is String)
                  ? (data['reason'] as String)
                  : 'unauthorized';
          await _onForceLogout!.call(reason);
        }
      });

      s.on('auth:blocked', (data) async {
        debugPrint('[presence][${_ts()}] <auth:blocked> $data');
        if (_onForceLogout != null) {
          await _onForceLogout!.call('blocked');
        }
      });
      // NEW: server -> user notifications (admin approvals, etc.)
      s.on('notify', (data) async {
        // expected: { user_id, type, title, body, meta, at }
        try {
          final Map<String, dynamic> payload =
              (data is Map)
                  ? Map<String, dynamic>.from(data)
                  : <String, dynamic>{};
          debugPrint('[presence][${_ts()}] <notify> $payload');
          if (_onNotify != null) await _onNotify!(payload);
        } catch (e) {
          debugPrint('[presence] notify parse error: $e');
        }
      });

      // server visibility events
      //  s.on('presence:snapshot', (d) => debugPrint('[presence][${_ts()}] <presence:snapshot> $d'));
      s.on(
        'presence:count',
        (d) => debugPrint('[presence][${_ts()}] <presence:count> $d'),
      );
      //  s.on('presence:delta',   (d) => debugPrint('[presence][${_ts()}] <presence:delta> $d'));
    } finally {
      _connecting = false;
    }
  }

  List<ConnectivityResult> _normalizeConnectivity(dynamic event) {
    if (event is ConnectivityResult) return [event];
    if (event is List<ConnectivityResult>) return event;
    return const [ConnectivityResult.none];
  }

  bool _isAnyOnline(List<ConnectivityResult> results) {
    for (final r in results) {
      if (r != ConnectivityResult.none) return true;
    }
    return false;
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) async {
    if (_sock == null) return;
    debugPrint('[presence][${_ts()}] lifecycle: $state');

    if (state == AppLifecycleState.resumed) {
      await resumeOnline();
    } else if (state == AppLifecycleState.paused ||
        state == AppLifecycleState.detached) {
      try {
        _sock?.emit('presence:offline');
      } catch (_) {}
      debugPrint('[presence][${_ts()}] -> presence:offline (bg)');
      _hb?.cancel();
      _hb = null;
    }
  }
}
