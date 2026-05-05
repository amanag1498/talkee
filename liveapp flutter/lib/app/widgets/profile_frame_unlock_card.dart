import 'dart:async';
import 'dart:ui';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';

import '../../services/app_settings_service.dart';
import '../theme/brand.dart';
import 'framed_avatar.dart';

OverlayEntry? _activeProfileFrameEntry;

void showProfileFrameUnlockCard({
  required String frameName,
  required String message,
  required String userName,
  String? avatarUrl,
  String? frameUrl,
  String? rarity,
}) {
  _activeProfileFrameEntry?.remove();
  _activeProfileFrameEntry = null;

  final overlayState =
      Get.key.currentState?.overlay ??
      (Get.overlayContext != null
          ? Overlay.of(Get.overlayContext!, rootOverlay: true)
          : null) ??
      (Get.context != null ? Overlay.of(Get.context!, rootOverlay: true) : null);
  if (overlayState == null) {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (_activeProfileFrameEntry == null) {
        showProfileFrameUnlockCard(
          frameName: frameName,
          message: message,
          userName: userName,
          avatarUrl: avatarUrl,
          frameUrl: frameUrl,
          rarity: rarity,
        );
      }
    });
    return;
  }

  late OverlayEntry entry;
  entry = OverlayEntry(
    builder: (_) => _ProfileFrameUnlockHost(
      frameName: frameName,
      message: message,
      userName: userName,
      avatarUrl: avatarUrl,
      frameUrl: frameUrl,
      rarity: rarity,
      onClosed: () {
        if (_activeProfileFrameEntry == entry) {
          _activeProfileFrameEntry?.remove();
          _activeProfileFrameEntry = null;
        }
      },
    ),
  );

  _activeProfileFrameEntry = entry;
  overlayState.insert(entry);
}

class _ProfileFrameUnlockHost extends StatefulWidget {
  const _ProfileFrameUnlockHost({
    required this.frameName,
    required this.message,
    required this.userName,
    required this.onClosed,
    this.avatarUrl,
    this.frameUrl,
    this.rarity,
  });

  final String frameName;
  final String message;
  final String userName;
  final String? avatarUrl;
  final String? frameUrl;
  final String? rarity;
  final VoidCallback onClosed;

  @override
  State<_ProfileFrameUnlockHost> createState() => _ProfileFrameUnlockHostState();
}

class _ProfileFrameUnlockHostState extends State<_ProfileFrameUnlockHost>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller;
  late final Animation<double> _fade;
  late final Animation<Offset> _slide;
  late final Animation<double> _scale;
  Timer? _dismissTimer;
  bool _closing = false;

  @override
  void initState() {
    super.initState();
    HapticFeedback.lightImpact();
    _controller = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 320),
      reverseDuration: const Duration(milliseconds: 220),
    );
    final curve = CurvedAnimation(
      parent: _controller,
      curve: Curves.easeOutCubic,
      reverseCurve: Curves.easeInCubic,
    );
    _fade = Tween<double>(begin: 0, end: 1).animate(curve);
    _slide = Tween<Offset>(
      begin: const Offset(0, -0.08),
      end: Offset.zero,
    ).animate(curve);
    _scale = Tween<double>(begin: 0.94, end: 1.0).animate(curve);
    _controller.forward();
    _dismissTimer = Timer(const Duration(seconds: 4), _close);
  }

  @override
  void dispose() {
    _dismissTimer?.cancel();
    _controller.dispose();
    super.dispose();
  }

  Future<void> _close() async {
    if (_closing) {
      return;
    }
    _closing = true;
    _dismissTimer?.cancel();
    await _controller.reverse();
    widget.onClosed();
  }

  @override
  Widget build(BuildContext context) {
    final variant =
        Get.isRegistered<AppSettingsService>()
            ? Get.find<AppSettingsService>().activePremiumThemeVariant
            : 'midnight';
    final tokens = getPremiumThemeTokens(variant);
    final accent = _accentForRarity(widget.rarity);

    return Positioned.fill(
      child: SafeArea(
        child: IgnorePointer(
          ignoring: false,
          child: Center(
            child: FadeTransition(
              opacity: _fade,
              child: SlideTransition(
                position: _slide,
                child: ScaleTransition(
                  scale: _scale,
                  child: Material(
                    color: Colors.transparent,
                    child: GestureDetector(
                      onTap: _close,
                      child: Container(
                        width: 324,
                        padding: const EdgeInsets.fromLTRB(18, 18, 18, 16),
                        decoration: BoxDecoration(
                          borderRadius: BorderRadius.circular(28),
                          gradient: LinearGradient(
                            begin: Alignment.topLeft,
                            end: Alignment.bottomRight,
                            colors: [
                              Colors.white.withValues(alpha: 0.16),
                              Color.lerp(
                                    tokens.primaryButtonGradient.last,
                                    Colors.black,
                                    0.78,
                                  )!
                                  .withValues(alpha: 0.92),
                            ],
                          ),
                          border: Border.all(
                            color: accent.withValues(alpha: 0.34),
                          ),
                          boxShadow: [
                            BoxShadow(
                              color: accent.withValues(alpha: 0.22),
                              blurRadius: 28,
                              spreadRadius: 1,
                            ),
                            BoxShadow(
                              color: Colors.black.withValues(alpha: 0.18),
                              blurRadius: 20,
                              offset: const Offset(0, 12),
                            ),
                          ],
                        ),
                        child: ClipRRect(
                          borderRadius: BorderRadius.circular(24),
                          child: BackdropFilter(
                            filter: ImageFilter.blur(sigmaX: 14, sigmaY: 14),
                            child: Column(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Container(
                                  padding: const EdgeInsets.symmetric(
                                    horizontal: 10,
                                    vertical: 5,
                                  ),
                                  decoration: BoxDecoration(
                                    borderRadius: BorderRadius.circular(999),
                                    color: accent.withValues(alpha: 0.16),
                                  ),
                                  child: Text(
                                    'PROFILE FRAME UNLOCKED',
                                    style: TextStyle(
                                      color: Colors.white.withValues(alpha: 0.94),
                                      fontSize: 11,
                                      fontWeight: FontWeight.w800,
                                      letterSpacing: 0.8,
                                    ),
                                  ),
                                ),
                                const SizedBox(height: 14),
                                FramedAvatar(
                                  size: 148,
                                  label: widget.userName,
                                  avatarUrl: widget.avatarUrl,
                                  frameUrl: widget.frameUrl,
                                  avatarInset: 0.05,
                                ),
                                const SizedBox(height: 14),
                                Text(
                                  widget.frameName,
                                  textAlign: TextAlign.center,
                                  style: TextStyle(
                                    color: Colors.white.withValues(alpha: 0.96),
                                    fontSize: 22,
                                    fontWeight: FontWeight.w900,
                                  ),
                                ),
                                const SizedBox(height: 6),
                                Text(
                                  widget.message,
                                  textAlign: TextAlign.center,
                                  style: TextStyle(
                                    color: Colors.white.withValues(alpha: 0.78),
                                    fontSize: 13,
                                    height: 1.35,
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  Color _accentForRarity(String? rarity) {
    switch ((rarity ?? '').trim().toLowerCase()) {
      case 'mythic':
        return const Color(0xFFFFC95A);
      case 'legendary':
        return const Color(0xFFFF7B7B);
      case 'epic':
        return const Color(0xFFC88BFF);
      case 'rare':
        return const Color(0xFF72B6FF);
      default:
        return const Color(0xFF7DE3C3);
    }
  }
}
