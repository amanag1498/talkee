@extends('layouts.admin-berry')
@section('title','New Entry Pack')

@section('content')
@include('admin.entry-packs.partials.form', [
  'route' => route('admin.entry-packs.store'),
  'method' => 'POST',
  'pack' => null,
])
@endsection
