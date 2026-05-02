<?php
namespace App\Http\Controllers;

use App\Models\AgencyRequest;
use Illuminate\Http\Request;
use App\Services\AgencyApplicationService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AgencyRequestController extends Controller
{
  public function __construct(private AgencyApplicationService $applications) {}

  public function create(){ return view('agency.apply'); }

 public function store(Request $request)
    {
        $data = $request->validate([
            'agency_name'   => 'required|string|max:120',
            'legal_name'    => 'nullable|string|max:120',
            'contact_phone' => 'nullable|string|max:30',
            'website'       => 'nullable|url',
            'about'         => 'nullable|string|max:1000',
        ]);
        try {
            $this->applications->submit($request->user(), $data);
            return redirect()->route('me.applications')->with('ok','Agency application submitted.');
        } catch (HttpException $e) {
            return back()->with('err', $e->getMessage())->withInput();
        }
    }

}
