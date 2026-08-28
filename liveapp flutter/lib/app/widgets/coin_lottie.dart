import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:lottie/lottie.dart';

Future<bool>? _coinAssetExists;

class CoinLottie extends StatelessWidget {
  const CoinLottie({super.key, this.size = 24, this.fit = BoxFit.contain});

  static const asset = 'assets/images/animations/coin.json';

  final double size;
  final BoxFit fit;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: size,
      height: size,
      child: FutureBuilder<bool>(
        future: _coinAssetExists ??= _assetExists(),
        builder: (context, snapshot) {
          if (snapshot.data == true) {
            return Lottie.asset(
              asset,
              width: size,
              height: size,
              fit: fit,
              repeat: true,
              frameRate: FrameRate.max,
            );
          }
          if (snapshot.connectionState != ConnectionState.done) {
            return const SizedBox.shrink();
          }
          return Icon(
            Icons.monetization_on_rounded,
            size: size,
            color: const Color(0xFFFFD54F),
          );
        },
      ),
    );
  }

  static Future<bool> _assetExists() async {
    try {
      await rootBundle.load(asset);
      return true;
    } catch (error, stackTrace) {
      if (kDebugMode) {
        debugPrint('Missing Fortune Wheel coin animation: $error');
        debugPrintStack(stackTrace: stackTrace);
      }
      return false;
    }
  }
}
