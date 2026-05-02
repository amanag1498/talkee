<?php

// app/Http/Controllers/Api/PushTokenController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DevicePushToken;
use Illuminate\Http\Request;

class PushTokenController extends Controller
{
    public function register(Request $req) {
        $req->validate(['token'=>'required|string','platform'=>'nullable|string']);
        $deviceId = $req->header('X-Device-Id') ?? $req->input('device_id');

        $row = DevicePushToken::updateOrCreate(
            ['token'=>$req->token],
            [
              'user_id'     => $req->user()->id,
              'device_id'   => $deviceId,
              'platform'    => $req->input('platform','android'),
              'last_seen_at'=> now(),
            ]
        );
        return response()->json(['ok'=>true,'id'=>$row->id]);
    }

    public function unregister(Request $req) {
        $req->validate(['token'=>'required|string']);
        DevicePushToken::where('token',$req->token)->delete();
        return response()->json(['ok'=>true]);
    }
}

