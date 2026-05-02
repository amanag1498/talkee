import 'package:flutter/material.dart';
import '../theme/brand.dart';

class GroupCard extends StatelessWidget {
  final String name;
  final int members;
  final VoidCallback? onTap;

  const GroupCard({super.key, required this.name, required this.members, this.onTap});

  @override
  Widget build(BuildContext context) {
    return InkWell(
      borderRadius: BorderRadius.circular(16),
      onTap: onTap,
      child: Ink(
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(16),
          gradient: const LinearGradient(
            colors: [kTalkeePrimary, Color(0xFF3E2374)],
            begin: Alignment.topLeft, end: Alignment.bottomRight,
          ),
        ),
        child: Padding(
          padding: const EdgeInsets.all(14.0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Icon(Icons.forum_rounded, color: Colors.white, size: 22),
              const Spacer(),
              Text(name,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 16)),
              const SizedBox(height: 6),
              Text('$members members',
                  style: TextStyle(color: Colors.white.withOpacity(.9), fontWeight: FontWeight.w500)),
            ],
          ),
        ),
      ),
    );
  }
}
