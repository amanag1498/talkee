import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../models/application_dto.dart';

class ApplicationStatusBanner extends StatelessWidget {
  final ApplicationItemDto item;

  const ApplicationStatusBanner({
    super.key,
    required this.item,
  });

  @override
  Widget build(BuildContext context) {
    final palette = _paletteFor(item.status);
    final submitted = item.submittedAt != null
        ? DateFormat('dd MMM yyyy').format(item.submittedAt!)
        : null;

    return Container(
      margin: const EdgeInsets.only(bottom: 16),
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: [palette.base, palette.base.withOpacity(.72)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(24),
        border: Border.all(color: palette.outline),
        boxShadow: [
          BoxShadow(
            color: palette.base.withOpacity(.18),
            blurRadius: 20,
            offset: const Offset(0, 10),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 42,
                height: 42,
                decoration: BoxDecoration(
                  color: Colors.white.withOpacity(.14),
                  borderRadius: BorderRadius.circular(14),
                ),
                child: Icon(palette.icon, color: Colors.white),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      item.title,
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 17,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      'Status: ${item.status.toUpperCase()}',
                      style: TextStyle(
                        color: Colors.white.withOpacity(.82),
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ],
                ),
              ),
              _StatusChip(
                label: item.status.toUpperCase(),
                tint: Colors.white,
              ),
            ],
          ),
          if (submitted != null) ...[
            const SizedBox(height: 14),
            Text(
              'Submitted on $submitted',
              style: TextStyle(
                color: Colors.white.withOpacity(.78),
                fontWeight: FontWeight.w600,
              ),
            ),
          ],
          if ((item.reviewNotes ?? '').trim().isNotEmpty) ...[
            const SizedBox(height: 12),
            Text(
              item.reviewNotes!.trim(),
              style: const TextStyle(
                color: Colors.white,
                height: 1.35,
                fontWeight: FontWeight.w600,
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _StatusPalette {
  final Color base;
  final Color outline;
  final IconData icon;

  const _StatusPalette({
    required this.base,
    required this.outline,
    required this.icon,
  });
}

_StatusPalette _paletteFor(String status) {
  switch (status) {
    case 'approved':
      return const _StatusPalette(
        base: Color(0xFF237A57),
        outline: Color(0x663EF2A0),
        icon: Icons.verified_rounded,
      );
    case 'rejected':
      return const _StatusPalette(
        base: Color(0xFF8D2E3D),
        outline: Color(0x66FF8A99),
        icon: Icons.cancel_rounded,
      );
    default:
      return const _StatusPalette(
        base: Color(0xFF4A327F),
        outline: Color(0x668C63FF),
        icon: Icons.hourglass_top_rounded,
      );
  }
}

class _StatusChip extends StatelessWidget {
  final String label;
  final Color tint;

  const _StatusChip({
    required this.label,
    required this.tint,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
      decoration: BoxDecoration(
        color: tint.withOpacity(.16),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: tint.withOpacity(.24)),
      ),
      child: Text(
        label,
        style: const TextStyle(
          color: Colors.white,
          fontSize: 11,
          fontWeight: FontWeight.w800,
          letterSpacing: .3,
        ),
      ),
    );
  }
}
