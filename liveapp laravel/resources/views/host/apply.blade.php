@extends('layouts.app')
@section('title','Apply as Host')
@section('content')
<h1>Apply as Host</h1>
<form method="post" action="{{ route('host.apply.store') }}">@csrf
  <div class="mb-3"><label class="form-label">Stage Name</label><input name="stage_name" class="form-control"></div>
  <div class="mb-3"><label class="form-label">Contact Phone</label><input name="contact_phone" class="form-control"></div>
  <div class="row">
    <div class="col-md-6 mb-3"><label class="form-label">Country</label><input name="country" class="form-control"></div>
    <div class="col-md-6 mb-3"><label class="form-label">City</label><input name="city" class="form-control"></div>
  </div>
  <div class="mb-3"><label class="form-label">About</label><textarea name="about" rows="4" class="form-control"></textarea></div>
  <button class="btn btn-primary">Submit</button>
</form>
@endsection
