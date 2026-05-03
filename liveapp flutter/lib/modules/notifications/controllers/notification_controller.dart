import 'package:get/get.dart';
import 'package:flutter/foundation.dart';
import '../../../services/api_client.dart';
import '../models/notification_dto.dart';
import '../services/notification_api.dart';
import 'package:liveapp/services/live_eligibility_service.dart';


class NotificationsController extends GetxController {
  final NotificationsApi api;
  NotificationsController(ApiClient client) : api = NotificationsApi(client);

  final items       = <NotificationDto>[].obs;
  final loading     = false.obs;
  final unreadCount = 0.obs;

  final _hasMore = true.obs;
  bool get hasMore => _hasMore.value;

  bool _busy = false;

  @override
  void onInit() {
    super.onInit();
    refreshAll();
  }

  Future<void> refreshBadge() async {
    try {
      unreadCount.value = await api.unreadCount();
    } catch (e, st) {
      debugPrint('notifications refreshBadge error: $e\n$st');
    }
  }
  void _recomputeCanGoLiveFromItems() {
    try {
      if (!Get.isRegistered<LiveEligibilityService>()) return;
      final svc = Get.find<LiveEligibilityService>();

      // items are newest-first already
      final seq = items.map((n) {
        final m = n.meta ?? const <String, dynamic>{};
        final ver = int.tryParse('${m['ver'] ?? 0}');
        final can = (m['can_go_live'] == true || m['can_go_live'] == false)
            ? m['can_go_live'] as bool
            : null;
        return EligEvent(
          type: n.type,
          at: n.createdAt,
          version: ver,
          canGoLive: can,
        );
      });

      svc.recomputeFromNotifications(seq);
    } catch (_) {}
  }
  void _recomputeUnread() {
    unreadCount.value = items.where((n) => n.isUnread).length;
  }

  Future<void> refreshAll() async {
    if (_busy) return;
    _busy = true;
    loading.value = true;
    try {
      final first = await api.fetchPage(page: 1, perPage: 20);
      items.assignAll(first);
      _hasMore.value = first.length == 20;
      _recomputeUnread();
      _recomputeCanGoLiveFromItems();

      // precise badge from backend (optional)
      try { unreadCount.value = await api.unreadCount(); } catch (_) {}
    } catch (e, st) {
      debugPrint('notifications refresh error: $e\n$st');
    } finally {
      loading.value = false;
      _busy = false;
    }
  }

  Future<void> loadMore() async {
    if (_busy || !hasMore || items.isEmpty) return;
    _busy = true;
    try {
      final last = items.last.id;
      final next = await api.fetchBefore(beforeId: last, perPage: 20);
      if (next.isEmpty) {
        _hasMore.value = false;
      } else {
        items.addAll(next);
        _hasMore.value = next.length == 20;
        _recomputeUnread();
        _recomputeCanGoLiveFromItems();

      }
    } catch (e, st) {
      debugPrint('notifications loadMore error: $e\n$st');
    } finally {
      _busy = false;
    }
  }

  Future<void> markRead(String id) async {
    final idx = items.indexWhere((e) => e.id == id);
    if (idx != -1 && items[idx].isUnread) {
      items[idx] = items[idx].copyWith(readAt: DateTime.now());
      _recomputeUnread();
    }
    try { await api.markRead(id); } catch (_) {}
  }

  Future<void> markAllRead() async {
    items.assignAll(items.map((n) => n.copyWith(readAt: n.readAt ?? DateTime.now())));
    _recomputeUnread();
    try { await api.markAllRead(); } catch (_) {}
  }

  Future<int> markManyRead(List<String> ids) async {
    final idset = ids.toSet();
    items.assignAll(items.map((n) => idset.contains(n.id) ? n.copyWith(readAt: n.readAt ?? DateTime.now()) : n));
    _recomputeUnread();
    try { return await api.markManyRead(ids); } catch (_) { return 0; }
  }
}
