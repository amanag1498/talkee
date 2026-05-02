<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Host;
use App\Models\HostEnrollRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HostEnrollRequestController extends Controller
{
  public function index(){
    $requests = HostEnrollRequest::with(['hostUser','agency'])->latest()->paginate(20);
    return view('admin.enroll_requests.index', compact('requests'));
  }
  public function show(HostEnrollRequest $enroll_request){
    $enroll_request->load(['hostUser','agency']);
    return view('admin.enroll_requests.show', compact('enroll_request'));
  }
  public function update(Request $request, HostEnrollRequest $enroll_request){
    $request->validate(['action'=>'required|in:approve,reject','notes'=>'nullable|string|max:1000']);
    if ($enroll_request->status!=='pending') return back()->with('err','Already reviewed.');

    if ($request->action==='reject'){
      $enroll_request->update([
        'status'=>'rejected','review_notes'=>$request->notes,'reviewed_by'=>$request->user()->id,'reviewed_at'=>now()
      ]);
      return back()->with('ok','Rejected.');
    }

    DB::transaction(function() use ($enroll_request,$request){
      $host = Host::firstOrCreate(['user_id'=>$enroll_request->host_user_id]);
      $host->update(['agency_id' => $enroll_request->agency_id]);
      $enroll_request->update([
        'status'=>'approved','review_notes'=>$request->notes,'reviewed_by'=>$request->user()->id,'reviewed_at'=>now()
      ]);
    });

    return redirect()->route('admin.enroll-requests.index')->with('ok','Approved.');
  }
}
