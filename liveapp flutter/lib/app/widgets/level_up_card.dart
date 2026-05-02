import 'dart:async';
import 'dart:ui';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter/widgets.dart';
import 'package:get/get.dart';

import '../../services/app_settings_service.dart';
import '../theme/brand.dart';

OverlayEntry? _activeLevelUpEntry;

void showLevelUpCard({
  required int level,
  required String levelTitle,
  int? oldLevel,
  String? oldLevelTitle,
  String? badgeColor,
}) {
  _activeLevelUpEntry?.remove();
  _activeLevelUpEntry = null;

  final overlayState =
      Get.key.currentState?.overlay ??
      (Get.overlayContext != null
          ? Overlay.of(Get.overlayContext!, rootOverlay: true)
          : null) ??
      (Get.context != null ? Overlay.of(Get.context!, rootOverlay: true) : null);
  if (overlayState == null) {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (_activeLevelUpEntry == null) {
        showLevelUpCard(
          level: level,
          levelTitle: levelTitle,
          oldLevel: oldLevel,
          oldLevelTitle: oldLevelTitle,
          badgeColor: badgeColor,
        );
      }
    });
    return;
  }

  late OverlayEntry entry;
  entry = OverlayEntry(
    builder: (_) => _LevelUpCardHost(
      level: level,
      levelTitle: levelTitle,
      oldLevel: oldLevel,
      oldLevelTitle: oldLevelTitle,
      badgeColor: badgeColor,
      onClosed: () {
        if (_activeLevelUpEntry == entry) {
          _activeLevelUpEntry?.remove();
          _activeLevelUpEntry = null;
        }
      },
    ),
  );

  _activeLevelUpEntry = entry;
  overlayState.insert(entry);
}

class _LevelUpCardHost extends StatefulWidget {
  const _LevelUpCardHost({
    required this.level,
    required this.levelTitle,
    required this.oldLevel,
    required this.oldLevelTitle,
    required this.badgeColor,
    required this.onClosed,
  });

  final int level;
  final String levelTitle;
  final int? oldLevel;
  final String? oldLevelTitle;
  final String? badgeColor;
  final VoidCallback onClosed;

  @override
  State<_LevelUpCardHost> createState() => _LevelUpCardHostState();
}

class _LevelUpCardHostState extends State<_LevelUpCardHost>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller;
  late final Animation<double> _fade;
  late final Animation<Offset> _slide;
  late final Animation<double> _scale;
  late final Animation<double> _medallionScale;
  late final Animation<double> _glowPulse;
  late final Animation<double> _shimmerAlign;
  Timer? _dismissTimer;
  bool _closing = false;

  @override
  void initState() {
    super.initState();
    HapticFeedback.mediumImpact();
    _controller = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 360),
      reverseDuration: const Duration(milliseconds: 220),
    );
    final curve = CurvedAnimation(
      parent: _controller,
      curve: Curves.easeOutCubic,
      reverseCurve: Curves.easeInCubic,
    );
    _fade = Tween<double>(begin: 0, end: 1).animate(curve);
    _slide = Tween<Offset>(
      begin: const Offset(0, -0.12),
      end: Offset.zero,
    ).animate(curve);
    _scale = TweenSequence<double>([
      TweenSequenceItem(
        tween: Tween<double>(begin: 0.9, end: 1.035)
            .chain(CurveTween(curve: Curves.easeOutBack)),
        weight: 70,
      ),
      TweenSequenceItem(
        tween: Tween<double>(begin: 1.035, end: 1.0)
            .chain(CurveTween(curve: Curves.easeOut)),
        weight: 30,
      ),
    ]).animate(curve);
    _medallionScale = TweenSequence<double>([
      TweenSequenceItem(
        tween: Tween<double>(begin: 0.84, end: 1.08)
            .chain(CurveTween(curve: Curves.easeOutBack)),
        weight: 65,
      ),
      TweenSequenceItem(
        tween: Tween<double>(begin: 1.08, end: 1.0)
            .chain(CurveTween(curve: Curves.easeOut)),
        weight: 35,
      ),
    ]).animate(curve);
    _glowPulse = Tween<double>(begin: 0.72, end: 1.0).animate(
      CurvedAnimation(
        parent: _controller,
        curve: const Interval(0.0, 0.72, curve: Curves.easeOutCubic),
      ),
    );
    _shimmerAlign = Tween<double>(begin: -1.35, end: 1.35).animate(
      CurvedAnimation(
        parent: _controller,
        curve: const Interval(0.12, 0.88, curve: Curves.easeInOutCubic),
      ),
    );
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

  Color _parseBadgeColor() {
    final raw = widget.badgeColor?.trim();
    final parsed = _safeParseHexColor(raw);
    return parsed ?? const Color(0xFFFFC845);
  }

  Color? _safeParseHexColor(String? value) {
    final raw = value?.trim().toUpperCase() ?? '';
    if (!RegExp(r'^#(?:[0-9A-F]{6}|[0-9A-F]{8})$').hasMatch(raw)) {
      return null;
    }

    final hex = raw.substring(1);
    final normalizedHex = hex.length == 6 ? 'FF$hex' : hex;
    return Color(int.parse(normalizedHex, radix: 16));
  }

  @override
  Widget build(BuildContext context) {
    final variant =
        Get.isRegistered<AppSettingsService>()
            ? Get.find<AppSettingsService>().activePremiumThemeVariant
            : 'midnight';
    final tokens = getPremiumThemeTokens(variant);
    final badgeColor = _parseBadgeColor();
    final accentGradient = <Color>[
      tokens.primaryButtonGradient.first,
      tokens.primaryButtonGradient.last,
    ];
    final badgeAccentGradient = <Color>[
      Color.lerp(tokens.primaryButtonGradient.first, badgeColor, 0.28) ??
          tokens.primaryButtonGradient.first,
      Color.lerp(tokens.primaryButtonGradient.last, badgeColor, 0.52) ??
          tokens.primaryButtonGradient.last,
    ];

    return Positioned(
      top: 0,
      left: 12,
      right: 12,
      bottom: 0,
      child: SafeArea(
        child: IgnorePointer(
          ignoring: false,
          child: Center(
            child: RepaintBoundary(
              child: FadeTransition(
                opacity: _fade,
                child: SlideTransition(
                  position: _slide,
                child: ScaleTransition(
                  scale: _scale,
                  child: Material(
                    color: Colors.transparent,
                    child: AnimatedBuilder(
                      animation: _controller,
                      builder: (context, child) {
                        return ClipRRect(
                          borderRadius: BorderRadius.circular(28),
                          child: BackdropFilter(
                            filter: ImageFilter.blur(sigmaX: 26, sigmaY: 26),
                            child: Container(
                              constraints: const BoxConstraints(maxWidth: 378),
                              decoration: BoxDecoration(
                                gradient: LinearGradient(
                                  colors: <Color>[
                                    tokens.cardGradient.first.withOpacity(0.98),
                                    tokens.cardGradient.last.withOpacity(0.94),
                                  ],
                                  begin: Alignment.topLeft,
                                  end: Alignment.bottomRight,
                                ),
                                borderRadius: BorderRadius.circular(28),
                                border: Border.all(
                                  color: tokens.borderColor.withOpacity(0.9),
                                ),
                                boxShadow: <BoxShadow>[
                                  BoxShadow(
                                    color: tokens.glowColor.withOpacity(
                                      0.18 + (0.18 * _glowPulse.value),
                                    ),
                                    blurRadius: 28 + (12 * _glowPulse.value),
                                    spreadRadius: 1.5 + _glowPulse.value,
                                    offset: const Offset(0, 14),
                                  ),
                                  BoxShadow(
                                    color: Colors.black.withOpacity(0.24),
                                    blurRadius: 20,
                                    offset: const Offset(0, 8),
                                  ),
                                ],
                              ),
                              child: Stack(
                                children: <Widget>[
                                  Positioned.fill(
                                    child: IgnorePointer(
                                      child: Align(
                                        alignment: Alignment(_shimmerAlign.value, 0),
                                        child: Transform.rotate(
                                          angle: -0.28,
                                          child: Container(
                                            width: 92,
                                            decoration: BoxDecoration(
                                              gradient: LinearGradient(
                                                colors: <Color>[
                                                  Colors.white.withOpacity(0),
                                                  Colors.white.withOpacity(0.075),
                                                  Colors.white.withOpacity(0),
                                                ],
                                              ),
                                            ),
                                          ),
                                        ),
                                      ),
                                    ),
                                  ),
                              Positioned(
                                top: -28,
                                right: -12,
                                  child: Container(
                                    width: 134,
                                    height: 134,
                                    decoration: BoxDecoration(
                                      shape: BoxShape.circle,
                                      gradient: RadialGradient(
                                        colors: <Color>[
                                          tokens.glowColor.withOpacity(0.34),
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
                                        colors: accentGradient,
                                        begin: Alignment.centerLeft,
                                        end: Alignment.centerRight,
                                      ),
                                      borderRadius: BorderRadius.circular(999),
                                    ),
                                  ),
                                ),
                                Padding(
                                  padding: const EdgeInsets.fromLTRB(16, 18, 14, 14),
                                  child: Column(
                                    mainAxisSize: MainAxisSize.min,
                                    children: <Widget>[
                                      Align(
                                        alignment: Alignment.centerLeft,
                                        child: Container(
                                          padding: const EdgeInsets.symmetric(
                                            horizontal: 10,
                                            vertical: 5,
                                          ),
                                          decoration: BoxDecoration(
                                            color: tokens.chipColor.withOpacity(0.84),
                                            borderRadius: BorderRadius.circular(999),
                                            border: Border.all(
                                              color: badgeColor.withOpacity(0.36),
                                            ),
                                          ),
                                          child: Text(
                                            'MOVED TO NEW LEVEL',
                                            style: TextStyle(
                                              color: tokens.textSecondary,
                                              fontSize: 9.5,
                                              fontWeight: FontWeight.w800,
                                              letterSpacing: 1.15,
                                            ),
                                          ),
                                        ),
                                      ),
                                      const SizedBox(height: 10),
                                      Row(
                                        crossAxisAlignment: CrossAxisAlignment.start,
                                        children: <Widget>[
                                          ScaleTransition(
                                            scale: _medallionScale,
                                            child: Container(
                                              width: 56,
                                              height: 56,
                                              padding: const EdgeInsets.all(1.6),
                                              decoration: BoxDecoration(
                                                shape: BoxShape.circle,
                                                gradient: LinearGradient(
                                                  colors: badgeAccentGradient,
                                                  begin: Alignment.topLeft,
                                                  end: Alignment.bottomRight,
                                                ),
                                                boxShadow: <BoxShadow>[
                                                  BoxShadow(
                                                    color: tokens.glowColor.withOpacity(
                                                      0.16 + (0.16 * _glowPulse.value),
                                                    ),
                                                    blurRadius: 14 + (8 * _glowPulse.value),
                                                  ),
                                                ],
                                              ),
                                              child: Container(
                                                decoration: BoxDecoration(
                                                  shape: BoxShape.circle,
                                                  color: tokens.cardGradient.last.withOpacity(0.94),
                                                ),
                                                child: Center(
                                                  child: Text(
                                                    '${widget.level}',
                                                    style: TextStyle(
                                                      color: tokens.textPrimary,
                                                      fontSize: 22,
                                                      fontWeight: FontWeight.w900,
                                                    ),
                                                  ),
                                                ),
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
                                                    colors: accentGradient,
                                                    begin: Alignment.topLeft,
                                                    end: Alignment.bottomRight,
                                                  ).createShader(rect),
                                                  child: const Text(
                                                    'LEVEL UP',
                                                    style: TextStyle(
                                                      color: Colors.white,
                                                      fontSize: 12,
                                                      fontWeight: FontWeight.w800,
                                                      letterSpacing: 1.2,
                                                    ),
                                                  ),
                                                ),
                                                const SizedBox(height: 4),
                                                Text(
                                                  'Level ${widget.level} reached',
                                                  style: TextStyle(
                                                    color: tokens.textPrimary,
                                                    fontSize: 23,
                                                    fontWeight: FontWeight.w900,
                                                    height: 1,
                                                  ),
                                                ),
                                                const SizedBox(height: 6),
                                                Text(
                                                  widget.levelTitle,
                                                  maxLines: 1,
                                                  overflow: TextOverflow.ellipsis,
                                                  style: TextStyle(
                                                    color: tokens.textSecondary,
                                                    fontSize: 13,
                                                    fontWeight: FontWeight.w700,
                                                  ),
                                                ),
                                                if (widget.oldLevel != null) ...[
                                                  const SizedBox(height: 10),
                                                  _TierJourneyPill(
                                                    oldLevel: widget.oldLevel!,
                                                    oldLevelTitle: widget.oldLevelTitle,
                                                    newLevel: widget.level,
                                                    newLevelTitle: widget.levelTitle,
                                                    tokens: tokens,
                                                    badgeColor: badgeColor,
                                                  ),
                                                ],
                                                const SizedBox(height: 8),
                                                Container(
                                                  padding: const EdgeInsets.symmetric(
                                                    horizontal: 10,
                                                    vertical: 6,
                                                  ),
                                                  decoration: BoxDecoration(
                                                    color: badgeColor.withOpacity(0.14),
                                                    borderRadius: BorderRadius.circular(999),
                                                    border: Border.all(
                                                      color: badgeColor.withOpacity(0.34),
                                                    ),
                                                  ),
                                                  child: Text(
                                                    'Level Badge',
                                                    style: TextStyle(
                                                      color: Color.lerp(
                                                            badgeColor,
                                                            tokens.textPrimary,
                                                            0.25,
                                                          ) ??
                                                          tokens.textPrimary,
                                                      fontSize: 11,
                                                      fontWeight: FontWeight.w700,
                                                    ),
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
                                      Container(
                                        width: double.infinity,
                                        padding: const EdgeInsets.symmetric(
                                          horizontal: 12,
                                          vertical: 11,
                                        ),
                                        decoration: BoxDecoration(
                                          color: Colors.white.withOpacity(0.04),
                                          borderRadius: BorderRadius.circular(16),
                                          border: Border.all(
                                            color: Colors.white.withOpacity(0.06),
                                          ),
                                        ),
                                        child: Text(
                                          widget.oldLevel != null
                                              ? 'You moved from Level ${widget.oldLevel} to Level ${widget.level}. Keep spending to climb further.'
                                              : 'Your profile level has advanced. Keep spending to reach the next tier.',
                                          style: TextStyle(
                                            color: tokens.textSecondary.withOpacity(0.96),
                                            fontSize: 12,
                                            fontWeight: FontWeight.w600,
                                          ),
                                        ),
                                    ),
                                  ],
                                ),
                              ),
                            ],
                              ),
                            ),
                          ),
                        );
                      },
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

class _TierJourneyPill extends StatelessWidget {
  const _TierJourneyPill({
    required this.oldLevel,
    required this.oldLevelTitle,
    required this.newLevel,
    required this.newLevelTitle,
    required this.tokens,
    required this.badgeColor,
  });

  final int oldLevel;
  final String? oldLevelTitle;
  final int newLevel;
  final String newLevelTitle;
  final PremiumThemeTokens tokens;
  final Color badgeColor;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
      decoration: BoxDecoration(
        color: Colors.white.withOpacity(0.04),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: Colors.white.withOpacity(0.07)),
      ),
      child: Row(
        children: <Widget>[
          _TierNode(
            label: 'L$oldLevel',
            active: false,
            color: tokens.textSecondary.withOpacity(0.48),
            textColor: tokens.textSecondary,
          ),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 8),
              child: Container(
                height: 2.5,
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    colors: <Color>[
                      tokens.primaryButtonGradient.first.withOpacity(0.45),
                      tokens.primaryButtonGradient.last,
                    ],
                  ),
                  borderRadius: BorderRadius.circular(999),
                ),
              ),
            ),
          ),
          _TierNode(
            label: 'L$newLevel',
            active: true,
            color: badgeColor,
            textColor: tokens.textPrimary,
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              newLevelTitle,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              textAlign: TextAlign.right,
              style: TextStyle(
                color: tokens.textPrimary,
                fontSize: 11.5,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _TierNode extends StatelessWidget {
  const _TierNode({
    required this.label,
    required this.active,
    required this.color,
    required this.textColor,
  });

  final String label;
  final bool active;
  final Color color;
  final Color textColor;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: active ? 40 : 34,
      height: active ? 40 : 34,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        gradient: LinearGradient(
          colors: <Color>[
            color.withOpacity(active ? 1 : 0.58),
            color.withOpacity(active ? 0.72 : 0.36),
          ],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        boxShadow: active
            ? <BoxShadow>[
                BoxShadow(
                  color: color.withOpacity(0.28),
                  blurRadius: 14,
                ),
              ]
            : const <BoxShadow>[],
      ),
      child: Center(
        child: Text(
          label,
          style: TextStyle(
            color: textColor,
            fontSize: active ? 11.5 : 10.5,
            fontWeight: FontWeight.w900,
          ),
        ),
      ),
    );
  }
}
