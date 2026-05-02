@extends('layouts.admin-berry')
@section('title','Moderation Analytics')
@section('content')
<div class="row g-3">
  <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">Pending Reports</div><div class="display-6 fw-semibold">{{ $analytics['pending_reports'] }}</div></div></div></div>
  <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">Blocks Today</div><div class="display-6 fw-semibold">{{ $analytics['blocks_today'] }}</div></div></div></div>
  <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">Kicks Today</div><div class="display-6 fw-semibold">{{ $analytics['kicks_today'] }}</div></div></div></div>
  <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">Auto Triggers</div><div class="display-6 fw-semibold">{{ $analytics['auto_moderation_triggers'] }}</div></div></div></div>
</div>
<div class="row g-3 mt-1">
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header"><h5 class="mb-0">Top Reported Users</h5></div>
      <div class="card-body table-responsive">
        <table class="table table-sm align-middle"><thead><tr><th>User</th><th>Reports</th></tr></thead><tbody>
          @foreach($analytics['top_reported_users'] as $row)
            <tr><td>{{ $row->reportedUser?->name ?? ('User #'.$row->reported_user_id) }}</td><td>{{ $row->report_count }}</td></tr>
          @endforeach
        </tbody></table>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header"><h5 class="mb-0">Hosts With Most Blocks</h5></div>
      <div class="card-body table-responsive">
        <table class="table table-sm align-middle"><thead><tr><th>Host</th><th>Blocks</th></tr></thead><tbody>
          @foreach($analytics['hosts_with_most_blocks'] as $row)
            <tr><td>{{ $row->hostUser?->name ?? ('User #'.$row->host_user_id) }}</td><td>{{ $row->block_count }}</td></tr>
          @endforeach
        </tbody></table>
      </div>
    </div>
  </div>
</div>
@endsection
