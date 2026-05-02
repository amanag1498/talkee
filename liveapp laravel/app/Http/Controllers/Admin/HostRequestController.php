<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Host;
use App\Models\HostRequest;
use App\Services\NotifyUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class HostRequestController extends Controller
{
  public function index(){
    $requests = HostRequest::with('user')->latest()->paginate(20);
    return view('admin.host_requests.index', compact('requests'));
  }
  public function show(HostRequest $host_request){
    $host_request->load('user');
    return view('admin.host_requests.show', compact('host_request'));
  }
  public function update(Request $request, HostRequest $host_request){
    $request->validate(['action'=>'required|in:approve,reject','notes'=>'nullable|string|max:1000']);
    if ($host_request->status!=='pending') return back()->with('err','Already reviewed.');

    if ($request->action==='reject'){
      $host_request->update([
        'status'=>'rejected','review_notes'=>$request->notes,'reviewed_by'=>$request->user()->id,'reviewed_at'=>now()
      ]);
      try {
        Redis::publish('users:notify', json_encode([
          'user_id' => (int) $host_request->user_id,
          'type'    => 'host_rejected',
          'title'   => 'Host request reviewed',
          'body'    => 'Unfortunately your host request was rejected.',
          'meta'    => ['notes'=>$request->notes],
          'at'      => now()->toIso8601String(),
        ]));
      } catch (\Throwable $e) {}

      try {
                NotifyUser::send((int) $host_request->user_id, [
                    'type'   => 'host_rejected',
                    'title'  => 'Host request reviewed',
                    'body'   => 'Unfortunately your host request was rejected.',
                    'meta'   => ['notes' => $request->notes],
                    'screen' => 'notifications',
                ], [
                    'push'    => true,
                    'persist' => true,
                ]);
            } catch (\Throwable $e) {}
      return back()->with('ok','Rejected.');
    }

    DB::transaction(function() use ($host_request,$request){
      $u = $host_request->user;
      $u->assignRole('host');
      Host::firstOrCreate(
        ['user_id'=>$u->id],
        [
          'stage_name'=>$host_request->stage_name,
          'contact_phone'=>$host_request->contact_phone,
          'country'=>$host_request->country,
          'city'=>$host_request->city,
          'bio'=>$host_request->about,
          'payout_percentage' => (float)($request->input('payout_percentage', 0)),
          'weekly_bonus'      => (int)($request->input('weekly_bonus', 0)),
        ]
      );
      $host_request->update([
        'status'=>'approved','review_notes'=>$request->notes,'reviewed_by'=>$request->user()->id,'reviewed_at'=>now()
      ]);
      // 🔔 LIVE push to user (no persistence yet)
    try {
      Redis::publish('users:notify', json_encode([
        'user_id' => (int) $host_request->user_id,
        'type'    => 'host_approved',
        'title'   => 'Host request approved 🎉',
        'body'    => 'You can now host live rooms. Tap to get started!',
        'meta'    => ['notes'=>$request->notes],
        'at'      => now()->toIso8601String(),
      ]));
    } catch (\Throwable $e) {}
    try {
                NotifyUser::send((int) $host_request->user_id, [
                    'type'   => 'host_approved',
                    'title'  => 'Host request approved 🎉',
                    'body'   => 'You can now host live rooms. Tap to get started!',
                    'meta'   => ['notes' => $request->notes],
                    'screen' => 'notifications',
                ], [
                    'push'    => true,
                    'persist' => true,
                ]);
            } catch (\Throwable $e) {}
    });

    return redirect()->route('admin.host-requests.index')->with('ok','Approved.');
  }
}
