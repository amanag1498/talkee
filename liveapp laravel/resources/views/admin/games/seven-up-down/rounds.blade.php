@extends('layouts.admin-berry')

@section('content')
<div class="container-fluid py-4">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h3 class="mb-1">Lucky 7 Rounds</h3>
      <p class="text-muted mb-0">Round history with snapshotted returns, persisted dice, winners, timing, and settlement state.</p>
    </div>
    <div class="d-flex gap-2">
      <a href="{{ route('admin.games.seven-up-down.dashboard') }}" class="btn btn-light border">Back to Dashboard</a>
      <form method="post" action="{{ route('admin.games.seven-up-down.tick') }}">
        @csrf
        <button class="btn btn-primary">Tick Current Round</button>
      </form>
    </div>
  </div>

  <form class="row g-3 mb-4">
    <div class="col-md-3"><input type="text" name="q" value="{{ request('q') }}" class="form-control" placeholder="Round key or id"></div>
    <div class="col-md-2"><input type="text" name="status" value="{{ request('status') }}" class="form-control" placeholder="Status"></div>
    <div class="col-md-2"><select name="winning_pot" class="form-select"><option value="">All outcomes</option>@foreach(['DOWN','SEVEN','UP'] as $pot)<option value="{{ $pot }}" @selected(request('winning_pot') === $pot)>{{ $pot }}</option>@endforeach</select></div>
    <div class="col-md-1"><input type="number" min="2" max="12" name="dice_total" value="{{ request('dice_total') }}" class="form-control" placeholder="Dice"></div>
    <div class="col-md-2"><input type="date" name="date_from" value="{{ request('date_from') }}" class="form-control"></div>
    <div class="col-md-2"><input type="date" name="date_to" value="{{ request('date_to') }}" class="form-control"></div>
    <div class="col-md-2"><button class="btn btn-outline-dark w-100">Filter</button></div>
  </form>

  <div class="card border-0 shadow-sm">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead>
            <tr>
              <th>Round</th>
              <th>Status</th>
              <th>Winner</th>
              <th>Totals</th>
              <th>Locks</th>
              <th>Ends</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            @forelse($rounds as $round)
              <tr>
                <td>#{{ $round->id }}<div class="small text-muted">{{ $round->round_key }}</div></td>
                <td class="text-capitalize">{{ $round->status }}</td>
                <td>{{ $round->winning_pot ?? '—' }}<div class="small text-muted">Dice {{ $round->dice_one ?? '—' }} + {{ $round->dice_two ?? '—' }} = {{ $round->dice_total ?? '—' }}</div></td>
                <td class="small text-muted">Down {{ number_format((int) $round->total_bet_down) }} | Seven {{ number_format((int) $round->total_bet_seven) }} | Up {{ number_format((int) $round->total_bet_up) }}</td>
                <td>{{ optional($round->locks_at)->toDateTimeString() }}</td>
                <td>{{ optional($round->ends_at)->toDateTimeString() }}</td>
                <td class="text-end">
                  <form method="post" action="{{ route('admin.games.seven-up-down.rounds.reconcile', $round) }}" class="d-inline">
                    @csrf
                    <button class="btn btn-sm btn-outline-dark">Reconcile</button>
                  </form>
                </td>
              </tr>
            @empty
              <tr><td colspan="7" class="text-center text-muted py-5">No rounds found.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="mt-4">{{ $rounds->links() }}</div>
</div>
@endsection
