import 'dart:async';
import 'dart:collection';
import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../../app/models/remote_media_kind.dart';
import '../../../app/theme/brand.dart';
import '../../../app/widgets/remote_media_art.dart';
import '../models/room_gift_animation_event.dart';

class GiftAnchorRegistry {
  static const String stageCenter = 'stage_center';
  static const String audioHostAvatar = 'audio_host_avatar';
  static const String videoHostTile = 'video_host_tile';
  static const String pkLeft = 'pk_left';
  static const String pkRight = 'pk_right';
  static const String giftButton = 'gift_button';

  final Map<String, GlobalKey> _keys = <String, GlobalKey>{};

  GlobalKey keyFor(String name) =>
      _keys.putIfAbsent(name, () => GlobalKey(debugLabel: name));

  Rect? rectFor(String name, BuildContext overlayContext) {
    final key = _keys[name];
    final targetContext = key?.currentContext;
    if (targetContext == null) return null;
    if (!targetContext.mounted || !overlayContext.mounted) return null;
    RenderObject? render;
    RenderObject? overlayRender;
    try {
      render = targetContext.findRenderObject();
      overlayRender = overlayContext.findRenderObject();
    } catch (_) {
      return null;
    }
    if (render is! RenderBox || overlayRender is! RenderBox) return null;
    if (!render.attached || !overlayRender.attached || !render.hasSize) {
      return null;
    }
    final topLeft = render.localToGlobal(
      Offset.zero,
      ancestor: overlayRender,
    );
    return topLeft & render.size;
  }

  void dispose() {
    _keys.clear();
  }
}

class GiftComboTracker {
  GiftComboTracker({this.window = const Duration(seconds: 3)});

  final Duration window;
  final Map<String, DateTime> _lastSeen = <String, DateTime>{};

  bool canMerge(String comboKey, DateTime now) {
    final previous = _lastSeen[comboKey];
    if (previous == null) {
      _lastSeen[comboKey] = now;
      return false;
    }
    final merge = now.difference(previous) <= window;
    _lastSeen[comboKey] = now;
    return merge;
  }

  void clearExpired(DateTime now) {
    final expired = _lastSeen.entries
        .where((entry) => now.difference(entry.value) > window)
        .map((entry) => entry.key)
        .toList(growable: false);
    for (final key in expired) {
      _lastSeen.remove(key);
    }
  }

  void clear() {
    _lastSeen.clear();
  }
}

class GiftAnimationQueue {
  GiftAnimationQueue({this.maxLength = 30});

  final int maxLength;
  final List<RoomGiftAnimationEvent> _items = <RoomGiftAnimationEvent>[];

  List<RoomGiftAnimationEvent> get items => List.unmodifiable(_items);

  void enqueue(RoomGiftAnimationEvent event) {
    if (_items.length >= maxLength) {
      final replaceIndex = _items.indexWhere(
        (candidate) =>
            candidate.tier == RoomGiftAnimationTier.small ||
            candidate.tier == RoomGiftAnimationTier.medium,
      );
      if (replaceIndex >= 0) {
        _items.removeAt(replaceIndex);
      } else if (_items.isNotEmpty) {
        _items.removeAt(0);
      }
    }
    _items.add(event);
    _items.sort((a, b) {
      final priority = _tierPriority(b.tier).compareTo(_tierPriority(a.tier));
      if (priority != 0) return priority;
      return a.createdAt.compareTo(b.createdAt);
    });
  }

  RoomGiftAnimationEvent? takeFirstWhere(
    bool Function(RoomGiftAnimationEvent event) test,
  ) {
    final index = _items.indexWhere(test);
    if (index < 0) return null;
    return _items.removeAt(index);
  }

  int indexWhere(bool Function(RoomGiftAnimationEvent event) test) =>
      _items.indexWhere(test);

  RoomGiftAnimationEvent removeAt(int index) => _items.removeAt(index);

  void clear() => _items.clear();

  static int _tierPriority(RoomGiftAnimationTier tier) {
    switch (tier) {
      case RoomGiftAnimationTier.legendary:
        return 4;
      case RoomGiftAnimationTier.premium:
        return 3;
      case RoomGiftAnimationTier.medium:
        return 2;
      case RoomGiftAnimationTier.small:
        return 1;
    }
  }
}

class GiftAnimationOverlayManager extends ChangeNotifier {
  GiftAnimationOverlayManager({this.maxQueueLength = 30})
    : _queue = GiftAnimationQueue(maxLength: maxQueueLength);

  final int maxQueueLength;
  final GiftAnimationQueue _queue;
  final GiftComboTracker _comboTracker = GiftComboTracker();
  final LinkedHashSet<String> _recentDedupe = LinkedHashSet<String>();

  GiftActiveAnimation? _primary;
  GiftActiveAnimation? _secondary;
  GiftLocalFeedback? _localFeedback;
  Timer? _primaryTimer;
  Timer? _secondaryTimer;
  Timer? _localFeedbackTimer;
  int _sequence = 0;

  GiftActiveAnimation? get primary => _primary;
  GiftActiveAnimation? get secondary => _secondary;
  GiftLocalFeedback? get localFeedback => _localFeedback;

  void handleSocketGiftEvent(
    Map<String, dynamic> raw, {
    required String currentThemeKey,
    int? receiverFallbackId,
    int? currentUserId,
    String? inferredPkSide,
  }) {
    final parsed = RoomGiftAnimationEvent.fromJson(
      raw,
      receiverFallbackId: receiverFallbackId,
    );
    final senderThemeKey = _resolveThemeKey(
      _readThemeKey(
        raw,
        snakeCase: 'sender_active_theme_key',
        camelCase: 'senderActiveThemeKey',
      ),
      fallback:
          parsed.senderActiveThemeKey ??
          normalizePremiumThemeVariant(currentThemeKey),
    );
    final receiverThemeKey = _resolveThemeKey(
      _readThemeKey(
        raw,
        snakeCase: 'receiver_active_theme_key',
        camelCase: 'receiverActiveThemeKey',
      ),
      fallback:
          parsed.receiverActiveThemeKey ??
          normalizePremiumThemeVariant(currentThemeKey),
    );
    final event = parsed.copyWith(
      giftId: parsed.giftId > 0
          ? parsed.giftId
          : parsed.giftName.trim().hashCode.abs(),
      senderId: parsed.senderId > 0
          ? parsed.senderId
          : _stableFallbackId(
              '${parsed.senderName}:${parsed.giftName}:${parsed.createdAt.toIso8601String()}',
            ),
      receiverId: parsed.receiverId > 0
          ? parsed.receiverId
          : (receiverFallbackId ?? 1),
      senderActiveThemeKey: senderThemeKey,
      receiverActiveThemeKey: receiverThemeKey,
      isLocalSender:
          currentUserId != null && currentUserId == parsed.senderId,
      pkSide:
          raw['pk_side']?.toString().trim().isNotEmpty == true
              ? raw['pk_side'].toString().trim().toLowerCase()
              : inferredPkSide,
    );

    if (event.roomId.isEmpty || event.giftId <= 0) {
      return;
    }

    if (_recentDedupe.contains(event.dedupeKey)) return;
    _recentDedupe.add(event.dedupeKey);
    while (_recentDedupe.length > 100) {
      _recentDedupe.remove(_recentDedupe.first);
    }

    final now = DateTime.now();
    _comboTracker.clearExpired(now);
    final shouldMerge = _comboTracker.canMerge(event.comboKey, now);
    if (shouldMerge && _mergeIntoActive(event)) {
      notifyListeners();
      return;
    }
    if (shouldMerge && _mergeIntoQueue(event)) {
      notifyListeners();
      return;
    }

    _queue.enqueue(event);
    _drainQueue();
    notifyListeners();
  }

  String? _readThemeKey(
    Map<String, dynamic> raw, {
    required String snakeCase,
    required String camelCase,
  }) {
    final direct = raw[snakeCase] ?? raw[camelCase];
    final value = direct?.toString().trim();
    if (value == null || value.isEmpty) return null;
    return value;
  }

  String _resolveThemeKey(String? value, {required String fallback}) {
    final candidate = value?.trim();
    if (candidate == null || candidate.isEmpty) {
      return normalizePremiumThemeVariant(fallback);
    }
    return normalizePremiumThemeVariant(candidate);
  }

  int _stableFallbackId(String raw) {
    final normalized = raw.trim();
    if (normalized.isEmpty) return 1;
    return normalized.hashCode.abs() + 1;
  }

  void showLocalSenderFeedback({
    required String giftName,
    required String currentThemeKey,
  }) {
    _localFeedbackTimer?.cancel();
    _localFeedback = GiftLocalFeedback(
      id: ++_sequence,
      giftName: giftName,
      themeKey: currentThemeKey,
    );
    notifyListeners();
    _localFeedbackTimer = Timer(const Duration(milliseconds: 700), () {
      _localFeedback = null;
      notifyListeners();
    });
  }

  bool _mergeIntoActive(RoomGiftAnimationEvent incoming) {
    for (final lane in <GiftActiveAnimation?>[_primary, _secondary]) {
      if (lane == null) continue;
      if (lane.event.comboKey != incoming.comboKey) continue;
      final merged = _mergeCombo(lane.event, incoming);
      lane.event = merged;
      lane.revision++;
      _rescheduleLane(lane.lane, merged.displayDuration);
      return true;
    }
    return false;
  }

  bool _mergeIntoQueue(RoomGiftAnimationEvent incoming) {
    final index = _queue.indexWhere(
      (candidate) => candidate.comboKey == incoming.comboKey,
    );
    if (index < 0) return false;
    final merged = _mergeCombo(_queue.items[index], incoming);
    _queue.removeAt(index);
    _queue.enqueue(merged);
    return true;
  }

  RoomGiftAnimationEvent _mergeCombo(
    RoomGiftAnimationEvent current,
    RoomGiftAnimationEvent incoming,
  ) {
    return current.copyWith(
      senderName:
          incoming.senderName.trim().isNotEmpty
              ? incoming.senderName
              : current.senderName,
      giftName:
          incoming.giftName.trim().isNotEmpty
              ? incoming.giftName
              : current.giftName,
      quantity: current.quantity + incoming.quantity,
      totalCoins: current.totalCoins + incoming.totalCoins,
      comboCount: current.comboCount + incoming.quantity,
      message:
          (incoming.message ?? '').trim().isNotEmpty
              ? incoming.message
              : current.message,
      createdAt: incoming.createdAt,
      senderAvatar: incoming.senderAvatar ?? current.senderAvatar,
      senderLevel: incoming.senderLevel ?? current.senderLevel,
      senderIsVip: incoming.senderIsVip ?? current.senderIsVip,
      senderActiveThemeKey:
          incoming.senderActiveThemeKey ?? current.senderActiveThemeKey,
      receiverActiveThemeKey:
          incoming.receiverActiveThemeKey ?? current.receiverActiveThemeKey,
      receiverName: incoming.receiverName ?? current.receiverName,
      receiverAvatar: incoming.receiverAvatar ?? current.receiverAvatar,
      pkSide: incoming.pkSide ?? current.pkSide,
      isLocalSender: incoming.isLocalSender || current.isLocalSender,
    );
  }

  void _drainQueue() {
    if (_primary?.event.tier == RoomGiftAnimationTier.legendary) return;
    if (_primary == null && _secondary != null) return;

    if (_primary == null) {
      final next = _queue.takeFirstWhere((_) => true);
      if (next != null) {
        _activate(next, GiftAnimationLane.primary);
      }
    }

    final primaryTier = _primary?.event.tier;
    if (_secondary == null &&
        primaryTier != RoomGiftAnimationTier.legendary &&
        primaryTier != RoomGiftAnimationTier.premium) {
      final next = _queue.takeFirstWhere(
        (event) =>
            event.tier == RoomGiftAnimationTier.small ||
            event.tier == RoomGiftAnimationTier.medium,
      );
      if (next != null) {
        _activate(next, GiftAnimationLane.secondary);
      }
    }
  }

  void _activate(RoomGiftAnimationEvent event, GiftAnimationLane lane) {
    final active = GiftActiveAnimation(
      id: ++_sequence,
      lane: lane,
      event: event,
    );
    switch (lane) {
      case GiftAnimationLane.primary:
        _primaryTimer?.cancel();
        _primary = active;
        _primaryTimer = Timer(event.displayDuration, () {
          _primary = null;
          _drainQueue();
          notifyListeners();
        });
        break;
      case GiftAnimationLane.secondary:
        _secondaryTimer?.cancel();
        _secondary = active;
        _secondaryTimer = Timer(event.displayDuration, () {
          _secondary = null;
          _drainQueue();
          notifyListeners();
        });
        break;
    }
  }

  void _rescheduleLane(GiftAnimationLane lane, Duration duration) {
    switch (lane) {
      case GiftAnimationLane.primary:
        _primaryTimer?.cancel();
        _primaryTimer = Timer(duration, () {
          _primary = null;
          _drainQueue();
          notifyListeners();
        });
        break;
      case GiftAnimationLane.secondary:
        _secondaryTimer?.cancel();
        _secondaryTimer = Timer(duration, () {
          _secondary = null;
          _drainQueue();
          notifyListeners();
        });
        break;
    }
  }

  void clear() {
    _primaryTimer?.cancel();
    _secondaryTimer?.cancel();
    _localFeedbackTimer?.cancel();
    _queue.clear();
    _comboTracker.clear();
    _recentDedupe.clear();
    _primary = null;
    _secondary = null;
    _localFeedback = null;
    notifyListeners();
  }

  @override
  void dispose() {
    _primaryTimer?.cancel();
    _secondaryTimer?.cancel();
    _localFeedbackTimer?.cancel();
    super.dispose();
  }
}

class GiftAnimationLayer extends StatelessWidget {
  const GiftAnimationLayer({
    super.key,
    required this.manager,
    required this.anchors,
    required this.currentThemeKey,
    required this.receiverAnchorName,
    required this.stageCenterAnchorName,
    this.pkLeftAnchorName,
    this.pkRightAnchorName,
  });

  final GiftAnimationOverlayManager manager;
  final GiftAnchorRegistry anchors;
  final String currentThemeKey;
  final String receiverAnchorName;
  final String stageCenterAnchorName;
  final String? pkLeftAnchorName;
  final String? pkRightAnchorName;

  @override
  Widget build(BuildContext context) {
    return IgnorePointer(
      ignoring: true,
      child: AnimatedBuilder(
        animation: manager,
        builder: (context, _) {
          return LayoutBuilder(
            builder: (context, constraints) {
              final defaultStageCenter = Rect.fromCenter(
                center: Offset(
                  constraints.maxWidth / 2,
                  constraints.maxHeight * 0.42,
                ),
                width: constraints.maxWidth * 0.72,
                height: constraints.maxHeight * 0.32,
              );
              final stageCenter = _sanitizeRect(
                anchors.rectFor(stageCenterAnchorName, context),
                fallback: defaultStageCenter,
                maxWidth: constraints.maxWidth,
                maxHeight: constraints.maxHeight,
              );

              final defaultGiftButtonRect = Rect.fromCenter(
                center: Offset(
                  constraints.maxWidth - 36,
                  constraints.maxHeight - 60,
                ),
                width: 46,
                height: 46,
              );
              final giftButtonRect = _sanitizeRect(
                anchors.rectFor(GiftAnchorRegistry.giftButton, context),
                fallback: defaultGiftButtonRect,
                maxWidth: constraints.maxWidth,
                maxHeight: constraints.maxHeight,
              );

              return Stack(
                children: [
                  if (manager.localFeedback != null)
                    _LocalSenderFeedbackView(
                      key: ValueKey(manager.localFeedback!.id),
                      anchor: giftButtonRect,
                      themeKey: manager.localFeedback!.themeKey,
                      label: manager.localFeedback!.giftName,
                    ),
                  if (manager.primary != null)
                    Positioned.fill(
                      child: _GiftAnimationSprite(
                        key: ValueKey(
                          'gift-${manager.primary!.id}-${manager.primary!.revision}',
                        ),
                        animation: manager.primary!,
                        receiverRect: _resolveReceiverRect(
                          context,
                          stageCenter,
                          constraints.maxWidth,
                          constraints.maxHeight,
                          manager.primary!.event,
                        ),
                        stageCenterRect: stageCenter,
                        giftButtonRect: giftButtonRect,
                        currentThemeKey: currentThemeKey,
                      ),
                    ),
                  if (manager.secondary != null)
                    Positioned.fill(
                      child: _GiftAnimationSprite(
                        key: ValueKey(
                          'gift-${manager.secondary!.id}-${manager.secondary!.revision}',
                        ),
                        animation: manager.secondary!,
                        receiverRect: _resolveReceiverRect(
                          context,
                          stageCenter,
                          constraints.maxWidth,
                          constraints.maxHeight,
                          manager.secondary!.event,
                        ),
                        stageCenterRect: stageCenter,
                        giftButtonRect: giftButtonRect,
                        currentThemeKey: currentThemeKey,
                      ),
                    ),
                ],
              );
            },
          );
        },
      ),
    );
  }

  Rect _resolveReceiverRect(
    BuildContext context,
    Rect stageCenter,
    double maxWidth,
    double maxHeight,
    RoomGiftAnimationEvent event,
  ) {
    final side = event.pkSide?.trim().toLowerCase();
    final anchorName =
        side == 'right' && pkRightAnchorName != null
            ? pkRightAnchorName!
            : side == 'left' && pkLeftAnchorName != null
            ? pkLeftAnchorName!
            : receiverAnchorName;
    return _sanitizeRect(
      anchors.rectFor(anchorName, context),
      fallback: stageCenter,
      maxWidth: maxWidth,
      maxHeight: maxHeight,
    );
  }

  Rect _sanitizeRect(
    Rect? raw, {
    required Rect fallback,
    required double maxWidth,
    required double maxHeight,
  }) {
    if (raw == null ||
        !raw.left.isFinite ||
        !raw.top.isFinite ||
        !raw.width.isFinite ||
        !raw.height.isFinite ||
        raw.width <= 0 ||
        raw.height <= 0) {
      return fallback;
    }

    final center = raw.center;
    final outsideViewport =
        center.dx < -24 ||
        center.dy < -24 ||
        center.dx > maxWidth + 24 ||
        center.dy > maxHeight + 24;
    if (outsideViewport) return fallback;

    final clampedWidth = raw.width.clamp(24.0, maxWidth);
    final clampedHeight = raw.height.clamp(24.0, maxHeight);
    final clampedCenter = Offset(
      center.dx.clamp(clampedWidth / 2, maxWidth - (clampedWidth / 2)),
      center.dy.clamp(clampedHeight / 2, maxHeight - (clampedHeight / 2)),
    );
    return Rect.fromCenter(
      center: clampedCenter,
      width: clampedWidth,
      height: clampedHeight,
    );
  }
}

enum GiftAnimationLane { primary, secondary }

class GiftActiveAnimation {
  GiftActiveAnimation({
    required this.id,
    required this.lane,
    required this.event,
  });

  final int id;
  final GiftAnimationLane lane;
  RoomGiftAnimationEvent event;
  int revision = 0;
}

class GiftLocalFeedback {
  const GiftLocalFeedback({
    required this.id,
    required this.giftName,
    required this.themeKey,
  });

  final int id;
  final String giftName;
  final String themeKey;
}

class _LocalSenderFeedbackView extends StatefulWidget {
  const _LocalSenderFeedbackView({
    super.key,
    required this.anchor,
    required this.themeKey,
    required this.label,
  });

  final Rect anchor;
  final String themeKey;
  final String label;

  @override
  State<_LocalSenderFeedbackView> createState() =>
      _LocalSenderFeedbackViewState();
}

class _LocalSenderFeedbackViewState extends State<_LocalSenderFeedbackView>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 650),
  )..forward();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(widget.themeKey);
    return AnimatedBuilder(
      animation: _controller,
      builder: (context, _) {
        final t = Curves.easeOutCubic.transform(_controller.value);
        final opacity = (1 - t).clamp(0.0, 1.0);
        final dy = -20 * t;
        return Positioned(
          left: widget.anchor.center.dx - 26,
          top: widget.anchor.top - 24 + dy,
          child: Opacity(
            opacity: opacity,
            child: Transform.scale(
              scale: .84 + (.26 * (1 - (t - .4).abs().clamp(0.0, 1.0))),
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
                decoration: BoxDecoration(
                  color: tokens.glassColor.withOpacity(.92),
                  borderRadius: BorderRadius.circular(999),
                  border: Border.all(color: tokens.borderColor.withOpacity(.36)),
                  boxShadow: [
                    BoxShadow(
                      color: tokens.glowColor.withOpacity(.25),
                      blurRadius: 16,
                      offset: const Offset(0, 6),
                    ),
                  ],
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(
                      Icons.auto_awesome_rounded,
                      color: tokens.primaryButtonGradient.first,
                      size: 15,
                    ),
                    const SizedBox(width: 6),
                    Text(
                      widget.label,
                      style: TextStyle(
                        color: tokens.textPrimary,
                        fontWeight: FontWeight.w800,
                        fontSize: 11.5,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        );
      },
    );
  }
}

class _GiftAnimationSprite extends StatefulWidget {
  const _GiftAnimationSprite({
    super.key,
    required this.animation,
    required this.receiverRect,
    required this.stageCenterRect,
    required this.giftButtonRect,
    required this.currentThemeKey,
  });

  final GiftActiveAnimation animation;
  final Rect receiverRect;
  final Rect stageCenterRect;
  final Rect giftButtonRect;
  final String currentThemeKey;

  @override
  State<_GiftAnimationSprite> createState() => _GiftAnimationSpriteState();
}

class _GiftAnimationSpriteState extends State<_GiftAnimationSprite>
    with SingleTickerProviderStateMixin {
  late AnimationController _controller;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      vsync: this,
      duration: widget.animation.event.displayDuration,
    )..forward();
  }

  @override
  void didUpdateWidget(covariant _GiftAnimationSprite oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.animation.event.displayDuration !=
        widget.animation.event.displayDuration) {
      _controller.dispose();
      _controller = AnimationController(
        vsync: this,
        duration: widget.animation.event.displayDuration,
      )..forward();
      return;
    }
    if (widget.animation.revision != oldWidget.animation.revision) {
      _controller.forward(from: _controller.value * .45);
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final event = widget.animation.event;
    final fallbackThemeKey = normalizePremiumThemeVariant(widget.currentThemeKey);
    final senderTheme = getPremiumThemeTokens(
      normalizePremiumThemeVariant(
        event.senderActiveThemeKey ?? fallbackThemeKey,
      ),
    );
    final receiverTheme = getPremiumThemeTokens(
      normalizePremiumThemeVariant(
        event.receiverActiveThemeKey ?? fallbackThemeKey,
      ),
    );

    return AnimatedBuilder(
      animation: _controller,
      builder: (context, _) {
        final t = Curves.easeInOutCubic.transform(_controller.value);
        switch (event.tier) {
          case RoomGiftAnimationTier.small:
            return _buildSmall(event, t, senderTheme, receiverTheme);
          case RoomGiftAnimationTier.medium:
            return _buildMedium(event, t, senderTheme, receiverTheme);
          case RoomGiftAnimationTier.premium:
            return _buildPremium(event, t, senderTheme, receiverTheme);
          case RoomGiftAnimationTier.legendary:
            return _buildLegendary(event, t, senderTheme, receiverTheme);
        }
      },
    );
  }

  Widget _buildSmall(
    RoomGiftAnimationEvent event,
    double t,
    PremiumThemeTokens senderTheme,
    PremiumThemeTokens receiverTheme,
  ) {
    final arrival = Curves.easeOutBack.transform((t * 1.12).clamp(0.0, 1.0));
    final drift = Curves.easeInOut.transform(t.clamp(0.0, 1.0));
    final size = 58 + (event.comboCount.clamp(1, 12) * 2.4);
    final baseX = math.sin(widget.animation.id * 1.7) * 18;
    final center =
        widget.receiverRect.center +
        Offset(baseX * (1 - t), -16 - (26 * drift));
    final opacity = (1 - (t * 0.86)).clamp(0.0, 1.0);
    final scale = .66 + (0.42 * arrival);
    return Stack(
      children: [
        _GiftMotionTrail(
          start: widget.receiverRect.center + Offset(baseX, 8),
          end: center,
          tokens: senderTheme,
          progress: t,
          width: 3,
          maxLength: 40,
          opacityScale: .28,
        ),
        _HostReactionGlow(
          receiverRect: widget.receiverRect,
          tokens: receiverTheme,
          progress: t,
          intensity: 0.7 + (event.comboCount * .04),
        ),
        _ReceiverReactionBadge(
          event: event,
          receiverRect: widget.receiverRect,
          tokens: receiverTheme,
          progress: t,
        ),
        Positioned(
          left: center.dx - (size / 2),
          top: center.dy - (size / 2),
          child: Opacity(
            opacity: opacity,
            child: Transform.scale(
              scale: scale,
              child: _GiftAssetCard(
                event: event,
                tier: event.tier,
                size: size,
                tokens: senderTheme,
                showCombo: event.comboCount > 1,
              ),
            ),
          ),
        ),
      ],
    );
  }

  Widget _buildMedium(
    RoomGiftAnimationEvent event,
    double t,
    PremiumThemeTokens senderTheme,
    PremiumThemeTokens receiverTheme,
  ) {
    final start =
        event.isLocalSender
            ? widget.giftButtonRect.center
            : Offset(
              widget.stageCenterRect.left + 28,
              widget.stageCenterRect.center.dy,
            );
    final end = widget.receiverRect.center;
    final travel = _arcLerp(
      start,
      end,
      t,
      lift: math.max(42, (start.dy - end.dy).abs() * .18 + 34),
    );
    final size = 130.0 + math.min(50.0, event.comboCount * 4.0);
    final showCombo = event.comboCount > 1;
    final scale = .78 + (.24 * math.sin(t * math.pi));
    return Stack(
      children: [
        _GiftMotionTrail(
          start: start,
          end: travel,
          tokens: senderTheme,
          progress: t,
          width: 5,
          maxLength: 84,
          opacityScale: .34,
        ),
        _HostReactionGlow(
          receiverRect: widget.receiverRect,
          tokens: receiverTheme,
          progress: t,
          intensity: 0.9 + (event.comboCount * .05),
        ),
        _ReceiverReactionBadge(
          event: event,
          receiverRect: widget.receiverRect,
          tokens: receiverTheme,
          progress: t,
        ),
        Positioned(
          left: travel.dx - (size / 2),
          top: travel.dy - (size / 2),
          child: Transform.scale(
            scale: scale,
            child: _GiftAssetCard(
              event: event,
              tier: event.tier,
              size: size,
              tokens: senderTheme,
              showCombo: showCombo,
              comboInline: true,
            ),
          ),
        ),
      ],
    );
  }

  Widget _buildPremium(
    RoomGiftAnimationEvent event,
    double t,
    PremiumThemeTokens senderTheme,
    PremiumThemeTokens receiverTheme,
  ) {
    final alignment = _alignmentForRect(widget.stageCenterRect, context);
    final size = 250.0 + math.min(110.0, event.comboCount * 6.0);
    final reveal = Curves.easeOutCubic.transform((t * 1.08).clamp(0.0, 1.0));
    final settle = Curves.easeInOut.transform(t.clamp(0.0, 1.0));
    final shimmerOpacity = (1 - (t - .72).clamp(0.0, 1.0)).clamp(0.0, 1.0);
    final slideDy = 24 * (1 - reveal);
    return Stack(
      children: [
        Positioned.fill(
          child: Opacity(
            opacity: (.26 * shimmerOpacity).clamp(0.0, .26),
            child: DecoratedBox(
              decoration: BoxDecoration(
                gradient: RadialGradient(
                  colors: [
                    senderTheme.glowColor.withOpacity(.30),
                    Colors.transparent,
                  ],
                ),
              ),
            ),
          ),
        ),
        Positioned.fill(
          child: Opacity(
            opacity: (.12 * shimmerOpacity).clamp(0.0, .12),
            child: DecoratedBox(
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topCenter,
                  end: Alignment.bottomCenter,
                  colors: [
                    senderTheme.primaryButtonGradient.first.withOpacity(.22),
                    Colors.transparent,
                    senderTheme.cardGradient.last.withOpacity(.14),
                  ],
                ),
              ),
            ),
          ),
        ),
        _HostReactionGlow(
          receiverRect: widget.receiverRect,
          tokens: receiverTheme,
          progress: t,
          intensity: 1.1 + (event.comboCount * .06),
          large: true,
        ),
        _GiftMotionTrail(
          start:
              event.isLocalSender
                  ? widget.giftButtonRect.center
                  : Offset(
                    widget.stageCenterRect.left + 26,
                    widget.stageCenterRect.bottom - 8,
                  ),
          end: widget.stageCenterRect.center,
          tokens: senderTheme,
          progress: reveal,
          width: 7,
          maxLength: 120,
          opacityScale: .26,
        ),
        _ReceiverReactionBadge(
          event: event,
          receiverRect: widget.receiverRect,
          tokens: receiverTheme,
          progress: t,
          prominent: true,
        ),
        Positioned.fill(
          child: Align(
            alignment: alignment,
            child: Transform.scale(
              scale: .76 + (.24 * Curves.easeOutBack.transform(reveal)),
              child: Transform.translate(
                offset: Offset(0, slideDy),
                child: Opacity(
                  opacity: (1 - (t - .88).clamp(0.0, 1.0)).clamp(0.0, 1.0),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      _SenderBanner(event: event, tokens: senderTheme),
                      const SizedBox(height: 12),
                      Transform.rotate(
                        angle: math.sin(settle * math.pi) * 0.018,
                        child: _GiftAssetCard(
                          event: event,
                          tier: event.tier,
                          size: size,
                          tokens: senderTheme,
                          showCombo: true,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ],
    );
  }

  Widget _buildLegendary(
    RoomGiftAnimationEvent event,
    double t,
    PremiumThemeTokens senderTheme,
    PremiumThemeTokens receiverTheme,
  ) {
    final screenSize = MediaQuery.sizeOf(context);
    final reveal = Curves.easeOutCubic.transform((t * 1.02).clamp(0.0, 1.0));
    final glowOpacity = (.58 * Curves.easeOut.transform(t.clamp(0.0, 1.0)));
    return Stack(
      children: [
        Positioned.fill(
          child: Opacity(
            opacity: glowOpacity.clamp(0.0, .58),
            child: DecoratedBox(
              decoration: BoxDecoration(
                color: Colors.black.withOpacity(.80),
                gradient: RadialGradient(
                  colors: [
                    senderTheme.glowColor.withOpacity(.18),
                    Colors.black.withOpacity(.82),
                  ],
                ),
              ),
            ),
          ),
        ),
        Positioned.fill(
          child: Opacity(
            opacity: (.22 * (1 - (t - .82).clamp(0.0, 1.0))).clamp(0.0, .22),
            child: DecoratedBox(
              decoration: BoxDecoration(
                gradient: SweepGradient(
                  colors: [
                    senderTheme.primaryButtonGradient.first.withOpacity(.18),
                    Colors.transparent,
                    senderTheme.primaryButtonGradient.last.withOpacity(.18),
                    Colors.transparent,
                  ],
                ),
              ),
            ),
          ),
        ),
        _HostReactionGlow(
          receiverRect: widget.receiverRect,
          tokens: receiverTheme,
          progress: t,
          intensity: 1.4 + (event.comboCount * .08),
          large: true,
        ),
        _ReceiverReactionBadge(
          event: event,
          receiverRect: widget.receiverRect,
          tokens: receiverTheme,
          progress: t,
          prominent: true,
        ),
        Positioned(
          left: 20,
          right: 20,
          top: math.max(30, widget.stageCenterRect.top - 26),
          child: Transform.translate(
            offset: Offset(0, 22 * (1 - reveal)),
            child: Opacity(
              opacity: reveal,
              child: _SenderBanner(
                event: event,
                tokens: senderTheme,
                legendary: true,
              ),
            ),
          ),
        ),
        Positioned.fill(
          child: Align(
            alignment: Alignment.center,
            child: Transform.translate(
              offset: Offset(0, 26 * (1 - reveal)),
              child: Transform.scale(
                scale: .82 + (.18 * Curves.easeOutBack.transform(reveal)),
                child: Transform.rotate(
                  angle: math.sin(t * math.pi) * 0.01,
                  child: SizedBox(
                    width: screenSize.width,
                    height: screenSize.height * .82,
                    child: _LegendaryGiftHero(
                      event: event,
                      tokens: senderTheme,
                      showCombo: true,
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
      ],
    );
  }

  Offset _arcLerp(Offset start, Offset end, double t, {required double lift}) {
    final curved = Curves.easeInOutCubic.transform(t.clamp(0.0, 1.0));
    final base = Offset.lerp(start, end, curved)!;
    final verticalArc = math.sin(curved * math.pi) * lift;
    return Offset(base.dx, base.dy - verticalArc);
  }

  Alignment _alignmentForRect(Rect rect, BuildContext context) {
    final size = MediaQuery.sizeOf(context);
    if (size.width <= 0 || size.height <= 0) {
      return Alignment.center;
    }
    final dx = ((rect.center.dx / size.width) * 2) - 1;
    final dy = ((rect.center.dy / size.height) * 2) - 1;
    return Alignment(
      dx.clamp(-1.0, 1.0),
      dy.clamp(-1.0, 1.0),
    );
  }
}

class _LegendaryGiftHero extends StatelessWidget {
  const _LegendaryGiftHero({
    required this.event,
    required this.tokens,
    required this.showCombo,
  });

  final RoomGiftAnimationEvent event;
  final PremiumThemeTokens tokens;
  final bool showCombo;

  @override
  Widget build(BuildContext context) {
    final mediaSize = MediaQuery.sizeOf(context);
    final fallbackSize = math.min(mediaSize.width * .72, mediaSize.height * .52);
    return Stack(
      clipBehavior: Clip.none,
      children: [
        Positioned.fill(
          child: RemoteMediaArt(
            url: event.giftAssetUrl,
            explicitType: switch (event.assetKind) {
              RemoteMediaKind.svg => 'svg',
              RemoteMediaKind.svga => 'svga',
              RemoteMediaKind.gif => 'gif',
              RemoteMediaKind.image => 'image',
              RemoteMediaKind.unknown => event.giftType,
            },
            width: double.infinity,
            height: double.infinity,
            fit: BoxFit.contain,
            fallback: _GiftAssetFallback(
              label: event.giftName,
              size: fallbackSize,
              tokens: tokens,
              loading: true,
            ),
          ),
        ),
        IgnorePointer(
          child: Positioned.fill(
            child: DecoratedBox(
              decoration: BoxDecoration(
                gradient: RadialGradient(
                  colors: [
                    tokens.glowColor.withOpacity(.18),
                    Colors.transparent,
                  ],
                ),
              ),
            ),
          ),
        ),
        if (showCombo)
          Positioned(
            right: 20,
            top: 20,
            child: _ComboBadge(
              count: event.comboCount,
              tokens: tokens,
            ),
          ),
      ],
    );
  }
}

class _GiftMotionTrail extends StatelessWidget {
  const _GiftMotionTrail({
    required this.start,
    required this.end,
    required this.tokens,
    required this.progress,
    required this.width,
    required this.maxLength,
    required this.opacityScale,
  });

  final Offset start;
  final Offset end;
  final PremiumThemeTokens tokens;
  final double progress;
  final double width;
  final double maxLength;
  final double opacityScale;

  @override
  Widget build(BuildContext context) {
    final delta = end - start;
    final length = delta.distance;
    if (length <= 1) return const SizedBox.shrink();
    final clampedLength = math.min(length, maxLength);
    final angle = math.atan2(delta.dy, delta.dx);
    final opacity = (math.sin(progress * math.pi) * opacityScale).clamp(0.0, 1.0);
    return Positioned(
      left: end.dx - clampedLength,
      top: end.dy - (width / 2),
      child: Transform.rotate(
        angle: angle,
        alignment: Alignment.centerRight,
        child: Opacity(
          opacity: opacity,
          child: Container(
            width: clampedLength,
            height: width,
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(999),
              gradient: LinearGradient(
                colors: [
                  Colors.transparent,
                  tokens.primaryButtonGradient.first.withOpacity(.14),
                  tokens.primaryButtonGradient.last.withOpacity(.34),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _SenderBanner extends StatelessWidget {
  const _SenderBanner({
    required this.event,
    required this.tokens,
    this.legendary = false,
  });

  final RoomGiftAnimationEvent event;
  final PremiumThemeTokens tokens;
  final bool legendary;

  @override
  Widget build(BuildContext context) {
    final sideLabel = _pkSideLabel(event.pkSide);
    return Container(
      padding: EdgeInsets.symmetric(
        horizontal: legendary ? 16 : 12,
        vertical: legendary ? 12 : 9,
      ),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: [
            tokens.cardGradient.first.withOpacity(.95),
            tokens.primaryButtonGradient.last.withOpacity(.92),
          ],
        ),
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: tokens.borderColor.withOpacity(.32)),
        boxShadow: [
          BoxShadow(
            color: tokens.glowColor.withOpacity(.24),
            blurRadius: 18,
            offset: const Offset(0, 8),
          ),
        ],
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _AvatarBadge(
            name: event.senderName,
            avatarUrl: event.senderAvatar,
            tokens: tokens,
            radius: legendary ? 18 : 15,
            fallbackIcon: Icons.card_giftcard_rounded,
          ),
          const SizedBox(width: 10),
          Flexible(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Wrap(
                  spacing: 6,
                  runSpacing: 6,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    Text(
                      legendary
                          ? '${event.senderName} sent a legendary gift'
                          : event.senderName,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        color: tokens.textPrimary,
                        fontWeight: FontWeight.w900,
                        fontSize: legendary ? 15 : 13,
                      ),
                    ),
                    if (event.senderLevel != null)
                      _MetaChip(
                        label: 'LV ${event.senderLevel}',
                        tokens: tokens,
                        compact: true,
                      ),
                    if (event.senderIsVip == true)
                      _MetaChip(
                        label: 'VIP',
                        tokens: tokens,
                        compact: true,
                        highlight: true,
                      ),
                    if (sideLabel != null)
                      _MetaChip(
                        label: sideLabel,
                        tokens: tokens,
                        compact: true,
                      ),
                  ],
                ),
                const SizedBox(height: 2),
                Text(
                  '${event.giftName} x${event.comboCount}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    color: tokens.textSecondary,
                    fontWeight: FontWeight.w700,
                    fontSize: legendary ? 13 : 11.5,
                  ),
                ),
                if ((event.message ?? '').trim().isNotEmpty) ...[
                  const SizedBox(height: 4),
                  Text(
                    event.message!.trim(),
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      color: tokens.textPrimary.withOpacity(.92),
                      fontWeight: FontWeight.w600,
                      fontSize: legendary ? 12 : 10.5,
                    ),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _ReceiverReactionBadge extends StatelessWidget {
  const _ReceiverReactionBadge({
    required this.event,
    required this.receiverRect,
    required this.tokens,
    required this.progress,
    this.prominent = false,
  });

  final RoomGiftAnimationEvent event;
  final Rect receiverRect;
  final PremiumThemeTokens tokens;
  final double progress;
  final bool prominent;

  @override
  Widget build(BuildContext context) {
    final receiverName = (event.receiverName ?? '').trim();
    final receiverAvatar = (event.receiverAvatar ?? '').trim();
    if (receiverName.isEmpty && receiverAvatar.isEmpty) {
      return const SizedBox.shrink();
    }
    final opacity = ((1 - (progress - .78).clamp(0.0, 1.0)) * .98).clamp(
      0.0,
      1.0,
    );
    final pulse = math.sin(progress * math.pi);
    final scale = 0.94 + (pulse * 0.08);
    final dy = prominent ? -8.0 : -4.0;
    return Positioned(
      left: receiverRect.center.dx - 38,
      top: receiverRect.top + dy,
      child: Opacity(
        opacity: opacity,
        child: Transform.scale(
          scale: scale,
          child: Container(
            constraints: const BoxConstraints(maxWidth: 120),
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
            decoration: BoxDecoration(
              color: tokens.glassColor.withOpacity(.94),
              borderRadius: BorderRadius.circular(999),
              border: Border.all(color: tokens.borderColor.withOpacity(.38)),
              boxShadow: [
                BoxShadow(
                  color: tokens.glowColor.withOpacity(.22),
                  blurRadius: 16,
                  offset: const Offset(0, 6),
                ),
              ],
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                _AvatarBadge(
                  name: receiverName,
                  avatarUrl: receiverAvatar,
                  tokens: tokens,
                  radius: 10,
                  fallbackIcon: Icons.favorite_rounded,
                ),
                if (receiverName.isNotEmpty) ...[
                  const SizedBox(width: 6),
                  Flexible(
                    child: Text(
                      receiverName,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        color: tokens.textPrimary,
                        fontWeight: FontWeight.w800,
                        fontSize: 10.5,
                      ),
                    ),
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _HostReactionGlow extends StatelessWidget {
  const _HostReactionGlow({
    required this.receiverRect,
    required this.tokens,
    required this.progress,
    required this.intensity,
    this.large = false,
  });

  final Rect receiverRect;
  final PremiumThemeTokens tokens;
  final double progress;
  final double intensity;
  final bool large;

  @override
  Widget build(BuildContext context) {
    final pulse = math.sin(progress * math.pi);
    final radius = (large ? receiverRect.longestSide * 0.9 : receiverRect.longestSide * 0.56) *
        (0.94 + (pulse * .24));
    final opacity = ((1 - (progress - .78).clamp(0.0, 1.0)) * .26).clamp(0.0, .26);
    return Positioned(
      left: receiverRect.center.dx - radius,
      top: receiverRect.center.dy - radius,
      child: Opacity(
        opacity: opacity,
        child: Container(
          width: radius * 2,
          height: radius * 2,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            gradient: RadialGradient(
              colors: [
                tokens.glowColor.withOpacity((.18 * intensity).clamp(0.0, .42)),
                Colors.transparent,
              ],
            ),
            border: Border.all(
              color: tokens.primaryButtonGradient.first.withOpacity(
                (.22 * intensity).clamp(0.0, .46),
              ),
              width: large ? 3 : 2,
            ),
          ),
        ),
      ),
    );
  }
}

class _AvatarBadge extends StatelessWidget {
  const _AvatarBadge({
    required this.name,
    required this.avatarUrl,
    required this.tokens,
    required this.radius,
    required this.fallbackIcon,
  });

  final String name;
  final String? avatarUrl;
  final PremiumThemeTokens tokens;
  final double radius;
  final IconData fallbackIcon;

  @override
  Widget build(BuildContext context) {
    final safeName = name.trim();
    final safeAvatar = avatarUrl?.trim();
    final initials = _initialsForName(safeName);
    return CircleAvatar(
      radius: radius,
      backgroundColor: tokens.glassColor.withOpacity(.72),
      backgroundImage:
          (safeAvatar?.isNotEmpty == true) ? NetworkImage(safeAvatar!) : null,
      child:
          safeAvatar?.isNotEmpty == true
              ? null
              : initials != null
              ? Text(
                initials,
                style: TextStyle(
                  color: tokens.textPrimary,
                  fontWeight: FontWeight.w900,
                  fontSize: radius * .7,
                ),
              )
              : Icon(
                fallbackIcon,
                color: tokens.primaryButtonGradient.first,
                size: radius * 1.05,
              ),
    );
  }
}

class _MetaChip extends StatelessWidget {
  const _MetaChip({
    required this.label,
    required this.tokens,
    this.compact = false,
    this.highlight = false,
  });

  final String label;
  final PremiumThemeTokens tokens;
  final bool compact;
  final bool highlight;

  @override
  Widget build(BuildContext context) {
    final gradient =
        highlight
            ? tokens.primaryButtonGradient
            : <Color>[
              tokens.glassColor.withOpacity(.85),
              tokens.cardGradient.last.withOpacity(.68),
            ];
    return Container(
      padding: EdgeInsets.symmetric(
        horizontal: compact ? 7 : 9,
        vertical: compact ? 3 : 5,
      ),
      decoration: BoxDecoration(
        gradient: LinearGradient(colors: gradient),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: tokens.borderColor.withOpacity(.34)),
      ),
      child: Text(
        label,
        style: TextStyle(
          color: tokens.textPrimary,
          fontWeight: FontWeight.w900,
          fontSize: compact ? 9.5 : 10.5,
          height: 1,
        ),
      ),
    );
  }
}

String? _pkSideLabel(String? pkSide) {
  final normalized = pkSide?.trim().toLowerCase();
  if (normalized == 'left') return 'LEFT';
  if (normalized == 'right') return 'RIGHT';
  return null;
}

String? _initialsForName(String name) {
  final safe = name.trim();
  if (safe.isEmpty) return null;
  final parts = safe
      .split(RegExp(r'\s+'))
      .where((part) => part.isNotEmpty)
      .take(2)
      .toList(growable: false);
  if (parts.isEmpty) return null;
  final buffer = StringBuffer();
  for (final part in parts) {
    buffer.write(part.characters.first.toUpperCase());
  }
  return buffer.toString();
}

class _GiftAssetCard extends StatelessWidget {
  const _GiftAssetCard({
    required this.event,
    required this.tier,
    required this.size,
    required this.tokens,
    required this.showCombo,
    this.comboInline = false,
  });

  final RoomGiftAnimationEvent event;
  final RoomGiftAnimationTier tier;
  final double size;
  final PremiumThemeTokens tokens;
  final bool showCombo;
  final bool comboInline;

  @override
  Widget build(BuildContext context) {
    return RepaintBoundary(
      child: Stack(
        clipBehavior: Clip.none,
        alignment: Alignment.center,
        children: [
          SizedBox(
            width: size,
            height: size,
            child: _GiftAssetRenderer(
              event: event,
              size: size,
              tokens: tokens,
            ),
          ),
          if (showCombo)
            Positioned(
              right: comboInline ? -6 : -2,
              top: comboInline ? -6 : -2,
              child: _ComboBadge(
                count: event.comboCount,
                tokens: tokens,
              ),
            ),
          if (tier.index >= RoomGiftAnimationTier.premium.index)
            IgnorePointer(
              child: Container(
                width: size,
                height: size,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  gradient: RadialGradient(
                    colors: [
                      tokens.glowColor.withOpacity(
                        tier == RoomGiftAnimationTier.legendary ? .22 : .14,
                      ),
                      Colors.transparent,
                    ],
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class _ComboBadge extends StatelessWidget {
  const _ComboBadge({required this.count, required this.tokens});

  final int count;
  final PremiumThemeTokens tokens;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 5),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: tokens.primaryButtonGradient,
        ),
        borderRadius: BorderRadius.circular(999),
        boxShadow: [
          BoxShadow(
            color: tokens.glowColor.withOpacity(.24),
            blurRadius: 12,
            offset: const Offset(0, 6),
          ),
        ],
      ),
      child: Text(
        'x$count',
        style: TextStyle(
          color: tokens.textPrimary,
          fontWeight: FontWeight.w900,
          fontSize: 12,
        ),
      ),
    );
  }
}

class _GiftAssetRenderer extends StatelessWidget {
  const _GiftAssetRenderer({
    required this.event,
    required this.size,
    required this.tokens,
  });

  final RoomGiftAnimationEvent event;
  final double size;
  final PremiumThemeTokens tokens;

  @override
  Widget build(BuildContext context) {
    return RemoteMediaArt(
      url: event.giftAssetUrl,
      explicitType: switch (event.assetKind) {
        RemoteMediaKind.svg => 'svg',
        RemoteMediaKind.svga => 'svga',
        RemoteMediaKind.gif => 'gif',
        RemoteMediaKind.image => 'image',
        RemoteMediaKind.unknown => event.giftType,
      },
      width: size,
      height: size,
      fit: BoxFit.contain,
      fallback: _GiftAssetFallback(
        label: event.giftName,
        size: size,
        tokens: tokens,
        loading: true,
      ),
    );
  }
}

class _GiftAssetFallback extends StatelessWidget {
  const _GiftAssetFallback({
    required this.label,
    required this.size,
    required this.tokens,
    this.loading = false,
  });

  final String label;
  final double size;
  final PremiumThemeTokens tokens;
  final bool loading;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        gradient: LinearGradient(
          colors: [
            tokens.primaryButtonGradient.first.withOpacity(.24),
            tokens.primaryButtonGradient.last.withOpacity(.14),
          ],
        ),
      ),
      child: Stack(
        alignment: Alignment.center,
        children: [
          if (loading)
            Positioned.fill(
              child: DecoratedBox(
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                    colors: [
                      Colors.white.withOpacity(.08),
                      Colors.transparent,
                      Colors.white.withOpacity(.06),
                    ],
                  ),
                ),
              ),
            ),
          Icon(
            Icons.redeem_rounded,
            size: size * .42,
            color: tokens.textPrimary,
          ),
          Positioned(
            left: 10,
            right: 10,
            bottom: 10,
            child: Text(
              label,
              maxLines: 1,
              textAlign: TextAlign.center,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                color: tokens.textPrimary.withOpacity(.92),
                fontWeight: FontWeight.w800,
                fontSize: math.max(10, size * .08),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
