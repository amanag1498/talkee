// lib/modules/subscriptions/widgets/choose_plan_sheet.dart
import 'dart:math' as math;
import 'dart:ui' show ImageFilter;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:intl/intl.dart';

import '../../../app/theme/brand.dart';
import '../../../services/app_settings_service.dart';
import '../../wallet/services/wallet_api.dart';
import '../models/subscription_plan_dto.dart';

PremiumThemeTokens _choosePlanTokens() => getPremiumThemeTokens(
  Get.find<AppSettingsService>().activePremiumThemeVariant,
);

class ChoosePlanSheet extends StatefulWidget {
  final List<SubscriptionPlanDto> plans;

  const ChoosePlanSheet({
    super.key,
    required this.plans,
  });

  static Future<SubscriptionPlanDto?> show(
      BuildContext context, {
        required List<SubscriptionPlanDto> plans,
      }) {
    return showModalBottomSheet<SubscriptionPlanDto>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      backgroundColor: Colors.transparent,
      barrierColor: Colors.black.withOpacity(.55),
      builder: (_) => ChoosePlanSheet(plans: plans),
    );
  }

  @override
  State<ChoosePlanSheet> createState() => _ChoosePlanSheetState();
}

class _ChoosePlanSheetState extends State<ChoosePlanSheet>
    with TickerProviderStateMixin {
  late final AnimationController _bgMotion;   // background blobs
  late final AnimationController _shineCtrl;  // card shine sweep
  late final AnimationController _ctaPulse;   // CTA micro pulse

  int? _selectedId;
  int? _walletBalanceCoins;

  @override
  void initState() {
    super.initState();
    _selectedId = widget.plans.isNotEmpty ? widget.plans.first.id : null;

    _bgMotion  = AnimationController(vsync: this, duration: const Duration(seconds: 18))..repeat();
    _shineCtrl = AnimationController(vsync: this, duration: const Duration(seconds: 5))..repeat();
    _ctaPulse  = AnimationController(vsync: this, duration: const Duration(milliseconds: 1300))
      ..repeat(reverse: true);
    _loadWalletBalance();
  }

  Future<void> _loadWalletBalance() async {
    try {
      final summary = await Get.find<WalletApi>().fetchSummary();
      if (!mounted) return;
      setState(() => _walletBalanceCoins = summary.balance);
    } catch (_) {
      // Balance is contextual metadata for the sheet; keep the flow working if it fails.
    }
  }

  @override
  void dispose() {
    _bgMotion.dispose();
    _shineCtrl.dispose();
    _ctaPulse.dispose();
    super.dispose();
  }

  SubscriptionPlanDto? get _selected {
    if (_selectedId == null) return null;
    return widget.plans.firstWhere((p) => p.id == _selectedId, orElse: () => widget.plans.first);
  }

  // pick “most popular” by best value (duration/price), nudge to middle if edge
  int _popularIndexFor(List<SubscriptionPlanDto> plans) {
    if (plans.isEmpty) return 0;
    double best = -1;
    int idx = 0;
    for (var i = 0; i < plans.length; i++) {
      final p = plans[i];
      final price = (p.priceCoins <= 0) ? 1 : p.priceCoins;
      final score = p.durationDays / price;
      if (score > best) { best = score; idx = i; }
    }
    if ((plans.length == 3 || plans.length == 4) && (idx == 0 || idx == plans.length - 1)) {
      return (plans.length / 2).floor();
    }
    return idx;
  }

  @override
  Widget build(BuildContext context) {
    final tokens = _choosePlanTokens();
    final maxH = MediaQuery.of(context).size.height * .92;
    final popularIdx = _popularIndexFor(widget.plans);

    return Stack(
      children: [
        // animated purple glassy background
        Positioned.fill(child: _GlassyBackdrop(t: _bgMotion)),

        // frosted glass panel
        Align(
          alignment: Alignment.bottomCenter,
          child: ClipRRect(
            borderRadius: const BorderRadius.vertical(top: Radius.circular(28)),
            child: BackdropFilter(
              filter: ImageFilter.blur(sigmaX: 18, sigmaY: 18),
                child: Container(
                  decoration: BoxDecoration(
                  color: tokens.cardGradient.last.withOpacity(.88),
                  border: Border(
                    top: BorderSide(color: tokens.borderColor.withOpacity(.72)),
                  ),
                  boxShadow: [
                    BoxShadow(color: Colors.black.withOpacity(.5), blurRadius: 32, offset: const Offset(0, -12)),
                  ],
                ),
                child: SafeArea(
                  top: false,
                  child: ConstrainedBox(
                    constraints: BoxConstraints(maxHeight: maxH),
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(16, 14, 16, 16),
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          // grabber
                          Container(
                            width: 44, height: 4,
                            decoration: BoxDecoration(
                              color: tokens.borderColor.withOpacity(.72),
                              borderRadius: BorderRadius.circular(999),
                            ),
                          ),
                          const SizedBox(height: 14),

                          // header
                          const _Header(),
                          const SizedBox(height: 6),
                          Text(
                            'Instant access after purchase. Cancel anytime.',
                            textAlign: TextAlign.center,
                            style: TextStyle(
                              color: tokens.textSecondary.withOpacity(.86),
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                          if (_walletBalanceCoins != null) ...[
                            const SizedBox(height: 12),
                            _BalancePill(
                              label:
                                  '${NumberFormat.compact().format(_walletBalanceCoins)} coins available',
                            ),
                          ],
                          const SizedBox(height: 16),

                          // LIST (previous design) with premium visuals
                          Flexible(
                            child: ListView.separated(
                              padding: const EdgeInsets.only(bottom: 12),
                              itemCount: widget.plans.length,
                              separatorBuilder: (_, __) => const SizedBox(height: 12),
                              itemBuilder: (_, i) {
                                final plan = widget.plans[i];
                                final selected = plan.id == _selectedId;
                                final popular  = i == popularIdx;
                                final palette  = _TierPalette.fromName(plan.name);
                                return _PlanListCard(
                                  plan: plan,
                                  selected: selected,
                                  popular: popular,
                                  palette: palette,
                                  shineT: _shineCtrl,
                                  onTap: () {
                                    HapticFeedback.selectionClick();
                                    setState(() => _selectedId = plan.id);
                                  },
                                );
                              },
                            ),
                          ),

                          const SizedBox(height: 8),

                          // CTA
                          Row(
                            children: [
                              Expanded(
                                child: ScaleTransition(
                                  scale: Tween(begin: .98, end: 1.0).animate(
                                    CurvedAnimation(parent: _ctaPulse, curve: Curves.easeInOut),
                                  ),
                                  child: _NeoCtaButton(
    label: _selected == null
    ? 'Select a plan'
        : 'Unlock for ${_selected!.priceCoins} coins',
    loading: false,
    enabled: _selected != null,
    shineT: _shineCtrl, // reuse your shine controller
    onPressed: _selected == null
    ? null
        : () async {
    final plan = _selected!;
    Navigator.of(context).pop(plan);
    },
    ),
                                ),
                              ),
                            ],
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
      ],
    );
  }
}

class _BtnRow extends StatelessWidget {
  final IconData icon;
  final String label;
  const _BtnRow({required this.icon, required this.label});
  @override
  Widget build(BuildContext context) {
    return Row(mainAxisAlignment: MainAxisAlignment.center, children: [
      Icon(icon),
      const SizedBox(width: 10),
      Text(label, style: const TextStyle(fontWeight: FontWeight.w900)),
    ]);
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Header (gradient title + glow)
// ─────────────────────────────────────────────────────────────────────────────
class _Header extends StatelessWidget {
  const _Header();

  @override
  Widget build(BuildContext context) {
    final tokens = _choosePlanTokens();
    final g = LinearGradient(
      colors: tokens.primaryButtonGradient,
      begin: Alignment.topLeft,
      end: Alignment.bottomRight,
    );
    return Stack(
      clipBehavior: Clip.none,
      children: [
        Positioned.fill(
          top: 8,
          child: IgnorePointer(
            child: Container(
              decoration: const BoxDecoration(
                gradient: RadialGradient(radius: .85, colors: [Color(0x334C2B93), Colors.transparent]),
              ),
            ),
          ),
        ),
        Center(
          child: ShaderMask(
            shaderCallback: (r) => g.createShader(r),
            child: const Text(
              'Unlock live streams',
              textAlign: TextAlign.center,
              style: TextStyle(color: Colors.white, fontWeight: FontWeight.w900, fontSize: 22, letterSpacing: .2),
            ),
          ),
        ),
      ],
    );
  }
}

class _BalancePill extends StatelessWidget {
  const _BalancePill({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    final tokens = _choosePlanTokens();
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: tokens.chipColor.withOpacity(.82),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: tokens.borderColor.withOpacity(.9)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(
            Icons.account_balance_wallet_rounded,
            size: 16,
            color: tokens.primaryButtonGradient.first,
          ),
          const SizedBox(width: 8),
          Text(
            label,
            style: TextStyle(
              color: tokens.textPrimary,
              fontWeight: FontWeight.w800,
            ),
          ),
        ],
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Animated purple background (soft blobs)
// ─────────────────────────────────────────────────────────────────────────────
class _GlassyBackdrop extends StatelessWidget {
  final Animation<double> t;
  const _GlassyBackdrop({required this.t});
  @override
  Widget build(BuildContext context) {
    final tokens = _choosePlanTokens();
    return AnimatedBuilder(
      animation: t,
      builder:
          (_, __) => CustomPaint(
            painter: _BlobPainter(
              t.value,
              colors: [
                tokens.primaryButtonGradient.first,
                tokens.cardGradient.first,
                tokens.glowColor,
              ],
            ),
          ),
    );
  }
}

class _BlobPainter extends CustomPainter {
  final double t;
  final List<Color> colors;
  _BlobPainter(this.t, {required this.colors});

  @override
  void paint(Canvas canvas, Size size) {
    final w = size.width, h = size.height;

    void blob(Offset base, double r, Color c, double drift, double phase) {
      final dx = math.sin((t * 2 * math.pi) + phase) * drift;
      final dy = math.cos((t * 2 * math.pi) + phase) * (drift * .6);
      final center = base + Offset(dx, dy);
      final paint = Paint()
        ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 64)
        ..color = c.withOpacity(.45);
      canvas.drawCircle(center, r, paint);
    }

    blob(Offset(w * .25, h * .35), h * .40, colors[0], 28, 0.0);
    blob(Offset(w * .75, h * .30), h * .36, colors[1], 36, 1.1);
    blob(Offset(w * .60, h * .70), h * .42, colors[2], 30, 2.2);
  }

  @override
  bool shouldRepaint(covariant _BlobPainter old) =>
      old.t != t || old.colors != colors;
}

// ─────────────────────────────────────────────────────────────────────────────
// Tier palette mapping by plan name (Bronze/Silver/Gold/Platinum)
// ─────────────────────────────────────────────────────────────────────────────
class _TierPalette {
  final Color ringA, ringB; // sweep ring
  final Color bgA, bgB;     // inner gradient
  final Color badge;        // ribbon color

  const _TierPalette(this.ringA, this.ringB, this.bgA, this.bgB, this.badge);

  factory _TierPalette.fromName(String name) {
    final n = name.toLowerCase();
    if (n.contains('bronze')) {
      return const _TierPalette(Color(0xFFB87333), Color(0xFF8C5A28), Color(0xFF24160C), Color(0xFF1A120D), Color(0xFFB87333));
    } else if (n.contains('silver')) {
      return const _TierPalette(Color(0xFFC0C0C0), Color(0xFF9FA4AD), Color(0xFF1E2330), Color(0xFF131826), Color(0xFFC0C0C0));
    } else if (n.contains('gold')) {
      return const _TierPalette(Color(0xFFFFD54F), Color(0xFFFFB300), Color(0xFF2A1E08), Color(0xFF1A1407), Color(0xFFFFC107));
    } else if (n.contains('platinum')) {
      return const _TierPalette(Color(0xFFE0E0E0), Color(0xFFB0BEC5), Color(0xFF1E2226), Color(0xFF121417), Color(0xFFB0BEC5));
    }
    return const _TierPalette(Color(0xFF7B50C5), Color(0xFF3E2374), Color(0xFF23143F), Color(0xFF170E2E), Color(0xFFFF4D67));
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// LIST CARD (previous layout) with ribbon, shine, perks, palette
// ─────────────────────────────────────────────────────────────────────────────
class _PlanListCard extends StatelessWidget {
  final SubscriptionPlanDto plan;
  final bool selected;
  final bool popular;
  final _TierPalette palette;
  final AnimationController shineT;
  final VoidCallback onTap;

  const _PlanListCard({
    required this.plan,
    required this.selected,
    required this.popular,
    required this.palette,
    required this.shineT,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final perks = plan.perks.take(3).toList();

    return GestureDetector(
      onTap: onTap,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 220),
        curve: Curves.easeOutCubic,
        padding: const EdgeInsets.all(2), // gradient ring thickness
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(18),
          gradient: selected
              ? SweepGradient(
            colors: [palette.ringA, palette.ringB, palette.ringA],
            transform: GradientRotation(shineT.value * 2 * math.pi),
          )
              : null,
          color: selected ? null : Colors.white.withOpacity(.08),
          boxShadow: selected
              ? [BoxShadow(color: palette.ringA.withOpacity(.35), blurRadius: 20, offset: const Offset(0, 8))]
              : null,
        ),
        child: Container(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(16),
            color: Colors.white.withOpacity(.06),
            border: Border.all(color: Colors.white.withOpacity(.16)),
          ),
          clipBehavior: Clip.antiAlias,
          child: Stack(
            clipBehavior: Clip.none,
            children: [
              // inner gradient
              Positioned.fill(
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    gradient: LinearGradient(
                      colors: selected
                          ? [palette.bgA.withOpacity(.78), palette.bgB.withOpacity(.78)]
                          : [palette.bgA.withOpacity(.60), palette.bgB.withOpacity(.60)],
                      begin: Alignment.topLeft,
                      end: Alignment.bottomRight,
                    ),
                  ),
                ),
              ),
              // diagonal shine
              Positioned.fill(
                child: IgnorePointer(
                  child: AnimatedBuilder(
                    animation: shineT,
                    builder: (_, __) => CustomPaint(painter: _ShineStripe(t: shineT.value)),
                  ),
                ),
              ),
              // MOST POPULAR ribbon (top-left corner, outside a bit)
              if (popular)
                Positioned(
                  top: 20,
                  left: -45,
                  child: Transform.rotate(
                    angle: -math.pi / 4,
                    child: Container(
                      width: 160,
                      height: 28,
                      alignment: Alignment.center,
                      decoration: BoxDecoration(
                        gradient: LinearGradient(
                          colors: [palette.badge, palette.badge.withOpacity(.85)],
                          begin: Alignment.centerLeft,
                          end: Alignment.centerRight,
                        ),
                        borderRadius: BorderRadius.circular(6),
                        boxShadow: [BoxShadow(color: Colors.black.withOpacity(.35), blurRadius: 12, offset: const Offset(0, 8))],
                      ),
                      child: const Text(
                        'MOST POPULAR',
                        style: TextStyle(color: Colors.white, fontWeight: FontWeight.w900, fontSize: 11, letterSpacing: 1.0),
                      ),
                    ),
                  ),
                ),

              // content
              Padding(
                padding: const EdgeInsets.fromLTRB(14, 14, 14, 14),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    // radio-ish dot
                    AnimatedContainer(
                      duration: const Duration(milliseconds: 220),
                      width: 22,
                      height: 22,
                      decoration: BoxDecoration(
                        shape: BoxShape.circle,
                        border: Border.all(color: selected ? Colors.white : Colors.white.withOpacity(.65), width: 2),
                      ),
                      child: AnimatedContainer(
                        duration: const Duration(milliseconds: 220),
                        margin: const EdgeInsets.all(3),
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          color: selected ? Colors.white : Colors.transparent,
                        ),
                      ),
                    ),
                    const SizedBox(width: 12),

                    // details
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          // name + price
                          Row(
                            children: [
                              Expanded(
                                child: Text(
                                  plan.name,
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w900, fontSize: 16),
                                ),
                              ),
                              const SizedBox(width: 8),
                              _PricePill(coins: plan.priceCoins, anim: shineT),
                            ],
                          ),
                          const SizedBox(height: 4),
                          Text(
                            '${plan.durationDays} days access',
                            style: TextStyle(color: Colors.white.withOpacity(.86), fontWeight: FontWeight.w600),
                          ),
                          if (perks.isNotEmpty) const SizedBox(height: 10),
                          // perks
                          ...perks.map((text) => Padding(
                            padding: const EdgeInsets.only(bottom: 6),
                            child: Row(
                              children: [
                                const Icon(Icons.check_circle_rounded, size: 18, color: Colors.white),
                                const SizedBox(width: 8),
                                Expanded(
                                  child: Text(
                                    text,
                                    style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700),
                                  ),
                                ),
                              ],
                            ),
                          )),
                          if (perks.isEmpty)
                            Text('Includes premium access',
                                style: TextStyle(color: Colors.white.withOpacity(.85), fontWeight: FontWeight.w700)),
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
    );
  }
}

// diagonal shine stripe painter (re-usable)
class _ShineStripe extends CustomPainter {
  final double t;
  const _ShineStripe({required this.t});

  @override
  void paint(Canvas canvas, Size size) {
    final dx = _lerp(-size.width * .5, size.width * 1.2, t);
    final rect = Rect.fromLTWH(dx, -size.height * .2, size.width * .3, size.height * 1.4);
    final paint = Paint()
      ..shader = LinearGradient(
        begin: Alignment.topLeft,
        end: Alignment.bottomRight,
        colors: [Colors.white.withOpacity(0.0), Colors.white.withOpacity(0.12), Colors.white.withOpacity(0.0)],
        stops: const [0.0, 0.5, 1.0],
      ).createShader(rect);
    canvas.save();
    canvas.transform(Matrix4.rotationZ(-0.6).storage);
    canvas.drawRRect(RRect.fromRectAndRadius(rect, const Radius.circular(24)), paint);
    canvas.restore();
  }

  double _lerp(double a, double b, double t) => a + (b - a) * t;
  @override
  bool shouldRepaint(covariant _ShineStripe old) => old.t != t;
}

// price pill with gentle pulse
class _PricePill extends StatelessWidget {
  final int coins;
  final Animation<double> anim;
  const _PricePill({required this.coins, required this.anim});

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: anim,
      builder: (_, __) {
        final pulse = 1 + (math.sin(anim.value * 2 * math.pi) * 0.03);
        return Transform.scale(
          scale: pulse,
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
            decoration: BoxDecoration(
              color: Colors.white.withOpacity(.14),
              borderRadius: BorderRadius.circular(999),
              border: Border.all(color: Colors.white.withOpacity(.22)),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                ShaderMask(
                  shaderCallback: (r) => const LinearGradient(
                    colors: [Color(0xFFFFC107), Color(0xFFFFE082)],
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                  ).createShader(r),
                  child: const Icon(Icons.monetization_on_rounded, size: 16, color: Colors.white),
                ),
                const SizedBox(width: 6),
                Text('$coins', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w900)),
              ],
            ),
          ),
        );
      },
    );
  }
}
class _NeoCtaButton extends StatefulWidget {
  final String label;
  final bool loading;
  final bool enabled;
  final Animation<double> shineT;
  final VoidCallback? onPressed;

  const _NeoCtaButton({
    required this.label,
    required this.loading,
    required this.enabled,
    required this.shineT,
    required this.onPressed,
  });

  @override
  State<_NeoCtaButton> createState() => _NeoCtaButtonState();
}

class _NeoCtaButtonState extends State<_NeoCtaButton>
    with SingleTickerProviderStateMixin {
  late final AnimationController _press; // press scale
  bool _down = false;

  @override
  void initState() {
    super.initState();
    _press = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 140),
      lowerBound: 0.0,
      upperBound: 1.0,
    );
  }

  @override
  void dispose() {
    _press.dispose();
    super.dispose();
  }

  void _setDown(bool v) {
    if (_down == v) return;
    setState(() => _down = v);
    if (v) {
      _press.forward(from: 0);
      HapticFeedback.selectionClick();
    } else {
      _press.reverse();
    }
  }

  @override
  Widget build(BuildContext context) {
    final tokens = _choosePlanTokens();
    final canTap = widget.enabled && !widget.loading;

    final scale = Tween<double>(begin: 1.0, end: 0.98)
        .animate(CurvedAnimation(parent: _press, curve: Curves.easeOutCubic));

    return GestureDetector(
      onTapDown: (_) => _setDown(true),
      onTapCancel: () => _setDown(false),
      onTapUp: (_) {
        _setDown(false);
        if (canTap) widget.onPressed?.call();
      },
      child: AnimatedBuilder(
        animation: Listenable.merge([_press, widget.shineT]),
        builder: (_, __) {
          return Transform.scale(
            scale: scale.value,
            child: Container(
              padding: const EdgeInsets.all(2), // gradient ring thickness
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(16),
                gradient: canTap
                    ? SweepGradient(
                  colors: [
                    tokens.primaryButtonGradient.first,
                    tokens.primaryButtonGradient.last,
                    tokens.primaryButtonGradient.first,
                  ],
                  transform: GradientRotation(widget.shineT.value * 2 * math.pi),
                )
                    : null,
                color: canTap ? null : tokens.glassColor.withOpacity(.42),
                boxShadow: canTap
                    ? [
                  BoxShadow(
                    color: tokens.glowColor.withOpacity(.35),
                    blurRadius: 20,
                    offset: const Offset(0, 8),
                  ),
                ]
                    : null,
              ),
              child: Stack(
                children: [
                  // frosted inner plate
                  Container(
                    height: 54,
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular(14),
                      color: tokens.glassColor.withOpacity(.08),
                      border: Border.all(
                        color: tokens.borderColor.withOpacity(.82),
                      ),
                      gradient: LinearGradient(
                        begin: Alignment.topLeft,
                        end: Alignment.bottomRight,
                        colors: canTap
                            ? [
                                tokens.cardGradient.first.withOpacity(.9),
                                tokens.cardGradient.last.withOpacity(.86),
                              ]
                            : [
                                tokens.chipColor.withOpacity(.58),
                                tokens.glassColor.withOpacity(.42),
                              ],
                      ),
                    ),
                    clipBehavior: Clip.antiAlias,
                    child: Stack(
                      children: [
                        // diagonal shimmer
                        Positioned.fill(
                          child: IgnorePointer(
                            child: CustomPaint(
                              painter: _ButtonShinePainter(t: widget.shineT.value),
                            ),
                          ),
                        ),
                        // content row
                        Center(
                          child: AnimatedSwitcher(
                            duration: const Duration(milliseconds: 220),
                            transitionBuilder: (child, anim) => FadeTransition(
                              opacity: anim,
                              child: SlideTransition(
                                position: Tween<Offset>(
                                  begin: const Offset(0, .12),
                                  end: Offset.zero,
                                ).animate(anim),
                                child: child,
                              ),
                            ),
                            child: widget.loading
                                ? Row(
                              key: const ValueKey('loading'),
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                SizedBox(
                                  width: 18,
                                  height: 18,
                                  child: CircularProgressIndicator(
                                    strokeWidth: 2.4,
                                    color: tokens.textPrimary,
                                  ),
                                ),
                                const SizedBox(width: 10),
                                Text(
                                  'Processing…',
                                  style: TextStyle(
                                    color: tokens.textPrimary,
                                    fontWeight: FontWeight.w900,
                                  ),
                                ),
                              ],
                            )
                                : Row(
                              key: const ValueKey('label'),
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                // coin icon with gold gradient
                                ShaderMask(
                                  shaderCallback: (r) => const LinearGradient(
                                    colors: [Color(0xFFFFC107), Color(0xFFFFE082)],
                                    begin: Alignment.topLeft,
                                    end: Alignment.bottomRight,
                                  ).createShader(r),
                                  child: const Icon(
                                    Icons.monetization_on_rounded,
                                    size: 20,
                                    color: Colors.white,
                                  ),
                                ),
                                const SizedBox(width: 10),
                                Text(
                                  widget.label,
                                  style: TextStyle(
                                    color: tokens.textPrimary,
                                    fontWeight: FontWeight.w900,
                                    fontSize: 16,
                                  ),
                                ),
                                const SizedBox(width: 10),
                                // arrow that floats a bit
                                _FloatIcon(
                                  icon: Icons.arrow_forward_rounded,
                                  active: canTap,
                                ),
                              ],
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}

class _FloatIcon extends StatefulWidget {
  final IconData icon;
  final bool active;
  const _FloatIcon({required this.icon, required this.active});

  @override
  State<_FloatIcon> createState() => _FloatIconState();
}

class _FloatIconState extends State<_FloatIcon>
    with SingleTickerProviderStateMixin {
  late final AnimationController _ctrl;

  @override
  void initState() {
    super.initState();
    _ctrl = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 900),
    )..repeat(reverse: true);
  }

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final tokens = _choosePlanTokens();
    if (!widget.active) return Icon(widget.icon, color: tokens.textPrimary);
    return AnimatedBuilder(
      animation: _ctrl,
      builder: (_, __) {
        final dx = math.sin(_ctrl.value * 2 * math.pi) * 2.0;
        return Transform.translate(
          offset: Offset(dx, 0),
          child: Icon(Icons.arrow_forward_rounded, color: tokens.textPrimary),
        );
      },
    );
  }
}

class _ButtonShinePainter extends CustomPainter {
  final double t; // 0..1
  const _ButtonShinePainter({required this.t});

  @override
  void paint(Canvas canvas, Size size) {
    final dx = _lerp(-size.width * .6, size.width * 1.2, t);
    final rect = Rect.fromLTWH(dx, -size.height * .6, size.width * .28, size.height * 2.2);
    final paint = Paint()
      ..shader = LinearGradient(
        begin: Alignment.topCenter,
        end: Alignment.bottomCenter,
        colors: [
          Colors.white.withOpacity(0.00),
          Colors.white.withOpacity(0.18),
          Colors.white.withOpacity(0.00),
        ],
        stops: const [0.0, 0.5, 1.0],
      ).createShader(rect);
    canvas.save();
    canvas.transform(Matrix4.rotationZ(-0.45).storage);
    canvas.drawRRect(RRect.fromRectAndRadius(rect, const Radius.circular(24)), paint);
    canvas.restore();
  }

  double _lerp(double a, double b, double t) => a + (b - a) * t;
  @override
  bool shouldRepaint(covariant _ButtonShinePainter old) => old.t != t;
}
