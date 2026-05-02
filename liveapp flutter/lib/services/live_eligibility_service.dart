import 'dart:async'; // for Future.microtask
import 'package:get/get.dart';
import '../data/models/user_model.dart';
// import '../services/api_client.dart'; // <- not needed here, remove if unused
import '../services/storage_service.dart'; // 👈 NEW

class LiveEligibilityService extends GetxService {
  final canGoLive = false.obs;
  int _lastVersion = 0;
  DateTime? _lastEventAt;

  void setFromUser(UserModel user) {
    final dynamic v = (user as dynamic).canGoLive;
    if (v is bool) {
      canGoLive.value = v;
    } else {
      try {
        canGoLive.value = user.roles.contains('host');
      } catch (_) {
        canGoLive.value = false;
      }
    }
    _lastVersion = 0;
    _lastEventAt = null;

    // 👇 persist the seeded value so storage stays in sync across restarts
    _persistCanGoLive(canGoLive.value);
  }

  void applyEvent(String type, {int? ver, DateTime? at, bool? canFromMeta}) {
    final v = _toGoLive(type, canFromMeta: canFromMeta);
    if (v == null) return;

    if (ver != null && ver > 0) {
      if (ver < _lastVersion) return;
      if (ver == _lastVersion && v == canGoLive.value) return;
      _set(v, ver: ver);
      return;
    }

    final ts = at ?? DateTime.now();
    if (_lastEventAt != null) {
      final cmp = ts.compareTo(_lastEventAt!);
      if (cmp < 0) return;
      if (cmp == 0 && _isMoreRestrictive(type) == false) return;
    }
    _set(v, at: ts);
  }

  /// Recompute from notifications (newest-first). First relevant wins.
  void recomputeFromNotifications(Iterable<EligEvent> itemsNewestFirst) {
    for (final it in itemsNewestFirst) {
      final v = _toGoLive(it.type, canFromMeta: it.canGoLive);
      if (v == null) continue;

      if ((it.version ?? 0) > 0) {
        _set(v, ver: it.version!);
        return;
      } else {
        _set(v, at: it.at);
        return;
      }
    }
  }

  // ────────────────────────────────────────────────────────────────────────
  // Internal helpers
  // ────────────────────────────────────────────────────────────────────────

  void _set(bool val, {int? ver, DateTime? at}) {
    final changed = canGoLive.value != val;
    canGoLive.value = val;

    // 👇 persist every time we set (even if unchanged keeps storage corrected)
    _persistCanGoLive(val);

    if (ver != null) {
      _lastVersion = ver;
      _lastEventAt = null;
    } else if (at != null) {
      _lastEventAt = at;
    }
  }

  // Persist the flag into StorageService.userJson (under "canGoLive" and "can_go_live")
  void _persistCanGoLive(bool val) {
    Future.microtask(() async {
      try {
        final storage = Get.find<StorageService>();
        // If you added setCanGoLive in StorageService (recommended):
        await storage.setCanGoLive(val);

        // If you DIDN'T add setCanGoLive, you can use updateUserJson instead:
        // await storage.updateUserJson((json) {
        //   json['canGoLive'] = val;
        //   json['can_go_live'] = val; // optional snake_case mirror
        // });
      } catch (_) {
        // ignore: storage may not be available during early app boot
      }
    });
  }

  bool? _toGoLive(String type, {bool? canFromMeta}) {
    switch (type) {
      case 'host_approved':
      case 'host_unblocked':
        return canFromMeta ?? true;
      case 'host_blocked':
      case 'host_rejected':
        return canFromMeta ?? false;
      default:
        return null;
    }
  }

  bool _isMoreRestrictive(String type) =>
      type == 'host_blocked' || type == 'host_rejected';
}

/// Public DTO you can import anywhere
class EligEvent {
  final String type;
  final DateTime at;
  final int? version;     // same “ver” you send from backend
  final bool? canGoLive;  // optional meta override

  const EligEvent({
    required this.type,
    required this.at,
    this.version,
    this.canGoLive,
  });
}
