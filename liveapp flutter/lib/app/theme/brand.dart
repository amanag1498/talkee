import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

/// Brand colors you shared
const kTalkeePrimary = Color(0xFF673AB6); // #673AB6
const kTalkeeBg = Color(0xFFF0EBF8); // #F0EBF8

/// Optional: a subtle brand gold for accents (snackbars, chips, highlights)
const kTalkeeGold = Color(0xFFFFCC00);

/// Radius scale (kept consistent across components).
const _rSm = 12.0;
const _rMd = 16.0;
const _rLg = 22.0;
const _rXl = 28.0;

const List<String> kPremiumThemeVariants = <String>[
  'midnight',
  'aurora',
  'gold',
  'ocean',
  'inferno',
  'emerald',
  'ice',
  'cyberpunk',
  'ruby_sky',
  'violet_lime',
  'sunset_pop',
  'teal_rose',
  'gold_black',
  'obsidian_rose',
  'royal_sapphire',
  'noir_opal',
  'imperial_jade',
  'molten_pearl',
  'amethyst_chrome',
  'crimson_velvet',
];

@immutable
class PremiumThemeTokens {
  const PremiumThemeTokens({
    required this.backgroundGradient,
    required this.cardGradient,
    required this.glassColor,
    required this.borderColor,
    required this.glowColor,
    required this.primaryButtonGradient,
    required this.chipColor,
    required this.textPrimary,
    required this.textSecondary,
    required this.dangerColor,
    required this.successColor,
  });

  final List<Color> backgroundGradient;
  final List<Color> cardGradient;
  final Color glassColor;
  final Color borderColor;
  final Color glowColor;
  final List<Color> primaryButtonGradient;
  final Color chipColor;
  final Color textPrimary;
  final Color textSecondary;
  final Color dangerColor;
  final Color successColor;

  static PremiumThemeTokens? fromRemoteJson(Map<String, dynamic>? json) {
    if (json == null) return null;
    final backgroundGradient = _parseGradient(json['backgroundGradient']);
    final cardGradient = _parseGradient(json['cardGradient']);
    final primaryButtonGradient = _parseGradient(json['primaryButtonGradient']);
    final glassColor = _parseColor(json['glassColor']);
    final borderColor = _parseColor(json['borderColor']);
    final glowColor = _parseColor(json['glowColor']);
    final chipColor = _parseColor(json['chipColor']);
    final textPrimary = _parseColor(json['textPrimary'], opaqueOnly: true);
    final textSecondary = _parseColor(json['textSecondary'], opaqueOnly: true);
    final dangerColor = _parseColor(json['dangerColor']);
    final successColor = _parseColor(json['successColor']);

    if (backgroundGradient == null ||
        cardGradient == null ||
        primaryButtonGradient == null ||
        glassColor == null ||
        borderColor == null ||
        glowColor == null ||
        chipColor == null ||
        textPrimary == null ||
        textSecondary == null ||
        dangerColor == null ||
        successColor == null) {
      return null;
    }

    return PremiumThemeTokens(
      backgroundGradient: backgroundGradient,
      cardGradient: cardGradient,
      glassColor: glassColor,
      borderColor: borderColor,
      glowColor: glowColor,
      primaryButtonGradient: primaryButtonGradient,
      chipColor: chipColor,
      textPrimary: textPrimary,
      textSecondary: textSecondary,
      dangerColor: dangerColor,
      successColor: successColor,
    );
  }
}

Color? _parseColor(dynamic value, {bool opaqueOnly = false}) {
  final raw = value?.toString().trim().toUpperCase() ?? '';
  if (!RegExp(r'^#(?:[0-9A-F]{6}|[0-9A-F]{8})$').hasMatch(raw)) {
    return null;
  }

  final hex = raw.substring(1);
  if (opaqueOnly && hex.length == 8 && !hex.startsWith('FF')) {
    return null;
  }

  final normalizedHex = hex.length == 6 ? 'FF$hex' : hex;
  return Color(int.parse(normalizedHex, radix: 16));
}

List<Color>? _parseGradient(dynamic value) {
  if (value is! List || value.length != 2) {
    return null;
  }

  final first = _parseColor(value[0]);
  final second = _parseColor(value[1]);
  if (first == null || second == null) {
    return null;
  }

  return [first, second];
}

class _RemoteThemeRegistration {
  const _RemoteThemeRegistration({
    required this.tokenSource,
    required this.tokens,
  });

  final String tokenSource;
  final PremiumThemeTokens tokens;
}

final Map<String, _RemoteThemeRegistration> _remoteThemeRegistrations =
    <String, _RemoteThemeRegistration>{};

bool hasLocalPremiumThemeVariant(String variant) {
  return kPremiumThemeVariants.contains(variant.trim().toLowerCase());
}

void registerRemotePremiumThemeTokens({
  required String themeKey,
  required String tokenSource,
  required Map<String, dynamic> remoteTokens,
}) {
  final normalizedKey = themeKey.trim().toLowerCase();
  final normalizedSource = tokenSource.trim().toLowerCase();
  final parsed = PremiumThemeTokens.fromRemoteJson(remoteTokens);
  if (normalizedKey.isEmpty || parsed == null) {
    _remoteThemeRegistrations.remove(normalizedKey);
    return;
  }
  _remoteThemeRegistrations[normalizedKey] = _RemoteThemeRegistration(
    tokenSource: normalizedSource,
    tokens: parsed,
  );
}

void unregisterRemotePremiumThemeTokens([String? themeKey]) {
  if (themeKey == null) {
    _remoteThemeRegistrations.clear();
    return;
  }
  _remoteThemeRegistrations.remove(themeKey.trim().toLowerCase());
}

String normalizePremiumThemeVariant(String variant) {
  final normalized = variant.trim().toLowerCase();
  return kPremiumThemeVariants.contains(normalized) ||
          _remoteThemeRegistrations.containsKey(normalized)
      ? normalized
      : 'midnight';
}
PremiumThemeTokens resolvePremiumThemeTokens(
  String variant, {
  String? tokenSource,
  Map<String, dynamic>? remoteTokens,
}) {
  final normalized = variant.trim().toLowerCase();
  final normalizedSource = tokenSource?.trim().toLowerCase();
  final inlineRemote = PremiumThemeTokens.fromRemoteJson(remoteTokens);
  final registeredRemote = _remoteThemeRegistrations[normalized];

  if (normalizedSource == 'remote' && inlineRemote != null) {
    return inlineRemote;
  }

  if (normalizedSource == 'remote' &&
      registeredRemote != null &&
      registeredRemote.tokenSource == 'remote') {
    return registeredRemote.tokens;
  }

  if (hasLocalPremiumThemeVariant(normalized)) {
    return _localPremiumThemeTokens(normalized);
  }

  if (inlineRemote != null) {
    return inlineRemote;
  }

  if (registeredRemote != null) {
    return registeredRemote.tokens;
  }

  return _localPremiumThemeTokens('midnight');
}

PremiumThemeTokens getPremiumThemeTokens(String variant) {
  final normalized = variant.trim().toLowerCase();
  final registeredRemote = _remoteThemeRegistrations[normalized];
  if (registeredRemote != null && registeredRemote.tokenSource == 'remote') {
    return registeredRemote.tokens;
  }
  if (!hasLocalPremiumThemeVariant(normalized) && registeredRemote != null) {
    return registeredRemote.tokens;
  }
  return _localPremiumThemeTokens(normalizePremiumThemeVariant(variant));
}

PremiumThemeTokens _localPremiumThemeTokens(String normalizedVariant) {
  switch (normalizedVariant) {
    case 'midnight':
      return const PremiumThemeTokens(
        backgroundGradient: [Color(0xFF0D0A19), Color(0xFF1D1233)],
        cardGradient: [Color(0xFF1A1330), Color(0xFF271B45)],
        glassColor: Color(0xFF201631),
        borderColor: Color(0x4D8D79C7),
        glowColor: Color(0x668B5CF6),
        primaryButtonGradient: [Color(0xFF8B5CF6), Color(0xFF6D3FE4)],
        chipColor: Color(0xFF2B1E49),
        textPrimary: Colors.white,
        textSecondary: Color(0xFFC9BFE9),
        dangerColor: Color(0xFFFF6B81),
        successColor: Color(0xFF51D0A8),
      );
    case 'aurora':
      return const PremiumThemeTokens(
        backgroundGradient: [Color(0xFF091421), Color(0xFF12314F)],
        cardGradient: [Color(0xFF10243A), Color(0xFF183759)],
        glassColor: Color(0x5CFFFFFF),
        borderColor: Color(0x336FD4FF),
        glowColor: Color(0x665C8DFF),
        primaryButtonGradient: [Color(0xFF5C8DFF), Color(0xFF38C4FF)],
        chipColor: Color(0xFF193654),
        textPrimary: Colors.white,
        textSecondary: Color(0xFFC5DCF2),
        dangerColor: Color(0xFFFF788E),
        successColor: Color(0xFF41D6B0),
      );
    case 'gold':
      return const PremiumThemeTokens(
        backgroundGradient: [Color(0xFF17120A), Color(0xFF2C2110)],
        cardGradient: [Color(0xFF241A0E), Color(0xFF392815)],
        glassColor: Color(0x52FFF4D6),
        borderColor: Color(0x40FFD36A),
        glowColor: Color(0x66FFCC33),
        primaryButtonGradient: [Color(0xFFFFD54A), Color(0xFFFFB300)],
        chipColor: Color(0xFF3A2A12),
        textPrimary: Color(0xFFFFF7E7),
        textSecondary: Color(0xFFE7D3A3),
        dangerColor: Color(0xFFFF7A6B),
        successColor: Color(0xFF58D68D),
      );
      case 'ocean':
  return const PremiumThemeTokens(
    backgroundGradient: [Color(0xFF061A26), Color(0xFF0A2F3F)],
    cardGradient: [Color(0xFF0B2533), Color(0xFF103C52)],
    glassColor: Color(0x6638BDF8),
    borderColor: Color(0x3340C4FF),
    glowColor: Color(0x6638BDF8),
    primaryButtonGradient: [Color(0xFF38BDF8), Color(0xFF0EA5E9)],
    chipColor: Color(0xFF123E55),
    textPrimary: Colors.white,
    textSecondary: Color(0xFFB6DFF5),
    dangerColor: Color(0xFFFF6B6B),
    successColor: Color(0xFF4ADE80),
  );

case 'inferno':
  return const PremiumThemeTokens(
    backgroundGradient: [Color(0xFF1A0505), Color(0xFF3A0D0D)],
    cardGradient: [Color(0xFF2A0B0B), Color(0xFF4A1515)],
    glassColor: Color(0x66FF6B6B),
    borderColor: Color(0x66FF3B3B),
    glowColor: Color(0x66FF3B3B),
    primaryButtonGradient: [Color(0xFFFF3B3B), Color(0xFFFF7A18)],
    chipColor: Color(0xFF4A1A1A),
    textPrimary: Colors.white,
    textSecondary: Color(0xFFFFC9C9),
    dangerColor: Color(0xFFFF4D4D),
    successColor: Color(0xFF6EE7B7),
  );

case 'emerald':
  return const PremiumThemeTokens(
    backgroundGradient: [Color(0xFF051A12), Color(0xFF0F3D2E)],
    cardGradient: [Color(0xFF0B2B20), Color(0xFF144C3A)],
    glassColor: Color(0x664ADE80),
    borderColor: Color(0x334ADE80),
    glowColor: Color(0x664ADE80),
    primaryButtonGradient: [Color(0xFF22C55E), Color(0xFF16A34A)],
    chipColor: Color(0xFF124534),
    textPrimary: Colors.white,
    textSecondary: Color(0xFFB9F3D0),
    dangerColor: Color(0xFFFF6B6B),
    successColor: Color(0xFF4ADE80),
  );

case 'ice':
  return const PremiumThemeTokens(
    backgroundGradient: [Color(0xFF0F2027), Color(0xFF203A43)],
    cardGradient: [Color(0xFF1C2B33), Color(0xFF2C3E50)],
    glassColor: Color(0x55FFFFFF),
    borderColor: Color(0x33E0F2FE),
    glowColor: Color(0x66E0F2FE),
    primaryButtonGradient: [Color(0xFF60A5FA), Color(0xFF93C5FD)],
    chipColor: Color(0xFF2C3E50),
    textPrimary: Colors.white,
    textSecondary: Color(0xFFBFD9F2),
    dangerColor: Color(0xFFFF6B6B),
    successColor: Color(0xFF4ADE80),
  );

case 'cyberpunk':
  return const PremiumThemeTokens(
    backgroundGradient: [Color(0xFF0A0015), Color(0xFF1A0033)],
    cardGradient: [Color(0xFF14002A), Color(0xFF2A0055)],
    glassColor: Color(0x66FF00FF),
    borderColor: Color(0x33FF00FF),
    glowColor: Color(0x66FF00FF),
    primaryButtonGradient: [Color(0xFFFF00FF), Color(0xFF00FFFF)],
    chipColor: Color(0xFF2A0055),
    textPrimary: Colors.white,
    textSecondary: Color(0xFFE0B3FF),
    dangerColor: Color(0xFFFF4D4D),
    successColor: Color(0xFF00FFCC),
  );
case 'ruby_sky':
  return const PremiumThemeTokens(
    backgroundGradient: [Color(0xFF16030F), Color(0xFF10243D)],
    cardGradient: [Color(0xFF2A0B1C), Color(0xFF173656)],
    glassColor: Color(0x66FF5C8A),
    borderColor: Color(0x3379C7FF),
    glowColor: Color(0x66FF5C8A),
    primaryButtonGradient: [Color(0xFFFF5C8A), Color(0xFF4DB8FF)],
    chipColor: Color(0xFF2A2648),
    textPrimary: Colors.white,
    textSecondary: Color(0xFFE9D7F3),
    dangerColor: Color(0xFFFF6B7A),
    successColor: Color(0xFF4ADE80),
  );
case 'violet_lime':
  return const PremiumThemeTokens(
    backgroundGradient: [Color(0xFF12051E), Color(0xFF1B2A12)],
    cardGradient: [Color(0xFF220D35), Color(0xFF253F18)],
    glassColor: Color(0x6668F46D),
    borderColor: Color(0x338E7CFF),
    glowColor: Color(0x668E7CFF),
    primaryButtonGradient: [Color(0xFF8E7CFF), Color(0xFF68F46D)],
    chipColor: Color(0xFF2A213F),
    textPrimary: Colors.white,
    textSecondary: Color(0xFFE0E7D4),
    dangerColor: Color(0xFFFF738A),
    successColor: Color(0xFF68F46D),
  );
case 'sunset_pop':
  return const PremiumThemeTokens(
    backgroundGradient: [Color(0xFF241006), Color(0xFF4A1236)],
    cardGradient: [Color(0xFF3A180B), Color(0xFF5A1C46)],
    glassColor: Color(0x66FF8A3D),
    borderColor: Color(0x33FF56A5),
    glowColor: Color(0x66FF8A3D),
    primaryButtonGradient: [Color(0xFFFF8A3D), Color(0xFFFF56A5)],
    chipColor: Color(0xFF4B2030),
    textPrimary: Colors.white,
    textSecondary: Color(0xFFFFD8C8),
    dangerColor: Color(0xFFFF6F6B),
    successColor: Color(0xFF5BE7A9),
  );
case 'teal_rose':
  return const PremiumThemeTokens(
    backgroundGradient: [Color(0xFF071B1A), Color(0xFF3B1024)],
    cardGradient: [Color(0xFF0F2C2A), Color(0xFF4E1730)],
    glassColor: Color(0x6654E3C2),
    borderColor: Color(0x33FF6BA3),
    glowColor: Color(0x6654E3C2),
    primaryButtonGradient: [Color(0xFF54E3C2), Color(0xFFFF6BA3)],
    chipColor: Color(0xFF23343A),
    textPrimary: Colors.white,
    textSecondary: Color(0xFFE7D7E0),
    dangerColor: Color(0xFFFF6B7A),
    successColor: Color(0xFF54E3C2),
  );
case 'gold_black':
  return const PremiumThemeTokens(
    backgroundGradient: [Color(0xFF050505), Color(0xFF1A1408)],
    cardGradient: [Color(0xFF111111), Color(0xFF2B2110)],
    glassColor: Color(0x55FFD86B),
    borderColor: Color(0x40FFD86B),
    glowColor: Color(0x66FFCC33),
    primaryButtonGradient: [Color(0xFFFFD86B), Color(0xFFFFB300)],
    chipColor: Color(0xFF221A0E),
    textPrimary: Color(0xFFFFF8E8),
    textSecondary: Color(0xFFE4D2A1),
    dangerColor: Color(0xFFFF7A6B),
    successColor: Color(0xFF5DD39E),
  );
case 'obsidian_rose':
  return const PremiumThemeTokens(
    backgroundGradient: [Color(0xFF060608), Color(0xFF250E18)],
    cardGradient: [Color(0xFF121216), Color(0xFF3A1524)],
    glassColor: Color(0x55FF77A8),
    borderColor: Color(0x33FF77A8),
    glowColor: Color(0x66FF77A8),
    primaryButtonGradient: [Color(0xFFFF77A8), Color(0xFFFFB07A)],
    chipColor: Color(0xFF23171D),
    textPrimary: Colors.white,
    textSecondary: Color(0xFFF0D2DE),
    dangerColor: Color(0xFFFF6B7A),
    successColor: Color(0xFF63E6BE),
  );
case 'royal_sapphire':
  return const PremiumThemeTokens(
    backgroundGradient: [Color(0xFF050814), Color(0xFF1B163D)],
    cardGradient: [Color(0xFF0E1630), Color(0xFF2A2361)],
    glassColor: Color(0x556D8CFF),
    borderColor: Color(0x336D8CFF),
    glowColor: Color(0x666D8CFF),
    primaryButtonGradient: [Color(0xFF6D8CFF), Color(0xFFC9A7FF)],
    chipColor: Color(0xFF1E2447),
    textPrimary: Colors.white,
    textSecondary: Color(0xFFD6D9F8),
    dangerColor: Color(0xFFFF788E),
    successColor: Color(0xFF57D9B5),
  );
case 'noir_opal':
  return const PremiumThemeTokens(
    backgroundGradient: [Color(0xFF040507), Color(0xFF10211F)],
    cardGradient: [Color(0xFF0C0F14), Color(0xFF183430)],
    glassColor: Color(0x554FF2D2),
    borderColor: Color(0x336EE7D8),
    glowColor: Color(0x6659E3D2),
    primaryButtonGradient: [Color(0xFF7CF7E2), Color(0xFF7B8CFF)],
    chipColor: Color(0xFF162220),
    textPrimary: Color(0xFFF4FFFC),
    textSecondary: Color(0xFFC4E5DE),
    dangerColor: Color(0xFFFF7B8B),
    successColor: Color(0xFF7CF7E2),
  );
case 'imperial_jade':
  return const PremiumThemeTokens(
    backgroundGradient: [Color(0xFF06110D), Color(0xFF2A1B07)],
    cardGradient: [Color(0xFF10211A), Color(0xFF3B2810)],
    glassColor: Color(0x555CE1B9),
    borderColor: Color(0x33E8C36A),
    glowColor: Color(0x665CE1B9),
    primaryButtonGradient: [Color(0xFF5CE1B9), Color(0xFFE8C36A)],
    chipColor: Color(0xFF213026),
    textPrimary: Color(0xFFF7FFF9),
    textSecondary: Color(0xFFD7E8D6),
    dangerColor: Color(0xFFFF8570),
    successColor: Color(0xFF5CE1B9),
  );
case 'molten_pearl':
  return const PremiumThemeTokens(
    backgroundGradient: [Color(0xFF120B07), Color(0xFF3B1C14)],
    cardGradient: [Color(0xFF221512), Color(0xFF52342B)],
    glassColor: Color(0x55FFD7C9),
    borderColor: Color(0x33FFF1D6),
    glowColor: Color(0x66FFC6A8),
    primaryButtonGradient: [Color(0xFFFFC6A8), Color(0xFFFFF1D6)],
    chipColor: Color(0xFF3A2924),
    textPrimary: Color(0xFFFFFAF6),
    textSecondary: Color(0xFFF0D7CB),
    dangerColor: Color(0xFFFF8A76),
    successColor: Color(0xFF8FE3C3),
  );
case 'amethyst_chrome':
  return const PremiumThemeTokens(
    backgroundGradient: [Color(0xFF09090E), Color(0xFF26182F)],
    cardGradient: [Color(0xFF12131A), Color(0xFF3B2946)],
    glassColor: Color(0x55D8C8FF),
    borderColor: Color(0x33BFD0E8),
    glowColor: Color(0x66C8B4FF),
    primaryButtonGradient: [Color(0xFFC8B4FF), Color(0xFFBFD0E8)],
    chipColor: Color(0xFF272731),
    textPrimary: Color(0xFFFCFBFF),
    textSecondary: Color(0xFFDDD8E8),
    dangerColor: Color(0xFFFF8094),
    successColor: Color(0xFF8FE4D4),
  );
case 'crimson_velvet':
  return const PremiumThemeTokens(
    backgroundGradient: [Color(0xFF120507), Color(0xFF2B0D18)],
    cardGradient: [Color(0xFF1D0A0E), Color(0xFF441426)],
    glassColor: Color(0x55FF8A9B),
    borderColor: Color(0x33FFB36B),
    glowColor: Color(0x66FF6B81),
    primaryButtonGradient: [Color(0xFFFF6B81), Color(0xFFFFB36B)],
    chipColor: Color(0xFF31151D),
    textPrimary: Color(0xFFFFF7F8),
    textSecondary: Color(0xFFF0CDD3),
    dangerColor: Color(0xFFFF6B6B),
    successColor: Color(0xFF7FE0B5),
  );
    default:
      return const PremiumThemeTokens(
        backgroundGradient: [Color(0xFF0F0B1C), Color(0xFF23153C)],
        cardGradient: [Color(0xFF1B1230), Color(0xFF2A1C4A)],
        glassColor: Color(0xFF201631),
        borderColor: Color(0x4D8D79C7),
        glowColor: Color(0x66673AB6),
        primaryButtonGradient: [Color(0xFF7B50C5), Color(0xFF673AB6)],
        chipColor: Color(0xFF2B1D46),
        textPrimary: Colors.white,
        textSecondary: Color(0xFFD5C9F0),
        dangerColor: Color(0xFFFF6F8B),
        successColor: Color(0xFF4DD1A5),
      );
  }
}

/// Public: Light & Dark themes
ThemeData talkeeLightTheme({String variant = 'midnight'}) {
  final seed = _seedForVariant(variant);
  final background = _lightBackgroundForVariant(variant);
  final scheme = ColorScheme.fromSeed(
    seedColor: seed,
    primary: seed,
    brightness: Brightness.light,
    background: background,
  );

  return _baseTheme(scheme).copyWith(scaffoldBackgroundColor: background);
}

ThemeData talkeeDarkTheme({String variant = 'midnight'}) {
  final seed = _seedForVariant(variant);
  final background = _darkBackgroundForVariant(variant);
  final scheme = ColorScheme.fromSeed(
    seedColor: seed,
    brightness: Brightness.dark,
  );

  return _baseTheme(scheme).copyWith(scaffoldBackgroundColor: background);
}

Color _seedForVariant(String variant) {
  final normalized = variant.trim().toLowerCase();
  final registeredRemote = _remoteThemeRegistrations[normalized];
  if (registeredRemote?.tokenSource == 'remote' ||
      (!hasLocalPremiumThemeVariant(normalized) && registeredRemote != null)) {
    return registeredRemote!.tokens.primaryButtonGradient.first;
  }

  switch (normalized) {
    case 'aurora':
      return const Color(0xFF5C8DFF);
    case 'gold':
      return const Color(0xFFFFC845);
    case 'ocean':
      return const Color(0xFF38BDF8);
    case 'inferno':
      return const Color(0xFFFF3B3B);
    case 'emerald':
      return const Color(0xFF22C55E);
    case 'ice':
      return const Color(0xFF60A5FA);
    case 'cyberpunk':
      return const Color(0xFFFF00FF);
    case 'ruby_sky':
      return const Color(0xFFFF5C8A);
    case 'violet_lime':
      return const Color(0xFF8E7CFF);
    case 'sunset_pop':
      return const Color(0xFFFF8A3D);
    case 'teal_rose':
      return const Color(0xFF54E3C2);
    case 'gold_black':
      return const Color(0xFFFFD86B);
    case 'obsidian_rose':
      return const Color(0xFFFF77A8);
    case 'royal_sapphire':
      return const Color(0xFF6D8CFF);
    case 'noir_opal':
      return const Color(0xFF7CF7E2);
    case 'imperial_jade':
      return const Color(0xFF5CE1B9);
    case 'molten_pearl':
      return const Color(0xFFFFC6A8);
    case 'amethyst_chrome':
      return const Color(0xFFC8B4FF);
    case 'crimson_velvet':
      return const Color(0xFFFF6B81);
    case 'midnight':
    default:
      return const Color(0xFF8B5CF6);
  }
}

Color _lightBackgroundForVariant(String variant) {
  final normalized = variant.trim().toLowerCase();
  final registeredRemote = _remoteThemeRegistrations[normalized];
  if (registeredRemote?.tokenSource == 'remote' ||
      (!hasLocalPremiumThemeVariant(normalized) && registeredRemote != null)) {
    return registeredRemote!.tokens.backgroundGradient.last;
  }

  switch (normalized) {
    case 'aurora':
      return const Color(0xFFEAF4FF);
    case 'gold':
      return const Color(0xFFFFF7E6);
    case 'ruby_sky':
      return const Color(0xFFFFEEF4);
    case 'violet_lime':
      return const Color(0xFFF3F6E8);
    case 'sunset_pop':
      return const Color(0xFFFFEFE5);
    case 'teal_rose':
      return const Color(0xFFEEF7F5);
    case 'gold_black':
      return const Color(0xFFFFF8EA);
    case 'obsidian_rose':
      return const Color(0xFFFFEEF3);
    case 'royal_sapphire':
      return const Color(0xFFEEF2FF);
    case 'noir_opal':
      return const Color(0xFFF1FFFB);
    case 'imperial_jade':
      return const Color(0xFFF4FBF5);
    case 'molten_pearl':
      return const Color(0xFFFFF6F1);
    case 'amethyst_chrome':
      return const Color(0xFFF7F5FC);
    case 'crimson_velvet':
      return const Color(0xFFFFF1F3);
    case 'midnight':
    default:
      return const Color(0xFFF1ECFF);
  }
}

Color _darkBackgroundForVariant(String variant) {
  final normalized = variant.trim().toLowerCase();
  final registeredRemote = _remoteThemeRegistrations[normalized];
  if (registeredRemote?.tokenSource == 'remote' ||
      (!hasLocalPremiumThemeVariant(normalized) && registeredRemote != null)) {
    return registeredRemote!.tokens.backgroundGradient.first;
  }

  switch (normalized) {
    case 'aurora':
      return const Color(0xFF091421);
    case 'gold':
      return const Color(0xFF17120A);
    case 'ruby_sky':
      return const Color(0xFF16030F);
    case 'violet_lime':
      return const Color(0xFF12051E);
    case 'sunset_pop':
      return const Color(0xFF241006);
    case 'teal_rose':
      return const Color(0xFF071B1A);
    case 'gold_black':
      return const Color(0xFF050505);
    case 'obsidian_rose':
      return const Color(0xFF060608);
    case 'royal_sapphire':
      return const Color(0xFF050814);
    case 'noir_opal':
      return const Color(0xFF040507);
    case 'imperial_jade':
      return const Color(0xFF06110D);
    case 'molten_pearl':
      return const Color(0xFF120B07);
    case 'amethyst_chrome':
      return const Color(0xFF09090E);
    case 'crimson_velvet':
      return const Color(0xFF120507);
    case 'midnight':
    default:
      return const Color(0xFF0D0A19);
  }
}

/// Shared base (applies to both themes)
ThemeData _baseTheme(ColorScheme colorScheme) {
  //final isDark = colorScheme.brightness == Brightness.dark;
  final isDark = true;

  // Balanced typography (Material3), slightly bolder titles/labels
  final baseText =
      (isDark ? Typography.whiteMountainView : Typography.blackMountainView);
  final themedBase = GoogleFonts.plusJakartaSansTextTheme(baseText);
  final textTheme = themedBase.copyWith(
    displayLarge: themedBase.displayLarge?.copyWith(
      fontWeight: FontWeight.w800,
    ),
    displayMedium: themedBase.displayMedium?.copyWith(
      fontWeight: FontWeight.w800,
    ),
    displaySmall: themedBase.displaySmall?.copyWith(
      fontWeight: FontWeight.w800,
    ),
    headlineLarge: themedBase.headlineLarge?.copyWith(
      fontWeight: FontWeight.w800,
    ),
    headlineMedium: themedBase.headlineMedium?.copyWith(
      fontWeight: FontWeight.w800,
    ),
    headlineSmall: themedBase.headlineSmall?.copyWith(
      fontWeight: FontWeight.w800,
    ),
    titleLarge: themedBase.titleLarge?.copyWith(fontWeight: FontWeight.w700),
    titleMedium: themedBase.titleMedium?.copyWith(fontWeight: FontWeight.w700),
    titleSmall: themedBase.titleSmall?.copyWith(fontWeight: FontWeight.w700),
    labelLarge: themedBase.labelLarge?.copyWith(
      fontWeight: FontWeight.w700,
      letterSpacing: .2,
    ),
    labelMedium: themedBase.labelMedium?.copyWith(fontWeight: FontWeight.w700),
    labelSmall: themedBase.labelSmall?.copyWith(fontWeight: FontWeight.w700),
    bodyLarge: themedBase.bodyLarge?.copyWith(height: 1.25),
    bodyMedium: themedBase.bodyMedium?.copyWith(height: 1.25),
    bodySmall: themedBase.bodySmall?.copyWith(height: 1.25),
  );

  return ThemeData(
    useMaterial3: true,
    colorScheme: colorScheme,
    visualDensity: VisualDensity.adaptivePlatformDensity,
    fontFamily: GoogleFonts.plusJakartaSans().fontFamily,
    textTheme: textTheme,

    // AppBar: translucent + bold titles
    appBarTheme: AppBarTheme(
      backgroundColor: Colors.transparent,
      elevation: 0,
      scrolledUnderElevation: 0,
      surfaceTintColor: Colors.transparent,
      centerTitle: false,
      titleTextStyle: textTheme.titleLarge?.copyWith(
        color: isDark ? Colors.white : const Color(0xFF201339),
        fontWeight: FontWeight.w800,
      ),
      iconTheme: IconThemeData(
        color: isDark ? Colors.white : const Color(0xFF2B164D),
      ),
    ),

    // cardTheme: CardTheme(
    //   color: isDark ? colorScheme.surface : Colors.white,
    //   elevation: 0,
    //   margin: EdgeInsets.zero,
    //   surfaceTintColor: isDark ? null : Colors.white,
    //   shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(_rLg)),
    // ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: isDark ? colorScheme.surface.withOpacity(.95) : Colors.white,
      hintStyle: TextStyle(
        color: isDark ? Colors.white70 : const Color(0xFF6B5A8F),
      ),
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(_rMd),
        borderSide: BorderSide(
          color: isDark ? Colors.white10 : const Color(0xFFE6DFF4),
        ),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(_rMd),
        borderSide: BorderSide(
          color: isDark ? Colors.white10 : const Color(0xFFE6DFF4),
        ),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(_rMd),
        borderSide: BorderSide(color: colorScheme.primary, width: 1.6),
      ),
    ),

    elevatedButtonTheme: ElevatedButtonThemeData(
      style: ElevatedButton.styleFrom(
        backgroundColor: colorScheme.primary,
        foregroundColor: Colors.white,
        elevation: isDark ? 1.5 : 0,
        padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(_rLg),
        ),
        textStyle: textTheme.labelLarge,
      ),
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        backgroundColor:
            isDark
                ? colorScheme.secondaryContainer
                : colorScheme.primaryContainer,
        foregroundColor: isDark ? Colors.white : colorScheme.onPrimaryContainer,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(_rLg),
        ),
        padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
        textStyle: textTheme.labelLarge,
      ),
    ),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(
        foregroundColor: colorScheme.primary,
        side: BorderSide(color: colorScheme.primary.withOpacity(.5)),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(_rLg),
        ),
        padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
        textStyle: textTheme.labelLarge,
      ),
    ),

    chipTheme: ChipThemeData(
      backgroundColor:
          isDark
              ? colorScheme.surfaceContainerHighest
              : const Color(0xFFEDE5FA),
      labelStyle: textTheme.labelLarge!.copyWith(
        color: isDark ? Colors.white : const Color(0xFF3E256D),
      ),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(_rSm)),
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
      side: BorderSide(
        color: isDark ? Colors.white12 : const Color(0xFFE3D9F6),
      ),
      iconTheme: IconThemeData(color: isDark ? Colors.white : kTalkeePrimary),
    ),

    navigationBarTheme: NavigationBarThemeData(
      backgroundColor: (isDark ? const Color(0xFF151026) : Colors.white)
          .withOpacity(0.72),
      indicatorColor: kTalkeePrimary.withOpacity(.15),
      labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
      iconTheme: WidgetStateProperty.resolveWith((states) {
        final selected = states.contains(WidgetState.selected);
        return IconThemeData(
          color:
              selected
                  ? kTalkeePrimary
                  : (isDark ? Colors.white70 : const Color(0xFF5F4B8E)),
          size: selected ? 26 : 24,
        );
      }),
      labelTextStyle: WidgetStateProperty.all(
        textTheme.labelMedium?.copyWith(
          color: isDark ? Colors.white : const Color(0xFF3D276B),
          fontWeight: FontWeight.w700,
        ),
      ),
      height: 68,
    ),

    bottomSheetTheme: BottomSheetThemeData(
      backgroundColor: (isDark ? colorScheme.surface : Colors.white)
          .withOpacity(.92),
      modalBackgroundColor: (isDark ? colorScheme.surface : Colors.white)
          .withOpacity(.96),
      elevation: 0,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(_rXl)),
      ),
      showDragHandle: true,
      dragHandleColor: isDark ? Colors.white30 : const Color(0xFFBCA9E7),
    ),

    // dialogTheme: DialogTheme(
    //   backgroundColor: isDark ? colorScheme.surface : Colors.white,
    //   surfaceTintColor: isDark ? null : Colors.white,
    //   shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(_rXl)),
    //   titleTextStyle: textTheme.titleLarge?.copyWith(
    //     fontWeight: FontWeight.w800,
    //     color: isDark ? Colors.white : const Color(0xFF22163A),
    //   ),
    //   contentTextStyle: textTheme.bodyLarge?.copyWith(
    //     color: isDark ? Colors.white.withOpacity(.9) : const Color(0xFF3A2A5F),
    //   ),
    // ),
    snackBarTheme: SnackBarThemeData(
      behavior: SnackBarBehavior.floating,
      backgroundColor:
          isDark ? const Color(0xFF1D1631).withOpacity(.96) : Colors.white,
      contentTextStyle: textTheme.bodyMedium?.copyWith(
        color: isDark ? Colors.white : const Color(0xFF2B184D),
        fontWeight: FontWeight.w600,
      ),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(_rMd)),
      actionTextColor: kTalkeePrimary,
      elevation: isDark ? 1.5 : 0,
    ),

    dividerTheme: DividerThemeData(
      color: isDark ? Colors.white12 : const Color(0xFFE8E0F7),
      thickness: 1,
      space: 24,
    ),

    sliderTheme: SliderThemeData(
      activeTrackColor: colorScheme.primary,
      inactiveTrackColor: colorScheme.primary.withOpacity(.25),
      thumbColor: colorScheme.primary,
      overlayColor: colorScheme.primary.withOpacity(.12),
      trackHeight: 4,
      valueIndicatorColor: colorScheme.primary,
      valueIndicatorTextStyle: textTheme.labelSmall?.copyWith(
        color: Colors.white,
      ),
    ),

    switchTheme: SwitchThemeData(
      thumbColor: WidgetStateProperty.resolveWith(
        (s) =>
            s.contains(WidgetState.selected)
                ? colorScheme.primary
                : (isDark ? Colors.white70 : Colors.white),
      ),
      trackColor: WidgetStateProperty.resolveWith(
        (s) =>
            s.contains(WidgetState.selected)
                ? colorScheme.primary.withOpacity(.45)
                : (isDark ? Colors.white24 : const Color(0xFFE1D7F6)),
      ),
    ),

    progressIndicatorTheme: ProgressIndicatorThemeData(
      color: colorScheme.primary,
      linearTrackColor: colorScheme.primary.withOpacity(.2),
      circularTrackColor: colorScheme.primary.withOpacity(.18),
    ),
  );
}
