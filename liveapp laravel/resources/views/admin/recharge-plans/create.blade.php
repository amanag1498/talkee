@extends('layouts.admin-berry')
@section('title', 'Create Recharge Plan')

@section('content')
<form method="post" action="{{ route('admin.recharge-plans.store') }}" class="vstack gap-3">
  @csrf
  @include('admin.recharge-plans._form', ['mode' => 'create'])
</form>
@endsection
