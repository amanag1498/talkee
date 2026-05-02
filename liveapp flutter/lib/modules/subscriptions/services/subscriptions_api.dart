// lib/modules/subscriptions/services/subscriptions_api.dart
import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:liveapp/services/api_client.dart';
import '../models/subscription_plan_dto.dart';
import '../models/user_subscription_dto.dart';

class SubscriptionsApi {
  final ApiClient _api;
  SubscriptionsApi(this._api);

  void _log(String msg) => debugPrint('[api][subs] $msg');


  Future<List<SubscriptionPlanDto>> fetchPlans() async {
    final Response res = await _api.get('plans');
    final data = res.data;

    if (data is! List) {
      _log('Unexpected /plans shape: ${data.runtimeType}');
      return const [];
    }

    // Optional: peek at first item for debugging
    if (data.isNotEmpty) {
      _log('plans[0] raw: ${data.first}');
    }

    final list = data
        .map((e) => SubscriptionPlanDto.fromJson(
      Map<String, dynamic>.from(e as Map),
    ))
        .toList();

    // Debug: print parsed booleans
    for (final p in list) {
      _log('plan#${p.id} ${p.name} isActive=${p.isActive}');
    }

    return list;
  }

  Future<List<UserSubscriptionDto>> mySubscriptions() async {
    final Response res = await _api.get('subscriptions/me');
    final data = res.data;
    if (data is! List) return const [];
    return data
        .map((e) => UserSubscriptionDto.fromJson(
      Map<String, dynamic>.from(e as Map),
    ))
        .toList();
  }

  Future<Map<String, dynamic>> welcomeTip() async {
    try {
      _log('GET subscriptions/welcome-tip -> sending');
      final res = await _api.get('subscriptions/welcome-tip');
      _log('<- status=${res.statusCode} ok=${res.statusMessage}');
      final data = (res.data is Map)
          ? Map<String, dynamic>.from(res.data)
          : <String, dynamic>{};
      _log('payload=$data');
      return data;
    } on DioException catch (e) {
      final code = e.response?.statusCode ?? 0;
      _log('ERROR status=$code body=${e.response?.data}');
      if (code == 404) {
        _log('endpoint missing on server; treating as no-popup');
        return {'ok': true, 'show': false};
      }
      rethrow;
    }
  }

  Future<void> ackWelcomeTip({required int subId}) async {
    try {
      _log('POST subscriptions/welcome-tip/ack {sub_id:$subId}');
      final res = await _api.post('subscriptions/welcome-tip/ack', data: {'sub_id': subId});
      _log('ack <- status=${res.statusCode}');
    } catch (e) {
      _log('ack ERROR (ignored): $e');
    }
  }
  Future<UserSubscriptionDto> purchase({required int planId}) async {
    final String url = 'subscriptions';
    final payload = {'plan_id': planId};
    final sw = Stopwatch()..start();

    try {
      _log('POST $url body=$payload');
      final Response res = await _api.post(url, data: payload);
      sw.stop();

      _log('← $url status=${res.statusCode} in ${sw.elapsedMilliseconds}ms');
      // Keep payload logging compact to avoid huge prints
      final data = res.data is Map ? Map<String, dynamic>.from(res.data) : <String, dynamic>{};
      _log('body=${data.toString().substring(0, data.toString().length.clamp(0, 800))}');

      // Expecting { ok: true, subscription: {...} }
      final ok = data['ok'] == true;
      if (!ok || res.statusCode != 201) {
        _log('WARN: unexpected response (ok=$ok, status=${res.statusCode})');
        throw data['error'] ?? 'PURCHASE_FAILED';
      }

      final sub = (data['subscription'] is Map)
          ? UserSubscriptionDto.fromJson(Map<String, dynamic>.from(data['subscription']))
          : UserSubscriptionDto.empty();

      _log('parsed subscription id=${sub.id} status=${sub.status} active=${sub.isActiveNow}');
      return sub;
    } on DioError catch (e) {
      sw.stop();
      final sc = e.response?.statusCode;
      final body = e.response?.data;
      _log('ERROR Dio POST $url status=$sc in ${sw.elapsedMilliseconds}ms');
      _log('resp=${body is Map || body is List ? body : body.toString()}');
      // surface server-provided error if present
      if (body is Map && body['error'] != null) {
        throw body['error'];
      }
      throw e.message ?? 'NETWORK_ERROR';
    } catch (e, st) {
      sw.stop();
      _log('ERROR $e in ${sw.elapsedMilliseconds}ms');
      _log(st.toString());
      throw e.toString();
    }
  }

}
