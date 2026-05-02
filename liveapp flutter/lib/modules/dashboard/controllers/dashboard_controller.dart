import 'dart:async';

import 'package:get/get.dart';

import '../models/leaderboard_dto.dart';
import '../services/dashboard_api.dart';

class DashboardController extends GetxController {
  DashboardController(this._api);

  final DashboardApi _api;

  final isLoading = false.obs;
  final isRefreshing = false.obs;
  final error = RxnString();
  final leaderboards = Rxn<DashboardLeaderboardsDto>();

  Timer? _refreshTimer;
  Future<void>? _inFlightLoad;

  Future<void> load({bool silent = false}) async {
    if (_inFlightLoad != null) {
      return _inFlightLoad!;
    }

    if (!silent) {
      isLoading.value = true;
    } else {
      isRefreshing.value = true;
    }
    error.value = null;

    final future = _performLoad();
    _inFlightLoad = future;
    await future;
  }

  Future<void> _performLoad() async {
    try {
      leaderboards.value = await _api.fetchLeaderboards();
    } catch (e) {
      error.value = e.toString();
    } finally {
      isLoading.value = false;
      isRefreshing.value = false;
      _inFlightLoad = null;
    }
  }

  void startAutoRefresh() {
    _refreshTimer?.cancel();
    _refreshTimer = Timer.periodic(const Duration(seconds: 60), (_) {
      unawaited(load(silent: true));
    });
  }

  void stopAutoRefresh() {
    _refreshTimer?.cancel();
    _refreshTimer = null;
  }

  @override
  void onClose() {
    stopAutoRefresh();
    super.onClose();
  }
}
