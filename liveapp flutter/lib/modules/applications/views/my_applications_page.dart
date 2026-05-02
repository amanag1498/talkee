import 'dart:ui';

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:intl/intl.dart';

import '../../../app/theme/brand.dart';
import '../../../app/widgets/haptics.dart';
import '../../../services/app_settings_service.dart';
import '../controllers/applications_controller.dart';
import '../models/application_dto.dart';

PremiumThemeTokens _applicationsTokens() => getPremiumThemeTokens(
  Get.find<AppSettingsService>().activePremiumThemeVariant,
);

Future<T?> showMyApplicationsSheet<T>() {
  return Get.bottomSheet<T>(
    const _MyApplicationsSheet(),
    isScrollControlled: true,
  );
}

class MyApplicationsPage extends GetView<ApplicationsController> {
  const MyApplicationsPage({super.key});

  @override
  Widget build(BuildContext context) {
    final tokens = _applicationsTokens();
    return Scaffold(
      backgroundColor: tokens.backgroundGradient.first,
      appBar: AppBar(
        title: Text(
          'My Applications',
          style: TextStyle(
            color: tokens.textPrimary,
            fontWeight: FontWeight.w800,
          ),
        ),
        iconTheme: IconThemeData(color: tokens.textPrimary),
        backgroundColor: Colors.transparent,
        elevation: 0,
      ),
      body: const _ApplicationsBody(
        paddedTop: 18,
        surface: _ApplicationsSurface.page,
      ),
    );
  }
}

class _MyApplicationsSheet extends StatelessWidget {
  const _MyApplicationsSheet();

  @override
  Widget build(BuildContext context) {
    final tokens = _applicationsTokens();
    return ClipRRect(
      borderRadius: const BorderRadius.vertical(top: Radius.circular(30)),
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 18, sigmaY: 18),
        child: Container(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              colors: tokens.cardGradient,
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
            ),
            border: Border(top: BorderSide(color: tokens.borderColor)),
          ),
          child: SafeArea(
            top: false,
            child: ConstrainedBox(
              constraints: BoxConstraints(
                maxHeight: MediaQuery.of(context).size.height * .92,
              ),
              child: Column(
                children: [
                  const SizedBox(height: 10),
                  Container(
                    width: 42,
                    height: 4,
                    decoration: BoxDecoration(
                      color: tokens.borderColor.withOpacity(.85),
                      borderRadius: BorderRadius.circular(999),
                    ),
                  ),
                  const SizedBox(height: 12),
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 18),
                    child: Row(
                      children: [
                        Expanded(
                          child: Text(
                            'My Applications',
                            style: TextStyle(
                              color: tokens.textPrimary,
                              fontSize: 22,
                              fontWeight: FontWeight.w900,
                            ),
                          ),
                        ),
                        IconButton(
                          onPressed: () {
                            Haptics.selection();
                            Get.back<void>();
                          },
                          icon: Icon(Icons.close_rounded, color: tokens.textPrimary),
                        ),
                      ],
                    ),
                  ),
                  const Expanded(
                    child: _ApplicationsBody(
                      paddedTop: 0,
                      surface: _ApplicationsSurface.sheet,
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

enum _ApplicationsSurface { page, sheet }

class _ApplicationsBody extends GetView<ApplicationsController> {
  final double paddedTop;
  final _ApplicationsSurface surface;

  const _ApplicationsBody({
    required this.paddedTop,
    required this.surface,
  });

  @override
  Widget build(BuildContext context) {
    final isSheet = surface == _ApplicationsSurface.sheet;
    final tokens = _applicationsTokens();
    final content = Obx(() {
      if (controller.isLoading.value && controller.summary.value == null) {
        return Center(
          child: CircularProgressIndicator(
            color:
                isSheet
                    ? tokens.textPrimary
                    : tokens.primaryButtonGradient.first,
          ),
        );
      }
      if (controller.error.value != null && controller.summary.value == null) {
        return _ErrorState(
          title: 'Unable to load applications',
          subtitle: controller.error.value!,
          onRetry: () {
            Haptics.selection();
            controller.load();
          },
          dark: isSheet,
        );
      }
      final items = controller.applications;
      return RefreshIndicator(
        onRefresh: controller.load,
        child: ListView(
          padding: EdgeInsets.fromLTRB(18, paddedTop, 18, 24),
          children: [
            _ApplicationsSummaryCard(
              total: items.length,
              pending: items.where((e) => e.isPending).length,
              approved: items.where((e) => e.isApproved).length,
              rejected: items.where((e) => e.isRejected).length,
            ),
            const SizedBox(height: 18),
            if (items.isEmpty)
              _EmptyState(
                title: 'No applications yet',
                subtitle: 'When you submit a host, agency, or enrollment request, the status will appear here.',
                dark: isSheet,
              )
            else
              ...items.map((item) => Padding(
                    padding: const EdgeInsets.only(bottom: 14),
                    child: _ApplicationCard(
                      item: item,
                      dark: isSheet,
                    ),
                  )),
          ],
        ),
      );
    });

    if (isSheet) {
      return content;
    }

    return Container(
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: tokens.backgroundGradient,
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
      ),
      child: content,
    );
  }
}

class _ErrorState extends StatelessWidget {
  final String title;
  final String subtitle;
  final VoidCallback onRetry;
  final bool dark;

  const _ErrorState({
    required this.title,
    required this.subtitle,
    required this.onRetry,
    this.dark = false,
  });

  @override
  Widget build(BuildContext context) {
    final tokens = _applicationsTokens();
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 40),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Icon(
            Icons.assignment_late_rounded,
            size: 56,
            color:
                dark
                    ? tokens.textPrimary
                    : tokens.primaryButtonGradient.first,
          ),
          const SizedBox(height: 14),
          Text(
            title,
            style: Theme.of(context).textTheme.titleLarge?.copyWith(
                  fontWeight: FontWeight.w800,
                  color: tokens.textPrimary,
                ),
          ),
          const SizedBox(height: 8),
          Text(
            subtitle,
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  color:
                      dark
                          ? tokens.textSecondary.withOpacity(.82)
                          : tokens.textSecondary,
                ),
          ),
          const SizedBox(height: 14),
          FilledButton(onPressed: onRetry, child: const Text('Retry')),
        ],
      ),
    );
  }
}

class _ApplicationsSummaryCard extends StatelessWidget {
  final int total;
  final int pending;
  final int approved;
  final int rejected;

  const _ApplicationsSummaryCard({
    required this.total,
    required this.pending,
    required this.approved,
    required this.rejected,
  });

  @override
  Widget build(BuildContext context) {
    final tokens = _applicationsTokens();
    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: tokens.cardGradient,
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(28),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Application Status',
            style: Theme.of(context).textTheme.titleLarge?.copyWith(
                  color: tokens.textPrimary,
                  fontWeight: FontWeight.w800,
                ),
          ),
          const SizedBox(height: 6),
          Text(
            'This page only shows submitted requests and their review status.',
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  color: tokens.textSecondary,
                ),
          ),
          const SizedBox(height: 18),
          GridView.count(
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            crossAxisCount: 2,
            crossAxisSpacing: 10,
            mainAxisSpacing: 10,
            childAspectRatio: 1.8,
            children: [
              _SummaryStat(label: 'Total', value: '$total'),
              _SummaryStat(label: 'Pending', value: '$pending'),
              _SummaryStat(label: 'Approved', value: '$approved'),
              _SummaryStat(label: 'Rejected', value: '$rejected'),
            ],
          ),
        ],
      ),
    );
  }
}

class _SummaryStat extends StatelessWidget {
  final String label;
  final String value;

  const _SummaryStat({
    required this.label,
    required this.value,
  });

  @override
  Widget build(BuildContext context) {
    final tokens = _applicationsTokens();
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [
            tokens.chipColor.withOpacity(.96),
            tokens.glassColor.withOpacity(.74),
          ],
        ),
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: tokens.borderColor.withOpacity(.82)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Text(
            value,
            style: TextStyle(
              color: tokens.textPrimary,
              fontSize: 18,
              fontWeight: FontWeight.w800,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            label,
            style: TextStyle(
              color: tokens.textSecondary,
              fontSize: 12,
              fontWeight: FontWeight.w600,
            ),
          ),
        ],
      ),
    );
  }
}

class _ApplicationCard extends StatelessWidget {
  final ApplicationItemDto item;
  final bool dark;
  const _ApplicationCard({
    required this.item,
    this.dark = false,
  });

  @override
  Widget build(BuildContext context) {
    final tokens = _applicationsTokens();
    final statusColor = item.isApproved
        ? const Color(0xFF39C88B)
        : item.isRejected
            ? tokens.dangerColor
            : tokens.primaryButtonGradient.first;
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors:
              dark
                  ? tokens.cardGradient
                  : [
                    tokens.glassColor.withOpacity(.95),
                    tokens.cardGradient.first,
                  ],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(22),
        border: Border.all(color: tokens.borderColor),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  item.title,
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w800,
                        color: tokens.textPrimary,
                      ),
                ),
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                decoration: BoxDecoration(
                  color: statusColor.withOpacity(.12),
                  borderRadius: BorderRadius.circular(999),
                ),
                child: Text(
                  item.status.toUpperCase(),
                  style: TextStyle(color: statusColor, fontWeight: FontWeight.w800, fontSize: 12),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Text(
            _labelForType(item.type),
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  color: tokens.textSecondary.withOpacity(.84),
                ),
          ),
          const SizedBox(height: 10),
          _MetaRow(
            label: 'Submitted',
            value: item.submittedAt == null ? 'Unknown' : DateFormat.yMMMd().add_jm().format(item.submittedAt!.toLocal()),
            dark: dark,
          ),
          if (item.reviewedAt != null)
            _MetaRow(
              label: 'Reviewed',
              value: DateFormat.yMMMd().add_jm().format(item.reviewedAt!.toLocal()),
              dark: dark,
            ),
          if ((item.reviewNotes ?? '').trim().isNotEmpty)
            _MetaRow(
              label: 'Notes',
              value: item.reviewNotes!.trim(),
              dark: dark,
            ),
        ],
      ),
    );
  }

  static String _labelForType(String type) {
    switch (type) {
      case 'agency':
        return 'Agency application';
      case 'host':
        return 'Host application';
      case 'host_enroll':
        return 'Agency enrollment';
      default:
        return type;
    }
  }
}

class _MetaRow extends StatelessWidget {
  final String label;
  final String value;
  final bool dark;
  const _MetaRow({
    required this.label,
    required this.value,
    this.dark = false,
  });

  @override
  Widget build(BuildContext context) {
    final tokens = _applicationsTokens();
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: RichText(
        text: TextSpan(
          style: DefaultTextStyle.of(context).style.copyWith(fontSize: 14),
          children: [
            TextSpan(
              text: '$label: ',
              style: TextStyle(
                color: _applicationsTokens().textSecondary.withOpacity(.78),
                fontWeight: FontWeight.w700,
              ),
            ),
            TextSpan(
              text: value,
              style: TextStyle(
                color: _applicationsTokens().textPrimary,
                fontWeight: FontWeight.w600,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _EmptyState extends StatelessWidget {
  final String title;
  final String subtitle;
  final bool dark;
  const _EmptyState({
    required this.title,
    required this.subtitle,
    this.dark = false,
  });

  @override
  Widget build(BuildContext context) {
    final tokens = _applicationsTokens();
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 40),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Icon(
            Icons.assignment_late_rounded,
            size: 56,
            color:
                dark
                    ? tokens.textPrimary
                    : tokens.primaryButtonGradient.first,
          ),
          const SizedBox(height: 14),
          Text(
            title,
            style: Theme.of(context).textTheme.titleLarge?.copyWith(
                  fontWeight: FontWeight.w800,
                  color: tokens.textPrimary,
                ),
          ),
          const SizedBox(height: 8),
          Text(
            subtitle,
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  color:
                      dark
                          ? tokens.textSecondary.withOpacity(.8)
                          : tokens.textSecondary,
                ),
          ),
        ],
      ),
    );
  }
}
