class AppUrls {
  static const String host =
    // String.fromEnvironment('APP_HOST', defaultValue: '192.168.1.10');
     String.fromEnvironment('APP_HOST', defaultValue: '192.168.29.41');
  static const int apiPort =
      int.fromEnvironment('APP_API_PORT', defaultValue: 8000);
  static const int wsPort =
      int.fromEnvironment('APP_WS_PORT', defaultValue: 3001);
  static const String scheme =
      String.fromEnvironment('APP_SCHEME', defaultValue: 'http');

  static String get apiBase => '$scheme://$host:$apiPort/api';
  static String get wsPresence => '$scheme://$host:$wsPort/presence';
  static String get wsRooms => '$scheme://$host:$wsPort/rooms';
  static String get wsCalls => '$scheme://$host:$wsPort/calls';
}
