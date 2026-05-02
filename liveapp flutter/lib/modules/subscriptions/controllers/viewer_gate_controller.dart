// lib/modules/subscriptions/controllers/viewer_gate_controller.dart
import 'package:flutter/foundation.dart';
import 'package:get/get.dart';
import 'package:liveapp/services/api_client.dart';

import '../models/subscription_plan_dto.dart';
import '../services/subscriptions_api.dart';
import '../widgets/choose_plan_sheet.dart';

class ViewerGateController extends GetxController {
  late final SubscriptionsApi _api;
  final RxBool loading = false.obs;
  final RxList<SubscriptionPlanDto> plans = <SubscriptionPlanDto>[].obs;

  @override
  void onInit() {
    super.onInit();
    _api = SubscriptionsApi(Get.find<ApiClient>());
  }

  void _log(String msg) => debugPrint('[gate] $msg');

  /// Call this when user taps a LIVE card.
  Future<void> ensureAccessThen({required Future<void> Function() onGranted}) async {
    try {
      loading.value = true;
      _log('ensureAccessThen() start');

      // 1) Already subscribed?
      final subs = await _api.mySubscriptions();
      _log('mySubscriptions.len=${subs.length}');
      final hasActive = subs.any((s) => s.isActiveNow);
      _log('hasActive=$hasActive');

      if (hasActive) {
        _log('access GRANTED via existing subscription');
        // Get.snackbar('Access granted', 'Enjoy the live stream!',
        //     snackPosition: SnackPosition.BOTTOM);
        await onGranted();
        return;
      }

      // 2) No active sub -> fetch plans and show sheet
      final fetched = await _api.fetchPlans();
      _log('fetchPlans.len=${fetched.length}');

// DEBUG each plan row
      for (final p in fetched) {
        _log('plan parsed -> id=${p.id} name=${p.name} isActive=${p.isActive}');
      }

      final actives = fetched.where((p) => p.isActive).toList();
      _log('activePlans.len=${actives.length}');

      if (actives.isEmpty) {
        _log('NO active plans available');
        Get.snackbar('Subscriptions', 'No active plans available right now.',
            snackPosition: SnackPosition.BOTTOM);
        return;
      }
      plans.assignAll(actives);

      final ctx = Get.context;
      if (ctx == null) {
        _log('ERROR: Get.context is null; cannot open bottom sheet');
        Get.snackbar('Subscriptions', 'Unable to open plans right now.',
            snackPosition: SnackPosition.BOTTOM);
        return;
      }

      _log('opening ChoosePlanSheet…');
      final plan = await ChoosePlanSheet.show(
        ctx,
        plans: actives,
      );
      if (plan == null) {
        _log('ChoosePlanSheet dismissed without purchase');
        return;
      }

      _log('onPurchase -> plan id=${plan.id} name=${plan.name}');
      final sub = await _api.purchase(planId: plan.id);
      _log('purchase -> sub#${sub.id} active=${sub.isActiveNow}');

      if (!sub.isActiveNow) {
        _log('purchase completed but NOT active yet — blocking');
        throw 'Subscription not active yet.';
      }

      _log('access GRANTED after purchase');
      Get.snackbar('Unlocked', 'You can now watch live streams!',
          snackPosition: SnackPosition.BOTTOM);
      await onGranted();
      _log('ChoosePlanSheet closed');
    } catch (e, st) {
      _log('ERROR: $e');
      _log(st.toString());
      Get.snackbar('Subscription', e.toString(),
          snackPosition: SnackPosition.BOTTOM);
    } finally {
      loading.value = false;
      _log('ensureAccessThen() end');
    }
  }
}
