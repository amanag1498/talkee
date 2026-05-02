import 'dart:ui' show ImageFilter;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import '../../../app/widgets/talkee_logo.dart'; // adjust import

class ApprovalDialog extends StatefulWidget {
  const ApprovalDialog({
    super.key,
    required this.title,
    required this.message,
    this.ctaText = 'OK',
  });

  final String title;
  final String message;
  final String ctaText;

  @override
  State<ApprovalDialog> createState() => _ApprovalDialogState();
}

class _ApprovalDialogState extends State<ApprovalDialog>
    with TickerProviderStateMixin {
  static const _brand1 = Color(0xFF7B50C5);
  static const _brand2 = Color(0xFF3E2374);

  late final AnimationController _pop;

  @override
  void initState() {
    super.initState();
    HapticFeedback.lightImpact();
    _pop = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 220),
    )..forward();
  }

  @override
  void dispose() {
    _pop.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final curve = CurvedAnimation(parent: _pop, curve: Curves.easeOutBack);

    return FadeTransition(
      opacity: curve,
      child: ScaleTransition(
        scale: Tween(begin: .94, end: 1.0).animate(curve),
        child: Dialog(
          elevation: 0,
          backgroundColor: Colors.transparent,
          insetPadding: const EdgeInsets.symmetric(horizontal: 22),
          child: _Frosted(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(18, 18, 18, 14),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const TalkeeLogo(size: 54, showWordmark: false),
                  const SizedBox(height: 12),
                  ShaderMask(
                    shaderCallback: (r) => const LinearGradient(
                      colors: [_brand1, _brand2],
                      begin: Alignment.topLeft, end: Alignment.bottomRight,
                    ).createShader(r),
                    child: Text(
                      widget.title,
                      textAlign: TextAlign.center,
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 20,
                        fontWeight: FontWeight.w900,
                        letterSpacing: .2,
                      ),
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    widget.message,
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      color: Colors.white.withOpacity(.92),
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  const SizedBox(height: 18),
                  SizedBox(
                    width: double.infinity,
                    child: ElevatedButton(
                      style: ElevatedButton.styleFrom(
                        backgroundColor: _brand1,
                        foregroundColor: Colors.white,
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(14),
                        ),
                        padding: const EdgeInsets.symmetric(vertical: 14),
                        elevation: 6,
                      ),
                      onPressed: () => Get.back(),
                      child: Text(
                        widget.ctaText,
                        style: const TextStyle(fontWeight: FontWeight.w900),
                      ),
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

class _Frosted extends StatelessWidget {
  const _Frosted({required this.child});
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(22),
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 22, sigmaY: 22),
        child: Container(
          decoration: BoxDecoration(
            color: const Color(0xFF120A24).withOpacity(.58),
            border: Border.all(color: Colors.white.withOpacity(.10)),
            gradient: const LinearGradient(
              colors: [Color(0xFF24143F), Color(0xFF160D2B)],
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
            ),
            boxShadow: [
              BoxShadow(
                color: Colors.black.withOpacity(.42),
                blurRadius: 26,
                offset: const Offset(0, 12),
              ),
            ],
          ),
          child: child,
        ),
      ),
    );
  }
}
