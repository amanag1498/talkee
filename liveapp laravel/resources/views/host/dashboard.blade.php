@extends('layouts.app')
@section('title','Host Dashboard')

@section('content')
  <h3 class="mb-3">Host Dashboard</h3>
  <p>Welcome, {{ auth()->user()->name }}</p>

  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-outline-primary" href="{{ route('host.enroll.create') }}">Enroll to Agency</a>
    <a class="btn btn-outline-secondary" href="{{ route('me.applications') }}">My Applications</a>
    <a class="btn btn-outline-dark" href="{{ route('host.calls.index') }}">My Calls</a>
  </div>
@endsection
