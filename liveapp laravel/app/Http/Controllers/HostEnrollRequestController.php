<?php
namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\Host;
use Illuminate\Http\Request;
use App\Services\HostEnrollmentService;
use Symfony\Component\HttpKernel\Exception\HttpException;


class HostEnrollRequestController extends Controller
{
  public function __construct(private HostEnrollmentService $enrollment) {}
  public function create(Request $request) {
    $host = Host::where('user_id', $request->user()->id)->first();
    if (!$host) return redirect()->route('host.apply')->with('err','Apply as Host first.');
    $agencies = Agency::orderBy('name')->get(['id','name']);
    return view('host.enroll', compact('agencies','host'));
  }

  public function store(Request $request)
    {
        $data = $request->validate([
            'agency_id' => 'required|exists:agencies,id',
            'message'   => 'nullable|string|max:1000'
        ]);
        try {
            $this->enrollment->submit($request->user(), $data);
            return redirect()->route('host.dashboard')->with('ok','Enroll request submitted.');
        } catch (HttpException $e) {
            return back()->with('err', $e->getMessage())->withInput();
        }
    }

}
