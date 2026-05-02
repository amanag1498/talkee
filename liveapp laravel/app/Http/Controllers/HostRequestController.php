<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\HostApplicationService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class HostRequestController extends Controller
{
  public function __construct(private HostApplicationService $applications) {}
  public function create(){ return view('host.apply'); }

  public function store(Request $request)
    {
        $data = $request->validate([
            'stage_name'    => 'nullable|string|max:120',
            'contact_phone' => 'nullable|string|max:30',
            'country'       => 'nullable|string|max:80',
            'city'          => 'nullable|string|max:80',
            'about'         => 'nullable|string|max:1000',
        ]);
        try {
            $this->applications->submit($request->user(), $data);
            return redirect()->route('home')->with('ok', 'Host application submitted.');
        } catch (HttpException $e) {
            return back()->with('err', $e->getMessage())->withInput();
        }
    }
}
