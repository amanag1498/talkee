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
      return 286;
    }
    if (_isVipBanner) {
      return 266;
    }
    return 238;
  }

  EdgeInsets get _margin {
    return const EdgeInsets.fromLTRB(14, 0, 18, 0);
  }

  String get _headline {
    final safeName = widget.name.trim().isEmpty ? 'Someone' : widget.name.trim();
    if (_isHostBanner) {
      return 'Host $safeName joined';
    }
    if (_isVipBanner) {
      return 'VIP $safeName joined';
    }
    if (_isHighLevel) {
      return '$safeName joined';
    }
    return '$safeName joined';
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
    final shellRadius = _isHostBanner ? 22.0 : 19.0;
    final shellColors = <Color>[
      tokens.cardGradient.first.withValues(alpha: .96),
      tokens.cardGradient.last.withValues(alpha: .92),
    ];
    final accentColors =
        _isHostBanner
            ? <Color>[
              tokens.primaryButtonGradient.first,
              tokens.primaryButtonGradient.last,
            ]
            : _isVipBanner
            ? <Color>[
              tokens.primaryButtonGradient.last,
              tokens.primaryButtonGradient.first,
            ]
            : <Color>[
              tokens.primaryButtonGradient.first.withValues(alpha: .88),
              tokens.primaryButtonGradient.last.withValues(alpha: .72),
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
                child: Container(
                  constraints: BoxConstraints(maxWidth: _maxWidth),
                  margin: _margin,
                  child: DecoratedBox(
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular(shellRadius),
                      gradient: LinearGradient(
                        begin: Alignment.topLeft,
                        end: Alignment.bottomRight,
                        colors: shellColors,
                      ),
                      border: Border.all(
                        color: tokens.borderColor.withValues(alpha: .82),
                        width: _isHostBanner ? 1.3 : 1.05,
                      ),
                      boxShadow: [
                        BoxShadow(
                          color: tokens.glowColor.withValues(
                            alpha: _isHostBanner ? .28 : .2,
                          ),
                          blurRadius: _isHostBanner ? 22 : 16,
                          spreadRadius: _isHostBanner ? .7 : .2,
                        ),
                        BoxShadow(
                          color: Colors.black.withValues(alpha: .16),
                          blurRadius: 14,
                          offset: const Offset(0, 8),
                        ),
                      ],
                    ),
                    child: ClipRRect(
                      borderRadius: BorderRadius.circular(shellRadius),
                      child: Stack(
                        children: [
                          Positioned.fill(
                            child: AnimatedBuilder(
                              animation: _controller,
                              builder: (context, _) {
                                return FractionallySizedBox(
                                  alignment: Alignment.centerLeft,
                                  widthFactor: 0.16,
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
                                            Colors.white.withValues(alpha: .14),
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
                          Positioned(
                            left: 0,
                            right: 0,
                            top: 0,
                            child: Container(
                              height: 2,
                              decoration: BoxDecoration(
                                gradient: LinearGradient(colors: accentColors),
                              ),
                            ),
                          ),
                          Positioned(
                            top: -22,
                            right: -8,
                            child: DecoratedBox(
                              decoration: BoxDecoration(
                                shape: BoxShape.circle,
                                boxShadow: [
                                  BoxShadow(
                                    color: tokens.glowColor.withValues(alpha: .22),
                                    blurRadius: 28,
                                    spreadRadius: 6,
                                  ),
                                ],
                              ),
                              child: const SizedBox(width: 34, height: 34),
                            ),
                          ),
                          Padding(
                            padding: EdgeInsets.fromLTRB(
                              _isHostBanner ? 12 : 10,
                              _isHostBanner ? 10 : 8,
                              _isHostBanner ? 12 : 10,
                              _isHostBanner ? 10 : 8,
                            ),
                            child: Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                _Avatar(
                                  name: widget.name,
                                  avatarUrl: widget.avatarUrl,
                                  tokens: tokens,
                                  emphasis: _isHostBanner || _isVipBanner,
                                ),
                                const SizedBox(width: 8),
                                Expanded(
                                  child: Column(
                                    mainAxisSize: MainAxisSize.min,
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    children: [
                                      Row(
                                        mainAxisSize: MainAxisSize.min,
                                        children: [
                                          if (_badgeLabel != null) ...[
                                            FadeTransition(
                                              opacity: _badgeOpacity,
                                              child: ScaleTransition(
                                                scale: _badgeScale,
                                                child: _RoleBadge(
                                                  label: _badgeLabel!,
                                                  tokens: tokens,
                                                  emphasis:
                                                      _isHostBanner || _isVipBanner,
                                                ),
                                              ),
                                            ),
                                            const SizedBox(width: 0),
                                          ],
                                        ],
                                      ),
                                      const SizedBox(height: 5),
                                      Text(
                                        _headline,
                                        maxLines: 2,
                                        overflow: TextOverflow.ellipsis,
                                        style: TextStyle(
                                          color: tokens.textPrimary,
                                          fontSize: _isHostBanner ? 13.6 : 12.6,
                                          fontWeight: FontWeight.w900,
                                          letterSpacing: 0.1,
                                          height: 1.04,
                                        ),
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
    );
  }
}

class _Avatar extends StatelessWidget {
  const _Avatar({
    required this.name,
    required this.avatarUrl,
    required this.tokens,
    required this.emphasis,
  });

  final String name;
  final String? avatarUrl;
  final PremiumThemeTokens tokens;
  final bool emphasis;

  @override
  Widget build(BuildContext context) {
    final radius = emphasis ? 17.0 : 15.0;
    final trimmed = avatarUrl?.trim();
    final initial =
        name.trim().isNotEmpty ? name.trim().characters.first.toUpperCase() : '?';

    return Container(
      width: radius * 2,
      height: radius * 2,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        gradient: LinearGradient(
          colors: <Color>[
            tokens.primaryButtonGradient.first,
            tokens.primaryButtonGradient.last,
          ],
        ),
        border: Border.all(
          color: tokens.borderColor.withValues(alpha: .65),
        ),
        boxShadow: [
          BoxShadow(
            color: tokens.glowColor.withValues(alpha: emphasis ? .28 : .18),
            blurRadius: emphasis ? 12 : 8,
          ),
        ],
      ),
      padding: const EdgeInsets.all(2),
      child: CircleAvatar(
        backgroundColor: tokens.chipColor,
        backgroundImage:
            trimmed != null && trimmed.isNotEmpty ? NetworkImage(trimmed) : null,
        child:
            trimmed == null || trimmed.isEmpty
                ? Text(
                  initial,
                  style: TextStyle(
                    color: tokens.textPrimary,
                    fontWeight: FontWeight.w900,
                  ),
                )
                : null,
      ),
    );
  }
}

class _RoleBadge extends StatelessWidget {
  const _RoleBadge({
    required this.label,
    required this.tokens,
    required this.emphasis,
  });

  final String label;
  final PremiumThemeTokens tokens;
  final bool emphasis;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: EdgeInsets.symmetric(
        horizontal: emphasis ? 7 : 6,
        vertical: emphasis ? 3.5 : 3,
      ),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: <Color>[
            tokens.chipColor.withValues(alpha: .98),
            tokens.glassColor.withValues(alpha: .88),
          ],
        ),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(
          color: tokens.borderColor.withValues(alpha: .76),
        ),
      ),
      child: Text(
        label,
        style: TextStyle(
          color: tokens.textSecondary,
          fontSize: 8.6,
          fontWeight: FontWeight.w900,
          letterSpacing: 0.8,
        ),
      ),
    );
  }
}
