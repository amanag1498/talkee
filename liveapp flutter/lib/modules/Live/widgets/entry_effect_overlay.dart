import 'dart:async';
import 'dart:collection';
import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../../app/theme/brand.dart';
import '../../../app/widgets/remote_media_art.dart';
import '../../../services/app_settings_service.dart';
import '../models/live_entry_effect_event.dart';

class EntryEffectOverlay extends StatefulWidget {
  const EntryEffectOverlay({
    super.key,
    required this.roomId,
    this.events,
    this.initialEffect,
  });

  final String roomId;
  final Stream<Map<String, dynamic>>? events;
  final Map<String, dynamic>? initialEffect;

  @override
  State<EntryEffectOverlay> createState() => _EntryEffectOverlayState();
}

class _EntryEffectOverlayState extends State<EntryEffectOverlay>
    with TickerProviderStateMixin {
  final List<LiveEntryEffectEvent> _queue = <LiveEntryEffectEvent>[];
  final LinkedHashSet<String> _seen = LinkedHashSet<String>();
  StreamSubscription<Map<String, dynamic>>? _eventsSub;
  AnimationController? _main;
  AnimationController? _pulse;
  LiveEntryEffectEvent? _current;

  @override
  void initState() {
    super.initState();
    _bindStream();
    if (widget.initialEffect != null) {
      _enqueueRaw(widget.initialEffect!);
    }
  }

  @override
  void didUpdateWidget(covariant EntryEffectOverlay oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.events != widget.events) {
      _bindStream();
    }
    if (widget.initialEffect != null &&
        oldWidget.initialEffect != widget.initialEffect) {
      _enqueueRaw(widget.initialEffect!);
    }
  }

  void _bindStream() {
    _eventsSub?.cancel();
    final stream = widget.events;
    if (stream == null) return;
    _eventsSub = stream.listen(_enqueueRaw);
  }

  void _enqueueRaw(Map<String, dynamic> raw) {
    if (raw['room_id']?.toString() != widget.roomId) return;

    final event = LiveEntryEffectEvent.fromJson(raw);
    if (event.roomId.isEmpty ||
        event.userId == 0 ||
        event.entryPackId == 0 ||
        event.isExpired)
      return;
    if (_seen.contains(event.dedupeKey)) return;

    _seen.add(event.dedupeKey);
    while (_seen.length > 50) {
      _seen.remove(_seen.first);
    }

    _queue.add(event);
    _queue.sort((a, b) {
      final priorityCompare = b.priority.compareTo(a.priority);
      if (priorityCompare != 0) return priorityCompare;
      return a.triggeredAt.compareTo(b.triggeredAt);
    });

    if (_current == null) {
      _playNext();
    }
  }

  void _playNext() {
    if (!mounted) return;
    while (_queue.isNotEmpty && _queue.first.isExpired) {
      _queue.removeAt(0);
    }
    if (_queue.isEmpty) {
      setState(() => _current = null);
      return;
    }

    final event = _queue.removeAt(0);
    _main?.dispose();
    _pulse?.dispose();
    _main = AnimationController(
      vsync: this,
      duration: Duration(milliseconds: event.durationMs),
    );
    _pulse = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 900),
    )..repeat(reverse: true);

    _main!.addStatusListener((status) {
      if (status == AnimationStatus.completed) {
        _pulse?.stop();
        if (!mounted) return;
        Future<void>.delayed(const Duration(milliseconds: 120), _playNext);
      }
    });

    setState(() => _current = event);
    _main!.forward(from: 0);
  }

  @override
  void dispose() {
    _eventsSub?.cancel();
    _main?.dispose();
    _pulse?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final event = _current;
    final main = _main;
    final pulse = _pulse;
    if (event == null || main == null || pulse == null) {
      return const SizedBox.shrink();
    }

    return IgnorePointer(
      ignoring: true,
      child: AnimatedBuilder(
        animation: Listenable.merge([main, pulse]),
        builder: (context, _) {
          final t = Curves.easeOutCubic.transform(main.value.clamp(0.0, 1.0));
          final pulseValue = Curves.easeInOut.transform(
            pulse.value.clamp(0.0, 1.0),
          );
          switch (event.animationStyle) {
            case 'center':
              return _CenterEntryEffect(
                event: event,
                progress: t,
                pulse: pulseValue,
              );
            case 'fullscreen':
              return _FullscreenEntryEffect(
                event: event,
                progress: t,
                pulse: pulseValue,
              );
            case 'banner':
            default:
              return _BannerEntryEffect(event: event, progress: t);
          }
        },
      ),
    );
  }
}

PremiumThemeTokens _entryTokens() => getPremiumThemeTokens(
  Get.find<AppSettingsService>().activePremiumThemeVariant,
);

class _BannerEntryEffect extends StatelessWidget {
  const _BannerEntryEffect({required this.event, required this.progress});

  final LiveEntryEffectEvent event;
  final double progress;

  @override
  Widget build(BuildContext context) {
    final tokens = _entryTokens();
    final fadeOut =
        progress > .76 ? 1 - ((progress - .76) / .24).clamp(0.0, 1.0) : 1.0;
    final enter = Curves.easeOutBack.transform(
      (progress / .45).clamp(0.0, 1.0),
    );
    final translateY = (1 - enter) * -84;

    return Opacity(
      opacity: fadeOut.clamp(0.0, 1.0),
      child: Transform.translate(
        offset: Offset(0, translateY),
        child: Align(
          alignment: Alignment.topCenter,
          child: SafeArea(
            bottom: false,
            child: Container(
              margin: const EdgeInsets.fromLTRB(16, 12, 16, 0),
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(22),
                gradient: LinearGradient(
                  colors: [
                    tokens.cardGradient.first,
                    tokens.primaryButtonGradient.last.withValues(alpha: .88),
                  ],
                ),
                border: Border.all(color: tokens.borderColor.withOpacity(.34)),
                boxShadow: [
                  BoxShadow(
                    color: tokens.glowColor.withOpacity(.25),
                    blurRadius: 26,
                    offset: const Offset(0, 10),
                  ),
                ],
              ),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  _EffectArt(
                    assetUrl: event.svgUrl,
                    assetType: event.assetType,
                    size: 54,
                  ),
                  const SizedBox(width: 12),
                  Flexible(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(
                          event.userName,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                            color: Colors.white,
                            fontWeight: FontWeight.w900,
                            fontSize: 16,
                          ),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          '${event.entryPackName} entered the room',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(
                            color: Colors.white.withOpacity(.78),
                            fontWeight: FontWeight.w600,
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
    );
  }
}

class _CenterEntryEffect extends StatelessWidget {
  const _CenterEntryEffect({
    required this.event,
    required this.progress,
    required this.pulse,
  });

  final LiveEntryEffectEvent event;
  final double progress;
  final double pulse;

  @override
  Widget build(BuildContext context) {
    final tokens = _entryTokens();
    final fade =
        progress > .82 ? 1 - ((progress - .82) / .18).clamp(0.0, 1.0) : 1.0;
    final scale = .8 + (math.min(progress, .55) / .55) * .3;

    return Stack(
      children: [
        Positioned.fill(
          child: Opacity(
            opacity: (.28 * fade).clamp(0.0, 1.0),
            child: const ColoredBox(color: Colors.black),
          ),
        ),
        Center(
          child: Opacity(
            opacity: fade.clamp(0.0, 1.0),
            child: Transform.scale(
              scale: scale,
              child: Container(
                width: 280,
                padding: const EdgeInsets.fromLTRB(20, 22, 20, 18),
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(28),
                  gradient: LinearGradient(
                    colors: [
                      tokens.cardGradient.first,
                      tokens.primaryButtonGradient.last,
                    ],
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                  ),
                  boxShadow: [
                    BoxShadow(
                      color: tokens.glowColor.withOpacity(.18 + (pulse * .18)),
                      blurRadius: 28 + (pulse * 14),
                    ),
                  ],
                ),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    _EffectArt(
                      assetUrl: event.svgUrl,
                      assetType: event.assetType,
                      size: 120,
                    ),
                    const SizedBox(height: 14),
                    Text(
                      event.userName,
                      textAlign: TextAlign.center,
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 20,
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                    const SizedBox(height: 6),
                    Text(
                      '${event.entryPackName} arrival',
                      textAlign: TextAlign.center,
                      style: TextStyle(
                        color: Colors.white.withOpacity(.78),
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ],
    );
  }
}

class _FullscreenEntryEffect extends StatelessWidget {
  const _FullscreenEntryEffect({
    required this.event,
    required this.progress,
    required this.pulse,
  });

  final LiveEntryEffectEvent event;
  final double progress;
  final double pulse;

  @override
  Widget build(BuildContext context) {
    final fade =
        progress > .84 ? 1 - ((progress - .84) / .16).clamp(0.0, 1.0) : 1.0;
    final scale = .96 + (math.min(progress, .6) / .6) * .04;
    final mediaSize = MediaQuery.sizeOf(context);

    return Stack(
      children: [
        Positioned.fill(
          child: Opacity(
            opacity: (.38 * fade).clamp(0.0, 1.0),
            child: const ColoredBox(color: Colors.black),
          ),
        ),
        Positioned.fill(
          child: Opacity(
            opacity: fade.clamp(0.0, 1.0),
            child: Transform.scale(
              scale: scale,
              child: RemoteMediaArt(
                url: event.svgUrl,
                explicitType: event.assetType,
                width: mediaSize.width,
                height: mediaSize.height,
                fit: BoxFit.cover,
                fallback: _EffectArt(
                  assetUrl: event.svgUrl,
                  assetType: event.assetType,
                  size: math.max(mediaSize.width, mediaSize.height),
                )._fallback(math.max(mediaSize.width, mediaSize.height)),
              ),
            ),
          ),
        ),
      ],
    );
  }
}

class _EffectArt extends StatelessWidget {
  const _EffectArt({
    required this.assetUrl,
    required this.assetType,
    required this.size,
  });

  final String? assetUrl;
  final String? assetType;
  final double size;

  @override
  Widget build(BuildContext context) {
    return RemoteMediaArt(
      url: assetUrl,
      explicitType: assetType,
      width: size,
      height: size,
      fallback: _fallback(size),
    );
  }

  Widget _fallback(double size) {
    final tokens = _entryTokens();
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(size * .28),
        gradient: LinearGradient(
          colors: [tokens.primaryButtonGradient.first, tokens.dangerColor],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
      ),
      child: Icon(
        Icons.auto_awesome_rounded,
        color: Colors.white,
        size: size * .48,
      ),
    );
  }
}
