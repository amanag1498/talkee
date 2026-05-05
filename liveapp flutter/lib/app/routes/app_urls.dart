class AppUrls {
//   static const String apiHost = String.fromEnvironment(
//     'APP_API_HOST',
//     defaultValue: '187.127.162.27',
//   );
//   static const String socketHost = String.fromEnvironment(
//     'APP_SOCKET_HOST',
//     defaultValue: '187.127.162.30',
//   );
  static const String apiHost = String.fromEnvironment(
    'APP_API_HOST',
    defaultValue: '192.168.29.41',
  );
  static const String socketHost = String.fromEnvironment(
    'APP_SOCKET_HOST',
    defaultValue: '192.168.29.41',
  );
  static const int apiPort =
      int.fromEnvironment('APP_API_PORT', defaultValue: 8000);
  static const int wsPort =
      int.fromEnvironment('APP_WS_PORT', defaultValue: 3001);
  static const String scheme =
      String.fromEnvironment('APP_SCHEME', defaultValue: 'http');

  static String get apiOrigin => '$scheme://$apiHost:$apiPort';
  static String get socketOrigin => '$scheme://$socketHost:$wsPort';
  static String get apiBase => '$apiOrigin/api';
  static String get wsPresence => '$socketOrigin/presence';
  static String get wsRooms => '$socketOrigin/rooms';
  static String get wsCalls => '$socketOrigin/calls';
}
