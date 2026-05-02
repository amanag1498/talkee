<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AgencyRequest;
use App\Services\NotifyUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class AgencyRequestController extends Controller
{
    public function index(){
        $requests = AgencyRequest::with('user')->latest()->paginate(20);
        return view('admin.agency_requests.index', compact('requests'));
    }

    public function show(AgencyRequest $agency_request){
        $agency_request->load('user');
        return view('admin.agency_requests.show', compact('agency_request'));
    }

    public function update(Request $request, AgencyRequest $agency_request){
        $request->validate(['action'=>'required|in:approve,reject','notes'=>'nullable|string|max:1000']);
        if ($agency_request->status !== 'pending') {
            return back()->with('err','Already reviewed.');
        }

        // REJECT
        if ($request->action === 'reject') {
            $agency_request->update([
                'status'       => 'rejected',
                'review_notes' => $request->notes,
                'reviewed_by'  => $request->user()->id,
                'reviewed_at'  => now(),
            ]);

            // 🔔 LIVE push to user (same channel as Host)
            try {
                Redis::publish('users:notify', json_encode([
                    'user_id' => (int) $agency_request->user_id,
                    'type'    => 'agency_rejected',
                    'title'   => 'Agency request reviewed',
                    'body'    => 'Unfortunately your agency request was rejected.',
                    'meta'    => ['notes' => $request->notes],
                    'at'      => now()->toIso8601String(),
                ]));
            } catch (\Throwable $e) {}
             try {
                NotifyUser::send((int) $agency_request->user_id, [
                    'type'   => 'agency_rejected',
                    'title'  => 'Agency request reviewed',
                    'body'   => 'Unfortunately your agency request was rejected.',
                    'meta'   => ['notes' => $request->notes],
                    'screen' => 'notifications',
                ], [
                    'push'    => true,
                    'persist' => true,
                ]);
            } catch (\Throwable $e) {}

            return back()->with('ok','Rejected.');
        }

        // APPROVE
        DB::transaction(function () use ($agency_request, $request) {
            $u = $agency_request->user;
            $u->assignRole('agency');

            $agency = Agency::create([
                'owner_user_id' => $u->id,
                'name'          => $agency_request->agency_name,
                'legal_name'    => $agency_request->legal_name,
                'contact_email' => $u->email,
                'contact_phone' => $agency_request->contact_phone,
                'payout_percentage' => (float)($request->input('payout_percentage', 0)),
                'weekly_bonus'      => (int)($request->input('weekly_bonus', 0)),
            ]);

            $agency_request->update([
                'status'       => 'approved',
                'review_notes' => $request->notes,
                'reviewed_by'  => $request->user()->id,
                'reviewed_at'  => now(),
            ]);

            // 🔔 LIVE push to user (same channel as Host)
            try {
                Redis::publish('users:notify', json_encode([
                    'user_id' => (int) $agency_request->user_id,
                    'type'    => 'agency_approved',
                    'title'   => 'Agency request approved 🎉',
                    'body'    => 'You have been approved as an agency. Welcome aboard!',
                    'meta'    => [
                        'agency_id'   => (int) $agency->id,
                        'agency_name' => (string) $agency->name,
                        'notes'       => $request->notes,
                    ],
                    'at'      => now()->toIso8601String(),
                ]));
            } catch (\Throwable $e) {}

 try {
                NotifyUser::send((int) $agency_request->user_id, [
                    'type'   => 'agency_approved',
                    'title'  => 'Agency request approved 🎉',
                    'body'   => 'You have been approved as an agency. Welcome aboard!',
                    'meta'   => [
                        'agency_id'   => (int) $agency->id,
                        'agency_name' => (string) $agency->name,
                        'notes'       => $request->notes,
                    ],
                    'screen' => 'notifications', // deep-link target in app
                ], [
                    'push'    => true,
                    'persist' => true,
                ]);
            } catch (\Throwable $e) {}

        });

        return redirect()->route('admin.agency-requests.index')->with('ok','Approved.');
    }
}
