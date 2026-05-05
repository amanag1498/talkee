enum RemoteMediaKind { svg, svga, gif, image, unknown }

RemoteMediaKind detectRemoteMediaKind({
  String? explicitType,
  String? url,
}) {
  final normalizedType = (explicitType ?? '').trim().toLowerCase();
  switch (normalizedType) {
    case 'svg':
      return RemoteMediaKind.svg;
    case 'svga':
      return RemoteMediaKind.svga;
    case 'gif':
      return RemoteMediaKind.gif;
    case 'png':
    case 'jpg':
    case 'jpeg':
    case 'webp':
    case 'image':
      return RemoteMediaKind.image;
  }

  final normalizedUrl = (url ?? '').trim().toLowerCase();
  final path = Uri.tryParse(normalizedUrl)?.path.toLowerCase() ?? normalizedUrl;
  if (path.endsWith('.svga')) return RemoteMediaKind.svga;
  if (path.endsWith('.svg')) return RemoteMediaKind.svg;
  if (path.endsWith('.gif')) return RemoteMediaKind.gif;
  if (path.endsWith('.png') ||
      path.endsWith('.jpg') ||
      path.endsWith('.jpeg') ||
      path.endsWith('.webp')) {
    return RemoteMediaKind.image;
  }
  return RemoteMediaKind.unknown;
}

bool isBundledMediaPath(String value) {
  final normalized = value.trim();
  return normalized.startsWith('assets/') || normalized.startsWith('packages/');
}
