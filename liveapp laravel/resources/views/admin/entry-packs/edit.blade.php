@extends('layouts.admin-berry')
@section('title','Edit Entry Pack')

@section('content')
@include('admin.entry-packs.partials.form', [
  'route' => route('admin.entry-packs.update', $pack),
  'method' => 'PUT',
  'pack' => $pack,
])
@endsection
