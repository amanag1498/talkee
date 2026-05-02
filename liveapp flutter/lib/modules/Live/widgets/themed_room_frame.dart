import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../../app/theme/brand.dart';

class ThemedRoomFrame extends StatefulWidget {
  const ThemedRoomFrame({
    super.key,
    required this.child,
    required this.themeKey,
    required this.isHost,
    required this.isVip,
    required this.isSpeaking,
    this.isPkWinner = false,
    this.size,
    this.borderRadius = 20,
    this.enableAnimation = true,
  });

  final Widget child;
  final String themeKey;
  final bool isHost;
  final bool isVip;
  final bool isSpeaking;
  final bool isPkWinner;
  final double? size;
  final double borderRadius;
  final bool enableAnimation;

  @override
  State<ThemedRoomFrame> createState() => _ThemedRoomFrameState();
}

class _ThemedRoomFrameState extends State<ThemedRoomFrame>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 3600),
  );

  bool _reduceMotion = false;

  @override
  void initState() {
    super.initState();
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final media = MediaQuery.maybeOf(context);
    final nextReduceMotion =
        (media?.disableAnimations ?? false) ||
        (media?.accessibleNavigation ?? false);
    if (_reduceMotion != nextReduceMotion) {
      _reduceMotion = nextReduceMotion;
      _syncAnimationState();
    } else if (!_controller.isAnimating && _shouldAnimate) {
      _syncAnimationState();
    }
  }

  @override
  void didUpdateWidget(covariant ThemedRoomFrame oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.enableAnimation != widget.enableAnimation ||
        oldWidget.isSpeaking != widget.isSpeaking ||
        oldWidget.isHost != widget.isHost ||
        oldWidget.isVip != widget.isVip ||
        oldWidget.isPkWinner != widget.isPkWinner) {
      _syncAnimationState();
    }
  }

  bool get _shouldAnimate => widget.enableAnimation && !_reduceMotion;

  void _syncAnimationState() {
    if (_shouldAnimate) {
      if (!_controller.isAnimating) {
        _controller.repeat();
      }
    } else {
      _controller.stop();
      _controller.value = 0;
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(widget.themeKey);
    final radius = widget.borderRadius;
    final borderWidth =
        widget.isHost
            ? 2.9
            : widget.isPkWinner
            ? 2.5
            : widget.isVip
            ? 2.1
            : 1.5;
    final baseGlowBlur =
        widget.isHost
            ? 20.0
            : widget.isPkWinner
            ? 22.0
            : widget.isVip
            ? 15.0
            : 9.0;
    final baseGlowAlpha =
        widget.isHost
            ? .24
            : widget.isPkWinner
            ? .28
            : widget.isVip
            ? .20
            : .12;
    final contentStack = Stack(
      fit: StackFit.passthrough,
      clipBehavior: Clip.none,
      children: [
        widget.child,
        if (widget.isHost || widget.isVip)
          Positioned(
            top: 6,
            right: 6,
            child: _FrameBadge(
              label: widget.isHost ? 'HOST' : 'VIP',
              tokens: tokens,
              strong: widget.isHost,
            ),
          ),
      ],
    );
    final childContent =
        widget.size == null
            ? contentStack
            : SizedBox(
              width: widget.size,
              height: widget.size,
              child: contentStack,
            );

    return RepaintBoundary(
      child: AnimatedBuilder(
        animation: _controller,
        child: childContent,
        builder: (context, child) {
          final t = _shouldAnimate ? _controller.value : 0.0;
          final borderPhase = Curves.easeInOut.transform(
            0.5 - (0.5 * math.cos(2 * math.pi * t)),
          );
          final activePulseStrength =
              widget.isSpeaking
                  ? 1.0
                  : widget.isPkWinner
                  ? 0.55
                  : widget.isHost
                  ? 0.38
                  : widget.isVip
                  ? 0.24
                  : 0.12;
          final glowBlur =
              baseGlowBlur +
              (_shouldAnimate ? (borderPhase * 4.0 * activePulseStrength) : 0);
          final glowAlpha =
              baseGlowAlpha +
              (_shouldAnimate ? (0.10 * activePulseStrength * borderPhase) : 0);
          final sweepRotation = 2 * math.pi * t;
          final angle = 2 * math.pi * t;
          final animatedBegin = Alignment(math.cos(angle), math.sin(angle));
          final animatedEnd = Alignment(-math.cos(angle), -math.sin(angle));
          final pulseScale =
              widget.isSpeaking && _shouldAnimate
                  ? 1.0 + (borderPhase * 0.022)
                  : 1.0;
          final ringScale =
              widget.isSpeaking && _shouldAnimate ? 1.0 + (borderPhase * 0.16) : 1.0;
          final ringOpacity =
              widget.isSpeaking && _shouldAnimate
                  ? (1.0 - borderPhase) * .42
                  : 0.0;

          return Transform.scale(
            scale: pulseScale,
            child: Stack(
              clipBehavior: Clip.none,
              children: [
                if (ringOpacity > 0)
                  Positioned.fill(
                    child: IgnorePointer(
                      child: Transform.scale(
                        scale: ringScale,
                        child: DecoratedBox(
                          decoration: BoxDecoration(
                            borderRadius: BorderRadius.circular(radius + 2),
                            border: Border.all(
                              color: tokens.glowColor.withValues(alpha: ringOpacity),
                              width: 1.6,
                            ),
                          ),
                        ),
                      ),
                    ),
                  ),
                DecoratedBox(
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(radius),
                    boxShadow: [
                      BoxShadow(
                        color: tokens.glowColor.withValues(alpha: glowAlpha),
                        blurRadius: glowBlur,
                        spreadRadius:
                            widget.isHost || widget.isPkWinner ? 0.8 : 0.15,
                      ),
                    ],
                  ),
                  child: Container(
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular(radius),
                      gradient:
                          _shouldAnimate
                              ? SweepGradient(
                                transform: GradientRotation(sweepRotation),
                                colors: [
                                  tokens.primaryButtonGradient.first,
                                  tokens.primaryButtonGradient.last,
                                  tokens.primaryButtonGradient.first,
                                ],
                              )
                              : LinearGradient(
                                begin: Alignment.topLeft,
                                end: Alignment.bottomRight,
                                colors: tokens.primaryButtonGradient,
                              ),
                    ),
                    child: Padding(
                      padding: EdgeInsets.all(borderWidth),
                      child: ClipRRect(
                        borderRadius: BorderRadius.circular(
                          math.max(0, radius - borderWidth),
                        ),
                        child: DecoratedBox(
                          decoration: BoxDecoration(
                            borderRadius: BorderRadius.circular(
                              math.max(0, radius - borderWidth),
                            ),
                            gradient: LinearGradient(
                              begin:
                                  _shouldAnimate
                                      ? animatedBegin
                                      : Alignment.topLeft,
                              end:
                                  _shouldAnimate
                                      ? animatedEnd
                                      : Alignment.bottomRight,
                              colors: [
                                tokens.cardGradient.first.withValues(alpha: .58),
                                tokens.cardGradient.last.withValues(alpha: .32),
                              ],
                            ),
                            border: Border.all(
                              color: tokens.borderColor.withValues(
                                alpha: .22 + (_shouldAnimate ? borderPhase * .12 : 0),
                              ),
                            ),
                          ),
                          child: child,
                        ),
                      ),
                    ),
                  ),
                ),
              ],
            ),
          );
        },
      ),
    );
  }
}

class _FrameBadge extends StatelessWidget {
  const _FrameBadge({
    required this.label,
    required this.tokens,
    required this.strong,
  });

  final String label;
  final PremiumThemeTokens tokens;
  final bool strong;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(999),
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors:
              strong
                  ? tokens.primaryButtonGradient
                  : [
                    tokens.chipColor.withValues(alpha: .96),
                    tokens.primaryButtonGradient.first.withValues(alpha: .82),
                  ],
        ),
        border: Border.all(color: tokens.borderColor.withValues(alpha: .72)),
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 3),
        child: Text(
          label,
          style: TextStyle(
            color: tokens.textPrimary,
            fontSize: 9.5,
            fontWeight: FontWeight.w900,
            letterSpacing: .35,
          ),
        ),
      ),
    );
  }
}
