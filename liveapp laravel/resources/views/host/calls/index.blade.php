@extends('layouts.app')
@section('title','My Calls')

@section('content')
  <h3 class="mb-3">My Calls</h3>
  @include('partials.call-report-table', ['layout' => 'web'])
@endsection
