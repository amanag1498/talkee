import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:intl/intl.dart';

import '../../../app/theme/brand.dart';
import '../../../services/app_settings_service.dart';
import '../../Live/services/live_service.dart';

class UnblockRequestsPage extends StatefulWidget {
  const UnblockRequestsPage({super.key});

  @override
  State<UnblockRequestsPage> createState() => _UnblockRequestsPageState();
}

class _UnblockRequestsPageState extends State<UnblockRequestsPage> {
  final LiveService _live = Get.find<LiveService>();
  bool _loading = true;
  bool _busy = false;
  String? _error;
  List<Map<String, dynamic>> _rows = const <Map<String, dynamic>>[];

  PremiumThemeTokens get _tokens => getPremiumThemeTokens(
    Get.find<AppSettingsService>().activePremiumThemeVariant,
  );

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final rows = await _live.fetchHostUnblockRequests();
      if (!mounted) return;
      setState(() {
        _rows = rows;
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.toString().replaceFirst('Exception: ', '');
        _loading = false;
      });
    }
  }

  Future<void> _approve(Map<String, dynamic> row) async {
    final requestId = _toInt(row['id']);
    if (requestId == null || _busy) return;
    setState(() => _busy = true);
    try {
      await _live.approveUnblockRequest(requestId);
      if (!mounted) return;
      setState(() {
        _rows = _rows.where((entry) => _toInt(entry['id']) != requestId).toList();
      });
      Get.snackbar(
        'Moderation',
        '${_blockedUserName(row)} was unblocked.',
        snackPosition: SnackPosition.BOTTOM,
      );
    } catch (e) {
      Get.snackbar(
        'Moderation',
        e.toString().replaceFirst('Exception: ', ''),
        snackPosition: SnackPosition.BOTTOM,
      );
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _reject(Map<String, dynamic> row) async {
    final requestId = _toInt(row['id']);
    if (requestId == null || _busy) return;
    final controller = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) {
        return AlertDialog(
          title: const Text('Reject request?'),
          content: TextField(
            controller: controller,
            minLines: 2,
            maxLines: 4,
            decoration: const InputDecoration(
              labelText: 'Notes (optional)',
              hintText: 'Add a reason for rejection',
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(context).pop(false),
              child: const Text('Cancel'),
            ),
            FilledButton(
              onPressed: () => Navigator.of(context).pop(true),
              child: const Text('Reject'),
            ),
          ],
        );
      },
    );
    if (ok != true) return;
    setState(() => _busy = true);
    try {
      await _live.rejectUnblockRequest(
        requestId,
        notes: controller.text.trim(),
      );
      if (!mounted) return;
      setState(() {
        _rows = _rows.where((entry) => _toInt(entry['id']) != requestId).toList();
      });
      Get.snackbar(
        'Moderation',
        'Request rejected.',
        snackPosition: SnackPosition.BOTTOM,
      );
    } catch (e) {
      Get.snackbar(
        'Moderation',
        e.toString().replaceFirst('Exception: ', ''),
        snackPosition: SnackPosition.BOTTOM,
      );
    } finally {
      controller.dispose();
      if (mounted) setState(() => _busy = false);
    }
  }

  int? _toInt(dynamic value) {
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse(value?.toString() ?? '');
  }

  Map<String, dynamic> _blockedUser(Map<String, dynamic> row) {
    final payload = row['blocked_user'];
    if (payload is Map<String, dynamic>) return payload;
    if (payload is Map) return Map<String, dynamic>.from(payload);
    return const <String, dynamic>{};
  }

  String _blockedUserName(Map<String, dynamic> row) {
    final blockedUser = _blockedUser(row);
    final direct = blockedUser['name']?.toString().trim() ?? '';
    if (direct.isNotEmpty) return direct;
    return 'User';
  }

  @override
  Widget build(BuildContext context) {
    final tokens = _tokens;
    return Scaffold(
      backgroundColor: tokens.backgroundGradient.first,
      appBar: AppBar(
        title: const Text('Unblock Requests'),
        backgroundColor: Colors.transparent,
        elevation: 0,
      ),
      body: DecoratedBox(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            colors: [
              tokens.backgroundGradient.first,
              tokens.backgroundGradient.last,
            ],
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
          ),
        ),
        child: RefreshIndicator(
          onRefresh: _load,
          child: _loading
              ? const Center(child: CircularProgressIndicator())
              : _error != null
                  ? ListView(
                      children: [
                        const SizedBox(height: 140),
                        Center(
                          child: Text(
                            _error!,
                            style: TextStyle(color: tokens.textPrimary),
                          ),
                        ),
                      ],
                    )
                  : _rows.isEmpty
                      ? ListView(
                          children: [
                            const SizedBox(height: 140),
                            Center(
                              child: Text(
                                'No pending unblock requests.',
                                style: TextStyle(
                                  color: tokens.textPrimary,
                                  fontWeight: FontWeight.w700,
                                  fontSize: 16,
                                ),
                              ),
                            ),
                          ],
                        )
                      : ListView.separated(
                          padding: const EdgeInsets.all(16),
                          itemCount: _rows.length,
                          separatorBuilder: (_, __) => const SizedBox(height: 12),
                          itemBuilder: (_, index) {
                            final row = _rows[index];
                            final blockedUser = _blockedUser(row);
                            final createdAt = DateTime.tryParse(
                              (row['created_at'] ?? '').toString(),
                            );
                            final avatarUrl = blockedUser['avatar_url']?.toString();
                            return Container(
                              padding: const EdgeInsets.all(14),
                              decoration: BoxDecoration(
                                gradient: LinearGradient(
                                  colors: [
                                    tokens.cardGradient.first.withOpacity(.96),
                                    tokens.cardGradient.last.withOpacity(.94),
                                  ],
                                ),
                                borderRadius: BorderRadius.circular(22),
                                border: Border.all(color: tokens.borderColor),
                              ),
                              child: Column(
                                children: [
                                  Row(
                                    children: [
                                      CircleAvatar(
                                        radius: 22,
                                        backgroundColor:
                                            tokens.primaryButtonGradient.first,
                                        backgroundImage: avatarUrl != null &&
                                                avatarUrl.trim().isNotEmpty
                                            ? NetworkImage(avatarUrl.trim())
                                            : null,
                                        child: avatarUrl == null ||
                                                avatarUrl.trim().isEmpty
                                            ? Text(
                                                _blockedUserName(row)
                                                    .characters
                                                    .first
                                                    .toUpperCase(),
                                                style: TextStyle(
                                                  color: tokens.textPrimary,
                                                  fontWeight: FontWeight.w900,
                                                ),
                                              )
                                            : null,
                                      ),
                                      const SizedBox(width: 12),
                                      Expanded(
                                        child: Column(
                                          crossAxisAlignment: CrossAxisAlignment.start,
                                          children: [
                                            Text(
                                              _blockedUserName(row),
                                              style: TextStyle(
                                                color: tokens.textPrimary,
                                                fontWeight: FontWeight.w800,
                                                fontSize: 16,
                                              ),
                                            ),
                                            if (createdAt != null)
                                              Text(
                                                DateFormat(
                                                  'dd MMM yyyy • hh:mm a',
                                                ).format(createdAt),
                                                style: TextStyle(
                                                  color: tokens.textSecondary,
                                                  fontWeight: FontWeight.w600,
                                                ),
                                              ),
                                          ],
                                        ),
                                      ),
                                    ],
                                  ),
                                  if ((row['message'] ?? '')
                                      .toString()
                                      .trim()
                                      .isNotEmpty) ...[
                                    const SizedBox(height: 12),
                                    Align(
                                      alignment: Alignment.centerLeft,
                                      child: Text(
                                        row['message'].toString().trim(),
                                        style: TextStyle(
                                          color: tokens.textSecondary,
                                          fontWeight: FontWeight.w600,
                                        ),
                                      ),
                                    ),
                                  ],
                                  const SizedBox(height: 14),
                                  Row(
                                    children: [
                                      Expanded(
                                        child: OutlinedButton(
                                          onPressed: _busy ? null : () => _reject(row),
                                          child: const Text('Reject'),
                                        ),
                                      ),
                                      const SizedBox(width: 12),
                                      Expanded(
                                        child: FilledButton(
                                          onPressed: _busy ? null : () => _approve(row),
                                          child: const Text('Approve'),
                                        ),
                                      ),
                                    ],
                                  ),
                                ],
                              ),
                            );
                          },
                        ),
        ),
      ),
    );
  }
}
