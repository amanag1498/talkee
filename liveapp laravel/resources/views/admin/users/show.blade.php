@extends('layouts.admin-berry')
@section('title', 'User 360 · '.$user->name)

@section('content')
<div class="admin-section-stack">
  <div class="card">
    <div class="card-body d-flex flex-wrap justify-content-between align-items-start gap-3">
      <div>
        <div class="d-flex align-items-center gap-2 mb-2">
          <h4 class="mb-0">{{ $user->name }}</h4>
          @if($user->is_blocked)
            <span class="badge bg-danger">Blocked</span>
          @else
            <span class="badge bg-success">Active</span>
          @endif
          @foreach($user->getRoleNames() as $role)
            <span class="badge bg-light text-dark">{{ $role }}</span>
          @endforeach
        </div>
        <div class="text-muted">{{ $user->email }}</div>
        <div class="small text-muted mt-1">
          User #{{ $user->id }}
          @if($user->device_id)
            · Device <code>{{ $user->device_id }}</code>
          @endif
          · Joined {{ $user->created_at?->format('d M Y, H:i') }}
        </div>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <a href="{{ route('admin.wallets.show', $user) }}" class="btn btn-primary">Wallet</a>
        <a href="{{ route('admin.users.notifications', $user) }}" class="btn btn-light border">Notifications</a>
        @if($user->is_blocked)
          <form method="post" action="{{ route('admin.users.unblock', $user) }}">@csrf<button class="btn btn-success">Unblock</button></form>
        @else
          <form method="post" action="{{ route('admin.users.block', $user) }}">@csrf<button class="btn btn-danger">Block</button></form>
        @endif
      </div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-xl-2 col-md-4">
      <div class="card"><div class="card-body"><div class="text-muted small">Wallet</div><div class="h4 mb-0">{{ number_format($walletSummary['balance']) }}</div></div></div>
    </div>
    <div class="col-xl-2 col-md-4">
      <div class="card"><div class="card-body"><div class="text-muted small">Following</div><div class="h4 mb-0">{{ number_format($followingCount) }}</div></div></div>
    </div>
    <div class="col-xl-2 col-md-4">
      <div class="card"><div class="card-body"><div class="text-muted small">Followers</div><div class="h4 mb-0">{{ number_format($followersCount) }}</div></div></div>
    </div>
    <div class="col-xl-2 col-md-4">
      <div class="card"><div class="card-body"><div class="text-muted small">Rooms Joined</div><div class="h4 mb-0">{{ number_format($overviewStats['live_rooms_joined']) }}</div></div></div>
    </div>
    <div class="col-xl-2 col-md-4">
      <div class="card"><div class="card-body"><div class="text-muted small">Calls</div><div class="h4 mb-0">{{ number_format($overviewStats['calls_total']) }}</div></div></div>
    </div>
    <div class="col-xl-2 col-md-4">
      <div class="card"><div class="card-body"><div class="text-muted small">Gift Spend</div><div class="h4 mb-0">{{ number_format($overviewStats['gifts_sent']) }}</div></div></div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-4">
      <div class="card mb-3">
        <div class="card-header"><h6 class="mb-0">Identity</h6></div>
        <div class="card-body small">
          <div class="mb-2"><span class="text-muted">Firebase UID:</span> <code>{{ $user->firebase_uid ?? '—' }}</code></div>
          <div class="mb-2"><span class="text-muted">Provider:</span> {{ $user->provider ?? '—' }}</div>
          <div class="mb-2"><span class="text-muted">Device:</span> {{ $user->device_id ?? '—' }}</div>
          <div class="mb-2"><span class="text-muted">Email verified:</span> {{ $user->email_verified_at?->format('d M Y, H:i') ?? 'No' }}</div>
          <div class="mb-0"><span class="text-muted">Updated:</span> {{ $user->updated_at?->format('d M Y, H:i') }}</div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h6 class="mb-0">Wallet</h6>
          <a href="{{ route('admin.wallets.show', $user) }}" class="btn btn-sm btn-light border">Full Ledger</a>
        </div>
        <div class="card-body">
          <div class="row g-2 mb-3">
            <div class="col-6"><div class="border rounded-3 p-3"><div class="small text-muted">Credits</div><div class="fw-semibold">{{ number_format($walletSummary['credits']) }}</div></div></div>
            <div class="col-6"><div class="border rounded-3 p-3"><div class="small text-muted">Debits</div><div class="fw-semibold">{{ number_format($walletSummary['debits']) }}</div></div></div>
          </div>
          <form method="post" action="{{ route('admin.wallets.credit', $user) }}" class="row g-2 mb-2">
            @csrf
            <div class="col-4"><input type="number" name="amount" min="1" class="form-control" placeholder="Coins" required></div>
            <div class="col-8"><input type="text" name="note" class="form-control" placeholder="Credit reason"></div>
            <div class="col-12 d-grid"><button class="btn btn-success">Credit Wallet</button></div>
          </form>
          <form method="post" action="{{ route('admin.wallets.debit', $user) }}" class="row g-2">
            @csrf
            <div class="col-4"><input type="number" name="amount" min="1" class="form-control" placeholder="Coins" required></div>
            <div class="col-8"><input type="text" name="note" class="form-control" placeholder="Debit reason"></div>
            <div class="col-12 d-grid"><button class="btn btn-danger">Debit Wallet</button></div>
          </form>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header"><h6 class="mb-0">Level</h6></div>
        <div class="card-body">
          <div class="mb-2">
            @if($user->level)
              <span class="badge" style="background: {{ $user->level->badge_color ?: '#64748b' }}">L{{ $user->level->level }} · {{ $user->level->title }}</span>
            @else
              <span class="text-muted">No level assigned</span>
            @endif
          </div>
          <div class="small text-muted mb-3">
            Lifetime spend {{ number_format($levelProgress['lifetime_spend_coins'] ?? 0) }}
            @if(!empty($levelProgress['next_level']))
              · {{ number_format($levelProgress['remaining_spend_to_next_level'] ?? 0) }} to next
            @endif
          </div>
          <div class="progress mb-3" style="height: 10px;">
            <div class="progress-bar" style="width: {{ (float) ($levelProgress['progress_percent'] ?? 0) }}%"></div>
          </div>
          <form method="post" action="{{ route('admin.users.level.set', $user) }}" class="row g-2">
            @csrf
            <div class="col-12">
              <select name="level_id" class="form-select" required>
                @foreach($availableLevels as $level)
                  <option value="{{ $level->id }}" @selected((int) $user->level_id === (int) $level->id)>L{{ $level->level }} · {{ $level->title }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-12"><input type="text" name="reason" class="form-control" placeholder="Reason"></div>
            <div class="col-12 d-grid"><button class="btn btn-primary">Update Level</button></div>
          </form>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header"><h6 class="mb-0">Game Access</h6></div>
        <div class="card-body">
          <div class="small text-muted mb-3">
            Default state is locked. Only users explicitly enabled here can open game APIs or see game access in app config.
          </div>
          <form method="post" action="{{ route('admin.users.games.update', $user) }}" class="row g-3">
            @csrf
            <div class="col-12">
              <div class="border rounded-3 p-3">
                <div class="d-flex justify-content-between align-items-center gap-3">
                  <div>
                    <div class="fw-semibold">Teen Patti</div>
                    <div class="text-muted small">Unlock access for user #{{ $user->id }}</div>
                  </div>
                  <div class="form-check form-switch m-0">
                    <input type="hidden" name="teen_patti" value="0">
                    <input class="form-check-input" type="checkbox" name="teen_patti" value="1" id="game_access_teen_patti" @checked($gameAccessMap['teen_patti'] ?? false)>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-12">
              <div class="border rounded-3 p-3">
                <div class="d-flex justify-content-between align-items-center gap-3">
                  <div>
                    <div class="fw-semibold">Greedy</div>
                    <div class="text-muted small">Unlock access for user #{{ $user->id }}</div>
                  </div>
                  <div class="form-check form-switch m-0">
                    <input type="hidden" name="greedy" value="0">
                    <input class="form-check-input" type="checkbox" name="greedy" value="1" id="game_access_greedy" @checked($gameAccessMap['greedy'] ?? false)>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-12">
              <input type="text" name="reason" class="form-control" placeholder="Reason for access change">
            </div>
            <div class="col-12 d-grid">
              <button class="btn btn-primary">Save Game Access</button>
            </div>
          </form>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header"><h6 class="mb-0">Profile Frames</h6></div>
        <div class="card-body">
          <div class="row g-2 mb-3">
            <div class="col-md-6">
              <div class="border rounded-3 p-3 h-100">
                <div class="small text-muted">Equipped Frame</div>
                <div class="fw-semibold">{{ $equippedProfileFrame?->profileFrame?->name ?? 'None' }}</div>
                <div class="text-muted small">
                  @if($equippedProfileFrame?->profileFrame)
                    {{ strtoupper($equippedProfileFrame->profileFrame->rarity) }} · {{ $equippedProfileFrame->profileFrame->category }}
                  @else
                    No frame equipped
                  @endif
                </div>
              </div>
            </div>
            <div class="col-md-6">
              <div class="border rounded-3 p-3 h-100">
                <div class="small text-muted">Owned Frames</div>
                <div class="fw-semibold">{{ number_format($profileFrameHistory->count()) }}</div>
                <div class="text-muted small">Admin can grant, expire, auto-equip, and revoke frames here.</div>
              </div>
            </div>
          </div>
          <form method="post" action="{{ route('admin.users.profile-frames.store', $user) }}" class="row g-3 mb-3">
            @csrf
            <div class="col-12">
              <label class="form-label small text-muted mb-1">Frame</label>
              <select name="profile_frame_id" class="form-select" required>
                @foreach($availableProfileFrames as $frame)
                  <option value="{{ $frame->id }}">{{ $frame->name }} · {{ strtoupper($frame->rarity) }} · {{ $frame->unlock_type }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small text-muted mb-1">Expires At</label>
              <input type="datetime-local" name="expires_at" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label small text-muted mb-1 d-block">Options</label>
              <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" name="auto_equip" value="1" id="auto_equip_frame" checked>
                <label class="form-check-label" for="auto_equip_frame">Auto equip</label>
              </div>
            </div>
            <div class="col-12">
              <label class="form-label small text-muted mb-1">Reason</label>
              <input type="text" name="reason" class="form-control" placeholder="Reason">
            </div>
            <div class="col-12 d-grid">
              <button class="btn btn-primary">Assign Frame</button>
            </div>
          </form>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead><tr><th>ID</th><th>Frame</th><th>Source</th><th>Status</th><th>Granted</th><th>Expires</th><th class="text-end">Action</th></tr></thead>
              <tbody>
              @forelse($profileFrameHistory as $ownership)
                <tr>
                  <td>{{ $ownership->id }}</td>
                  <td>
                    <div class="fw-semibold">{{ $ownership->profileFrame?->name ?? '—' }}</div>
                    <div class="text-muted small">{{ $ownership->profileFrame?->rarity ? strtoupper($ownership->profileFrame->rarity) : '—' }}</div>
                  </td>
                  <td>{{ $ownership->source ?: '—' }}</td>
                  <td>
                    @if($ownership->is_equipped)
                      <span class="badge bg-success">Equipped</span>
                    @elseif($ownership->expires_at && $ownership->expires_at->isPast())
                      <span class="badge bg-warning text-dark">Expired</span>
                    @else
                      <span class="badge bg-secondary">Owned</span>
                    @endif
                  </td>
                  <td>{{ $ownership->granted_at?->format('d M Y H:i') ?? '—' }}</td>
                  <td>{{ $ownership->expires_at?->format('d M Y H:i') ?? 'Permanent' }}</td>
                  <td class="text-end">
                    <form method="post" action="{{ route('admin.users.profile-frames.destroy', [$user, $ownership]) }}" class="d-inline" onsubmit="return confirm('Revoke this profile frame?')">
                      @csrf @method('DELETE')
                      <input type="hidden" name="reason" value="Revoked from user 360">
                      <button class="btn btn-sm btn-outline-danger">Revoke</button>
                    </form>
                  </td>
                </tr>
              @empty
                <tr><td colspan="7" class="text-center text-muted py-3">No profile frames assigned.</td></tr>
              @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-8">
      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h6 class="mb-0">Subscriptions</h6>
          <a href="{{ route('admin.user-subscriptions.index') }}" class="btn btn-sm btn-light border">All Subscriptions</a>
        </div>
        <div class="card-body">
          <div class="row g-2 mb-3">
            <div class="col-md-4"><div class="border rounded-3 p-3"><div class="small text-muted">Active</div><div class="fw-semibold">{{ $activeSubscription?->plan?->name ?? 'None' }}</div></div></div>
            <div class="col-md-4"><div class="border rounded-3 p-3"><div class="small text-muted">Status</div><div class="fw-semibold">{{ $activeSubscription?->status ?? '—' }}</div></div></div>
            <div class="col-md-4"><div class="border rounded-3 p-3"><div class="small text-muted">Ends</div><div class="fw-semibold">{{ $activeSubscription?->ends_at?->format('d M Y') ?? '—' }}</div></div></div>
            <div class="col-md-12">
              <div class="border rounded-3 p-3 bg-light">
                <div class="small text-muted">Active Source</div>
                @if($activeSubscription)
                  <span class="badge {{ $activeSubscription->origin_badge_class }}">{{ $activeSubscription->origin_label }}</span>
                  <span class="small text-muted ms-2">{{ $activeSubscription->origin_description }}</span>
                @else
                  <span class="text-muted">—</span>
                @endif
              </div>
            </div>
          </div>
          <form method="post" action="{{ route('admin.users.subscriptions.store', $user) }}" class="row g-2 mb-3">
            @csrf
            <div class="col-md-4">
              <select name="plan_id" class="form-select" required>
                @foreach($availablePlans as $plan)
                  <option value="{{ $plan->id }}">{{ $plan->name }} · {{ number_format($plan->price_coins) }} coins / {{ $plan->duration_days }}d</option>
                @endforeach
              </select>
            </div>
            <div class="col-md-2">
              <select name="status" class="form-select">
                <option value="active">Active</option>
                <option value="cancelled">Cancelled</option>
                <option value="expired">Expired</option>
              </select>
            </div>
            <div class="col-md-2"><input type="datetime-local" name="starts_at" class="form-control"></div>
            <div class="col-md-2"><input type="datetime-local" name="ends_at" class="form-control"></div>
            <div class="col-md-2"><button class="btn btn-primary w-100">Grant</button></div>
            <div class="col-12"><input type="text" name="reason" class="form-control" placeholder="Reason"></div>
          </form>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead><tr><th>ID</th><th>Plan</th><th>Status</th><th>Source</th><th>Starts</th><th>Ends</th><th>Trace</th><th class="text-end">Action</th></tr></thead>
              <tbody>
              @forelse($subscriptions as $subscription)
                @php
                  $meta = is_array($subscription->meta ?? null) ? $subscription->meta : [];
                @endphp
                <tr>
                  <td>{{ $subscription->id }}</td>
                  <td>{{ $subscription->plan?->name ?? '—' }}</td>
                  <td><span class="badge bg-light text-dark">{{ strtoupper($subscription->status) }}</span></td>
                  <td>
                    <span class="badge {{ $subscription->origin_badge_class }}">{{ $subscription->origin_label }}</span>
                    <div class="small text-muted">{{ filter_var($meta['charged'] ?? false, FILTER_VALIDATE_BOOL) ? 'Coins charged' : 'No wallet charge' }}</div>
                  </td>
                  <td>{{ $subscription->starts_at?->format('d M Y H:i') }}</td>
                  <td>{{ $subscription->ends_at?->format('d M Y H:i') }}</td>
                  <td class="small text-muted">
                    {{ $meta['source'] ?? '—' }}
                    @if(!empty($meta['event'] ?? $meta['last_action'] ?? null))
                      · {{ $meta['event'] ?? $meta['last_action'] }}
                    @endif
                    @if(!empty($meta['previous_source'] ?? null))
                      <div>Previous: {{ $meta['previous_source'] }}</div>
                    @endif
                    @if(!empty($meta['wallet_transaction_id'] ?? null))
                      <div>Wallet Tx: #{{ $meta['wallet_transaction_id'] }}</div>
                    @endif
                  </td>
                  <td class="text-end">
                    <a href="{{ route('admin.user-subscriptions.edit', $subscription) }}" class="btn btn-sm btn-light border">Edit</a>
                    @if($subscription->status === 'active')
                      <form method="post" action="{{ route('admin.users.subscriptions.cancel', [$user, $subscription]) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-warning">Cancel</button></form>
                    @endif
                  </td>
                </tr>
              @empty
                <tr><td colspan="8" class="text-center text-muted py-3">No subscriptions.</td></tr>
              @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h6 class="mb-0">Entry Packs</h6>
          <a href="{{ route('admin.entry-packs.reports') }}" class="btn btn-sm btn-light border">Ownership Reports</a>
        </div>
        <div class="card-body">
          <div class="row g-2 mb-3">
            <div class="col-md-4"><div class="border rounded-3 p-3"><div class="small text-muted">Active Pack</div><div class="fw-semibold">{{ $activeEntryPack?->entryPack?->name ?? 'None' }}</div></div></div>
            <div class="col-md-4"><div class="border rounded-3 p-3"><div class="small text-muted">Style</div><div class="fw-semibold">{{ strtoupper($activeEntryPack?->entryPack?->animation_style ?? '—') }}</div></div></div>
            <div class="col-md-4"><div class="border rounded-3 p-3"><div class="small text-muted">Expires</div><div class="fw-semibold">{{ $activeEntryPack?->expires_at?->format('d M Y') ?? '—' }}</div></div></div>
            <div class="col-md-12">
              <div class="border rounded-3 p-3 bg-light">
                <div class="small text-muted">Active Ownership Source</div>
                @if($activeEntryPack)
                  <span class="badge {{ $activeEntryPack->origin_badge_class }}">{{ $activeEntryPack->origin_label }}</span>
                  <span class="small text-muted ms-2">{{ $activeEntryPack->origin_description }}</span>
                @else
                  <span class="text-muted">—</span>
                @endif
              </div>
            </div>
          </div>
          <form method="post" action="{{ route('admin.users.entry-packs.store', $user) }}" class="row g-2 mb-3">
            @csrf
            <div class="col-md-4">
              <select name="entry_pack_id" class="form-select" required>
                @foreach($availableEntryPacks as $pack)
                  <option value="{{ $pack->id }}">{{ $pack->name }} · {{ number_format($pack->price_coins) }} coins · {{ $pack->duration_days }}d</option>
                @endforeach
              </select>
            </div>
            <div class="col-md-3"><input type="datetime-local" name="purchased_at" class="form-control"></div>
            <div class="col-md-3"><input type="datetime-local" name="expires_at" class="form-control"></div>
            <div class="col-md-2 d-flex align-items-center"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active_entry" checked><label class="form-check-label" for="is_active_entry">Active</label></div></div>
            <div class="col-md-4">
              <select name="source_type" class="form-select">
                <option value="admin_grant">Admin grant</option>
                <option value="gift">Gift</option>
                <option value="promotional_gift">Promotional gift</option>
                <option value="signup_gift">Signup gift</option>
              </select>
            </div>
            <div class="col-md-4 d-flex align-items-center">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="charge_coins" value="1" id="charge_entry_pack">
                <label class="form-check-label" for="charge_entry_pack">Charge pack coins from user wallet</label>
              </div>
            </div>
            <div class="col-12"><input type="text" name="reason" class="form-control" placeholder="Reason"></div>
            <div class="col-12 d-grid"><button class="btn btn-primary">Assign Entry Pack</button></div>
          </form>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead><tr><th>ID</th><th>Pack</th><th>Status</th><th>Source</th><th>Trace</th><th>Purchased</th><th>Expires</th></tr></thead>
              <tbody>
              @forelse($entryHistory as $entry)
                <tr>
                  <td>{{ $entry->id }}</td>
                  <td>{{ $entry->entryPack?->name ?? '—' }}</td>
                  <td>
                    @if($entry->is_currently_usable)
                      <span class="badge bg-success">Active</span>
                    @elseif($entry->expires_at && $entry->expires_at->isPast())
                      <span class="badge bg-warning text-dark">Expired</span>
                    @else
                      <span class="badge bg-secondary">Inactive</span>
                    @endif
                  </td>
                  <td>
                    <span class="badge {{ $entry->origin_badge_class }}">{{ $entry->origin_label }}</span>
                    <div class="small text-muted">{{ $entry->charged ? 'Coins charged' : 'No wallet charge' }}</div>
                  </td>
                  <td class="small text-muted">
                    Source: {{ $entry->source ?: 'legacy' }}
                    @if($entry->wallet_transaction_id)
                      <div>Wallet Tx: #{{ $entry->wallet_transaction_id }}</div>
                    @endif
                    @if($entry->admin_note)
                      <div>Note: {{ \Illuminate\Support\Str::limit($entry->admin_note, 80) }}</div>
                    @endif
                  </td>
                  <td>{{ $entry->purchased_at?->format('d M Y H:i') }}</td>
                  <td>{{ $entry->expires_at?->format('d M Y H:i') ?? '—' }}</td>
                </tr>
              @empty
                <tr><td colspan="7" class="text-center text-muted py-3">No entry packs.</td></tr>
              @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header"><h6 class="mb-0">Host / Agency Linkage</h6></div>
        <div class="card-body">
          @if($user->host)
            <div class="row g-3">
              <div class="col-md-6">
                <div class="border rounded-3 p-3 h-100">
                  <div class="small text-muted">Host</div>
                  <div class="fw-semibold">#{{ $user->host->id }} · {{ $user->host->stage_name ?: $user->name }}</div>
                  <div class="text-muted small">{{ $user->host->city }} {{ $user->host->country }}</div>
                </div>
              </div>
              <div class="col-md-6">
                <div class="border rounded-3 p-3 h-100">
                  <div class="small text-muted">Agency</div>
                  <div class="fw-semibold">{{ $user->host->agency?->name ?? 'No agency' }}</div>
                  <div class="text-muted small">{{ $user->host->agency?->contact_email ?? '—' }}</div>
                </div>
              </div>
            </div>
          @else
            <div class="text-muted">User is not linked to a host profile.</div>
          @endif
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header"><h6 class="mb-0">Recent Activity</h6></div>
        <div class="card-body">
          <div class="table-responsive mb-3">
            <table class="table table-sm align-middle">
              <thead><tr><th>Room</th><th>Role</th><th>Joined</th></tr></thead>
              <tbody>
              @forelse($recentLiveParticipations as $row)
                <tr>
                  <td>
                    @if($row->room)
                      <a href="{{ route('admin.live-rooms.show', $row->room) }}">{{ $row->room->room_id }}</a>
                    @else
                      —
                    @endif
                  </td>
                  <td>{{ $row->role }}</td>
                  <td>{{ $row->joined_at?->format('d M Y H:i') }}</td>
                </tr>
              @empty
                <tr><td colspan="3" class="text-center text-muted">No live participation.</td></tr>
              @endforelse
              </tbody>
            </table>
          </div>
          <div class="table-responsive mb-3">
            <table class="table table-sm align-middle">
              <thead><tr><th>Call</th><th>Type</th><th>Status</th><th>Coins</th></tr></thead>
              <tbody>
              @forelse($recentCalls as $call)
                <tr>
                  <td>#{{ $call->id }}</td>
                  <td>{{ strtoupper($call->type) }}</td>
                  <td>{{ strtoupper($call->status) }}</td>
                  <td>{{ number_format($call->total_coins_charged ?? 0) }}</td>
                </tr>
              @empty
                <tr><td colspan="4" class="text-center text-muted">No calls.</td></tr>
              @endforelse
              </tbody>
            </table>
          </div>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead><tr><th>Gift</th><th>Room</th><th>Total Coins</th><th>When</th></tr></thead>
              <tbody>
              @forelse($recentGifts as $gift)
                <tr>
                  <td>{{ $gift->gift?->name ?? 'Gift' }}</td>
                  <td>@if($gift->room)<a href="{{ route('admin.live-rooms.show', $gift->room) }}">{{ $gift->room->room_id }}</a>@else—@endif</td>
                  <td>{{ number_format($gift->total_coins) }}</td>
                  <td>{{ $gift->created_at?->format('d M Y H:i') }}</td>
                </tr>
              @empty
                <tr><td colspan="4" class="text-center text-muted">No gifts sent.</td></tr>
              @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card">
        <div class="card-header"><h6 class="mb-0">Hosted / PK / Audit Trail</h6></div>
        <div class="card-body">
          <div class="table-responsive mb-3">
            <table class="table table-sm align-middle">
              <thead><tr><th>Hosted Room</th><th>Status</th><th>Started</th></tr></thead>
              <tbody>
              @forelse($recentHostedRooms as $room)
                <tr>
                  <td><a href="{{ route('admin.live-rooms.show', $room) }}">{{ $room->room_id }}</a></td>
                  <td>{{ strtoupper($room->status) }}</td>
                  <td>{{ $room->started_at?->format('d M Y H:i') ?: '—' }}</td>
                </tr>
              @empty
                <tr><td colspan="3" class="text-center text-muted">No hosted rooms.</td></tr>
              @endforelse
              </tbody>
            </table>
          </div>
          <div class="table-responsive mb-3">
            <table class="table table-sm align-middle">
              <thead><tr><th>PK Battle</th><th>Status</th><th>Score</th></tr></thead>
              <tbody>
              @forelse($pkBattles as $battle)
                <tr>
                  <td><a href="{{ route('admin.pk-battles.show', $battle) }}">{{ $battle->battle_id }}</a></td>
                  <td>{{ strtoupper($battle->status) }}</td>
                  <td>{{ number_format($battle->score_a) }} - {{ number_format($battle->score_b) }}</td>
                </tr>
              @empty
                <tr><td colspan="3" class="text-center text-muted">No PK participation.</td></tr>
              @endforelse
              </tbody>
            </table>
          </div>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead><tr><th>When</th><th>Area</th><th>Action</th><th>Admin</th><th>Reason</th></tr></thead>
              <tbody>
              @forelse($auditTrail as $audit)
                <tr>
                  <td>{{ $audit->created_at?->format('d M Y H:i') }}</td>
                  <td>{{ strtoupper(str_replace('_', ' ', $audit->area)) }}</td>
                  <td>{{ str_replace('_', ' ', $audit->action) }}</td>
                  <td>{{ $audit->admin?->name ?? 'System' }}</td>
                  <td>{{ $audit->reason ?: '—' }}</td>
                </tr>
              @empty
                <tr><td colspan="5" class="text-center text-muted">No admin audit entries.</td></tr>
              @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
@endsection
