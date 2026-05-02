import 'package:flutter_test/flutter_test.dart';
import 'package:liveapp/app/theme/brand.dart';

void main() {
  group('premium theme tokens', () {
    test('returns tokens for all supported variants', () {
      for (final variant in [
        'midnight',
        'aurora',
        'gold',
      ]) {
        final tokens = getPremiumThemeTokens(variant);
        expect(tokens.backgroundGradient, isNotEmpty);
        expect(tokens.cardGradient, isNotEmpty);
        expect(tokens.primaryButtonGradient, isNotEmpty);
      }
    });

    test('normalizes invalid variants to midnight', () {
      expect(normalizePremiumThemeVariant('neon'), 'midnight');
      expect(normalizePremiumThemeVariant(''), 'midnight');
    });

    test('invalid variant falls back to midnight token set', () {
      final invalid = getPremiumThemeTokens('neon');
      final midnight = getPremiumThemeTokens('midnight');

      expect(invalid.backgroundGradient, midnight.backgroundGradient);
      expect(invalid.cardGradient, midnight.cardGradient);
      expect(invalid.primaryButtonGradient, midnight.primaryButtonGradient);
    });
  });
}
