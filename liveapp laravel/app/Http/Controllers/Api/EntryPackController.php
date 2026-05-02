<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EntryPack;
use App\Services\EntryPackService;
use Illuminate\Http\Request;

class EntryPackController extends Controller
{
    public function __construct(private EntryPackService $entryPacks)
    {
    }

    public function index(Request $request)
    {
        return response()->json([
            'ok' => true,
            'data' => $this->entryPacks->listForUser($request->user()),
        ]);
    }

    public function purchase(Request $request, EntryPack $entryPack)
    {
        $user = $request->user();
        abort_unless($user, 401);

        $result = $this->entryPacks->purchase(
            $user,
            $entryPack,
            $request->header('Idempotency-Key') ?: $request->input('idempotency_key'),
        );

        return response()->json([
            'ok' => true,
            'data' => $result,
        ], 201);
    }

    public function mine(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 401);

        return response()->json([
            'ok' => true,
            'data' => $this->entryPacks->activePackPayloadForUser($user),
        ]);
    }

    public function activate(Request $request, EntryPack $entryPack)
    {
        $user = $request->user();
        abort_unless($user, 401);

        return response()->json([
            'ok' => true,
            'data' => $this->entryPacks->activate($user, $entryPack),
        ]);
    }
}
