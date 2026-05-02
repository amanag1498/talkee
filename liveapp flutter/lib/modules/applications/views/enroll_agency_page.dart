import 'dart:ui';

import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../../app/theme/brand.dart';
import '../../../app/widgets/haptics.dart';
import '../../../services/app_settings_service.dart';
import '../controllers/applications_controller.dart';
import '../models/application_dto.dart';
import '../../profile/controllers/profile_controller.dart';
import 'application_status_banner.dart';
import 'my_applications_page.dart';

PremiumThemeTokens _enrollTokens() => getPremiumThemeTokens(
  Get.find<AppSettingsService>().activePremiumThemeVariant,
);

class EnrollAgencyPage extends StatefulWidget {
  const EnrollAgencyPage({super.key});

  @override
  State<EnrollAgencyPage> createState() => _EnrollAgencyPageState();
}

class _EnrollAgencyPageState extends State<EnrollAgencyPage> {
  final _formKey = GlobalKey<FormState>();
  int? _selectedAgencyId;
  final _messageCtl = TextEditingController();

  ApplicationsController get controller => Get.find<ApplicationsController>();
  ProfileController get profileController => Get.find<ProfileController>();

  @override
  void initState() {
    super.initState();
    final agencies = controller.availableAgencies;
    if (agencies.isNotEmpty) _selectedAgencyId = agencies.first.id;
  }

  @override
  void dispose() {
    _messageCtl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    Haptics.medium();
    if (_selectedAgencyId == null) return;
    final ok = await controller.submitEnroll(
      agencyId: _selectedAgencyId!,
      message: _messageCtl.text.trim().isEmpty ? null : _messageCtl.text.trim(),
    );
    if (!mounted) return;
    if (ok) {
      Get.back<void>();
      showMyApplicationsSheet();
    }
  }

  @override
  Widget build(BuildContext context) {
    final tokens = _enrollTokens();
    return Scaffold(
      backgroundColor: tokens.backgroundGradient.first,
      appBar: AppBar(
        title: Text(
          'Enroll to Agency',
          style: TextStyle(
            color: tokens.textPrimary,
            fontWeight: FontWeight.w800,
          ),
        ),
        iconTheme: IconThemeData(color: tokens.textPrimary),
        backgroundColor: Colors.transparent,
        elevation: 0,
      ),
      body: Obx(() {
        final latest = controller.latestByType('host_enroll');
        final profile = profileController.profile.value;
        final agencies = controller.availableAgencies;
        final blocked = !controller.isHost || controller.isAgency || controller.isAdmin;
        final pending = latest?.isPending == true;
        final currentlyAttached = profile?.status.agencyAttached == true;
        if (_selectedAgencyId == null && agencies.isNotEmpty) {
          _selectedAgencyId = agencies.first.id;
        }
        return ListView(
          padding: const EdgeInsets.all(18),
          children: [
            if (latest != null) ApplicationStatusBanner(item: latest),
            if (blocked)
              const ApplicationStatusBanner(
                item: ApplicationItemDto(
                  id: 0,
                  type: 'host_enroll',
                  title: 'Enrollment unavailable',
                  status: 'info',
                  details: {},
                  reviewNotes: 'Only host accounts can enroll to an agency.',
                ),
              )
            else if (pending)
              const ApplicationStatusBanner(
                item: ApplicationItemDto(
                  id: 0,
                  type: 'host_enroll',
                  title: 'Enrollment already pending',
                  status: 'pending',
                  details: {},
                  reviewNotes: 'Your latest agency enrollment request is already under review.',
                ),
              )
            else if (currentlyAttached)
              const ApplicationStatusBanner(
                item: ApplicationItemDto(
                  id: 0,
                  type: 'host_enroll',
                  title: 'Enrollment already approved',
                  status: 'approved',
                  details: {},
                  reviewNotes: 'Your host account is already enrolled with an agency.',
                ),
              )
            else if (agencies.isEmpty)
              const ApplicationStatusBanner(
                item: ApplicationItemDto(
                  id: 0,
                  type: 'host_enroll',
                  title: 'No agencies',
                  status: 'info',
                  details: {},
                  reviewNotes: 'No agencies are available for enrollment right now.',
                ),
              )
            else
              _EnrollShell(
                child: Form(
                  key: _formKey,
                  child: Column(
                    children: [
                    DropdownButtonFormField<int>(
                      initialValue: _selectedAgencyId,
                      dropdownColor: tokens.cardGradient.last,
                      style: TextStyle(color: tokens.textPrimary),
                      decoration: InputDecoration(
                        labelText: 'Agency',
                        labelStyle: TextStyle(
                          color: tokens.textSecondary.withOpacity(.8),
                        ),
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
                          borderSide: BorderSide(
                            color: tokens.primaryButtonGradient.first,
                          ),
                        ),
                      ),
                      items: agencies
                          .map((agency) => DropdownMenuItem<int>(
                                value: agency.id,
                                child: Text(
                                  agency.name,
                                  style: TextStyle(color: tokens.textPrimary),
                                ),
                              ))
                          .toList(),
                      onChanged: (value) => setState(() => _selectedAgencyId = value),
                    ),
                    const SizedBox(height: 12),
                    TextFormField(
                      controller: _messageCtl,
                      maxLines: 5,
                      style: TextStyle(color: tokens.textPrimary),
                      decoration: InputDecoration(
                        labelText: 'Message',
                        labelStyle: TextStyle(
                          color: tokens.textSecondary.withOpacity(.8),
                        ),
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
                          borderSide: BorderSide(
                            color: tokens.primaryButtonGradient.first,
                          ),
                        ),
                      ),
                    ),
                    const SizedBox(height: 18),
                    SizedBox(
                      width: double.infinity,
                      child: FilledButton(
                        style: FilledButton.styleFrom(
                          backgroundColor: tokens.primaryButtonGradient.first,
                          foregroundColor: Colors.white,
                        ),
                        onPressed: controller.isSubmitting.value ? null : _submit,
                        child: controller.isSubmitting.value
                            ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2))
                            : const Text('Submit Enroll Request'),
                      ),
                    ),
                    if (controller.error.value != null) ...[
                      const SizedBox(height: 10),
                      Text(
                        controller.error.value!,
                        style: TextStyle(color: tokens.dangerColor),
                      ),
                    ],
                    ],
                  ),
                ),
              ),
          ],
        );
      }),
    );
  }
}

class _EnrollShell extends StatelessWidget {
  final Widget child;

  const _EnrollShell({required this.child});

  @override
  Widget build(BuildContext context) {
    final tokens = _enrollTokens();
    return ClipRRect(
      borderRadius: BorderRadius.circular(28),
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 18, sigmaY: 18),
        child: Container(
          padding: const EdgeInsets.all(18),
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
