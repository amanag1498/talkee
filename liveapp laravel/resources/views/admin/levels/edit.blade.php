@extends('layouts.admin-berry')
@section('title', 'Edit Level')

@section('content')
<form method="post" action="{{ route('admin.levels.update', $level) }}" class="vstack gap-3">
  @csrf
  @method('PUT')
  @include('admin.levels._form', ['mode' => 'edit'])
</form>
@endsection
