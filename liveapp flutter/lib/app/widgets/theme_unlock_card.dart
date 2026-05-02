import 'dart:async';
import 'dart:ui';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter/widgets.dart';
import 'package:get/get.dart';

import '../routes/app_routes.dart';
import '../theme/brand.dart';

OverlayEntry? _activeThemeUnlockEntry;

void showThemeUnlockCard({
  required String themeKey,
  required String themeName,
}) {
  _activeThemeUnlockEntry?.remove();
  _activeThemeUnlockEntry = null;

  final overlayState =
      Get.key.currentState?.overlay ??
      (Get.overlayContext != null
          ? Overlay.of(Get.overlayContext!, rootOverlay: true)
          : null) ??
      (Get.context != null ? Overlay.of(Get.context!, rootOverlay: true) : null);
  if (overlayState == null) {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (_activeThemeUnlockEntry == null) {
        showThemeUnlockCard(themeKey: themeKey, themeName: themeName);
      }
    });
    return;
  }

  final overlay = overlayState;
  if (overlay == null) {
    return;
  }

  late OverlayEntry entry;
  entry = OverlayEntry(
    builder: (_) => _ThemeUnlockCardHost(
      themeKey: themeKey,
      themeName: themeName,
      onClosed: () {
        if (_activeThemeUnlockEntry == entry) {
          _activeThemeUnlockEntry?.remove();
          _activeThemeUnlockEntry = null;
        }
      },
    ),
  );

  _activeThemeUnlockEntry = entry;
  overlay.insert(entry);
}

class _ThemeUnlockCardHost extends StatefulWidget {
  const _ThemeUnlockCardHost({
    required this.themeKey,
    required this.themeName,
    required this.onClosed,
  });

  final String themeKey;
  final String themeName;
  final VoidCallback onClosed;

  @override
  State<_ThemeUnlockCardHost> createState() => _ThemeUnlockCardHostState();
}

class _ThemeUnlockCardHostState extends State<_ThemeUnlockCardHost>
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
      begin: const Offset(0, -0.18),
      end: Offset.zero,
    ).animate(curve);
    _scale = Tween<double>(begin: 0.96, end: 1).animate(curve);
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

  Future<void> _openThemeCenter() async {
    await _close();
    if (Get.currentRoute != Routes.themeCenter) {
      await Get.toNamed(Routes.themeCenter);
    }
  }

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(widget.themeKey);
    final ribbonColors = <Color>[
      tokens.primaryButtonGradient.first.withOpacity(0.96),
      tokens.primaryButtonGradient.last.withOpacity(0.9),
    ];
    return Positioned(
      top: 14,
      left: 12,
      right: 12,
      child: SafeArea(
        bottom: false,
        child: IgnorePointer(
          ignoring: false,
          child: Align(
            alignment: Alignment.topCenter,
            child: RepaintBoundary(
              child: FadeTransition(
                opacity: _fade,
                child: SlideTransition(
                  position: _slide,
                  child: ScaleTransition(
                    scale: _scale,
                    child: Material(
                      color: Colors.transparent,
                      child: ClipRRect(
                        borderRadius: BorderRadius.circular(26),
                        child: BackdropFilter(
                          filter: ImageFilter.blur(sigmaX: 24, sigmaY: 24),
                          child: Container(
                            constraints: const BoxConstraints(maxWidth: 372),
                            decoration: BoxDecoration(
                              gradient: LinearGradient(
                                colors: <Color>[
                                  tokens.cardGradient.first.withOpacity(0.98),
                                  tokens.cardGradient.last.withOpacity(0.94),
                                ],
                                begin: Alignment.topLeft,
                                end: Alignment.bottomRight,
                              ),
                              borderRadius: BorderRadius.circular(26),
                              border: Border.all(
                                color: tokens.borderColor.withOpacity(0.9),
                              ),
                              boxShadow: <BoxShadow>[
                                BoxShadow(
                                  color: tokens.glowColor.withOpacity(0.26),
                                  blurRadius: 34,
                                  spreadRadius: 2,
                                  offset: const Offset(0, 12),
                                ),
                                BoxShadow(
                                  color: Colors.black.withOpacity(0.24),
                                  blurRadius: 22,
                                  offset: const Offset(0, 10),
                                ),
                              ],
                            ),
                            child: Stack(
                              children: <Widget>[
                                Positioned(
                                  top: -34,
                                  right: -18,
                                  child: Container(
                                    width: 126,
                                    height: 126,
                                    decoration: BoxDecoration(
                                      shape: BoxShape.circle,
                                      gradient: RadialGradient(
                                        colors: <Color>[
                                          tokens.glowColor.withOpacity(0.38),
                                          tokens.glowColor.withOpacity(0.02),
                                        ],
                                      ),
                                    ),
                                  ),
                                ),
                                Positioned(
                                  left: 16,
                                  right: 16,
                                  top: 10,
                                  child: Container(
                                    height: 4,
                                    decoration: BoxDecoration(
                                      gradient: LinearGradient(
                                        colors: ribbonColors,
                                        begin: Alignment.centerLeft,
                                        end: Alignment.centerRight,
                                      ),
                                      borderRadius: BorderRadius.circular(999),
                                    ),
                                  ),
                                ),
                                Positioned(
                                  left: 18,
                                  top: 22,
                                  child: Container(
                                    padding: const EdgeInsets.symmetric(
                                      horizontal: 10,
                                      vertical: 5,
                                    ),
                                    decoration: BoxDecoration(
                                      color: tokens.chipColor.withOpacity(0.86),
                                      borderRadius: BorderRadius.circular(999),
                                      border: Border.all(
                                        color: tokens.borderColor.withOpacity(0.75),
                                      ),
                                    ),
                                    child: Text(
                                      'UNLOCKED',
                                      style: TextStyle(
                                        color: tokens.textSecondary,
                                        fontSize: 10,
                                        fontWeight: FontWeight.w800,
                                        letterSpacing: 1.2,
                                      ),
                                    ),
                                  ),
                                ),
                                Padding(
                                  padding: const EdgeInsets.fromLTRB(16, 52, 14, 14),
                                  child: Column(
                                    mainAxisSize: MainAxisSize.min,
                                    children: <Widget>[
                                      Row(
                                        crossAxisAlignment: CrossAxisAlignment.start,
                                        children: <Widget>[
                                          Container(
                                            width: 50,
                                            height: 50,
                                            padding: const EdgeInsets.all(1.5),
                                            decoration: BoxDecoration(
                                              shape: BoxShape.circle,
                                              gradient: LinearGradient(
                                                colors: ribbonColors,
                                                begin: Alignment.topLeft,
                                                end: Alignment.bottomRight,
                                              ),
                                              boxShadow: <BoxShadow>[
                                                BoxShadow(
                                                  color: tokens.glowColor.withOpacity(0.34),
                                                  blurRadius: 18,
                                                ),
                                              ],
                                            ),
                                            child: Container(
                                              decoration: BoxDecoration(
                                                shape: BoxShape.circle,
                                                color: tokens.cardGradient.last.withOpacity(0.92),
                                              ),
                                              child: Icon(
                                                Icons.auto_awesome_rounded,
                                                color: tokens.textPrimary,
                                                size: 24,
                                              ),
                                            ),
                                          ),
                                          const SizedBox(width: 12),
                                          Expanded(
                                            child: Column(
                                              crossAxisAlignment: CrossAxisAlignment.start,
                                              mainAxisSize: MainAxisSize.min,
                                              children: <Widget>[
                                                ShaderMask(
                                                  shaderCallback: (rect) => LinearGradient(
                                                    colors: ribbonColors,
                                                    begin: Alignment.topLeft,
                                                    end: Alignment.bottomRight,
                                                  ).createShader(rect),
                                                  child: const Text(
                                                    'Theme Unlocked',
                                                    style: TextStyle(
                                                      color: Colors.white,
                                                      fontSize: 12,
                                                      fontWeight: FontWeight.w800,
                                                      letterSpacing: 0.9,
                                                    ),
                                                  ),
                                                ),
                                                const SizedBox(height: 4),
                                                Text(
                                                  widget.themeName,
                                                  maxLines: 1,
                                                  overflow: TextOverflow.ellipsis,
                                                  style: TextStyle(
                                                    color: tokens.textPrimary,
                                                    fontSize: 22,
                                                    fontWeight: FontWeight.w900,
                                                    height: 1,
                                                  ),
                                                ),
                                                const SizedBox(height: 6),
                                                Text(
                                                  'Now available in Theme Center.',
                                                  style: TextStyle(
                                                    color: tokens.textSecondary.withOpacity(0.96),
                                                    fontSize: 13,
                                                    fontWeight: FontWeight.w600,
                                                  ),
                                                ),
                                              ],
                                            ),
                                          ),
                                          GestureDetector(
                                            onTap: _close,
                                            behavior: HitTestBehavior.opaque,
                                            child: Container(
                                              width: 28,
                                              height: 28,
                                              decoration: BoxDecoration(
                                                color: Colors.white.withOpacity(0.06),
                                                shape: BoxShape.circle,
                                              ),
                                              child: Icon(
                                                Icons.close_rounded,
                                                color: tokens.textSecondary.withOpacity(0.92),
                                                size: 16,
                                              ),
                                            ),
                                          ),
                                        ],
                                      ),
                                      const SizedBox(height: 16),
                                      Row(
                                        children: <Widget>[
                                          Expanded(
                                            child: Container(
                                              padding: const EdgeInsets.symmetric(
                                                horizontal: 12,
                                                vertical: 10,
                                              ),
                                              decoration: BoxDecoration(
                                                color: Colors.white.withOpacity(0.04),
                                                borderRadius: BorderRadius.circular(16),
                                                border: Border.all(
                                                  color: Colors.white.withOpacity(0.06),
                                                ),
                                              ),
                                              child: Text(
                                                'Tap below to preview and apply it.',
                                                style: TextStyle(
                                                  color: tokens.textSecondary.withOpacity(0.94),
                                                  fontSize: 12,
                                                  fontWeight: FontWeight.w600,
                                                ),
                                              ),
                                            ),
                                          ),
                                          const SizedBox(width: 10),
                                          GestureDetector(
                                            onTap: _openThemeCenter,
                                            behavior: HitTestBehavior.opaque,
                                            child: Container(
                                              padding: const EdgeInsets.symmetric(
                                                horizontal: 16,
                                                vertical: 11,
                                              ),
                                              decoration: BoxDecoration(
                                                gradient: LinearGradient(
                                                  colors: ribbonColors,
                                                  begin: Alignment.topLeft,
                                                  end: Alignment.bottomRight,
                                                ),
                                                borderRadius: BorderRadius.circular(18),
                                                boxShadow: <BoxShadow>[
                                                  BoxShadow(
                                                    color: tokens.glowColor.withOpacity(0.26),
                                                    blurRadius: 16,
                                                    offset: const Offset(0, 6),
                                                  ),
                                                ],
                                              ),
                                              child: Text(
                                                'Open',
                                                style: TextStyle(
                                                  color: tokens.textPrimary,
                                                  fontSize: 12,
                                                  fontWeight: FontWeight.w900,
                                                  letterSpacing: 0.2,
                                                ),
                                              ),
                                            ),
                                          ),
                                        ],
                                      ),
                                    ],
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
}
