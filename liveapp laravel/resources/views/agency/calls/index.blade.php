@extends('layouts.agency-berry')
@section('title','Agency Calls')
@section('page_intro','Call activity, minutes, and earnings across hosts attached to your agency.')

@section('content')
  @include('partials.call-report-table', ['layout' => 'agency'])
@endsection
