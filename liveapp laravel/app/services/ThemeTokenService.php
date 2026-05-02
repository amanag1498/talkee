<?php

namespace App\Services;

use App\Models\Theme;
use InvalidArgumentException;

class ThemeTokenService
{
    private const REQUIRED_KEYS = [
        'backgroundGradient',
        'cardGradient',
        'glassColor',
        'borderColor',
        'glowColor',
        'primaryButtonGradient',
        'chipColor',
        'textPrimary',
        'textSecondary',
        'dangerColor',
        'successColor',
    ];

    private const GRADIENT_KEYS = [
        'backgroundGradient',
        'cardGradient',
        'primaryButtonGradient',
    ];

    private const TEXT_KEYS = [
        'textPrimary',
        'textSecondary',
    ];

    public function requiredKeys(): array
    {
        return self::REQUIRED_KEYS;
    }

    public function validateAndSanitizeRemoteTokens(?string $json): ?array
    {
        $raw = trim((string) $json);
        if ($raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('Remote tokens must be valid JSON.');
        }

        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Remote tokens must be a JSON object.');
        }

        return $this->sanitizeTokenMap($decoded);
    }

    public function sanitizeTokenMap(array $tokens): array
    {
        foreach (self::REQUIRED_KEYS as $key) {
            if (!array_key_exists($key, $tokens)) {
                throw new InvalidArgumentException("Remote token field [{$key}] is required.");
            }
        }

        $sanitized = [];

        foreach (self::GRADIENT_KEYS as $key) {
            $value = $tokens[$key];
            if (!is_array($value) || count($value) !== 2) {
                throw new InvalidArgumentException("Remote token field [{$key}] must contain exactly 2 colors.");
            }

            $sanitized[$key] = array_map(
                fn ($color) => $this->sanitizeHexColor($color, false, $key),
                array_values($value)
            );
        }

        foreach (array_diff(self::REQUIRED_KEYS, self::GRADIENT_KEYS) as $key) {
            $sanitized[$key] = $this->sanitizeHexColor(
                $tokens[$key],
                in_array($key, self::TEXT_KEYS, true),
                $key,
            );
        }

        return $sanitized;
    }

    public function remoteTokensForTheme(
        Theme $theme,
        ?int $appVersionCode = null,
        bool $respectAppVersion = true,
    ): ?array
    {
        if (!in_array($theme->token_source, ['remote', 'hybrid'], true)) {
            return null;
        }

        if (
            !$theme->is_active
            || ($respectAppVersion && !$this->isCompatibleWithAppVersion($theme, $appVersionCode))
        ) {
            return null;
        }

        $raw = $theme->remote_tokens;
        if (!is_array($raw)) {
            return null;
        }

        try {
            return $this->sanitizeTokenMap($raw);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function previewTokensForTheme(Theme $theme, ?int $appVersionCode = null): ?array
    {
        if (!in_array($theme->token_source, ['remote', 'hybrid'], true)) {
            return null;
        }

        if (!$theme->is_active) {
            return null;
        }

        if (!$this->isCompatibleWithAppVersion($theme, $appVersionCode)) {
            return null;
        }

        $candidate = is_array($theme->preview_tokens) ? $theme->preview_tokens : $theme->remote_tokens;
        if (!is_array($candidate)) {
            return null;
        }

        try {
            return $this->sanitizeTokenMap($candidate);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function isCompatibleWithAppVersion(Theme $theme, ?int $appVersionCode): bool
    {
        if ($appVersionCode === null || $appVersionCode <= 0) {
            return true;
        }

        if ($theme->min_app_version !== null && $appVersionCode < (int) $theme->min_app_version) {
            return false;
        }

        if ($theme->max_app_version !== null && $appVersionCode > (int) $theme->max_app_version) {
            return false;
        }

        return true;
    }

    private function sanitizeHexColor(mixed $value, bool $opaqueTextOnly, string $key): string
    {
        $color = strtoupper(trim((string) $value));
        if (!preg_match('/^#(?:[0-9A-F]{6}|[0-9A-F]{8})$/', $color)) {
            throw new InvalidArgumentException("Remote token field [{$key}] must be a valid hex color.");
        }

        if ($opaqueTextOnly && strlen($color) === 9 && substr($color, 1, 2) !== 'FF') {
            throw new InvalidArgumentException("Remote token field [{$key}] must be fully opaque.");
        }

        if (strlen($color) === 9 && substr($color, 1, 2) === '00') {
            throw new InvalidArgumentException("Remote token field [{$key}] cannot be fully transparent.");
        }

        return $color;
    }
}
