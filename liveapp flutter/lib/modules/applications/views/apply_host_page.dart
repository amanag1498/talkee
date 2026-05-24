import 'dart:math' as math;
import 'dart:ui';

import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../../app/theme/brand.dart';
import '../../../app/widgets/haptics.dart';
import '../../../services/app_settings_service.dart';
import '../controllers/applications_controller.dart';
import 'application_status_banner.dart';
import 'my_applications_page.dart';

PremiumThemeTokens _hostApplyTokens() => getPremiumThemeTokens(
  Get.find<AppSettingsService>().activePremiumThemeVariant,
);

class ApplyHostPage extends StatefulWidget {
  const ApplyHostPage({super.key});

  @override
  State<ApplyHostPage> createState() => _ApplyHostPageState();
}

class _ApplyHostPageState extends State<ApplyHostPage>
    with SingleTickerProviderStateMixin {
  final _formKey = GlobalKey<FormState>();
  final _stageCtl = TextEditingController();
  final _phoneCtl = TextEditingController();
  final _countryCtl = TextEditingController();
  final _cityCtl = TextEditingController();
  final _aboutCtl = TextEditingController();

  late final AnimationController _bgMotion;

  ApplicationsController get controller => Get.find<ApplicationsController>();

  @override
  void initState() {
    super.initState();
    _bgMotion = AnimationController(
      vsync: this,
      duration: const Duration(seconds: 18),
    )..repeat();
  }

  @override
  void dispose() {
    _stageCtl.dispose();
    _phoneCtl.dispose();
    _countryCtl.dispose();
    _cityCtl.dispose();
    _aboutCtl.dispose();
    _bgMotion.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    Haptics.medium();
    final ok = await controller.submitHost(
      stageName: _stageCtl.text.trim().isEmpty ? null : _stageCtl.text.trim(),
      contactPhone: _phoneCtl.text.trim().isEmpty ? null : _phoneCtl.text.trim(),
      country: _countryCtl.text.trim().isEmpty ? null : _countryCtl.text.trim(),
      city: _cityCtl.text.trim().isEmpty ? null : _cityCtl.text.trim(),
      about: _aboutCtl.text.trim().isEmpty ? null : _aboutCtl.text.trim(),
    );
    if (!mounted) return;
    if (ok) {
      Get.back<void>();
      showMyApplicationsSheet();
    }
  }

  @override
  Widget build(BuildContext context) {
    final tokens = _hostApplyTokens();
    return Scaffold(
      backgroundColor: tokens.backgroundGradient.first,
      appBar: AppBar(
        title: Text(
          'Apply Host',
          style: TextStyle(
            color: tokens.textPrimary,
            fontWeight: FontWeight.w800,
          ),
        ),
        iconTheme: IconThemeData(color: tokens.textPrimary),
        backgroundColor: Colors.transparent,
        elevation: 0,
      ),
      body: Stack(
        children: [
          Positioned.fill(child: _GlassyBackdrop(t: _bgMotion)),
          Positioned.fill(
            child: DecoratedBox(
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                  colors: [
                    tokens.cardGradient.first.withOpacity(.16),
                    Colors.transparent,
                    tokens.glassColor.withOpacity(.22),
                  ],
                ),
              ),
            ),
          ),
          Obx(() {
            final latest = controller.latestByType('host');
            final blocked = !controller.isNormalUser;
            final pending = latest?.isPending == true;

            return ListView(
              physics: const BouncingScrollPhysics(),
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 28),
              children: [
                _GlassShell(
                  padding: const EdgeInsets.all(20),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Become a Host',
                        style: TextStyle(
                          color: tokens.textPrimary,
                          fontSize: 28,
                          fontWeight: FontWeight.w900,
                        ),
                      ),
                      const SizedBox(height: 8),
                      Text(
                        'Apply to go live, build your audience, and access the existing host onboarding flow already wired in Talkieo.',
                        style: TextStyle(
                          color: tokens.textSecondary.withOpacity(.82),
                          height: 1.4,
                          fontWeight: FontWeight.w500,
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 14),
                _GlassShell(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: const [
                      _SectionTitle(title: 'Benefits', subtitle: 'What hosting unlocks'),
                      SizedBox(height: 12),
                      _BenefitLine(icon: Icons.live_tv_rounded, text: 'Start live sessions after approval'),
                      _BenefitLine(icon: Icons.emoji_events_rounded, text: 'Build host presence and attract gifting'),
                      _BenefitLine(icon: Icons.supervised_user_circle_rounded, text: 'Access agency enrollment and live eligibility'),
                    ],
                  ),
                ),
                const SizedBox(height: 14),
                if (latest != null) ApplicationStatusBanner(item: latest),
                if (blocked)
                  const _GlassInfoCard(
                    icon: Icons.block_rounded,
                    title: 'Application unavailable',
                    subtitle: 'Only normal users can submit a new host application.',
                  )
                else if (pending)
                  const _GlassInfoCard(
                    icon: Icons.hourglass_top_rounded,
                    title: 'Application already pending',
                    subtitle: 'Your latest host request is already under review.',
                  )
                else
                  _GlassShell(
                    child: Form(
                      key: _formKey,
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const _SectionTitle(
                            title: 'Host Details',
                            subtitle: 'Only backend-supported optional fields are shown',
                          ),
                          const SizedBox(height: 14),
                          _PremiumField(
                            controller: _stageCtl,
                            label: 'Stage Name',
                          ),
                          const SizedBox(height: 12),
                          _PremiumField(
                            controller: _phoneCtl,
                            label: 'Contact Phone',
                            keyboardType: TextInputType.phone,
                          ),
                          const SizedBox(height: 12),
                          _PremiumField(
                            controller: _countryCtl,
                            label: 'Country',
                          ),
                          const SizedBox(height: 12),
                          _PremiumField(
                            controller: _cityCtl,
                            label: 'City',
                          ),
                          const SizedBox(height: 12),
                          _PremiumField(
                            controller: _aboutCtl,
                            label: 'About / Why you want to host',
                            maxLines: 5,
                          ),
                          if (controller.error.value != null) ...[
                            const SizedBox(height: 14),
                            Text(
                              controller.error.value!,
                              style: TextStyle(
                                color: tokens.dangerColor,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                          ],
                          const SizedBox(height: 18),
                          SizedBox(
                            width: double.infinity,
                            child: FilledButton.icon(
                              onPressed: controller.isSubmitting.value ? null : _submit,
                              style: FilledButton.styleFrom(
                                backgroundColor: tokens.primaryButtonGradient.first,
                                foregroundColor: Colors.white,
                              ),
                              icon: controller.isSubmitting.value
                                  ? const SizedBox(
                                      width: 18,
                                      height: 18,
                                      child: CircularProgressIndicator(strokeWidth: 2),
                                    )
                                  : const Icon(Icons.mic_external_on_rounded),
                              label: Text(
                                controller.isSubmitting.value
                                    ? 'Submitting...'
                                    : 'Submit Host Application',
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
              ],
            );
          }),
        ],
      ),
    );
  }
}

class _GlassShell extends StatelessWidget {
  final Widget child;
  final EdgeInsetsGeometry padding;

  const _GlassShell({
    required this.child,
    this.padding = const EdgeInsets.all(18),
  });

  @override
  Widget build(BuildContext context) {
    final tokens = _hostApplyTokens();
    return ClipRRect(
      borderRadius: BorderRadius.circular(28),
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 18, sigmaY: 18),
        child: Container(
          padding: padding,
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              colors: tokens.cardGradient,
            ),
            borderRadius: BorderRadius.circular(28),
            border: Border.all(color: tokens.borderColor),
            boxShadow: [
              BoxShadow(
                color: tokens.glowColor.withOpacity(.22),
                blurRadius: 24,
                offset: const Offset(0, 10),
              ),
            ],
          ),
          child: child,
        ),
      ),
    );
  }
}

class _SectionTitle extends StatelessWidget {
  final String title;
  final String subtitle;

  const _SectionTitle({
    required this.title,
    required this.subtitle,
  });

  @override
  Widget build(BuildContext context) {
    final tokens = _hostApplyTokens();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          title,
          style: TextStyle(
            color: tokens.textPrimary,
            fontSize: 18,
            fontWeight: FontWeight.w800,
          ),
        ),
        const SizedBox(height: 4),
        Text(
          subtitle,
          style: TextStyle(
            color: tokens.textSecondary.withOpacity(.78),
            fontWeight: FontWeight.w500,
          ),
        ),
      ],
    );
  }
}

class _BenefitLine extends StatelessWidget {
  final IconData icon;
  final String text;

  const _BenefitLine({
    required this.icon,
    required this.text,
  });

  @override
  Widget build(BuildContext context) {
    final tokens = _hostApplyTokens();
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(
        children: [
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(
              color: tokens.glassColor.withOpacity(.9),
              borderRadius: BorderRadius.circular(14),
            ),
            child: Icon(icon, color: tokens.primaryButtonGradient.first),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              text,
              style: TextStyle(
                color: tokens.textPrimary,
                height: 1.3,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _PremiumField extends StatelessWidget {
  final TextEditingController controller;
  final String label;
  final TextInputType? keyboardType;
  final int maxLines;

  const _PremiumField({
    required this.controller,
    required this.label,
    this.keyboardType,
    this.maxLines = 1,
  });

  @override
  Widget build(BuildContext context) {
    final tokens = _hostApplyTokens();
    return TextFormField(
      controller: controller,
      keyboardType: keyboardType,
      maxLines: maxLines,
      style: TextStyle(color: tokens.textPrimary),
      decoration: InputDecoration(
        labelText: label,
        labelStyle: TextStyle(color: tokens.textSecondary.withOpacity(.8)),
        filled: true,
        fillColor: tokens.glassColor.withOpacity(.85),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(20),
          borderSide: BorderSide(color: tokens.borderColor),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(20),
          borderSide: BorderSide(color: tokens.borderColor),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(20),
          borderSide: BorderSide(color: tokens.primaryButtonGradient.first),
        ),
      ),
    );
  }
}

class _GlassInfoCard extends StatelessWidget {
  final IconData icon;
  final String title;
  final String subtitle;

  const _GlassInfoCard({
    required this.icon,
    required this.title,
    required this.subtitle,
  });

  @override
  Widget build(BuildContext context) {
    final tokens = _hostApplyTokens();
    return _GlassShell(
      child: Column(
        children: [
          Icon(icon, size: 42, color: tokens.textPrimary),
          const SizedBox(height: 12),
          Text(
            title,
            textAlign: TextAlign.center,
            style: TextStyle(
              color: tokens.textPrimary,
              fontSize: 18,
              fontWeight: FontWeight.w800,
            ),
          ),
          const SizedBox(height: 8),
          Text(
            subtitle,
            textAlign: TextAlign.center,
            style: TextStyle(
              color: tokens.textSecondary.withOpacity(.82),
              height: 1.35,
            ),
          ),
        ],
      ),
    );
  }
}

class _GlassyBackdrop extends StatelessWidget {
  final Animation<double> t;

  const _GlassyBackdrop({required this.t});

  @override
  Widget build(BuildContext context) {
    final tokens = _hostApplyTokens();
    return AnimatedBuilder(
      animation: t,
      builder: (_, __) => CustomPaint(painter: _BlobPainter(t.value, tokens)),
    );
  }
}

class _BlobPainter extends CustomPainter {
  final double t;
  final PremiumThemeTokens tokens;

  _BlobPainter(this.t, this.tokens);

  @override
  void paint(Canvas canvas, Size size) {
    final w = size.width;
    final h = size.height;

    void blob(Offset base, double r, Color c, double drift, double phase) {
      final dx = math.sin((t * 2 * math.pi) + phase) * drift;
      final dy = math.cos((t * 2 * math.pi) + phase) * (drift * .6);
      final center = base + Offset(dx, dy);
      final paint = Paint()
        ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 64)
        ..color = c.withOpacity(.40);
      canvas.drawCircle(center, r, paint);
    }

    blob(Offset(w * .20, h * .16), h * .24, tokens.primaryButtonGradient.first, 28, 0.0);
    blob(Offset(w * .82, h * .32), h * .22, tokens.cardGradient.last, 36, 1.1);
    blob(Offset(w * .58, h * .76), h * .26, tokens.glowColor, 30, 2.2);
  }

  @override
  bool shouldRepaint(covariant _BlobPainter old) => old.t != t;
}
