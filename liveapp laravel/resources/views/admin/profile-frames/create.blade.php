@extends('layouts.admin-berry')
@section('title', 'Create Profile Frame')
@section('content')
  <form method="post" action="{{ route('admin.profile-frames.store') }}" enctype="multipart/form-data">
    @csrf
    @include('admin.profile-frames._form', ['frame' => $frame])
  </form>
@endsection
