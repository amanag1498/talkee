<?php

namespace App\Services;

use App\Models\HostRequest;
use App\Models\User;
use App\Notifications\NewApplicationNotification;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpKernel\Exception\HttpException;

class HostApplicationService
{
    public function submit(User $user, array $data): HostRequest
    {
        if ($user->hasAnyRole(['agency', 'host'])) {
            throw new HttpException(409, 'Only normal users can apply for host.');
        }

        $pending = HostRequest::query()
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->first();

        if ($pending) {
            throw new HttpException(409, 'You already have a pending host request.');
        }

        $request = HostRequest::query()->create([
            'user_id' => $user->id,
            ...$data,
            'status' => 'pending',
        ]);

        $admins = User::role('admin')->get();
        if ($admins->isNotEmpty()) {
            Notification::send($admins, new NewApplicationNotification(
                type: 'host',
                requestId: $request->id,
                fromName: $user->name,
                fromEmail: $user->email
            ));
        }

        return $request;
    }
}
