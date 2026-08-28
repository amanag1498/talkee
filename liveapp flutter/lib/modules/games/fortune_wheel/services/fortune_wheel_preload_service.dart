import 'dart:async';

import 'package:flutter/widgets.dart';
import 'package:get/get.dart';

import '../../../../services/app_settings_service.dart';
import '../../../../services/storage_service.dart';
import '../models/fortune_wheel_models.dart';
import 'fortune_wheel_api.dart';

class FortuneWheelPreloadService extends GetxService
    with WidgetsBindingObserver {
  FortuneWheelPreloadService({
    required FortuneWheelApi api,
    required AppSettingsService settings,
    required StorageService storage,
  }) : _api = api,
       _settings = settings,
       _storage = storage;

  final FortuneWheelApi _api;
  final AppSettingsService _settings;
  final StorageService _storage;

  final RxBool loading = false.obs;
  final RxnString error = RxnString();
  final Rxn<FortuneWheelSnapshot> snapshot = Rxn<FortuneWheelSnapshot>();

  Worker? _settingsWorker;
  Worker? _userWorker;
  int? _loadedUserId;

  @override
  void onInit() {
    super.onInit();
    WidgetsBinding.instance.addObserver(this);
    _settingsWorker = ever<AppSettingsPayload?>(_settings.payload, (_) {
      unawaited(maybePreload(reason: 'settings_changed'));
    });
    _userWorker = ever<int>(_storage.userRevision, (_) {
      unawaited(maybePreload(reason: 'user_changed'));
    });
    unawaited(maybePreload(reason: 'startup'));
  }

  @override
  void onClose() {
    WidgetsBinding.instance.removeObserver(this);
    _settingsWorker?.dispose();
    _userWorker?.dispose();
    super.onClose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      unawaited(maybePreload(reason: 'app_resumed'));
    }
  }

  Future<void> maybePreload({String? reason}) async {
    final userId = _currentUserId;
    if (userId == null || !_settings.fortuneWheelEnabled) {
      snapshot.value = null;
      error.value = null;
      _loadedUserId = null;
      return;
    }
    if (_loadedUserId != null && _loadedUserId != userId) {
      snapshot.value = null;
      error.value = null;
      _loadedUserId = null;
    }
    if (snapshot.value != null && !_snapshotLooksStale(snapshot.value!)) {
      return;
    }
    if (loading.value) {
      return;
    }
    await refresh();
  }

  Future<void> refresh() async {
    if (loading.value) return;
    final userId = _currentUserId;
    if (userId == null || !_settings.fortuneWheelEnabled) {
      snapshot.value = null;
      _loadedUserId = null;
      return;
    }

    loading.value = true;
    error.value = null;
    try {
      final fetched = await _api.fetchSnapshot();
      if (_currentUserId != userId || !_settings.fortuneWheelEnabled) {
        snapshot.value = null;
        _loadedUserId = null;
        unawaited(maybePreload(reason: 'identity_changed_during_refresh'));
        return;
      }
      snapshot.value = fetched;
      _loadedUserId = userId;
    } catch (e) {
      error.value = e.toString().replaceFirst('Exception: ', '');
    } finally {
      loading.value = false;
    }
  }

  Future<FortuneWheelSpinResult> spin() async {
    final userId = _currentUserId;
    if (userId == null) {
      throw StateError('Sign in again before spinning.');
    }
    if (_loadedUserId != userId) {
      await refresh();
    }
    if (_loadedUserId != userId) {
      throw StateError('Fortune Wheel is still loading for this account.');
    }
    final result = await _api.spin(
      idempotencyKey: 'fortune_wheel_${DateTime.now().microsecondsSinceEpoch}',
    );
    if (_currentUserId != userId) {
      snapshot.value = null;
      _loadedUserId = null;
      unawaited(maybePreload(reason: 'identity_changed_during_spin'));
      throw StateError('The account changed while the wheel was spinning.');
    }
    final current = snapshot.value;
    if (current != null) {
      snapshot.value = current.copyWith(
        walletBalance: result.walletBalance,
        freeSpinsRemaining: result.freeSpinsRemaining,
        segments: result.segments.isEmpty ? current.segments : result.segments,
        recentSpins: [result.spin, ...current.recentSpins].take(10).toList(),
      );
    } else {
      unawaited(refresh());
    }
    return result;
  }

  bool _snapshotLooksStale(FortuneWheelSnapshot current) {
    final nowUtc = DateTime.now().toUtc();
    final wheelNow = switch (current.settings.timezone.trim()) {
      'UTC' => nowUtc,
      'Asia/Kolkata' => nowUtc.add(const Duration(hours: 5, minutes: 30)),
      _ => DateTime.now(),
    };
    final today = wheelNow.toIso8601String().substring(0, 10);
    return current.spunForDate.isNotEmpty && current.spunForDate != today;
  }

  int? get _currentUserId {
    if ((_storage.token ?? '').isEmpty) return null;
    final raw = _storage.userJson?['id'];
    if (raw is int) return raw;
    return int.tryParse(raw?.toString() ?? '');
  }
}
