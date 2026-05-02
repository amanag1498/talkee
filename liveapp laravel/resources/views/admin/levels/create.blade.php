@extends('layouts.admin-berry')
@section('title', 'Create Level')

@section('content')
<form method="post" action="{{ route('admin.levels.store') }}" class="vstack gap-3">
  @csrf
  @include('admin.levels._form', ['mode' => 'create'])
</form>
@endsection
