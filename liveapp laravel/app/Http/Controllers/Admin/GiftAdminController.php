<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Gift;
use Illuminate\Http\Request;

class GiftAdminController extends Controller
{
    public function index(Request $request)
    {
        $q = Gift::query()->orderBy('sort_order')->orderBy('id','desc');

        if ($s = $request->string('s')->trim()) {
            $q->where('name','like',"%{$s}%");
        }
        if ($request->filled('active')) {
            $q->where('is_active', (bool) $request->boolean('active'));
        }

        $gifts = $q->paginate(20);
        return view('admin.gifts.index', compact('gifts'));
    }

    public function create()
    {
        return view('admin.gifts.create', [
            'giftTypes' => Gift::GIFT_TYPES,
            'animationTiers' => Gift::ANIMATION_TIERS,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'       => 'required|string|max:120',
            'coins'      => 'required|integer|min:1',
            'gift_url'   => 'nullable|url|max:2048',
            'gift_type'  => 'nullable|in:'.implode(',', Gift::GIFT_TYPES),
            'animation_tier' => 'nullable|in:'.implode(',', Gift::ANIMATION_TIERS),
            'animation_duration_ms' => 'nullable|integer|min:800|max:12000',
            'is_active'  => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:100000',
        ]);
        $data['is_active'] = $request->boolean('is_active');
        $data['gift_type'] = $data['gift_type'] ?? null;
        $data['animation_tier'] = $data['animation_tier'] ?? null;
        $data['animation_duration_ms'] = $data['animation_duration_ms'] ?? null;
        Gift::create($data);

        return redirect()->route('admin.gifts.index')->with('ok','Gift created.');
    }

    public function edit(Gift $gift)
    {
        return view('admin.gifts.edit', [
            'gift' => $gift,
            'giftTypes' => Gift::GIFT_TYPES,
            'animationTiers' => Gift::ANIMATION_TIERS,
        ]);
    }

    public function update(Request $request, Gift $gift)
    {
        $data = $request->validate([
            'name'       => 'required|string|max:120',
            'coins'      => 'required|integer|min:1',
            'gift_url'   => 'nullable|url|max:2048',
            'gift_type'  => 'nullable|in:'.implode(',', Gift::GIFT_TYPES),
            'animation_tier' => 'nullable|in:'.implode(',', Gift::ANIMATION_TIERS),
            'animation_duration_ms' => 'nullable|integer|min:800|max:12000',
            'is_active'  => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:100000',
        ]);
        $data['is_active'] = $request->boolean('is_active');
        $data['gift_type'] = $data['gift_type'] ?? null;
        $data['animation_tier'] = $data['animation_tier'] ?? null;
        $data['animation_duration_ms'] = $data['animation_duration_ms'] ?? null;
        $gift->update($data);

        return redirect()->route('admin.gifts.index')->with('ok','Gift updated.');
    }

    public function destroy(Gift $gift)
    {
        $gift->delete();
        return back()->with('ok','Gift deleted.');
    }
}
