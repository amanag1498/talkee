import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:intl/intl.dart';

import '../../../app/theme/brand.dart';
import '../../../services/app_settings_service.dart';
import '../../Live/services/live_service.dart';

class BlockedUsersPage extends StatefulWidget {
  const BlockedUsersPage({super.key});

  @override
  State<BlockedUsersPage> createState() => _BlockedUsersPageState();
}

class _BlockedUsersPageState extends State<BlockedUsersPage> {
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
      final rows = await _live.fetchHostBlockedUsers();
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

  Future<void> _unblock(Map<String, dynamic> row) async {
    final userId = _toInt(row['user_id']);
    if (userId == null || _busy) return;
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) {
        return AlertDialog(
          title: const Text('Unblock user?'),
          content: Text(
            'This will allow ${_label(row)} to join your rooms again.',
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(context).pop(false),
              child: const Text('Cancel'),
            ),
            FilledButton(
              onPressed: () => Navigator.of(context).pop(true),
              child: const Text('Unblock'),
            ),
          ],
        );
      },
    );
    if (ok != true) return;
    setState(() => _busy = true);
    try {
      await _live.unblockUser(userId: userId);
      if (!mounted) return;
      setState(() {
        _rows = _rows.where((entry) => _toInt(entry['user_id']) != userId).toList();
      });
      Get.snackbar(
        'Moderation',
        '${_label(row)} was unblocked.',
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

  String _label(Map<String, dynamic> row) =>
      (row['name'] ?? 'User').toString().trim().isNotEmpty
          ? row['name'].toString().trim()
          : 'User';

  int? _toInt(dynamic value) {
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse(value?.toString() ?? '');
  }

  @override
  Widget build(BuildContext context) {
    final tokens = _tokens;
    return Scaffold(
      backgroundColor: tokens.backgroundGradient.first,
      appBar: AppBar(
        title: const Text('Blocked Users'),
        backgroundColor: Colors.transparent,
        elevation: 0,
      ),
      body: DecoratedBox(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [
              tokens.backgroundGradient.first,
              tokens.backgroundGradient.last,
            ],
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
                            textAlign: TextAlign.center,
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
                                'No blocked users yet.',
                                style: TextStyle(
                                  color: tokens.textPrimary,
                                  fontSize: 16,
                                  fontWeight: FontWeight.w700,
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
                            final blockedAt = DateTime.tryParse(
                              (row['blocked_at'] ?? row['created_at'] ?? '')
                                  .toString(),
                            );
                            final avatarUrl = row['avatar']?.toString();
                            final level = _toInt(row['level']);
                            final isVip = row['is_vip'] == true;
                            return Container(
                              padding: const EdgeInsets.all(14),
                              decoration: BoxDecoration(
                                gradient: LinearGradient(
                                  colors: [
                                    tokens.cardGradient.first.withOpacity(.96),
                                    tokens.cardGradient.last.withOpacity(.94),
                                  ],
                                  begin: Alignment.topLeft,
                                  end: Alignment.bottomRight,
                                ),
                                borderRadius: BorderRadius.circular(22),
                                border: Border.all(color: tokens.borderColor),
                              ),
                              child: Row(
                                children: [
                                  CircleAvatar(
                                    radius: 24,
                                    backgroundColor: tokens.primaryButtonGradient.first,
                                    backgroundImage: avatarUrl != null &&
                                            avatarUrl.trim().isNotEmpty
                                        ? NetworkImage(avatarUrl.trim())
                                        : null,
                                    child: avatarUrl == null || avatarUrl.trim().isEmpty
                                        ? Text(
                                            _label(row).characters.first.toUpperCase(),
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
                                          _label(row),
                                          style: TextStyle(
                                            color: tokens.textPrimary,
                                            fontWeight: FontWeight.w800,
                                            fontSize: 16,
                                          ),
                                        ),
                                        const SizedBox(height: 4),
                                        Wrap(
                                          spacing: 8,
                                          runSpacing: 6,
                                          children: [
                                            if (level != null)
                                              _MiniChip(label: 'LV $level'),
                                            if (isVip)
                                              const _MiniChip(label: 'VIP'),
                                            if (blockedAt != null)
                                              _MiniChip(
                                                label: DateFormat(
                                                  'dd MMM yyyy',
                                                ).format(blockedAt),
                                              ),
                                          ],
                                        ),
                                        if ((row['reason'] ?? '')
                                            .toString()
                                            .trim()
                                            .isNotEmpty) ...[
                                          const SizedBox(height: 8),
                                          Text(
                                            row['reason'].toString().trim(),
                                            style: TextStyle(
                                              color: tokens.textSecondary,
                                              fontWeight: FontWeight.w600,
                                            ),
                                          ),
                                        ],
                                      ],
                                    ),
                                  ),
                                  const SizedBox(width: 12),
                                  FilledButton(
                                    onPressed: _busy ? null : () => _unblock(row),
                                    child: const Text('Unblock'),
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

class _MiniChip extends StatelessWidget {
  const _MiniChip({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: tokens.glassColor.withOpacity(.18),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: tokens.borderColor.withOpacity(.6)),
      ),
      child: Text(
        label,
        style: TextStyle(
          color: tokens.textSecondary,
          fontSize: 11,
          fontWeight: FontWeight.w700,
        ),
      ),
    );
  }
}
