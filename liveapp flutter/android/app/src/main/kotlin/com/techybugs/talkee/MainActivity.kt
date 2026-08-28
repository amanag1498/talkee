package com.techybugs.talkee

import android.content.Context
import android.os.Build
import android.os.Bundle
import android.os.PowerManager
import android.os.VibrationEffect
import android.os.Vibrator
import android.os.VibratorManager
import android.provider.Settings
import android.view.WindowManager
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

class MainActivity : FlutterActivity() {
    private val CHANNEL = "com.techybugs.talkee/device"
    private var vibrator: Vibrator? = null
    private var callWakeLock: PowerManager.WakeLock? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        window.addFlags(WindowManager.LayoutParams.FLAG_SECURE)
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        vibrator = resolveVibrator()
    }

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)

        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, CHANNEL)
            .setMethodCallHandler { call, result ->
                when (call.method) {
                    "getDeviceId" -> {
                        try {
                            val id = Settings.Secure.getString(
                                contentResolver,
                                Settings.Secure.ANDROID_ID
                            )
                            result.success(id ?: "")
                        } catch (e: Exception) {
                            result.error("DEVICE_ID_ERROR", e.message, null)
                        }
                    }
                    "startCallVibration" -> {
                        try {
                            startCallVibration()
                            result.success(true)
                        } catch (e: Exception) {
                            result.error("VIBRATION_ERROR", e.message, null)
                        }
                    }

                    "stopCallVibration" -> {
                        try {
                            vibrator?.cancel()
                            result.success(true)
                        } catch (e: Exception) {
                            result.error("VIBRATION_STOP_ERROR", e.message, null)
                        }
                    }
                    "startKeepScreenAwake" -> {
                        try {
                            startKeepScreenAwake()
                            result.success(true)
                        } catch (e: Exception) {
                            result.error("SCREEN_AWAKE_ERROR", e.message, null)
                        }
                    }

                    "stopKeepScreenAwake" -> {
                        try {
                            stopKeepScreenAwake()
                            result.success(true)
                        } catch (e: Exception) {
                            result.error("SCREEN_AWAKE_STOP_ERROR", e.message, null)
                        }
                    }

                    else -> result.notImplemented()
                }
            }
    }

    private fun resolveVibrator(): Vibrator? {
        return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            val manager = getSystemService(Context.VIBRATOR_MANAGER_SERVICE) as VibratorManager
            manager.defaultVibrator
        } else {
            @Suppress("DEPRECATION")
            getSystemService(Context.VIBRATOR_SERVICE) as Vibrator
        }
    }

    private fun startCallVibration() {
        val activeVibrator = vibrator ?: resolveVibrator().also { vibrator = it } ?: return
        if (!activeVibrator.hasVibrator()) return

        activeVibrator.cancel()
        val pattern = longArrayOf(0, 650, 450, 650, 1200)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            activeVibrator.vibrate(
                VibrationEffect.createWaveform(pattern, 0)
            )
        } else {
            @Suppress("DEPRECATION")
            activeVibrator.vibrate(pattern, 0)
        }
    }

    private fun startKeepScreenAwake() {
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        val existing = callWakeLock
        if (existing?.isHeld == true) return

        val powerManager = getSystemService(Context.POWER_SERVICE) as PowerManager
        callWakeLock = powerManager.newWakeLock(
            PowerManager.SCREEN_BRIGHT_WAKE_LOCK,
            "Talkieo:ActiveCallScreenWakeLock"
        ).apply {
            setReferenceCounted(false)
            acquire()
        }
    }

    private fun stopKeepScreenAwake() {
        try {
            callWakeLock?.takeIf { it.isHeld }?.release()
        } catch (_: Exception) {
        } finally {
            callWakeLock = null
            window.clearFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        }
    }

    override fun onDestroy() {
        vibrator?.cancel()
        stopKeepScreenAwake()
        super.onDestroy()
    }
}
