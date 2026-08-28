import Flutter
import Security
import UIKit

@main
@objc class AppDelegate: FlutterAppDelegate {
  private let deviceChannel = "com.techybugs.talkee/device"
  private let deviceIdAccount = "persistent-device-id"

  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    application.isIdleTimerDisabled = true
    GeneratedPluginRegistrant.register(with: self)

    if let controller = window?.rootViewController as? FlutterViewController {
      FlutterMethodChannel(
        name: deviceChannel,
        binaryMessenger: controller.binaryMessenger
      ).setMethodCallHandler { [weak self] call, result in
        guard call.method == "getDeviceId" else {
          result(FlutterMethodNotImplemented)
          return
        }

        do {
          result(try self?.persistentDeviceId() ?? "")
        } catch {
          result(
            FlutterError(
              code: "DEVICE_ID_ERROR",
              message: error.localizedDescription,
              details: nil
            )
          )
        }
      }
    }

    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }

  private func persistentDeviceId() throws -> String {
    let service = Bundle.main.bundleIdentifier ?? "com.techybugs.talkee"
    let lookup: [String: Any] = [
      kSecClass as String: kSecClassGenericPassword,
      kSecAttrService as String: service,
      kSecAttrAccount as String: deviceIdAccount,
      kSecReturnData as String: true,
      kSecMatchLimit as String: kSecMatchLimitOne,
    ]

    var item: CFTypeRef?
    let status = SecItemCopyMatching(lookup as CFDictionary, &item)
    if status == errSecSuccess,
       let data = item as? Data,
       let existing = String(data: data, encoding: .utf8),
       !existing.isEmpty {
      return existing
    }
    guard status == errSecItemNotFound else {
      throw NSError(domain: NSOSStatusErrorDomain, code: Int(status))
    }

    let generated = "ios:\(UUID().uuidString.lowercased())"
    var insert = lookup
    insert.removeValue(forKey: kSecReturnData as String)
    insert.removeValue(forKey: kSecMatchLimit as String)
    insert[kSecValueData as String] = Data(generated.utf8)
    insert[kSecAttrAccessible as String] =
      kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly

    let addStatus = SecItemAdd(insert as CFDictionary, nil)
    if addStatus == errSecDuplicateItem {
      return try persistentDeviceId()
    }
    guard addStatus == errSecSuccess else {
      throw NSError(domain: NSOSStatusErrorDomain, code: Int(addStatus))
    }
    return generated
  }
}
