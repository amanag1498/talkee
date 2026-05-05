import 'dart:collection';

import 'package:flutter/material.dart';
import 'package:get/get.dart';

import 'fly_in_join_banner.dart';

class RoomJoinAnimationRequest {
  const RoomJoinAnimationRequest({
    required this.userId,
    required this.name,
    required this.themeKey,
    this.avatarUrl,
    this.frameUrl,
    this.isHost = false,
    this.isVip = false,
    this.level,
  });

  final String userId;
  final String name;
  final String? avatarUrl;
  final String? frameUrl;
  final String themeKey;
  final bool isHost;
  final bool isVip;
  final int? level;

  String get dedupeKey => '$userId|$themeKey|$isHost|$isVip|${level ?? 0}';
}

class RoomJoinAnimationOverlayManager {
  RoomJoinAnimationOverlayManager({
    Duration dedupeWindow = const Duration(seconds: 4),
  }) : _dedupeWindow = dedupeWindow;

  final Queue<RoomJoinAnimationRequest> _queue =
      Queue<RoomJoinAnimationRequest>();
  final Map<String, DateTime> _recentKeys = <String, DateTime>{};
  final Duration _dedupeWindow;

  OverlayEntry? _activeEntry;
  bool _isShowing = false;
  bool _disposed = false;
  BuildContext? _lastContext;

  void show(BuildContext context, RoomJoinAnimationRequest request) {
    if (_disposed) return;
    _lastContext = context;
    _pruneRecentKeys();
    if (_recentKeys.containsKey(request.dedupeKey)) {
      return;
    }
    _recentKeys[request.dedupeKey] = DateTime.now();
    _queue.add(request);
    _pump(context);
  }

  void dispose() {
    _disposed = true;
    _queue.clear();
    _recentKeys.clear();
    try {
      _activeEntry?.remove();
    } catch (_) {}
    _activeEntry = null;
    _isShowing = false;
  }

  void _pump(BuildContext context) {
    if (_disposed || _isShowing || _queue.isEmpty) {
      return;
    }
    final overlayContext = Get.overlayContext ?? _lastContext ?? context;
    final overlay =
        Navigator.maybeOf(overlayContext, rootNavigator: true)?.overlay ??
        Overlay.maybeOf(overlayContext, rootOverlay: true);
    if (overlay == null) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (_disposed) return;
        final retryContext = Get.overlayContext ?? _lastContext ?? context;
        _pump(retryContext);
      });
      return;
    }

    final request = _queue.removeFirst();
    _isShowing = true;

    late final OverlayEntry entry;
    entry = OverlayEntry(
      builder:
          (_) => Positioned.fill(
            child: IgnorePointer(
              ignoring: true,
              child: Material(
                type: MaterialType.transparency,
                child: FlyInJoinBanner(
                  userId: request.userId,
                  name: request.name,
                  avatarUrl: request.avatarUrl,
                  frameUrl: request.frameUrl,
                  themeKey: request.themeKey,
                  isHost: request.isHost,
                  isVip: request.isVip,
                  level: request.level,
                  onCompleted: () {
                    try {
                      entry.remove();
                    } catch (_) {}
                    if (identical(_activeEntry, entry)) {
                      _activeEntry = null;
                    }
                    _isShowing = false;
                    if (!_disposed) {
                      Future<void>.microtask(() => _pump(context));
                    }
                  },
                ),
              ),
            ),
          ),
    );

    _activeEntry = entry;
    overlay.insert(entry);
  }

  void _pruneRecentKeys() {
    final cutoff = DateTime.now().subtract(_dedupeWindow);
    _recentKeys.removeWhere((_, seenAt) => seenAt.isBefore(cutoff));
  }
}
