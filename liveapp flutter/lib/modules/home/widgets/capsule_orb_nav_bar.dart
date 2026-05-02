import 'dart:ui';

import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../../services/app_settings_service.dart';
import '../../../app/theme/brand.dart';

class GlassTabItem {
  final IconData icon;
  final String label;
  const GlassTabItem({required this.icon, required this.label});
}

class CapsuleOrbNavBar extends StatelessWidget {
  const CapsuleOrbNavBar({
    super.key,
    required this.items,
    required this.currentIndex,
    required this.onChanged,
    this.onGoLive,
    this.showGoLive = true,
    this.height = 64,
    this.radius = 32,
    this.iconSize = 18,
    this.activeAccent = const Color(0xFFD4C3FF),
    this.inactiveIcon = const Color(0x99FFFFFF),
    this.goLiveColor = const Color(0xFFFF4D8D),
  });

  final List<GlassTabItem> items;
  final int currentIndex;
  final ValueChanged<int> onChanged;
  final VoidCallback? onGoLive;
  final bool showGoLive;
  final double height;
  final double radius;
  final double iconSize;
  final Color activeAccent;
  final Color inactiveIcon;
  final Color goLiveColor;

  @override
  Widget build(BuildContext context) {
    final safeIndex = currentIndex.clamp(0, items.length - 1);
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );

    return SafeArea(
      top: false,
      minimum: const EdgeInsets.only(bottom: 8),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 18),
        child: ClipRRect(
          borderRadius: BorderRadius.circular(radius),
          child: BackdropFilter(
            filter: ImageFilter.blur(sigmaX: 16, sigmaY: 16),
            child: Container(
              height: height,
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 7),
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(radius),
                gradient: LinearGradient(
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                  colors: [
                    tokens.cardGradient.first.withValues(alpha: .94),
                    tokens.cardGradient.last.withValues(alpha: .97),
                  ],
                ),
                border: Border.all(
                  color: tokens.borderColor.withValues(alpha: .48),
                  width: 1,
                ),
                boxShadow: [
                  BoxShadow(
                    color: Colors.black.withValues(alpha: .30),
                    blurRadius: 24,
                    offset: const Offset(0, 10),
                  ),
                ],
              ),
              child: Row(
                children: [
                  Expanded(
                    child: LayoutBuilder(
                      builder: (context, constraints) {
                        final count = items.length;
                        final gap = constraints.maxWidth < 250 ? 4.0 : 6.0;
                        final totalGap = (count - 1) * gap;
                        final availableWidth =
                            (constraints.maxWidth - totalGap).clamp(0.0, constraints.maxWidth);
                        final slotWidth =
                            count > 0 ? availableWidth / count : availableWidth;
                        final iconOnly = slotWidth <= 52;
                        final compactWidth =
                            iconOnly ? slotWidth : slotWidth.clamp(38.0, 46.0);
                        final selectedWidth = iconOnly
                            ? slotWidth
                            : (availableWidth - ((count - 1) * compactWidth))
                                .clamp(compactWidth, availableWidth);
                        final showLabel = !iconOnly && selectedWidth >= 78;
                        final shortLabel = showLabel && selectedWidth < 108;

                        return Row(
                          children: List.generate(items.length, (index) {
                            final selected = index == safeIndex;
                            return Padding(
                              padding: EdgeInsets.only(
                                right: index == items.length - 1 ? 0 : gap,
                              ),
                              child: AnimatedContainer(
                                duration: const Duration(milliseconds: 280),
                                curve: Curves.easeOutCubic,
                                width: selected ? selectedWidth : compactWidth,
                                child: _OrbTabItem(
                                  item: items[index],
                                  selected: selected,
                                  showLabel: showLabel,
                                  shortLabel: shortLabel,
                                  iconSize: iconSize,
                                  activeAccent: activeAccent,
                                  inactiveIcon: inactiveIcon,
                                  tokens: tokens,
                                  onTap: () => onChanged(index),
                                ),
                              ),
                            );
                          }),
                        );
                      },
                    ),
                  ),
                  if (showGoLive) ...[
                    const SizedBox(width: 8),
                    _LiveOrbButton(
                      onTap: onGoLive,
                      color: goLiveColor,
                      tokens: tokens,
                    ),
                  ],
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _OrbTabItem extends StatefulWidget {
  const _OrbTabItem({
    required this.item,
    required this.selected,
    required this.showLabel,
    required this.shortLabel,
    required this.iconSize,
    required this.activeAccent,
    required this.inactiveIcon,
    required this.tokens,
    required this.onTap,
  });

  final GlassTabItem item;
  final bool selected;
  final bool showLabel;
  final bool shortLabel;
  final double iconSize;
  final Color activeAccent;
  final Color inactiveIcon;
  final PremiumThemeTokens tokens;
  final VoidCallback onTap;

  @override
  State<_OrbTabItem> createState() => _OrbTabItemState();
}

class _OrbTabItemState extends State<_OrbTabItem>
    with SingleTickerProviderStateMixin {
  late final AnimationController _pulse;

  @override
  void initState() {
    super.initState();
    _pulse = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1800),
    )..repeat(reverse: true);
  }

  @override
  void dispose() {
    _pulse.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _pulse,
      builder: (context, _) {
        final glow = widget.selected ? (0.18 + (_pulse.value * 0.10)) : 0.0;
        return AnimatedContainer(
          duration: const Duration(milliseconds: 280),
          curve: Curves.easeOutCubic,
          height: double.infinity,
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(24),
            gradient:
                widget.selected
                    ? LinearGradient(
                      begin: Alignment.topLeft,
                      end: Alignment.bottomRight,
                      colors: [
                        widget.tokens.cardGradient.first.withValues(alpha: .98),
                        widget.tokens.cardGradient.last.withValues(alpha: .94),
                      ],
                    )
                    : LinearGradient(
                      begin: Alignment.topLeft,
                      end: Alignment.bottomRight,
                      colors: [
                        widget.tokens.glassColor.withValues(alpha: .16),
                        widget.tokens.cardGradient.last.withValues(alpha: .56),
                      ],
                    ),
            border: Border.all(
              color:
                  widget.selected
                      ? widget.tokens.borderColor.withValues(alpha: .54)
                      : widget.tokens.borderColor.withValues(alpha: .18),
            ),
            boxShadow:
                widget.selected
                    ? [
                      BoxShadow(
                        color: widget.activeAccent.withValues(alpha: glow),
                        blurRadius: 20,
                        offset: const Offset(0, 6),
                      ),
                    ]
                    : null,
          ),
          child: Material(
            color: Colors.transparent,
            child: InkWell(
              onTap: widget.onTap,
              borderRadius: BorderRadius.circular(24),
              splashColor: Colors.white.withValues(alpha: .04),
              highlightColor: Colors.transparent,
              child: AnimatedPadding(
                duration: const Duration(milliseconds: 280),
                curve: Curves.easeOutCubic,
                padding: EdgeInsets.symmetric(
                  horizontal: widget.selected && widget.showLabel ? 8 : 0,
                ),
                child: Row(
                  mainAxisAlignment:
                      widget.selected
                          ? (widget.showLabel
                              ? MainAxisAlignment.start
                              : MainAxisAlignment.center)
                          : MainAxisAlignment.center,
                  children: [
                    Container(
                      width: 34,
                      height: 34,
                      decoration: BoxDecoration(
                        shape: BoxShape.circle,
                        gradient:
                            widget.selected
                                ? LinearGradient(
                                  begin: Alignment.topLeft,
                                  end: Alignment.bottomRight,
                                  colors: [
                                    widget.activeAccent,
                                    widget.tokens.primaryButtonGradient.last,
                                  ],
                                )
                                : null,
                        color:
                            widget.selected
                                ? null
                                : widget.tokens.glassColor.withValues(
                                  alpha: .10,
                                ),
                        boxShadow:
                            widget.selected
                                ? [
                                  BoxShadow(
                                    color: widget.activeAccent.withValues(
                                      alpha: .30,
                                    ),
                                    blurRadius: 14,
                                    offset: const Offset(0, 4),
                                  ),
                                ]
                                : null,
                      ),
                      child: Icon(
                        widget.item.icon,
                        size: widget.iconSize,
                        color:
                            widget.selected
                                ? widget.tokens.cardGradient.first
                                : widget.inactiveIcon,
                      ),
                    ),
                    if (widget.selected && widget.showLabel)
                      Flexible(
                        child: AnimatedSize(
                          duration: const Duration(milliseconds: 220),
                          curve: Curves.easeOutCubic,
                          child: Padding(
                            padding: const EdgeInsets.only(left: 10, right: 2),
                            child: Text(
                              _navLabel(
                                widget.item.label,
                                short: widget.shortLabel,
                              ),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              softWrap: false,
                              style: const TextStyle(
                                color: Colors.white,
                                fontSize: 12,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                          ),
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

String _navLabel(String label, {required bool short}) {
  if (!short) return label;
  switch (label) {
    case 'Video Rooms':
      return 'Video';
    case 'Audio Rooms':
      return 'Audio';
    case 'Live Users':
      return 'Users';
    case 'Settings':
      return 'Prefs';
    default:
      return label;
  }
}

class _LiveOrbButton extends StatefulWidget {
  const _LiveOrbButton({
    required this.onTap,
    required this.color,
    required this.tokens,
  });

  final VoidCallback? onTap;
  final Color color;
  final PremiumThemeTokens tokens;

  @override
  State<_LiveOrbButton> createState() => _LiveOrbButtonState();
}

class _LiveOrbButtonState extends State<_LiveOrbButton>
    with SingleTickerProviderStateMixin {
  late final AnimationController _pulse;

  @override
  void initState() {
    super.initState();
    _pulse = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1500),
    )..repeat(reverse: true);
  }

  @override
  void dispose() {
    _pulse.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final enabled = widget.onTap != null;
    return AnimatedBuilder(
      animation: _pulse,
      builder: (context, _) {
        final glow = enabled ? (0.22 + (_pulse.value * 0.10)) : 0.0;
        return SizedBox(
          width: 52,
          child: Material(
            color: Colors.transparent,
            child: InkWell(
              onTap: widget.onTap,
              borderRadius: BorderRadius.circular(22),
              child: Container(
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(22),
                  gradient: LinearGradient(
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                    colors:
                        enabled
                            ? [
                              widget.color.withValues(alpha: .96),
                              widget.tokens.dangerColor.withValues(alpha: .92),
                            ]
                            : [
                              widget.tokens.cardGradient.first.withValues(
                                alpha: .58,
                              ),
                              widget.tokens.cardGradient.last.withValues(
                                alpha: .52,
                              ),
                            ],
                  ),
                  border: Border.all(
                    color: widget.tokens.borderColor.withValues(
                      alpha: enabled ? .62 : .24,
                    ),
                  ),
                  boxShadow:
                      enabled
                          ? [
                            BoxShadow(
                              color: widget.color.withValues(alpha: glow),
                              blurRadius: 16,
                              offset: const Offset(0, 6),
                            ),
                          ]
                          : null,
                ),
                child: const Center(
                  child: Icon(
                    Icons.radio_button_checked_rounded,
                    size: 18,
                    color: Colors.white,
                  ),
                ),
              ),
            ),
          ),
        );
      },
    );
  }
}
