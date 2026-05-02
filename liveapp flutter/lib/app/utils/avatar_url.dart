import '../../services/api_client.dart';

String? resolveAvatarUrl(ApiClient api, String? raw) {
  if (raw == null || raw.trim().isEmpty) return null;
  if (raw.startsWith('http://') || raw.startsWith('https://')) return raw;
  final uri = Uri.parse(api.dio.options.baseUrl);
  final root = '${uri.scheme}://${uri.host}${uri.hasPort ? ':${uri.port}' : ''}';
  return raw.startsWith('/') ? '$root$raw' : '$root/$raw';
}
