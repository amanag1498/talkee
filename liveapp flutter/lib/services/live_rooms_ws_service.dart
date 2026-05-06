// rooms_socket_service.dart
import 'dart:async';
import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:socket_io_client/socket_io_client.dart' as IO;
import 'package:liveapp/services/device_id_service.dart';
import 'package:liveapp/services/app_settings_service.dart';

/// Socket.IO client for the /rooms namespace, with rich debugging.
class RoomsSocketService {
  IO.Socket? _sock;
  bool _verbose = true;
  final Set<String> _joinedRooms = <String>{};
  final StreamController<Map<String, dynamic>> _seatEvents =
      StreamController<Map<String, dynamic>>.broadcast();
  final StreamController<Map<String, dynamic>> _roomAudienceEvents =
      StreamController<Map<String, dynamic>>.broadcast();
  final StreamController<Map<String, dynamic>> _giftEvents =
      StreamController<Map<String, dynamic>>.broadcast();
  final StreamController<Map<String, dynamic>> _roomLifecycleEvents =
      StreamController<Map<String, dynamic>>.broadcast();
  final StreamController<Map<String, dynamic>> _entryEffectEvents =
      StreamController<Map<String, dynamic>>.broadcast();
  final StreamController<Map<String, dynamic>> _pkEvents =
      StreamController<Map<String, dynamic>>.broadcast();
  final StreamController<Map<String, dynamic>> _messageEvents =
      StreamController<Map<String, dynamic>>.broadcast();
  final StreamController<Map<String, dynamic>> _messageErrors =
      StreamController<Map<String, dynamic>>.broadcast();
  final StreamController<Map<String, dynamic>> _moderationEvents =
      StreamController<Map<String, dynamic>>.broadcast();
  final StreamController<Map<String, dynamic>> _moderationErrors =
      StreamController<Map<String, dynamic>>.broadcast();
  final StreamController<Map<String, dynamic>> _moderationSystemMessages =
      StreamController<Map<String, dynamic>>.broadcast();
  final StreamController<Map<String, dynamic>> _profileEvents =
      StreamController<Map<String, dynamic>>.broadcast();
  final StreamController<Map<String, dynamic>> _joinEvents =
      StreamController<Map<String, dynamic>>.broadcast();
  final StreamController<Map<String, dynamic>> _connectionEvents =
      StreamController<Map<String, dynamic>>.broadcast();

  bool get isConnected => _sock?.connected ?? false;
  String? get id => _sock?.id;
  Stream<Map<String, dynamic>> get seatEvents => _seatEvents.stream;
  Stream<Map<String, dynamic>> get roomAudienceEvents =>
      _roomAudienceEvents.stream;
  Stream<Map<String, dynamic>> get giftEvents => _giftEvents.stream;
  Stream<Map<String, dynamic>> get roomLifecycleEvents =>
      _roomLifecycleEvents.stream;
  Stream<Map<String, dynamic>> get entryEffectEvents =>
      _entryEffectEvents.stream;
  Stream<Map<String, dynamic>> get pkEvents => _pkEvents.stream;
  Stream<Map<String, dynamic>> get messageEvents => _messageEvents.stream;
  Stream<Map<String, dynamic>> get messageErrors => _messageErrors.stream;
  Stream<Map<String, dynamic>> get moderationEvents => _moderationEvents.stream;
  Stream<Map<String, dynamic>> get moderationErrors =>
      _moderationErrors.stream;
  Stream<Map<String, dynamic>> get moderationSystemMessages =>
      _moderationSystemMessages.stream;
  Stream<Map<String, dynamic>> get profileEvents => _profileEvents.stream;
  Stream<Map<String, dynamic>> get joinEvents => _joinEvents.stream;
  Stream<Map<String, dynamic>> get connectionEvents => _connectionEvents.stream;

  Future<void> start({
    required String wsRoomsUrl,
    required String bearerToken,
    required void Function(List<Map<String, dynamic>> snapshot) onSnapshot,
    required void Function(Map<String, dynamic> row) onUpsert,
    required void Function(String roomId) onRemove,
    bool verbose = true,
  }) async {
    _verbose = verbose;
    await stop();
    final deviceId = await DeviceIdService.getAndroidId();

    final opts = <String, dynamic>{
      'transports': ['websocket'],
      'auth': {
        'token': bearerToken,
        if (deviceId.isNotEmpty) 'device_id': deviceId,
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

    _log("connecting -> $wsRoomsUrl");
    _sock = IO.io(wsRoomsUrl, opts);

    // ---- Lifecycle / transport debug ----
    _sock!.on('connect', (_) {
      _log("connected (id=${_sock?.id})");
      _connectionEvents.add({'event': 'connect', 'id': _sock?.id});
      _tryLogTransport();
      _log("emit rooms:subscribe");
      _sock?.emit('rooms:subscribe');
      for (final roomId in _joinedRooms) {
        _log("rejoin room $roomId");
        _sock?.emit('rooms:join', {'room_id': roomId});
      }
    });

    _sock!.on('disconnect', (reason) {
      _log("disconnected: $reason");
      _connectionEvents.add({
        'event': 'disconnect',
        'reason': reason?.toString(),
      });
    });

    _sock!.on('connect_error', (e) {
      _log("connect_error: $e");
    });

    _sock!.on('connect_timeout', (e) {
      _log("connect_timeout: $e");
    });

    _sock!.on('error', (e) {
      _log("error: $e");
    });

    _sock!.on('reconnect_attempt', (n) => _log("reconnect_attempt #$n"));
    _sock!.on('reconnecting', (n) => _log("reconnecting #$n"));
    _sock!.on('reconnect_error', (e) => _log("reconnect_error: $e"));
    _sock!.on('reconnect_failed', (_) => _log("reconnect_failed"));
    _sock!.on('reconnect', (n) {
      _log("reconnect success (attempt #$n)");
      _connectionEvents.add({'event': 'reconnect', 'attempt': n});
    });

    // ping/pong (engine)
    _sock!.on('ping', (_) => _log("ping"));
    _sock!.on('pong', (_) => _log("pong"));

    // In case your server ever emits auth kicks on this namespace
    _sock!.on('auth:blocked', (data) {
      _log("auth:blocked ${_short(data)}");
    });

    // ---- SNAPSHOT ----
    // Server sends: { rooms: [...] }
    _sock!.on('rooms:snapshot', (data) {
      try {
        _log("rooms:snapshot rawType=${data.runtimeType} raw=${_short(data)}");
        List<dynamic> rawList;

        if (data is Map && data['rooms'] is List) {
          rawList = data['rooms'] as List;
        } else if (data is List) {
          // backward-compat, just in case
          rawList = data;
        } else {
          _log("WARN unexpected snapshot shape: ${_short(data)}");
          return;
        }

        final list =
            rawList.map((e) => Map<String, dynamic>.from(e as Map)).toList();

        _log(
          "rooms:snapshot parsed size=${list.length}"
          "${list.isNotEmpty ? ' first=' + _short(list.first) : ''}",
        );
        onSnapshot(list);
      } catch (e) {
        _log("ERR snapshot parse error: $e");
      }
    });

    // ---- Unified event stream ----
    // Server sends: rooms:event -> { type: 'live'|'updated'|'created'|'ended'|'deleted', room: {...}, at: ... }
    _sock!.on('rooms:event', (data) {
      try {
        _log("rooms:event rawType=${data.runtimeType} raw=${_short(data)}");
        final m = Map<String, dynamic>.from(data as Map);
        final type = (m['type'] ?? '').toString();
        final room =
            (m['room'] is Map)
                ? Map<String, dynamic>.from(m['room'])
                : <String, dynamic>{};
        final id = (room['id'] ?? room['room_id'] ?? '').toString();
        final status = (room['status'] ?? '').toString();

        _log("rooms:event type=$type id=$id status=$status");

        if (type == 'ended' || type == 'deleted' || status == 'ended') {
          if (id.isNotEmpty) {
            _roomLifecycleEvents.add({
              'event': type.isNotEmpty ? type : 'ended',
              'room_id': id,
              'room': room,
            });
          }
          if (id.isNotEmpty) onRemove(id);
          return;
        }

        if (id.isNotEmpty) {
          onUpsert(room);
        }
      } catch (e) {
        _log("ERR event parse error: $e");
      }
    });

    for (final eventName in const [
      'seat:request_created',
      'seat:request_accepted',
      'seat:request_rejected',
      'seat:request_cancelled',
      'speaker:added',
      'speaker:removed',
      'speaker:muted',
      'speaker:unmuted',
      'speakers:updated',
    ]) {
      _sock!.on(eventName, (data) {
        try {
          final row = Map<String, dynamic>.from(data as Map);
          row['event'] ??= eventName;
          _log("$eventName ${_short(row)}");
          _seatEvents.add(row);
        } catch (e) {
          _log("ERR $eventName parse error: $e");
        }
      });
    }

    _sock!.on('room:audience', (data) {
      try {
        final row = Map<String, dynamic>.from(data as Map);
        _roomAudienceEvents.add(row);
      } catch (e) {
        _log("ERR room:audience parse error: $e");
      }
    });

    void handleGiftEvent(String eventName, dynamic data) {
      try {
        final row = Map<String, dynamic>.from(data as Map);
        row['event'] ??= eventName;
        _log("$eventName ${_short(row)}");
        _giftEvents.add(row);
      } catch (e) {
        _log("ERR $eventName parse error: $e");
      }
    }

    for (final eventName in const [
      'room:gift',
      'room:gift_sent',
      'audio_room:gift_sent',
      'video_room:gift_sent',
    ]) {
      _sock!.on(eventName, (data) => handleGiftEvent(eventName, data));
    }

    _sock!.on('room:entry_effect', (data) {
      try {
        final row = Map<String, dynamic>.from(data as Map);
        _log("room:entry_effect ${_short(row)}");
        _entryEffectEvents.add(row);
      } catch (e) {
        _log("ERR room:entry_effect parse error: $e");
      }
    });

    _sock!.on('room:message:new', (data) {
      try {
        final row = Map<String, dynamic>.from(data as Map);
        _log("room:message:new ${_short(row)}");
        _messageEvents.add(row);
      } catch (e) {
        _log("ERR room:message:new parse error: $e");
      }
    });

    _sock!.on('room:message:error', (data) {
      try {
        final row = Map<String, dynamic>.from(data as Map);
        _log("room:message:error ${_short(row)}");
        _messageErrors.add(row);
      } catch (e) {
        _log("ERR room:message:error parse error: $e");
      }
    });

    for (final eventName in const [
      'room:user:kick',
      'room:user:kicked',
      'room:user:block',
      'room:user:blocked',
      'room:user:unblock',
      'room:user:unblocked',
      'room:user:moderation_target',
    ]) {
      _sock!.on(eventName, (data) {
        try {
          final row = Map<String, dynamic>.from(data as Map);
          row['event'] ??= eventName;
          _log("$eventName ${_short(row)}");
          _moderationEvents.add(row);
        } catch (e) {
          _log("ERR $eventName parse error: $e");
        }
      });
    }

    _sock!.on('room:moderation:error', (data) {
      try {
        final row = Map<String, dynamic>.from(data as Map);
        _log("room:moderation:error ${_short(row)}");
        _moderationErrors.add(row);
      } catch (e) {
        _log("ERR room:moderation:error parse error: $e");
      }
    });

    _sock!.on('room:moderation:system_message', (data) {
      try {
        final row = Map<String, dynamic>.from(data as Map);
        _log("room:moderation:system_message ${_short(row)}");
        _moderationSystemMessages.add(row);
      } catch (e) {
        _log("ERR room:moderation:system_message parse error: $e");
      }
    });

    _sock!.on('room:user:profile_updated', (data) {
      try {
        final row = Map<String, dynamic>.from(data as Map);
        _log("room:user:profile_updated ${_short(row)}");
        _profileEvents.add(row);
      } catch (e) {
        _log("ERR room:user:profile_updated parse error: $e");
      }
    });

    _sock!.on('room:user_joined', (data) {
      try {
        final row = Map<String, dynamic>.from(data as Map);
        _log("room:user_joined ${_short(row)}");
        _joinEvents.add(row);
      } catch (e) {
        _log("ERR room:user_joined parse error: $e");
      }
    });

    for (final eventName in const [
      'pk:invite_sent',
      'pk:invite_received',
      'pk:accepted',
      'pk:rejected',
      'pk:cancelled',
      'pk:started',
      'pk:score_updated',
      'pk:ended',
      'pk:expired',
      'pk:media_token_required',
      'pk:opponent_media_unavailable',
    ]) {
      _sock!.on(eventName, (data) {
        try {
          final row = Map<String, dynamic>.from(data as Map);
          row['event'] ??= eventName;
          _log("$eventName ${_short(row)}");
          _pkEvents.add(row);
        } catch (e) {
          _log("ERR $eventName parse error: $e");
        }
      });
    }

    // ---- LEGACY handlers (no-ops if server never sends them) ----
    _sock!.on('rooms:live', (data) {
      try {
        _log("LEGACY rooms:live ${_short(data)}");
        onUpsert(Map<String, dynamic>.from(data as Map));
      } catch (e) {
        _log("LEGACY rooms:live parse error: $e");
      }
    });
    _sock!.on('rooms:updated', (data) {
      try {
        _log("LEGACY rooms:updated ${_short(data)}");
        onUpsert(Map<String, dynamic>.from(data as Map));
      } catch (e) {
        _log("LEGACY rooms:updated parse error: $e");
      }
    });
    _sock!.on('rooms:ended', (data) {
      try {
        _log("LEGACY rooms:ended ${_short(data)}");
        final id = (data['id'] ?? data['room_id'] ?? '').toString();
        if (id.isNotEmpty) {
          _roomLifecycleEvents.add({
            'event': 'ended',
            'room_id': id,
            'room':
                data is Map
                    ? Map<String, dynamic>.from(data)
                    : <String, dynamic>{},
          });
          onRemove(id);
        }
      } catch (e) {
        _log("LEGACY rooms:ended parse error: $e");
      }
    });
  }

  Future<void> stop() async {
    _log("stop()");
    try {
      _sock?.off('connect');
      _sock?.off('disconnect');
      _sock?.off('connect_error');
      _sock?.off('connect_timeout');
      _sock?.off('error');
      _sock?.off('reconnect_attempt');
      _sock?.off('reconnecting');
      _sock?.off('reconnect_error');
      _sock?.off('reconnect_failed');
      _sock?.off('reconnect');
      _sock?.off('ping');
      _sock?.off('pong');

      _sock?.off('auth:blocked');

      _sock?.off('rooms:snapshot');
      _sock?.off('rooms:event');

      _sock?.off('rooms:live');
      _sock?.off('rooms:updated');
      _sock?.off('rooms:ended');
      _sock?.off('room:audience');
      _sock?.off('room:gift');
      _sock?.off('room:gift_sent');
      _sock?.off('audio_room:gift_sent');
      _sock?.off('video_room:gift_sent');
      _sock?.off('room:entry_effect');
      _sock?.off('room:message:new');
      _sock?.off('room:message:error');
      _sock?.off('room:user:kick');
      _sock?.off('room:user:kicked');
      _sock?.off('room:user:block');
      _sock?.off('room:user:blocked');
      _sock?.off('room:user:unblock');
      _sock?.off('room:user:unblocked');
      _sock?.off('room:user:moderation_target');
      _sock?.off('room:moderation:error');
      _sock?.off('room:moderation:system_message');
      _sock?.off('room:user:profile_updated');
      _sock?.off('room:user_joined');
      for (final eventName in const [
        'seat:request_created',
        'seat:request_accepted',
        'seat:request_rejected',
        'seat:request_cancelled',
        'speaker:added',
        'speaker:removed',
        'speaker:muted',
        'speakers:updated',
      ]) {
        _sock?.off(eventName);
      }
    } catch (_) {}

    try {
      _sock?.disconnect();
    } catch (_) {}
    try {
      _sock?.dispose();
    } catch (_) {}
    _sock = null;
  }

  void joinRoom(String roomId) {
    final normalized = roomId.trim();
    if (normalized.isEmpty) return;
    _joinedRooms.add(normalized);
    _log("emit rooms:join $normalized");
    _sock?.emit('rooms:join', {'room_id': normalized});
  }

  void leaveRoom(String roomId) {
    final normalized = roomId.trim();
    if (normalized.isEmpty) return;
    _joinedRooms.remove(normalized);
    _log("emit rooms:leave $normalized");
    _sock?.emit('rooms:leave', {'room_id': normalized});
  }

  void sendRoomMessage({
    required String roomId,
    required String roomType,
    required String message,
  }) {
    final normalizedRoomId = roomId.trim();
    final normalizedRoomType = roomType.trim().toLowerCase();
    if (normalizedRoomId.isEmpty || normalizedRoomType.isEmpty) return;
    _log("emit room:message:send room=$normalizedRoomId");
    _sock?.emit('room:message:send', {
      'room_id': normalizedRoomId,
      'room_type': normalizedRoomType,
      'message': message,
    });
  }

  void refreshUserProfile() {
    _log("emit user:profile:refresh");
    _sock?.emit('user:profile:refresh');
  }

  void on(String event, void Function(dynamic) handler) {
    _sock?.on(event, handler);
  }

  void off(String event, [void Function(dynamic)? handler]) {
    _sock?.off(event, handler);
  }

  // ---------- helpers ----------
  void _tryLogTransport() {
    try {
      final t = _sock?.io.engine?.transport;
      final name = t?.name;
      _log("transport=$name");
    } catch (_) {
      // not all platforms expose this
    }
  }

  void _log(String msg) {
    if (!_verbose) return;
    final ts = DateTime.now().toIso8601String();
    debugPrint('[rooms][$ts] $msg');
  }

  String _short(Object? data, {int max = 200}) {
    try {
      final s =
          data is String
              ? data
              : const JsonEncoder.withIndent(' ').convert(data);
      return (s.length <= max) ? s : s.substring(0, max) + '…';
    } catch (_) {
      final s = data.toString();
      return (s.length <= max) ? s : s.substring(0, max) + '…';
    }
  }
}
