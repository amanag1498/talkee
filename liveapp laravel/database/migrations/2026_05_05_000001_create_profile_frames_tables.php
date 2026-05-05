<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_frames', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->string('asset_url', 500)->nullable();
            $table->string('thumbnail_url', 500)->nullable();
            $table->string('rarity', 40)->default('rare');
            $table->string('category', 60)->default('general');
            $table->string('unlock_type', 60)->default('free_catalog');
            $table->unsignedInteger('valid_days')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('user_profile_frames', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('profile_frame_id')->constrained()->cascadeOnDelete();
            $table->string('source', 80)->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_equipped')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'profile_frame_id'], 'user_profile_frames_unique');
            $table->index(['user_id', 'is_equipped'], 'user_profile_frames_user_equipped_idx');
        });

        DB::table('profile_frames')->insert([
            [
                'name' => 'Crimson Heart Halo',
                'slug' => 'crimson-heart-halo',
                'asset_url' => 'profile-frames/seed/crimson-heart-halo.png',
                'thumbnail_url' => 'profile-frames/seed/crimson-heart-halo.png',
                'rarity' => 'legendary',
                'category' => 'romance',
                'unlock_type' => 'free_catalog',
                'sort_order' => 10,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Blush Angel Halo',
                'slug' => 'blush-angel-halo',
                'asset_url' => 'profile-frames/seed/blush-angel-halo.png',
                'thumbnail_url' => 'profile-frames/seed/blush-angel-halo.png',
                'rarity' => 'epic',
                'category' => 'romance',
                'unlock_type' => 'free_catalog',
                'sort_order' => 20,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Violet Aura Halo',
                'slug' => 'violet-aura-halo',
                'asset_url' => 'profile-frames/seed/violet-aura-halo.png',
                'thumbnail_url' => 'profile-frames/seed/violet-aura-halo.png',
                'rarity' => 'epic',
                'category' => 'mystic',
                'unlock_type' => 'free_catalog',
                'sort_order' => 30,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Solar Laurel Crest',
                'slug' => 'solar-laurel-crest',
                'asset_url' => 'profile-frames/seed/solar-laurel-crest.png',
                'thumbnail_url' => 'profile-frames/seed/solar-laurel-crest.png',
                'rarity' => 'rare',
                'category' => 'royal',
                'unlock_type' => 'free_catalog',
                'sort_order' => 40,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Obsidian Royal Crest',
                'slug' => 'obsidian-royal-crest',
                'asset_url' => 'profile-frames/seed/obsidian-royal-crest.png',
                'thumbnail_url' => 'profile-frames/seed/obsidian-royal-crest.png',
                'rarity' => 'mythic',
                'category' => 'royal',
                'unlock_type' => 'free_catalog',
                'sort_order' => 50,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Sapphire Heart Halo',
                'slug' => 'sapphire-heart-halo',
                'asset_url' => 'profile-frames/seed/sapphire-heart-halo.png',
                'thumbnail_url' => 'profile-frames/seed/sapphire-heart-halo.png',
                'rarity' => 'epic',
                'category' => 'romance',
                'unlock_type' => 'free_catalog',
                'sort_order' => 60,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Rose Crown Halo',
                'slug' => 'rose-crown-halo',
                'asset_url' => 'profile-frames/seed/rose-crown-halo.png',
                'thumbnail_url' => 'profile-frames/seed/rose-crown-halo.png',
                'rarity' => 'legendary',
                'category' => 'romance',
                'unlock_type' => 'free_catalog',
                'sort_order' => 70,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Lion King Crest',
                'slug' => 'lion-king-crest',
                'asset_url' => 'profile-frames/seed/lion-king-crest.png',
                'thumbnail_url' => 'profile-frames/seed/lion-king-crest.png',
                'rarity' => 'mythic',
                'category' => 'royal',
                'unlock_type' => 'free_catalog',
                'sort_order' => 80,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Host Sovereign Crest',
                'slug' => 'host-sovereign-crest',
                'asset_url' => 'profile-frames/seed/host-sovereign-crest.png',
                'thumbnail_url' => 'profile-frames/seed/host-sovereign-crest.png',
                'rarity' => 'mythic',
                'category' => 'host',
                'unlock_type' => 'free_catalog',
                'sort_order' => 90,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profile_frames');
        Schema::dropIfExists('profile_frames');
    }
};
