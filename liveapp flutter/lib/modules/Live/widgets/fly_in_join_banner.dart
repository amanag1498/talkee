import 'dart:ui';

import 'package:flutter/material.dart';

import '../../../app/theme/brand.dart';

class FlyInJoinBanner extends StatefulWidget {
  const FlyInJoinBanner({
    super.key,
    required this.userId,
    required this.name,
    required this.themeKey,
    required this.onCompleted,
    this.avatarUrl,
    this.isHost = false,
    this.isVip = false,
    this.level,
    this.duration = const Duration(milliseconds: 2200),
  });

  final String userId;
  final String name;
  final String? avatarUrl;
  final String themeKey;
  final bool isHost;
  final bool isVip;
  final int? level;
  final Duration duration;
  final VoidCallback onCompleted;

  @override
  State<FlyInJoinBanner> createState() => _FlyInJoinBannerState();
}

class _FlyInJoinBannerState extends State<FlyInJoinBanner>
    with SingleTickerProviderStateMixin {
  static const int _highLevelThreshold = 5;

  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: widget.duration,
  )..addStatusListener((status) {
      if (status == AnimationStatus.completed) {
        widget.onCompleted();
      }
    });

  late final Animation<Offset> _slide = TweenSequence<Offset>([
    TweenSequenceItem<Offset>(
      tween: Tween<Offset>(
        begin: _beginOffset,
        end: const Offset(0.035, 0),
      ).chain(CurveTween(curve: Curves.easeOutCubic)),
      weight: 76,
    ),
    TweenSequenceItem<Offset>(
      tween: Tween<Offset>(
        begin: const Offset(0.035, 0),
        end: Offset.zero,
      ).chain(CurveTween(curve: Curves.easeOutBack)),
      weight: 24,
    ),
  ]).animate(
    CurvedAnimation(
      parent: _controller,
      curve: const Interval(0.0, 0.46),
      reverseCurve: const Interval(0.72, 1.0, curve: Curves.easeInCubic),
    ),
  );

  late final Animation<double> _fade = TweenSequence<double>([
    TweenSequenceItem<double>(
      tween: Tween<double>(begin: 0, end: 1).chain(
        CurveTween(curve: const Interval(0.0, 0.12, curve: Curves.easeOut)),
      ),
      weight: 12,
    ),
    TweenSequenceItem<double>(
      tween: ConstantTween<double>(1),
      weight: 60,
    ),
    TweenSequenceItem<double>(
      tween: Tween<double>(begin: 1, end: 0).chain(
        CurveTween(curve: const Interval(0.78, 1.0, curve: Curves.easeIn)),
      ),
      weight: 28,
    ),
  ]).animate(_controller);

  late final Animation<double> _badgeScale = TweenSequence<double>([
    TweenSequenceItem<double>(tween: ConstantTween<double>(0.86), weight: 34),
    TweenSequenceItem<double>(
      tween: Tween<double>(begin: 0.86, end: 1.05).chain(
        CurveTween(curve: Curves.easeOutBack),
      ),
      weight: 18,
    ),
    TweenSequenceItem<double>(
      tween: Tween<double>(begin: 1.05, end: 1.0).chain(
        CurveTween(curve: Curves.easeOut),
      ),
      weight: 14,
    ),
    TweenSequenceItem<double>(tween: ConstantTween<double>(1.0), weight: 34),
  ]).animate(_controller);

  late final Animation<double> _badgeOpacity = TweenSequence<double>([
    TweenSequenceItem<double>(tween: ConstantTween<double>(0), weight: 32),
    TweenSequenceItem<double>(
      tween: Tween<double>(begin: 0, end: 1).chain(
        CurveTween(curve: Curves.easeOut),
      ),
      weight: 18,
    ),
    TweenSequenceItem<double>(tween: ConstantTween<double>(1), weight: 50),
  ]).animate(_controller);

  late final Animation<double> _shimmerProgress = TweenSequence<double>([
    TweenSequenceItem<double>(tween: ConstantTween<double>(-0.35), weight: 18),
    TweenSequenceItem<double>(
      tween: Tween<double>(begin: -0.35, end: 1.15).chain(
        CurveTween(curve: Curves.easeOutCubic),
      ),
      weight: 26,
    ),
    TweenSequenceItem<double>(tween: ConstantTween<double>(1.15), weight: 56),
  ]).animate(_controller);

  late final Animation<double> _scale = Tween<double>(
    begin: 0.95,
    end: 1.0,
  ).animate(
    CurvedAnimation(
      parent: _controller,
      curve: const Interval(0.0, 0.22, curve: Curves.easeOutCubic),
    ),
  );

  bool get _isHighLevel => (widget.level ?? 0) >= _highLevelThreshold;

  bool get _isHostBanner => widget.isHost;

  bool get _isVipBanner => !_isHostBanner && widget.isVip;

  Offset get _beginOffset {
    return const Offset(-1.18, 0);
  }

  Alignment get _alignment {
    return Alignment.centerLeft;
  }

  double get _maxWidth {
    if (_isHostBanner) {
      return 300;
    }
    if (_isVipBanner) {
      return 284;
    }
    return 258;
  }

  EdgeInsets get _margin {
    return const EdgeInsets.fromLTRB(14, 0, 18, 0);
  }

  String get _headline {
    final safeName = widget.name.trim().isEmpty ? 'Someone' : widget.name.trim();
    return safeName;
  }

  String get _subtitle {
    if (_isHostBanner) return 'Host entered the room';
    if (_isVipBanner) return 'VIP joined the live';
    if (_isHighLevel) return 'Level ${widget.level} joined the live';
    return 'Joined the live';
  }

  String? get _badgeLabel {
    if (_isHostBanner) return 'HOST';
    if (_isVipBanner) return 'VIP';
    if (_isHighLevel) return 'LV ${widget.level}';
    return null;
  }

  @override
  void initState() {
    super.initState();
    _controller.forward();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(widget.themeKey);
    final radius = _isHostBanner ? 28.0 : 26.0;
    final tint = _themeTint(widget.themeKey, tokens);
    final baseGlow = tint.withValues(alpha: _isHostBanner ? 0.22 : 0.16);
    final shellGradient = <Color>[
      Colors.white.withValues(alpha: 0.14),
      Colors.black.withValues(alpha: 0.34),
    ];
    final washGradient = <Color>[
      tint.withValues(alpha: _isHostBanner ? 0.28 : 0.22),
      tint.withValues(alpha: 0.08),
      Colors.transparent,
    ];

    return RepaintBoundary(
      child: IgnorePointer(
        ignoring: true,
        child: SafeArea(
          child: Align(
            alignment: _alignment,
            child: SlideTransition(
              position: _slide,
              child: FadeTransition(
                opacity: _fade,
                child: ScaleTransition(
                  scale: _scale,
                  child: Container(
                    constraints: BoxConstraints(maxWidth: _maxWidth),
                    margin: _margin,
                    child: ClipRRect(
                      borderRadius: BorderRadius.circular(radius),
                      child: BackdropFilter(
                        filter: ImageFilter.blur(sigmaX: 12, sigmaY: 12),
                        child: DecoratedBox(
                          decoration: BoxDecoration(
                            borderRadius: BorderRadius.circular(radius),
                            gradient: LinearGradient(
                              begin: Alignment.topLeft,
                              end: Alignment.bottomRight,
                              colors: shellGradient,
                            ),
                            border: Border.all(
                              color: Colors.white.withValues(alpha: 0.12),
                              width: 1,
                            ),
                            boxShadow: [
                              BoxShadow(
                                color: baseGlow,
                                blurRadius: _isHostBanner ? 24 : 18,
                                spreadRadius: 0.5,
                              ),
                              BoxShadow(
                                color: Colors.black.withValues(alpha: 0.16),
                                blurRadius: 18,
                                offset: const Offset(0, 10),
                              ),
                            ],
                          ),
                          child: Stack(
                            children: [
                              Positioned.fill(
                                child: DecoratedBox(
                                  decoration: BoxDecoration(
                                    gradient: LinearGradient(
                                      begin: Alignment.centerLeft,
                                      end: Alignment.centerRight,
                                      colors: washGradient,
                                      stops: const <double>[0.0, 0.42, 1.0],
                                    ),
                                  ),
                                ),
                              ),
                              Positioned.fill(
                                child: DecoratedBox(
                                  decoration: BoxDecoration(
                                    gradient: RadialGradient(
                                      center: const Alignment(-0.92, -0.18),
                                      radius: 1.05,
                                      colors: <Color>[
                                        tint.withValues(alpha: 0.20),
                                        Colors.transparent,
                                      ],
                                    ),
                                  ),
                                ),
                              ),
                              Positioned.fill(
                                child: DecoratedBox(
                                  decoration: BoxDecoration(
                                    gradient: LinearGradient(
                                      begin: Alignment.topCenter,
                                      end: Alignment.bottomCenter,
                                      colors: <Color>[
                                        Colors.white.withValues(alpha: 0.08),
                                        Colors.transparent,
                                        Colors.black.withValues(alpha: 0.08),
                                      ],
                                    ),
                                  ),
                                ),
                              ),
                              Positioned.fill(
                                child: AnimatedBuilder(
                                  animation: _controller,
                                  builder: (context, _) {
                                    return FractionallySizedBox(
                                      alignment: Alignment.centerLeft,
                                      widthFactor: 0.18,
                                      child: Transform.translate(
                                        offset: Offset(
                                          _maxWidth * _shimmerProgress.value,
                                          0,
                                        ),
                                        child: DecoratedBox(
                                          decoration: BoxDecoration(
                                            gradient: LinearGradient(
                                              begin: Alignment.topCenter,
                                              end: Alignment.bottomCenter,
                                              colors: <Color>[
                                                Colors.white.withValues(alpha: 0),
                                                Colors.white.withValues(alpha: 0.14),
                                                Colors.white.withValues(alpha: 0),
                                              ],
                                            ),
                                          ),
                                        ),
                                      ),
                                    );
                                  },
                                ),
                              ),
                              Padding(
                                padding: EdgeInsets.fromLTRB(
                                  _isHostBanner ? 13 : 12,
                                  _isHostBanner ? 11 : 10,
                                  _isHostBanner ? 14 : 12,
                                  _isHostBanner ? 11 : 10,
                                ),
                                child: Row(
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    _Avatar(
                                      name: widget.name,
                                      avatarUrl: widget.avatarUrl,
                                      tint: tint,
                                      emphasis: _isHostBanner || _isVipBanner,
                                    ),
                                    const SizedBox(width: 10),
                                    Expanded(
                                      child: Column(
                                        mainAxisSize: MainAxisSize.min,
                                        crossAxisAlignment: CrossAxisAlignment.start,
                                        children: [
                                          Row(
                                            children: [
                                              Expanded(
                                                child: Text(
                                                  _headline,
                                                  maxLines: 1,
                                                  overflow: TextOverflow.ellipsis,
                                                  style: TextStyle(
                                                    color: Colors.white.withValues(
                                                      alpha: 0.92,
                                                    ),
                                                    fontSize:
                                                        _isHostBanner ? 14.2 : 13.2,
                                                    fontWeight: FontWeight.w800,
                                                    letterSpacing: 0.1,
                                                    shadows: [
                                                      Shadow(
                                                        color: tint.withValues(
                                                          alpha: 0.28,
                                                        ),
                                                        blurRadius: 12,
                                                      ),
                                                    ],
                                                  ),
                                                ),
                                              ),
                                              if (_badgeLabel != null) ...[
                                                const SizedBox(width: 8),
                                                FadeTransition(
                                                  opacity: _badgeOpacity,
                                                  child: ScaleTransition(
                                                    scale: _badgeScale,
                                                    child: _RoleBadge(
                                                      label: _badgeLabel!,
                                                      tint: tint,
                                                    ),
                                                  ),
                                                ),
                                              ],
                                            ],
                                          ),
                                          const SizedBox(height: 3),
                                          Row(
                                            children: [
                                              Expanded(
                                                child: Text(
                                                  _subtitle,
                                                  maxLines: 1,
                                                  overflow: TextOverflow.ellipsis,
                                                  style: TextStyle(
                                                    color: Colors.white.withValues(
                                                      alpha: 0.68,
                                                    ),
                                                    fontSize: 11.2,
                                                    fontWeight: FontWeight.w500,
                                                    letterSpacing: 0.05,
                                                    height: 1.1,
                                                  ),
                                                ),
                                              ),
                                              const SizedBox(width: 8),
                                              Text(
                                                'x1',
                                                style: TextStyle(
                                                  color: Colors.white.withValues(
                                                    alpha: 0.88,
                                                  ),
                                                  fontSize: 12.2,
                                                  fontWeight: FontWeight.w700,
                                                  shadows: [
                                                    Shadow(
                                                      color: tint.withValues(
                                                        alpha: 0.24,
                                                      ),
                                                      blurRadius: 10,
                                                    ),
                                                  ],
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
    );
  }

  Color _themeTint(String themeKey, PremiumThemeTokens tokens) {
    final key = themeKey.trim().toLowerCase();
    if (key.contains('gold')) {
      return const Color(0xFFFFD700);
    }
    if (key.contains('fire') || key.contains('flame')) {
      return const Color(0xFFFF7A45);
    }
    if (key.contains('ice') || key.contains('frost')) {
      return const Color(0xFF7CCBFF);
    }
    if (key.contains('neon')) {
      return const Color(0xFF8C7BFF);
    }
    return Color.lerp(
          tokens.primaryButtonGradient.first,
          tokens.primaryButtonGradient.last,
          0.45,
        ) ??
        tokens.glowColor;
  }
}

class _Avatar extends StatelessWidget {
  const _Avatar({
    required this.name,
    required this.avatarUrl,
    required this.tint,
    required this.emphasis,
  });

  final String name;
  final String? avatarUrl;
  final Color tint;
  final bool emphasis;

  @override
  Widget build(BuildContext context) {
    final radius = emphasis ? 18.0 : 16.0;
    final trimmed = avatarUrl?.trim();
    final initial =
        name.trim().isNotEmpty ? name.trim().characters.first.toUpperCase() : '?';

    return Container(
      width: radius * 2,
      height: radius * 2,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        border: Border.all(
          color: Colors.white.withValues(alpha: 0.2),
        ),
        boxShadow: [
          BoxShadow(
            color: tint.withValues(alpha: emphasis ? 0.18 : 0.12),
            blurRadius: emphasis ? 14 : 10,
          ),
        ],
      ),
      padding: const EdgeInsets.all(2),
      child: ClipOval(
        child: BackdropFilter(
          filter: ImageFilter.blur(sigmaX: 8, sigmaY: 8),
          child: DecoratedBox(
            decoration: BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: <Color>[
                  Colors.white.withValues(alpha: 0.2),
                  tint.withValues(alpha: 0.18),
                ],
              ),
            ),
            child: CircleAvatar(
              backgroundColor: Colors.black.withValues(alpha: 0.12),
              backgroundImage: trimmed != null && trimmed.isNotEmpty
                  ? NetworkImage(trimmed)
                  : null,
              child: trimmed == null || trimmed.isEmpty
                  ? Text(
                      initial,
                      style: TextStyle(
                        color: Colors.white.withValues(alpha: 0.9),
                        fontWeight: FontWeight.w800,
                      ),
                    )
                  : null,
            ),
          ),
        ),
      ),
    );
  }
}

class _RoleBadge extends StatelessWidget {
  const _RoleBadge({
    required this.label,
    required this.tint,
  });

  final String label;
  final Color tint;

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(999),
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 8, sigmaY: 8),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              colors: <Color>[
                Colors.white.withValues(alpha: 0.12),
                tint.withValues(alpha: 0.16),
              ],
            ),
            borderRadius: BorderRadius.circular(999),
            border: Border.all(
              color: Colors.white.withValues(alpha: 0.14),
            ),
          ),
          child: Text(
            label,
            style: TextStyle(
              color: Colors.white.withValues(alpha: 0.78),
              fontSize: 8.4,
              fontWeight: FontWeight.w800,
              letterSpacing: 0.7,
              shadows: [
                Shadow(
                  color: tint.withValues(alpha: 0.22),
                  blurRadius: 8,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
