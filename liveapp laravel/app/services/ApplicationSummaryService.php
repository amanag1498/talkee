<?php

namespace App\Services;

use App\Models\Agency;
use App\Models\AgencyRequest;
use App\Models\HostEnrollRequest;
use App\Models\HostRequest;
use App\Models\User;

class ApplicationSummaryService
{
    public function summaryFor(User $user): array
    {
        $agencyRequests = AgencyRequest::query()
            ->where('user_id', $user->id)
            ->latest()
            ->get();

        $hostRequests = HostRequest::query()
            ->where('user_id', $user->id)
            ->latest()
            ->get();

        $enrollRequests = HostEnrollRequest::query()
            ->with('agency:id,name')
            ->where('host_user_id', $user->id)
            ->latest()
            ->get();

        $applications = [
                ...$agencyRequests->map(fn (AgencyRequest $request) => [
                    'id' => $request->id,
                    'type' => 'agency',
                    'title' => $request->agency_name,
                    'status' => $request->status,
                    'submitted_at' => optional($request->created_at)->toIso8601String(),
                    'reviewed_at' => optional($request->reviewed_at)->toIso8601String(),
                    'review_notes' => $request->review_notes,
                    'details' => [
                        'agency_name' => $request->agency_name,
                        'legal_name' => $request->legal_name,
                        'contact_phone' => $request->contact_phone,
                        'website' => $request->website,
                        'about' => $request->about,
                    ],
                ])->all(),
                ...$hostRequests->map(fn (HostRequest $request) => [
                    'id' => $request->id,
                    'type' => 'host',
                    'title' => $request->stage_name ?: 'Host application',
                    'status' => $request->status,
                    'submitted_at' => optional($request->created_at)->toIso8601String(),
                    'reviewed_at' => optional($request->reviewed_at)->toIso8601String(),
                    'review_notes' => $request->review_notes,
                    'details' => [
                        'stage_name' => $request->stage_name,
                        'contact_phone' => $request->contact_phone,
                        'country' => $request->country,
                        'city' => $request->city,
                        'about' => $request->about,
                    ],
                ])->all(),
                ...$enrollRequests->map(fn (HostEnrollRequest $request) => [
                    'id' => $request->id,
                    'type' => 'host_enroll',
                    'title' => $request->agency?->name ?: 'Agency enrollment',
                    'status' => $request->status,
                    'submitted_at' => optional($request->created_at)->toIso8601String(),
                    'reviewed_at' => optional($request->reviewed_at)->toIso8601String(),
                    'review_notes' => $request->review_notes,
                    'details' => [
                        'agency_id' => $request->agency_id,
                        'agency_name' => $request->agency?->name,
                        'message' => $request->message,
                    ],
                ])->all(),
            ];

        usort($applications, fn (array $a, array $b) => strcmp((string) ($b['submitted_at'] ?? ''), (string) ($a['submitted_at'] ?? '')));

        return [
            'applications' => $applications,
            'available_agencies' => Agency::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Agency $agency) => [
                    'id' => $agency->id,
                    'name' => $agency->name,
                ])->values()->all(),
        ];
    }
}
