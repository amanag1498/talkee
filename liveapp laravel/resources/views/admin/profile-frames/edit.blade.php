@extends('layouts.admin-berry')
@section('title', 'Edit Profile Frame')
@section('content')
  <form method="post" action="{{ route('admin.profile-frames.update', $frame) }}" enctype="multipart/form-data">
    @csrf
    @method('PUT')
    @include('admin.profile-frames._form', ['frame' => $frame])
  </form>
@endsection
