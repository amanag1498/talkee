import 'package:flutter/material.dart';

class FramedAvatar extends StatelessWidget {
  const FramedAvatar({
    super.key,
    required this.size,
    required this.label,
    this.avatarUrl,
    this.frameUrl,
    this.textColor = Colors.white,
    this.backgroundColor = Colors.transparent,
    this.avatarInset = 0.09,
    this.borderRadius,
    this.frameScale = 1.14,
  });

  final double size;
  final String label;
  final String? avatarUrl;
  final String? frameUrl;
  final Color textColor;
  final Color backgroundColor;
  final double avatarInset;
  final double? borderRadius;
  final double frameScale;

  @override
  Widget build(BuildContext context) {
    final trimmedAvatar = avatarUrl?.trim();
    final trimmedFrame = frameUrl?.trim();
    final radius = borderRadius ?? size / 2;
    final inset = size * _resolvedInset(trimmedFrame);
    final hasAvatar = trimmedAvatar != null && trimmedAvatar.isNotEmpty;
    final hasFrame = trimmedFrame != null && trimmedFrame.isNotEmpty;
    final initial = label.trim().isNotEmpty ? label.trim().characters.first.toUpperCase() : '?';
    final resolvedBackground = hasAvatar && hasFrame ? Colors.transparent : backgroundColor;

    return SizedBox(
      width: size,
      height: size,
      child: Stack(
        clipBehavior: Clip.none,
        fit: StackFit.expand,
        children: [
          Positioned.fill(
            child: Padding(
              padding: EdgeInsets.all(inset),
              child: DecoratedBox(
                decoration: BoxDecoration(
                  color: resolvedBackground,
                  shape: BoxShape.circle,
                ),
                child: ClipOval(
                  child: hasAvatar
                      ? Image.network(
                          trimmedAvatar,
                          fit: BoxFit.cover,
                          errorBuilder: (_, __, ___) => _FallbackInitial(
                            initial: initial,
                            textColor: textColor,
                          ),
                        )
                      : _FallbackInitial(
                          initial: initial,
                          textColor: textColor,
                        ),
                ),
              ),
            ),
          ),
          if (hasFrame)
            Positioned.fill(
              child: IgnorePointer(
                child: Transform.scale(
                  scale: _resolvedFrameScale(trimmedFrame),
                  child: RepaintBoundary(
                    child: Image.network(
                      trimmedFrame,
                      fit: BoxFit.cover,
                      alignment: Alignment.center,
                      filterQuality: FilterQuality.high,
                      errorBuilder: (_, __, ___) => const SizedBox.shrink(),
                    ),
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }

  double _resolvedInset(String? frameUrl) {
    final url = frameUrl?.toLowerCase() ?? '';
    if (url.contains('host-sovereign-crest') || url.contains('lion-king-crest')) {
      return 0.06;
    }
    if (url.contains('crown_') || url.contains('crown-') || url.contains('crown')) {
      return 0.07;
    }
    return avatarInset;
  }

  double _resolvedFrameScale(String? frameUrl) {
    final url = frameUrl?.toLowerCase() ?? '';
    if (url.contains('host-sovereign-crest') || url.contains('lion-king-crest')) {
      return frameScale + 0.18;
    }
    if (url.contains('crown_') || url.contains('crown-') || url.contains('crown')) {
      return frameScale + 0.16;
    }
    return frameScale;
  }
}

class _FallbackInitial extends StatelessWidget {
  const _FallbackInitial({
    required this.initial,
    required this.textColor,
  });

  final String initial;
  final Color textColor;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Text(
        initial,
        style: TextStyle(
          color: textColor,
          fontWeight: FontWeight.w900,
          fontSize: 22,
        ),
      ),
    );
  }
}
