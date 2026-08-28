import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:in_app_purchase_platform_interface/in_app_purchase_platform_interface.dart';
import 'package:in_app_purchase_storekit/in_app_purchase_storekit.dart';
import 'package:uuid/uuid.dart';

import '../../../services/app_settings_service.dart';
import '../../../services/auth_service.dart';
import '../models/wallet_summary_dto.dart';
import 'wallet_api.dart';

enum ApplePurchaseOutcomeType { success, pending, cancelled, failed }

class ApplePurchaseOutcome {
  const ApplePurchaseOutcome({required this.type, this.summary, this.message});

  final ApplePurchaseOutcomeType type;
  final WalletSummaryDto? summary;
  final String? message;
}

class AppleInAppPurchaseService {
  AppleInAppPurchaseService({
    required WalletApi walletApi,
    required AuthService authService,
    InAppPurchasePlatform? store,
  }) : _walletApi = walletApi,
       _authService = authService,
       _store = store ?? _storeKitPlatform();

  final WalletApi _walletApi;
  final AuthService _authService;
  final InAppPurchasePlatform _store;
  final Map<String, ProductDetails> _products = {};
  final Set<String> _processingTransactions = {};
  final List<PurchaseDetails> _deferredPurchases = [];

  StreamSubscription<List<PurchaseDetails>>? _purchaseSubscription;
  Completer<ApplePurchaseOutcome>? _activePurchase;
  String? _activeProductId;
  bool _available = false;

  bool get isAvailable => _available;

  static InAppPurchasePlatform _storeKitPlatform() {
    InAppPurchaseStoreKitPlatform.registerPlatform();
    return InAppPurchasePlatform.instance;
  }

  Future<void> initialize() async {
    if (AppSettingsService.currentPlatform != 'ios' ||
        _purchaseSubscription != null) {
      return;
    }

    _purchaseSubscription = _store.purchaseStream.listen(
      _handlePurchaseUpdates,
      onError: (Object error, StackTrace stackTrace) {
        _finishActive(
          ApplePurchaseOutcome(
            type: ApplePurchaseOutcomeType.failed,
            message: _friendlyError(error),
          ),
        );
      },
    );
    _available = await _store.isAvailable();
  }

  Future<Map<String, String>> loadLocalizedPrices(
    List<WalletPackDto> packs,
  ) async {
    await initialize();
    if (!_available) {
      throw Exception('The App Store is currently unavailable.');
    }

    final productIds =
        packs
            .map((pack) => pack.appleProductId)
            .whereType<String>()
            .where((id) => id.isNotEmpty)
            .toSet();
    if (productIds.isEmpty) {
      throw Exception('Apple coin packs have not been configured.');
    }

    final response = await _store.queryProductDetails(productIds);
    if (response.error != null) {
      throw Exception(response.error!.message);
    }

    _products
      ..clear()
      ..addEntries(
        response.productDetails.map((product) => MapEntry(product.id, product)),
      );

    if (_products.isEmpty) {
      throw Exception(
        'Apple coin packs are not available for this App Store account.',
      );
    }

    unawaited(_retryDeferredPurchases());
    return {
      for (final product in response.productDetails) product.id: product.price,
    };
  }

  Future<ApplePurchaseOutcome> purchase(WalletPackDto pack) async {
    await initialize();
    if (!_available) {
      return const ApplePurchaseOutcome(
        type: ApplePurchaseOutcomeType.failed,
        message: 'The App Store is currently unavailable.',
      );
    }
    if (_activePurchase != null) {
      return const ApplePurchaseOutcome(
        type: ApplePurchaseOutcomeType.failed,
        message: 'Another Apple purchase is already in progress.',
      );
    }

    final productId = pack.appleProductId;
    if (productId == null || productId.isEmpty) {
      return const ApplePurchaseOutcome(
        type: ApplePurchaseOutcomeType.failed,
        message: 'This coin pack is not configured for Apple purchases.',
      );
    }

    var product = _products[productId];
    if (product == null) {
      await loadLocalizedPrices([pack]);
      product = _products[productId];
    }
    if (product == null) {
      return const ApplePurchaseOutcome(
        type: ApplePurchaseOutcomeType.failed,
        message: 'This coin pack is unavailable in the App Store.',
      );
    }

    final user = _authService.currentUser;
    if (user == null) {
      return const ApplePurchaseOutcome(
        type: ApplePurchaseOutcomeType.failed,
        message: 'Please sign in again before purchasing coins.',
      );
    }

    final completer = Completer<ApplePurchaseOutcome>();
    _activePurchase = completer;
    _activeProductId = productId;

    try {
      final started = await _store.buyConsumable(
        purchaseParam: PurchaseParam(
          productDetails: product,
          applicationUserName: const Uuid().v5(
            Uuid.NAMESPACE_URL,
            'com.techybugs.talkee:user:${user.id}',
          ),
        ),
      );
      if (!started) {
        _finishActive(
          const ApplePurchaseOutcome(
            type: ApplePurchaseOutcomeType.failed,
            message: 'Apple could not start the purchase.',
          ),
        );
      }
    } catch (error) {
      _finishActive(
        ApplePurchaseOutcome(
          type: ApplePurchaseOutcomeType.failed,
          message: _friendlyError(error),
        ),
      );
    }

    return completer.future;
  }

  Future<void> _handlePurchaseUpdates(List<PurchaseDetails> purchases) async {
    for (final purchase in purchases) {
      switch (purchase.status) {
        case PurchaseStatus.pending:
          if (purchase.productID == _activeProductId) {
            _finishActive(
              const ApplePurchaseOutcome(
                type: ApplePurchaseOutcomeType.pending,
                message:
                    'Apple is processing this purchase. Coins will be added after approval.',
              ),
            );
          }
          break;
        case PurchaseStatus.canceled:
          if (purchase.pendingCompletePurchase) {
            await _store.completePurchase(purchase);
          }
          if (purchase.productID == _activeProductId) {
            _finishActive(
              const ApplePurchaseOutcome(
                type: ApplePurchaseOutcomeType.cancelled,
              ),
            );
          }
          break;
        case PurchaseStatus.error:
          if (purchase.productID == _activeProductId) {
            _finishActive(
              ApplePurchaseOutcome(
                type: ApplePurchaseOutcomeType.failed,
                message:
                    purchase.error?.message ??
                    'Apple could not complete the purchase.',
              ),
            );
          }
          break;
        case PurchaseStatus.purchased:
        case PurchaseStatus.restored:
          await _deliverPurchase(purchase);
          break;
      }
    }
  }

  Future<void> _deliverPurchase(PurchaseDetails purchase) async {
    final transactionId = purchase.purchaseID?.trim() ?? '';
    if (transactionId.isEmpty || !_processingTransactions.add(transactionId)) {
      return;
    }

    try {
      if (!_authService.isLoggedIn) {
        _defer(purchase);
        return;
      }

      final summary = await _walletApi.verifyApplePurchase(
        productId: purchase.productID,
        transactionId: transactionId,
      );
      if (purchase.pendingCompletePurchase) {
        await _store.completePurchase(purchase);
      }
      if (purchase.productID == _activeProductId) {
        _finishActive(
          ApplePurchaseOutcome(
            type: ApplePurchaseOutcomeType.success,
            summary: summary,
          ),
        );
      }
    } catch (error) {
      _defer(purchase);
      if (purchase.productID == _activeProductId) {
        _finishActive(
          ApplePurchaseOutcome(
            type: ApplePurchaseOutcomeType.failed,
            message:
                'Apple received the payment, but wallet verification is pending. '
                'It will retry automatically.',
          ),
        );
      }
      debugPrint('[apple_iap] delivery deferred: $error');
    } finally {
      _processingTransactions.remove(transactionId);
    }
  }

  void _defer(PurchaseDetails purchase) {
    final transactionId = purchase.purchaseID;
    if (_deferredPurchases.any(
      (existing) => existing.purchaseID == transactionId,
    )) {
      return;
    }
    _deferredPurchases.add(purchase);
  }

  Future<void> _retryDeferredPurchases() async {
    if (!_authService.isLoggedIn || _deferredPurchases.isEmpty) return;
    final queued = List<PurchaseDetails>.from(_deferredPurchases);
    _deferredPurchases.clear();
    for (final purchase in queued) {
      await _deliverPurchase(purchase);
    }
  }

  void _finishActive(ApplePurchaseOutcome outcome) {
    final completer = _activePurchase;
    _activePurchase = null;
    _activeProductId = null;
    if (completer != null && !completer.isCompleted) {
      completer.complete(outcome);
    }
  }

  String _friendlyError(Object error) {
    return error
        .toString()
        .replaceFirst('Exception: ', '')
        .replaceFirst('PlatformException', 'Apple purchase error');
  }

  Future<void> dispose() async {
    await _purchaseSubscription?.cancel();
    _purchaseSubscription = null;
  }
}
