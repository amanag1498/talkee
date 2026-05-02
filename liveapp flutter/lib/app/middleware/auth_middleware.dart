import 'package:flutter/material.dart';
import 'package:get/get.dart';
import '../../services/auth_service.dart';
import '../routes/app_routes.dart';

class AuthMiddleware extends GetMiddleware {
  final AuthService auth;
  AuthMiddleware(this.auth);

  @override
  RouteSettings? redirect(String? route) {
    if (!auth.isLoggedIn && route != Routes.login) {
      return const RouteSettings(name: Routes.login);
    }
    return null;
  }
}
