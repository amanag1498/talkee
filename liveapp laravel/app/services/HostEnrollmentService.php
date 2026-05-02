<?php

namespace App\Services;

use App\Models\Agency;
use App\Models\Host;
use App\Models\HostEnrollRequest;
use App\Models\User;
use App\Notifications\NewApplicationNotification;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpKernel\Exception\HttpException;

class HostEnrollmentService
{
    public function submit(User $user, array $data): HostEnrollRequest
    {
        if (!$user->hasRole('host')) {
            throw new HttpException(403, 'Only hosts can enroll to an agency.');
        }

        $host = Host::query()->where('user_id', $user->id)->first();
        if (!$host) {
            throw new HttpException(409, 'Apply as host first.');
        }

        $agency = Agency::query()->find($data['agency_id'] ?? null);
        if (!$agency) {
            throw new HttpException(422, 'Selected agency does not exist.');
        }

        $pending = HostEnrollRequest::query()->where([
            'host_user_id' => $user->id,
            'agency_id' => $agency->id,
            'status' => 'pending',
        ])->first();

        if ($pending) {
            throw new HttpException(409, 'Already requested for this agency.');
        }

        $request = HostEnrollRequest::query()->create([
            'host_user_id' => $user->id,
            'agency_id' => $agency->id,
            'message' => $data['message'] ?? null,
            'status' => 'pending',
        ]);

        $admins = User::role('admin')->get();
        if ($admins->isNotEmpty()) {
            Notification::send($admins, new NewApplicationNotification(
                type: 'enroll',
                requestId: $request->id,
                fromName: $user->name,
                fromEmail: $user->email
            ));
        }

        return $request->load('agency');
    }
}
