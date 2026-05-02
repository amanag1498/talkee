@extends('layouts.app')
@section('title','Enroll to Agency')
@section('content')
<h1>Enroll into an Agency</h1>
<form method="post" action="{{ route('host.enroll.store') }}">@csrf
  <div class="mb-3">
    <label class="form-label">Choose Agency</label>
    <select name="agency_id" class="form-select" required>
      <option value="">-- Select --</option>
      @foreach($agencies as $a)
        <option value="{{ $a->id }}">{{ $a->name }}</option>
      @endforeach
    </select>
  </div>
  <div class="mb-3">
    <label class="form-label">Message (optional)</label>
    <textarea name="message" rows="3" class="form-control"></textarea>
  </div>
  <button class="btn btn-primary">Send Request</button>
</form>
@endsection
