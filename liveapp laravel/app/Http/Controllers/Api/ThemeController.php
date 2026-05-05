<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ThemeUnlockService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ThemeController extends Controller
{
    public function __construct(private ThemeUnlockService $themes)
    {
    }

    public function index(Request $request)
    {
        $appVersionCode = (int) $request->header('X-App-Version-Code', 0);
        return response()->json([
            'ok' => true,
            'data' => $this->themes->catalogFor($request->user(), $appVersionCode > 0 ? $appVersionCode : null),
        ]);
    }

    public function select(Request $request)
    {
        $data = $request->validate([
            'theme_key' => 'required|string|max:80',
        ]);

        try {
            $appVersionCode = (int) $request->header('X-App-Version-Code', 0);
            $activeThemeKey = $this->themes->selectTheme(
                $request->user(),
                $data['theme_key'],
                $appVersionCode > 0 ? $appVersionCode : null,
            );
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
            if ($message === 'Theme does not exist.') {
                throw ValidationException::withMessages([
                    'theme_key' => $message,
                ]);
            }

            return response()->json([
                'ok' => false,
                'message' => $message,
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'active_theme_key' => $activeThemeKey,
                'fallback_theme_key' => ThemeUnlockService::FALLBACK_THEME_KEY,
            ],
        ]);
    }

    public function purchase(Request $request)
    {
        $data = $request->validate([
            'theme_key' => 'required|string|max:80',
        ]);

        try {
            $result = $this->themes->purchaseTheme(
                $request->user(),
                $data['theme_key'],
                $request->header('Idempotency-Key') ?: $request->input('idempotency_key'),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'theme_key' => $result['theme']->key,
                'active_theme_key' => $this->themes->activeThemeKeyFor($request->user()),
                'catalog' => $this->themes->catalogFor($request->user()),
            ],
        ], 201);
    }
}
