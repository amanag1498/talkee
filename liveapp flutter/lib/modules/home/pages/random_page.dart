import 'package:flutter/material.dart';

class RandomPage extends StatelessWidget {
  final double bottomPadding;
  const RandomPage({super.key, required this.bottomPadding});

  @override
  Widget build(BuildContext context) {
    final tiles = [
      ('Unseen Indie Games', Icons.videogame_asset_rounded),
      ('Bedroom Producers', Icons.music_note_rounded),
      ('City Night Walks', Icons.location_city_rounded),
      ('Noob to Pro Flutter', Icons.code_rounded),
      ('Chess Speedruns', Icons.extension_rounded),
      ('Spoken Word', Icons.mic_rounded),
    ];

    return GridView.builder(
      physics: const BouncingScrollPhysics(),
      padding: EdgeInsets.fromLTRB(16, kToolbarHeight + 8, 16, bottomPadding),
      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 2, crossAxisSpacing: 14, mainAxisSpacing: 14, childAspectRatio: 1.2,
      ),
      itemCount: tiles.length,
      itemBuilder: (_, i) {
        final t = tiles[i];
        return Container(
          decoration: BoxDecoration(
            color: Colors.white.withOpacity(.55),
            borderRadius: BorderRadius.circular(16),
            border: Border.all(color: Colors.white.withOpacity(.6)),
          ),
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(t.$2, size: 24, color: Colors.black87),
              const Spacer(),
              Text(t.$1, maxLines: 2, overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
            ],
          ),
        );
      },
    );
  }
}
