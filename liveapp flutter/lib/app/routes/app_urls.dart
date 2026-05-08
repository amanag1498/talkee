class AppUrls {
  static const String apiHost = String.fromEnvironment(
    'APP_API_HOST',
    defaultValue: 'talkee.in',
  );
  static const String socketHost = String.fromEnvironment(
    'APP_SOCKET_HOST',
    defaultValue: '187.127.162.30',
  );
//   static const String apiHost = String.fromEnvironment(
//     'APP_API_HOST',
//     defaultValue: '192.168.29.41',
//   );
//   static const String socketHost = String.fromEnvironment(
//     'APP_SOCKET_HOST',
//     defaultValue: '192.168.29.41',
//   );
  static const int apiPort =
      int.fromEnvironment('APP_API_PORT', defaultValue: 443);
  static const int wsPort =
      int.fromEnvironment('APP_WS_PORT', defaultValue: 3001);
  static const String apiScheme =
      String.fromEnvironment('APP_API_SCHEME', defaultValue: 'https');
  static const String socketScheme =
      String.fromEnvironment('APP_SOCKET_SCHEME', defaultValue: 'http');

  static String get apiOrigin => _buildOrigin(apiScheme, apiHost, apiPort);
  static String get socketOrigin =>
      _buildOrigin(socketScheme, socketHost, wsPort);
  static String get apiBase => '$apiOrigin/api';
  static String get wsPresence => '$socketOrigin/presence';
  static String get wsRooms => '$socketOrigin/rooms';
  static String get wsCalls => '$socketOrigin/calls';

  static String _buildOrigin(String scheme, String host, int port) {
    final normalizedScheme = scheme.trim().toLowerCase();
    final omitPort =
        (normalizedScheme == 'https' && port == 443) ||
        (normalizedScheme == 'http' && port == 80);
    return omitPort ? '$normalizedScheme://$host' : '$normalizedScheme://$host:$port';
  }
}
