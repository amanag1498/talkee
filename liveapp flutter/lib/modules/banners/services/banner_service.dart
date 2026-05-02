import 'dart:async';
import 'dart:io';
import 'dart:math';

import 'package:flutter/foundation.dart';

import '../../../services/api_client.dart';
import '../../../services/auth_service.dart';
import '../models/banner_item.dart';

class BannerService {
  BannerService({required this.api, required this.auth});

  final ApiClient api;
  final AuthService auth;

  final Map<String, _BannerCacheEntry> _cache = {};

  String? _sessionId;
  String _sessionTokenSnapshot = '';
  int? _cachedUserId;
  Future<int?>? _userIdInFlight;

  Future<List<BannerItem>> fetchBanners({
    required String placement,
    String? platform,
    String? role,
    bool forceRefresh = false,
  }) async {
    final resolvedPlatform = platform ?? _resolvePlatform();
    final resolvedRole = role ?? _resolveRole();
    final key = '$placement|$resolvedPlatform|$resolvedRole';

    if (!forceRefresh) {
      final cached = _cache[key];
      if (cached != null && cached.expiresAt.isAfter(DateTime.now())) {
        return cached.items;
      }
    }

    final query = {
      'placement': placement,
      'platform': resolvedPlatform,
      'role': resolvedRole,
    };

    Object? lastError;
    for (var attempt = 0; attempt < 2; attempt++) {
      try {
        final res = await api
            .get<dynamic>('banners', query: query)
            .timeout(const Duration(seconds: 8));

        final raw = res.data;
        final list = _extractList(raw)
            .whereType<Map>()
            .map((e) => _toBannerItem(Map<String, dynamic>.from(e)))
            .where((b) => b.hasImage)
            .toList()
          ..sort((a, b) => a.sortOrder.compareTo(b.sortOrder));

        _cache[key] = _BannerCacheEntry(
          items: list,
          expiresAt: DateTime.now().add(const Duration(minutes: 3)),
        );
        return list;
      } catch (e) {
        lastError = e;
        if (attempt == 0) {
          await Future<void>.delayed(const Duration(milliseconds: 250));
          continue;
        }
      }
    }

    debugPrint('[banners] fetch failed: $lastError');
    return const <BannerItem>[];
  }

  Future<void> trackImpression({
    required int bannerId,
    required String placement,
    Map<String, dynamic>? context,
    String? platform,
    String? role,
  }) {
    return trackBannerEvent(
      bannerId: bannerId,
      type: 'impression',
      placement: placement,
      platform: platform ?? _resolvePlatform(),
      role: role ?? _resolveRole(),
      sessionId: _resolveSessionId(),
      context: context,
    );
  }

  Future<void> trackClick({
    required int bannerId,
    required String placement,
    Map<String, dynamic>? context,
    String? platform,
    String? role,
  }) {
    return trackBannerEvent(
      bannerId: bannerId,
      type: 'click',
      placement: placement,
      platform: platform ?? _resolvePlatform(),
      role: role ?? _resolveRole(),
      sessionId: _resolveSessionId(),
      context: context,
    );
  }

  Future<void> trackBannerEvent({
    required int bannerId,
    required String type,
    required String placement,
    required String platform,
    required String role,
    required String sessionId,
    Map<String, dynamic>? context,
  }) async {
    if (bannerId <= 0) return;

    try {
      var uid = _resolveUserId();
      uid ??= await _resolveUserIdFromServer();
      final mergedContext = <String, dynamic>{
        ...?context,
        if (uid != null) 'user_id': uid,
        if (uid != null) 'userId': uid,
      };
      await api
          .post<void>(
            'banners/$bannerId/$type',
            data: {
              'placement': placement,
              'platform': platform,
              'role': role,
              'session_id': sessionId,
              if (uid != null) 'user_id': uid,
              if (uid != null) 'userId': uid,
              'context': mergedContext,
            },
          )
          .timeout(const Duration(seconds: 6));
    } catch (e) {
      debugPrint('[banners] track $type failed for id=$bannerId: $e');
    }
  }

  String _resolvePlatform() {
    if (kIsWeb) return 'web';
    if (Platform.isAndroid) return 'android';
    if (Platform.isIOS) return 'ios';
    return 'android';
  }

  String _resolveRole() {
    final user = auth.currentUser;
    if (user == null) return 'guest';
    final roles = user.roles.toSet();
    if (roles.contains('admin')) return 'admin';
    if (roles.contains('agency')) return 'agency';
    if (roles.contains('host')) return 'host';
    return 'user';
  }

  int? _resolveUserId() {
    if (_cachedUserId != null) return _cachedUserId;

    final modelId = auth.currentUser?.id;
    if (modelId != null) {
      _cachedUserId = modelId;
      return modelId;
    }

    final raw = auth.storage.userJson;
    final dynamic id = raw?['id'];
    if (id is int) {
      _cachedUserId = id;
      return id;
    }
    if (id is num) {
      _cachedUserId = id.toInt();
      return _cachedUserId;
    }
    if (id is String) {
      _cachedUserId = int.tryParse(id);
      return _cachedUserId;
    }
    return null;
  }

  Future<int?> _resolveUserIdFromServer() async {
    if (_cachedUserId != null) return _cachedUserId;
    _userIdInFlight ??= _fetchUserIdFromVerify();
    final id = await _userIdInFlight;
    _userIdInFlight = null;
    if (id != null) _cachedUserId = id;
    return id;
  }

  Future<int?> _fetchUserIdFromVerify() async {
    try {
      final res = await api
          .get<Map<String, dynamic>>('ws/verify')
          .timeout(const Duration(seconds: 4));
      final data = res.data ?? const <String, dynamic>{};
      final dynamic id = data['id'];
      if (id is int) return id;
      if (id is num) return id.toInt();
      if (id is String) return int.tryParse(id);
    } catch (_) {}
    return null;
  }

  String _resolveSessionId() {
    final token = auth.storage.token ?? '';
    if (_sessionId == null || token != _sessionTokenSnapshot) {
      _sessionTokenSnapshot = token;
      _sessionId = _newSessionId();
      _cachedUserId = null;
    }
    return _sessionId!;
  }

  String _newSessionId() {
    final rnd = Random.secure();
    final t = DateTime.now().microsecondsSinceEpoch.toRadixString(16);
    final a = rnd.nextInt(1 << 32).toRadixString(16);
    final b = rnd.nextInt(1 << 32).toRadixString(16);
    return 'sess_$t$a$b';
  }

  static List<dynamic> _extractList(dynamic raw) {
    if (raw is List) return raw;
    if (raw is Map<String, dynamic>) {
      final data = raw['data'];
      if (data is List) return data;
      final items = raw['items'];
      if (items is List) return items;
    }
    return const <dynamic>[];
  }

  BannerItem _toBannerItem(Map<String, dynamic> json) {
    final item = BannerItem.fromJson(json);
    final fixedImage = _normalizeImageUrl(item.imageUrl);
    if (fixedImage == item.imageUrl) return item;

    return BannerItem(
      id: item.id,
      title: item.title,
      imageUrl: fixedImage,
      actionType: item.actionType,
      actionValue: item.actionValue,
      buttonText: item.buttonText,
      sortOrder: item.sortOrder,
    );
  }

  String _normalizeImageUrl(String value) {
    final v = value.trim();
    if (v.isEmpty) return v;
    if (v.startsWith('http://') || v.startsWith('https://')) return v;

    final base = Uri.parse(api.dio.options.baseUrl);
    final hostRoot = Uri(
      scheme: base.scheme,
      host: base.host,
      port: base.hasPort ? base.port : null,
    ).toString();

    if (v.startsWith('/')) return '$hostRoot$v';
    return '$hostRoot/$v';
  }
}

class _BannerCacheEntry {
  const _BannerCacheEntry({required this.items, required this.expiresAt});

  final List<BannerItem> items;
  final DateTime expiresAt;
}
