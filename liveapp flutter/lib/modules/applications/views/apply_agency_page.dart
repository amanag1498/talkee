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

PremiumThemeTokens _agencyApplyTokens() => getPremiumThemeTokens(
  Get.find<AppSettingsService>().activePremiumThemeVariant,
);

class ApplyAgencyPage extends StatefulWidget {
  const ApplyAgencyPage({super.key});

  @override
  State<ApplyAgencyPage> createState() => _ApplyAgencyPageState();
}

class _ApplyAgencyPageState extends State<ApplyAgencyPage>
    with SingleTickerProviderStateMixin {
  final _formKey = GlobalKey<FormState>();
  final _agencyNameCtl = TextEditingController();
  final _legalNameCtl = TextEditingController();
  final _phoneCtl = TextEditingController();
  final _websiteCtl = TextEditingController();
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
    _agencyNameCtl.dispose();
    _legalNameCtl.dispose();
    _phoneCtl.dispose();
    _websiteCtl.dispose();
    _aboutCtl.dispose();
    _bgMotion.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    Haptics.medium();
    if (!_formKey.currentState!.validate()) return;
    final ok = await controller.submitAgency(
      agencyName: _agencyNameCtl.text.trim(),
      legalName: _legalNameCtl.text.trim().isEmpty ? null : _legalNameCtl.text.trim(),
      contactPhone: _phoneCtl.text.trim().isEmpty ? null : _phoneCtl.text.trim(),
      website: _websiteCtl.text.trim().isEmpty ? null : _websiteCtl.text.trim(),
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
    final tokens = _agencyApplyTokens();
    return Scaffold(
      backgroundColor: tokens.backgroundGradient.first,
      appBar: AppBar(
        title: Text(
          'Apply Agency',
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
            final latest = controller.latestByType('agency');
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
                        'Apply for Agency',
                        style: TextStyle(
                          color: tokens.textPrimary,
                          fontSize: 28,
                          fontWeight: FontWeight.w900,
                        ),
                      ),
                      const SizedBox(height: 8),
                      Text(
                        'Create and manage talent under your agency account using the existing Talkieo approval flow.',
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
                      _SectionTitle(title: 'Benefits', subtitle: 'What this unlocks if approved'),
                      SizedBox(height: 12),
                      _BenefitLine(icon: Icons.groups_rounded, text: 'Manage host applications and enrollments'),
                      _BenefitLine(icon: Icons.analytics_rounded, text: 'Track talent and performance centrally'),
                      _BenefitLine(icon: Icons.verified_rounded, text: 'Operate through the existing admin approval flow'),
                    ],
                  ),
                ),
                const SizedBox(height: 14),
                if (latest != null) ApplicationStatusBanner(item: latest),
                if (blocked)
                  const _GlassInfoCard(
                    icon: Icons.block_rounded,
                    title: 'Application unavailable',
                    subtitle: 'Only normal users can submit a new agency application.',
                  )
                else if (pending)
                  const _GlassInfoCard(
                    icon: Icons.hourglass_top_rounded,
                    title: 'Application already pending',
                    subtitle: 'Your latest agency request is already under review.',
                  )
                else
                  _GlassShell(
                    child: Form(
                      key: _formKey,
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const _SectionTitle(
                            title: 'Agency Details',
                            subtitle: 'Only fields supported by the current backend are shown',
                          ),
                          const SizedBox(height: 14),
                          _PremiumField(
                            controller: _agencyNameCtl,
                            label: 'Agency Name',
                            validator: (value) => (value == null || value.trim().isEmpty)
                                ? 'Agency name is required.'
                                : null,
                          ),
                          const SizedBox(height: 12),
                          _PremiumField(
                            controller: _legalNameCtl,
                            label: 'Legal Name',
                          ),
                          const SizedBox(height: 12),
                          _PremiumField(
                            controller: _phoneCtl,
                            label: 'Contact Phone',
                            keyboardType: TextInputType.phone,
                          ),
                          const SizedBox(height: 12),
                          _PremiumField(
                            controller: _websiteCtl,
                            label: 'Website',
                            keyboardType: TextInputType.url,
                          ),
                          const SizedBox(height: 12),
                          _PremiumField(
                            controller: _aboutCtl,
                            label: 'About',
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
                                  : const Icon(Icons.apartment_rounded),
                              label: Text(
                                controller.isSubmitting.value
                                    ? 'Submitting...'
                                    : 'Submit Agency Application',
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
    final tokens = _agencyApplyTokens();
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
    final tokens = _agencyApplyTokens();
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
    final tokens = _agencyApplyTokens();
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
  final String? Function(String?)? validator;

  const _PremiumField({
    required this.controller,
    required this.label,
    this.keyboardType,
    this.maxLines = 1,
    this.validator,
  });

  @override
  Widget build(BuildContext context) {
    final tokens = _agencyApplyTokens();
    return TextFormField(
      controller: controller,
      keyboardType: keyboardType,
      maxLines: maxLines,
      validator: validator,
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
    final tokens = _agencyApplyTokens();
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
    final tokens = _agencyApplyTokens();
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
