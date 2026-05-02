<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UserLevel;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserLevelAdminController extends Controller
{
    public function index()
    {
        $levels = UserLevel::query()
            ->withCount('users')
            ->orderBy('sort_order')
            ->orderBy('level')
            ->get();

        $summary = [
            'levels' => $levels->count(),
            'active_levels' => $levels->where('is_active', true)->count(),
            'users_mapped' => (int) $levels->sum('users_count'),
            'highest_threshold' => (int) $levels->max('min_spend_coins'),
        ];

        return view('admin.levels.index', compact('levels', 'summary'));
    }

    public function create()
    {
        return view('admin.levels.create', [
            'level' => new UserLevel([
                'is_active' => true,
                'sort_order' => (int) UserLevel::query()->max('sort_order') + 1,
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        UserLevel::query()->create($this->payload($data));

        return redirect()
            ->route('admin.levels.index')
            ->with('ok', 'Level created.');
    }

    public function edit(UserLevel $level)
    {
        return view('admin.levels.edit', compact('level'));
    }

    public function update(Request $request, UserLevel $level)
    {
        $data = $this->validated($request, $level);
        $level->update($this->payload($data));

        return redirect()
            ->route('admin.levels.index')
            ->with('ok', 'Level updated.');
    }

    public function destroy(UserLevel $level)
    {
        if ($level->users()->exists()) {
            return redirect()
                ->route('admin.levels.index')
                ->with('error', 'Cannot delete a level that is currently assigned to users.');
        }

        $level->delete();

        return redirect()
            ->route('admin.levels.index')
            ->with('ok', 'Level deleted.');
    }

    private function validated(Request $request, ?UserLevel $level = null): array
    {
        return $request->validate([
            'level' => [
                'required',
                'integer',
                'min:1',
                Rule::unique('user_levels', 'level')->ignore($level?->id),
            ],
            'title' => ['required', 'string', 'max:255'],
            'min_spend_coins' => ['required', 'integer', 'min:0'],
            'badge_icon' => ['nullable', 'string', 'max:100'],
            'badge_color' => ['nullable', 'regex:/^#?[0-9A-Fa-f]{6}$/'],
            'benefits' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ]);
    }

    private function payload(array $data): array
    {
        $benefits = $this->normalizeBenefits($data['benefits'] ?? null);

        return [
            'level' => (int) $data['level'],
            'title' => trim($data['title']),
            'min_spend_coins' => (int) $data['min_spend_coins'],
            'badge_icon' => filled($data['badge_icon'] ?? null) ? trim($data['badge_icon']) : null,
            'badge_color' => filled($data['badge_color'] ?? null)
                ? '#' . ltrim(trim($data['badge_color']), '#')
                : null,
            'benefits' => $benefits,
            'is_active' => (bool) ($data['is_active'] ?? false),
            'sort_order' => (int) $data['sort_order'],
        ];
    }

    private function normalizeBenefits(?string $raw): ?array
    {
        if (!filled($raw)) {
            return null;
        }

        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return array_values(array_filter(array_map(
                fn ($value) => is_string($value) ? trim($value) : null,
                $decoded
            )));
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $benefits = array_values(array_filter(array_map(
            fn ($line) => trim(ltrim($line, "- \t")),
            $lines
        )));

        return $benefits ?: null;
    }
}
