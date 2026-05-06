String? profileFrameAssetUrlFromPayload(dynamic payload) {
  if (payload is String) {
    final trimmed = payload.trim();
    return trimmed.isEmpty ? null : trimmed;
  }

  if (payload is Map) {
    final map = Map<String, dynamic>.from(payload);
    final direct = map['asset_url']?.toString().trim();
    if (direct != null && direct.isNotEmpty) {
      return direct;
    }

    final nested = map['profile_frame'];
    if (nested is String) {
      final nestedUrl = nested.trim();
      if (nestedUrl.isNotEmpty) {
        return nestedUrl;
      }
    }
    if (nested is Map) {
      final nestedMap = Map<String, dynamic>.from(nested);
      final nestedUrl = nestedMap['asset_url']?.toString().trim();
      if (nestedUrl != null && nestedUrl.isNotEmpty) {
        return nestedUrl;
      }
    }
  }

  return null;
}
