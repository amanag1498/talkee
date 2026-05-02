@extends('layouts.admin-berry')
@section('title', 'Edit Recharge Plan')

@section('content')
<form method="post" action="{{ route('admin.recharge-plans.update', $plan) }}" class="vstack gap-3">
  @csrf
  @method('PUT')
  @include('admin.recharge-plans._form', ['mode' => 'edit'])
</form>
@endsection
