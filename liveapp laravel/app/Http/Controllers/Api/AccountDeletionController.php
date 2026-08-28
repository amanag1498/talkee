<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AccountDeletionService;
use Illuminate\Http\Request;

class AccountDeletionController extends Controller
{
    public function __invoke(Request $request, AccountDeletionService $accounts)
    {
        $accounts->delete($request->user());

        return response()->json([
            'ok' => true,
            'message' => 'Your Talkieo account has been permanently deleted.',
        ]);
    }
}
