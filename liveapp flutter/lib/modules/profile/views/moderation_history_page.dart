import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:intl/intl.dart';

import '../../../app/theme/brand.dart';
import '../../../services/app_settings_service.dart';
import '../../Live/services/live_service.dart';

class ModerationHistoryPage extends StatefulWidget {
  const ModerationHistoryPage({super.key});

  @override
  State<ModerationHistoryPage> createState() => _ModerationHistoryPageState();
}

class _ModerationHistoryPageState extends State<ModerationHistoryPage> {
  final LiveService _live = Get.find<LiveService>();
  bool _loading = true;
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
      final rows = await _live.fetchHostModerationHistory();
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

  Map<String, dynamic> _mapOf(dynamic value) {
    if (value is Map<String, dynamic>) return value;
    if (value is Map) return Map<String, dynamic>.from(value);
    return const <String, dynamic>{};
  }

  String _targetName(Map<String, dynamic> row) {
    final target = _mapOf(row['target_user']);
    final name = target['name']?.toString().trim() ?? '';
    if (name.isNotEmpty) return name;
    return 'User #${row['target_user_id'] ?? ''}';
  }

  @override
  Widget build(BuildContext context) {
    final tokens = _tokens;
    return Scaffold(
      backgroundColor: tokens.backgroundGradient.first,
      appBar: AppBar(
        title: const Text('Moderation History'),
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
                                'No moderation history yet.',
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
                            final createdAt = DateTime.tryParse(
                              (row['created_at'] ?? '').toString(),
                            );
                            final actionType = (row['action_type'] ?? 'action')
                                .toString()
                                .replaceAll('_', ' ')
                                .toUpperCase();
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
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    actionType,
                                    style: TextStyle(
                                      color: tokens.textPrimary,
                                      fontWeight: FontWeight.w800,
                                      fontSize: 13,
                                      letterSpacing: .4,
                                    ),
                                  ),
                                  const SizedBox(height: 8),
                                  Text(
                                    _targetName(row),
                                    style: TextStyle(
                                      color: tokens.textPrimary,
                                      fontWeight: FontWeight.w800,
                                      fontSize: 17,
                                    ),
                                  ),
                                  if ((row['reason'] ?? '')
                                      .toString()
                                      .trim()
                                      .isNotEmpty) ...[
                                    const SizedBox(height: 6),
                                    Text(
                                      row['reason'].toString().trim(),
                                      style: TextStyle(
                                        color: tokens.textSecondary,
                                        fontWeight: FontWeight.w600,
                                      ),
                                    ),
                                  ],
                                  const SizedBox(height: 8),
                                  Text(
                                    createdAt == null
                                        ? 'Unknown time'
                                        : DateFormat(
                                            'dd MMM yyyy • hh:mm a',
                                          ).format(createdAt),
                                    style: TextStyle(
                                      color: tokens.textSecondary.withOpacity(.82),
                                      fontWeight: FontWeight.w600,
                                    ),
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
