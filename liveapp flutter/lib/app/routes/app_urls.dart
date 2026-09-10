class AppUrls {
  static const String apiHost = String.fromEnvironment(
    'APP_API_HOST',
    defaultValue: 'talkee.in',
  );
  static const String socketHost = String.fromEnvironment(
    'APP_SOCKET_HOST',
    defaultValue: 'socket.talkee.in',
  );
  //   static const String apiHost = String.fromEnvironment(
  //     'APP_API_HOST',
  //     defaultValue: '192.168.29.41',
  //   );
  //   static const String socketHost = String.fromEnvironment(
  //     'APP_SOCKET_HOST',
  //     defaultValue: '192.168.29.41',
  //   );
  static const int apiPort = int.fromEnvironment(
    'APP_API_PORT',
    defaultValue: 443,
  );
  static const int wsPort = int.fromEnvironment(
    'APP_WS_PORT',
    defaultValue: 443,
  );
  static const String apiScheme = String.fromEnvironment(
    'APP_API_SCHEME',
    defaultValue: 'https',
  );
  static const String socketScheme = String.fromEnvironment(
    'APP_SOCKET_SCHEME',
    defaultValue: 'https',
  );

  static String get apiOrigin => _buildOrigin(apiScheme, apiHost, apiPort);
  static String get socketOrigin =>
      _buildOrigin(socketScheme, socketHost, wsPort);
  static String get websiteOrigin => apiOrigin;
  static String get apiBase => '$apiOrigin/api';
  static String get wsPresence => '$socketOrigin/presence';
  static String get wsRooms => '$socketOrigin/rooms';
  static String get wsCalls => '$socketOrigin/calls';
  static String get wsGames => '$socketOrigin/games';
  static String get privacyPolicyUrl => '$websiteOrigin/privacy-policy';
  static String get termsOfServiceUrl => '$websiteOrigin/terms-of-service';
  static String get accountDeletionUrl => '$websiteOrigin/account-deletion';
  static String get supportUrl => websiteOrigin;
  static const String supportEmail = 'admin@talkee.in';
  static String get deactivateAccountMailto =>
      'mailto:$supportEmail?subject=${Uri.encodeComponent('Talkieo account deactivation request')}';

  static String _buildOrigin(String scheme, String host, int port) {
    final normalizedScheme = scheme.trim().toLowerCase();
    final omitPort =
        (normalizedScheme == 'https' && port == 443) ||
        (normalizedScheme == 'http' && port == 80);
    return omitPort
        ? '$normalizedScheme://$host'
        : '$normalizedScheme://$host:$port';
  }
}
