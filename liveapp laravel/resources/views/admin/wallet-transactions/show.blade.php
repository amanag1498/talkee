@extends('layouts.admin-berry')
@section('title', 'Ledger Entry #'.$transaction->id)

@php
  $user = $transaction->wallet?->user;
  $metadata = $transaction->meta ?? [];
  $integrityClass = match($integrity['status']) {'balanced' => 'bg-success', 'mismatch' => 'bg-danger', default => 'bg-warning text-dark'};
@endphp

@section('content')
<div class="row g-3">
  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex flex-wrap align-items-start justify-content-between gap-3">
        <div>
          <div class="d-flex flex-wrap align-items-center gap-2">
            <h5 class="mb-0">Ledger Entry #{{ $transaction->id }}</h5>
            <span class="badge {{ $transaction->type === 'credit' ? 'bg-success' : 'bg-warning text-dark' }}">{{ ucfirst($transaction->type) }}</span>
            <span class="badge {{ $integrityClass }}">{{ ucfirst($integrity['status']) }}</span>
          </div>
          <p class="text-muted mb-0 mt-1">Read-only audit view of the recorded wallet movement and its source context.</p>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
          <span class="text-muted small">{{ $transaction->created_at?->timezone(config('app.timezone'))->format('d M Y, h:i:s A T') }}</span>
          <a href="{{ route('admin.wallet-transactions.index') }}" class="btn btn-light border btn-sm">Back to Ledger</a>
          @if($user)
            <a href="{{ route('admin.wallets.show', $user) }}" class="btn btn-primary btn-sm">User Wallet</a>
          @endif
        </div>
      </div>
    </div>
  </div>

  @foreach([
    ['Coins', ($transaction->type === 'credit' ? '+' : '-').number_format((int) $transaction->coins), str($transaction->category ?: 'uncategorized')->replace('_', ' ')->title(), $transaction->type === 'credit' ? 'text-success' : 'text-warning'],
    ['Balance Before', $transaction->balance_before === null ? 'Not recorded' : number_format((int) $transaction->balance_before), 'Stored snapshot', ''],
    ['Balance After', $transaction->balance_after === null ? 'Not recorded' : number_format((int) $transaction->balance_after), 'Stored snapshot', ''],
    ['Expected After', $integrity['expected'] === null ? 'Unavailable' : number_format($integrity['expected']), $integrity['difference'] === null ? 'Snapshots incomplete' : 'Difference '.number_format($integrity['difference']), $integrity['status'] === 'balanced' ? 'text-success' : 'text-danger'],
  ] as [$label, $value, $meta, $tone])
    <div class="col-xl-3 col-md-6">
      <div class="card h-100"><div class="card-body">
        <div class="text-muted small">{{ $label }}</div>
        <div class="fs-3 fw-semibold {{ $tone }}">{{ $value }}</div>
        <div class="small text-muted">{{ $meta }}</div>
      </div></div>
    </div>
  @endforeach

  <div class="col-xl-6">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0">Wallet Owner</h6></div>
      <div class="card-body">
        <dl class="row mb-0 g-3">
          <div class="col-sm-6"><dt class="text-muted small">User</dt><dd class="fw-medium mb-0">{{ $user?->name ?? 'Deleted or unavailable user' }}</dd></div>
          <div class="col-sm-6"><dt class="text-muted small">Email</dt><dd class="fw-medium mb-0 text-break">{{ $user?->email ?? '—' }}</dd></div>
          <div class="col-sm-6"><dt class="text-muted small">User ID</dt><dd class="fw-medium mb-0">{{ $user?->id ?? '—' }}</dd></div>
          <div class="col-sm-6"><dt class="text-muted small">Wallet ID</dt><dd class="fw-medium mb-0">{{ $transaction->wallet_id }}</dd></div>
          <div class="col-sm-6"><dt class="text-muted small">Current Wallet Balance</dt><dd class="fw-medium mb-0">{{ $transaction->wallet ? number_format((int) $transaction->wallet->balance) : 'Wallet unavailable' }}</dd></div>
          <div class="col-sm-6"><dt class="text-muted small">Counterparty</dt><dd class="fw-medium mb-0">{{ $transaction->counterparty?->name ?? '—' }}{{ $transaction->counterparty_user_id ? ' (#'.$transaction->counterparty_user_id.')' : '' }}</dd></div>
        </dl>
      </div>
    </div>
  </div>

  <div class="col-xl-6">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0">Financial Classification</h6></div>
      <div class="card-body">
        <dl class="row mb-0 g-3">
          <div class="col-sm-6"><dt class="text-muted small">Direction</dt><dd class="fw-medium mb-0">{{ ucfirst($transaction->type) }}</dd></div>
          <div class="col-sm-6"><dt class="text-muted small">Category</dt><dd class="fw-medium mb-0">{{ str($transaction->category ?: 'uncategorized')->replace('_', ' ')->title() }}</dd></div>
          <div class="col-sm-6"><dt class="text-muted small">Coins</dt><dd class="fw-medium mb-0">{{ number_format((int) $transaction->coins) }}</dd></div>
          <div class="col-sm-6"><dt class="text-muted small">Cash Value</dt><dd class="fw-medium mb-0">{{ $transaction->amount !== null ? (($transaction->currency ?: '—').' '.number_format((float) $transaction->amount, 2)) : '—' }}</dd></div>
          <div class="col-sm-6"><dt class="text-muted small">Gateway</dt><dd class="fw-medium mb-0">{{ $transaction->gateway ?: '—' }}</dd></div>
          <div class="col-sm-6"><dt class="text-muted small">Gateway Transaction</dt><dd class="fw-medium mb-0 text-break">{{ $transaction->transaction_id ?: '—' }}</dd></div>
        </dl>
      </div>
    </div>
  </div>

  <div class="col-xl-6">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0">Source Reference</h6></div>
      <div class="card-body">
        <dl class="row mb-0 g-3">
          <div class="col-12"><dt class="text-muted small">Reference</dt><dd class="fw-medium mb-0 text-break">{{ $transaction->reference ?: '—' }}</dd></div>
          <div class="col-sm-6"><dt class="text-muted small">Reference Type</dt><dd class="fw-medium mb-0">{{ $transaction->reference_type ?: '—' }}</dd></div>
          <div class="col-sm-6"><dt class="text-muted small">Reference ID</dt><dd class="fw-medium mb-0">{{ $transaction->reference_id ?: '—' }}</dd></div>
          <div class="col-12"><dt class="text-muted small">Description</dt><dd class="fw-medium mb-0">{{ $transaction->description ?: '—' }}</dd></div>
          <div class="col-sm-6"><dt class="text-muted small">Created</dt><dd class="fw-medium mb-0">{{ $transaction->created_at?->timezone(config('app.timezone'))->format('d M Y, h:i:s A') }}</dd></div>
          <div class="col-sm-6"><dt class="text-muted small">Updated</dt><dd class="fw-medium mb-0">{{ $transaction->updated_at?->timezone(config('app.timezone'))->format('d M Y, h:i:s A') }}</dd></div>
        </dl>
      </div>
    </div>
  </div>

  <div class="col-xl-6">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0">Balance Integrity</h6></div>
      <div class="card-body">
        <div class="alert {{ $integrity['status'] === 'balanced' ? 'alert-success' : ($integrity['status'] === 'mismatch' ? 'alert-danger' : 'alert-warning') }} mb-0">
          <div class="d-flex align-items-start justify-content-between gap-3">
            <div>
              <div class="fw-semibold">{{ $integrity['status'] === 'balanced' ? 'Balance movement reconciles' : ($integrity['status'] === 'missing' ? 'Balance snapshot is incomplete' : 'Balance movement does not reconcile') }}</div>
              <div class="mt-1">
                @if($integrity['expected'] !== null)
                  Expected {{ number_format($integrity['expected']) }}, recorded {{ number_format((int) $transaction->balance_after) }}, difference {{ number_format($integrity['difference']) }}.
                @else
                  One or both balance snapshots were not stored, so this entry cannot be mathematically verified.
                @endif
              </div>
            </div>
            <span class="badge {{ $integrityClass }}">{{ ucfirst($integrity['status']) }}</span>
          </div>
        </div>
      </div>
    </div>
  </div>

  @if($relatedRecords !== [])
    <div class="col-12">
      <div class="card">
        <div class="card-header">
          <h6 class="mb-1">Linked Source Records</h6>
          <div class="text-muted small">Operational records found using this ledger entry's reference and metadata.</div>
        </div>
        <div class="card-body">
          <div class="row g-3">
            @foreach($relatedRecords as $record)
              <div class="col-lg-6">
                <div class="border rounded-3 p-3 h-100">
                  <h6>{{ $record['title'] }}</h6>
                  <dl class="row mb-0 g-3">
                    @foreach($record['fields'] as $label => $value)
                      <div class="col-sm-6">
                        <dt class="text-muted small">{{ $label }}</dt>
                        <dd class="fw-medium mb-0 text-break">{{ filled($value) ? $value : '—' }}</dd>
                      </div>
                    @endforeach
                  </dl>
                </div>
              </div>
            @endforeach
          </div>
        </div>
      </div>
    </div>
  @endif

  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h6 class="mb-1">Metadata</h6>
        <div class="text-muted small">Raw producer context stored with the transaction.</div>
      </div>
      <div class="card-body">
        @if($metadata === [])
          <p class="text-muted mb-0">No metadata was recorded for this entry.</p>
        @else
          <div class="row g-3">
            @foreach($metadata as $key => $value)
              <div class="col-xl-4 col-md-6">
                <div class="border rounded-3 p-3 h-100">
                  <div class="text-muted small">{{ str((string) $key)->replace('_', ' ')->title() }}</div>
                  <div class="fw-medium text-break">{{ is_array($value) || is_object($value) ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ($value === null ? 'null' : (is_bool($value) ? ($value ? 'true' : 'false') : $value)) }}</div>
                </div>
              </div>
            @endforeach
          </div>
          <details class="mt-3">
            <summary class="text-primary fw-semibold" style="cursor: pointer">View raw JSON</summary>
            <pre class="bg-dark text-light rounded-3 p-3 mt-2 mb-0 overflow-auto">{{ json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
          </details>
        @endif
      </div>
    </div>
  </div>
</div>
@endsection
