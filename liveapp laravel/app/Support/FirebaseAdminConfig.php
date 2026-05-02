<?php

namespace App\Support;

class FirebaseAdminConfig
{
    public static function serviceAccountPath(): ?string
    {
        $configured = env('FIREBASE_CREDENTIALS');
        $default = storage_path('app/firebase-admin.json');

        if (is_string($configured) && $configured !== '' && is_file($configured)) {
            return $configured;
        }

        if (is_file($default)) {
            return $default;
        }

        return $configured ?: $default;
    }

    public static function projectId(): ?string
    {
        $serviceAccountPath = self::serviceAccountPath();
        $serviceAccountJson = [];

        if (is_string($serviceAccountPath) && is_file($serviceAccountPath)) {
            $serviceAccountJson = json_decode((string) @file_get_contents($serviceAccountPath), true) ?: [];
        }

        return $serviceAccountJson['project_id'] ?? env('FIREBASE_PROJECT_ID');
    }
}
