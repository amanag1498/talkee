// lib/services/api_client.dart
import 'dart:convert';

import 'package:dio/dio.dart' as dio_pkg;
import 'package:get/get.dart';

import 'storage_service.dart';
import '../services/device_id_service.dart';
import 'app_settings_service.dart';

class ApiClient {
  final dio_pkg.Dio dio;
  final StorageService storage;

  // In-memory deviceId cache to avoid repeated platform calls
  String? _cachedDeviceId;
  Future<String>? _fetchInFlight;

  ApiClient({required String baseUrl, required this.storage})
    : dio = dio_pkg.Dio(
        dio_pkg.BaseOptions(
          baseUrl: _normalizeBase(baseUrl),
          connectTimeout: const Duration(seconds: 15),
          receiveTimeout: const Duration(seconds: 20),
          sendTimeout: const Duration(seconds: 20),
          headers: {'Accept': 'application/json'},
        ),
      ) {
    // ── Auth / Device headers ────────────────────────────────────────────────
    dio.interceptors.add(
      dio_pkg.InterceptorsWrapper(
        onRequest: (options, handler) async {
          // Bearer auth
          final t = storage.token;
          if (t != null && t.isNotEmpty) {
            options.headers['Authorization'] = 'Bearer $t';
          }

          // Device id header
          final deviceId = await _getDeviceId();
          if (deviceId.isNotEmpty) {
            options.headers['X-Device-Id'] = deviceId;
          }

          options.headers['X-Client-Platform'] =
              AppSettingsService.currentPlatform;
          options.headers['X-App-Version'] = AppSettingsService.appVersionName;
          options.headers['X-App-Version-Code'] =
              AppSettingsService.appVersionCode.toString();

          handler.next(options);
        },
      ),
    );

    // ── Verbose logging (requests, responses, errors) ───────────────────────
    dio.interceptors.add(
      dio_pkg.InterceptorsWrapper(
        onRequest: (o, h) {
          String pretty(dynamic v) {
            try {
              return const JsonEncoder.withIndent('  ').convert(v);
            } catch (_) {
              return '$v';
            }
          }

          // Redact token
          final redacted = Map.of(o.headers);
          final auth = redacted['Authorization'];
          if (auth is String && auth.startsWith('Bearer ')) {
            redacted['Authorization'] =
                'Bearer '
                '${auth.length > 40 ? '${auth.substring(7, 31)}...<redacted>' : '<redacted>'}';
          }

          print('➡️  ${o.method} ${o.uri}');
          print('➡️  headers: ${pretty(redacted)}');
          if (o.data != null) {
            final body =
                (o.data is dio_pkg.FormData) ? '(FormData)' : pretty(o.data);
            print('➡️  body:\n$body');
          }
          h.next(o);
        },
        onResponse: (r, h) {
          String pretty(dynamic v) {
            try {
              return const JsonEncoder.withIndent('  ').convert(v);
            } catch (_) {
              return '$v';
            }
          }

          print(
            '✅  ${r.statusCode} ${r.requestOptions.method} ${r.requestOptions.uri}',
          );
          if (r.data != null) {
            print('✅  body:\n${pretty(r.data)}');
          }
          h.next(r);
        },
        onError: (e, h) {
          String pretty(dynamic v) {
            try {
              return const JsonEncoder.withIndent('  ').convert(v);
            } catch (_) {
              return '$v';
            }
          }

          final ro = e.requestOptions;
          print('❌  ${e.response?.statusCode ?? '-'} ${ro.method} ${ro.uri}');
          if (e.response?.data != null) {
            print('❌  error body:\n${pretty(e.response!.data)}');
          }
          print('❌  dio error: ${e.type} ${e.message}');

          final statusCode = e.response?.statusCode ?? 0;
          final body = e.response?.data;
          if (statusCode == 426 && body is Map) {
            final map = Map<String, dynamic>.from(body);
            final error = (map['error'] ?? '').toString().trim().toUpperCase();
            if (error == 'APP_UPGRADE_REQUIRED' &&
                Get.isRegistered<AppSettingsService>()) {
              Get.find<AppSettingsService>().applyUpgradeRequirement(
                minimumAndroidVersionCode:
                    int.tryParse(
                      (map['minimum_android_version_code'] ?? '').toString(),
                    ) ??
                    1,
                message: map['message']?.toString(),
              );
            }
          }

          h.next(e);
        },
      ),
    );
  }

  // ────────────────────────────────────────────────────────────────────────────
  // Public helpers
  // ────────────────────────────────────────────────────────────────────────────

  /// GET (you can use `query:` or `params:` as aliases for queryParameters)
  Future<dio_pkg.Response<T>> get<T>(
    String path, {
    Map<String, dynamic>? query,
    Map<String, dynamic>? params, // alias
    Map<String, String>? headers,
    String? ifNoneMatch, // optional ETag
  }) {
    final qp = query ?? params;
    final opts = dio_pkg.Options(
      headers: {
        if (headers != null) ...headers,
        if (ifNoneMatch != null && ifNoneMatch.isNotEmpty)
          'If-None-Match': ifNoneMatch,
      },
    );
    return dio.get<T>(_normalizePath(path), queryParameters: qp, options: opts);
  }

  /// POST
  Future<dio_pkg.Response<T>> post<T>(
    String path, {
    dynamic data,
    Map<String, dynamic>? query,
    Map<String, dynamic>? params, // alias
    Map<String, String>? headers,
  }) {
    final qp = query ?? params;
    final opts = dio_pkg.Options(headers: headers);
    return dio.post<T>(
      _normalizePath(path),
      data: data,
      queryParameters: qp,
      options: opts,
    );
  }

  /// PUT
  Future<dio_pkg.Response<T>> put<T>(
    String path, {
    dynamic data,
    Map<String, dynamic>? query,
    Map<String, dynamic>? params, // alias
    Map<String, String>? headers,
  }) {
    final qp = query ?? params;
    final opts = dio_pkg.Options(headers: headers);
    return dio.put<T>(
      _normalizePath(path),
      data: data,
      queryParameters: qp,
      options: opts,
    );
  }

  /// PATCH
  Future<dio_pkg.Response<T>> patch<T>(
    String path, {
    dynamic data,
    Map<String, dynamic>? query,
    Map<String, dynamic>? params, // alias
    Map<String, String>? headers,
  }) {
    final qp = query ?? params;
    final opts = dio_pkg.Options(headers: headers);
    return dio.patch<T>(
      _normalizePath(path),
      data: data,
      queryParameters: qp,
      options: opts,
    );
  }

  /// DELETE
  Future<dio_pkg.Response<T>> delete<T>(
    String path, {
    dynamic data,
    Map<String, dynamic>? query,
    Map<String, dynamic>? params, // alias
    Map<String, String>? headers,
  }) {
    final qp = query ?? params;
    final opts = dio_pkg.Options(headers: headers);
    return dio.delete<T>(
      _normalizePath(path),
      data: data,
      queryParameters: qp,
      options: opts,
    );
  }

  // ────────────────────────────────────────────────────────────────────────────
  // Internals
  // ────────────────────────────────────────────────────────────────────────────

  static String _normalizeBase(String base) {
    return base.endsWith('/') ? base : '$base/';
  }

  static String _normalizePath(String path) {
    return path.startsWith('/') ? path.substring(1) : path;
  }

  Future<String> _getDeviceId() async {
    if (_cachedDeviceId != null) return _cachedDeviceId!;
    _fetchInFlight ??= DeviceIdService.getAndroidId();
    final id = await _fetchInFlight!;
    _cachedDeviceId = id;
    _fetchInFlight = null;
    return id;
  }
}
