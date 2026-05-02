@extends('layouts.app')
@section('title','Apply as Agency')
@section('content')
<h1>Apply to become an Agency</h1>
<form method="post" action="{{ route('agency.apply.store') }}">@csrf
  <div class="mb-3"><label class="form-label">Agency Name*</label><input name="agency_name" class="form-control" required></div>
  <div class="mb-3"><label class="form-label">Legal Name</label><input name="legal_name" class="form-control"></div>
  <div class="mb-3"><label class="form-label">Contact Phone</label><input name="contact_phone" class="form-control"></div>
  <div class="mb-3"><label class="form-label">Website</label><input name="website" class="form-control" placeholder="https://"></div>
  <div class="mb-3"><label class="form-label">About</label><textarea name="about" rows="4" class="form-control"></textarea></div>
  <button class="btn btn-primary">Submit</button>
</form>
@endsection
