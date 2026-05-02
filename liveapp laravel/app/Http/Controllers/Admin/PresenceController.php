<?php
// app/Http/Controllers/Admin/PresenceController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PresenceReader;
use Illuminate\Http\Request;

class PresenceController extends Controller
{
    public function __construct(private PresenceReader $presence) {}

    public function index()
    {
        return view('admin.presence.index', [
            'count' => $this->presence->count(),
            'rows'  => $this->presence->list(true),
        ]);
    }

    public function stats(Request $request)
    {
        return response()->json([
            'count' => $this->presence->count(),
            'rows'  => $this->presence->list($request->boolean('withUsers', true)),
            'ts'    => now()->toIso8601String(),
        ]);
    }
}
