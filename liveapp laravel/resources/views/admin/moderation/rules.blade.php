@extends('layouts.admin-berry')
@section('title','Moderation Rules')
@section('content')
@php
  $ruleCollection = $rules->getCollection();
  $activeCount = $ruleCollection->where('is_active', true)->count();
  $criticalCount = $ruleCollection->where('severity', 'critical')->count();
  $reviewCount = $ruleCollection->where('action', 'review')->count();
  $kickOrBlockCount = $ruleCollection->whereIn('action', ['kick', 'block'])->count();

  $severityBadge = fn ($severity) => match($severity) {
      'critical' => 'bg-danger-subtle text-danger border border-danger-subtle',
      'high' => 'bg-warning-subtle text-warning border border-warning-subtle',
      'medium' => 'bg-info-subtle text-info border border-info-subtle',
      default => 'bg-success-subtle text-success border border-success-subtle',
  };

  $actionBadge = fn ($action) => match($action) {
      'block' => 'bg-danger-subtle text-danger border border-danger-subtle',
      'kick' => 'bg-warning-subtle text-warning border border-warning-subtle',
      'review' => 'bg-info-subtle text-info border border-info-subtle',
      'mute' => 'bg-secondary-subtle text-secondary border border-secondary-subtle',
      default => 'bg-success-subtle text-success border border-success-subtle',
  };

  $typeHelp = [
      'bad_word' => 'Matches a literal phrase anywhere in the message.',
      'spam' => 'Triggers when the same user hits the threshold within the time window.',
      'link' => 'Detects URLs, domains, and obvious external links.',
      'flooding' => 'Triggers on message volume in a short time window.',
      'custom' => 'Literal phrase matching for scams, threats, and off-platform asks.',
  ];
@endphp

<style>
  .moderation-rule-shell {
    background: linear-gradient(180deg, rgba(19, 24, 38, 0.04), rgba(19, 24, 38, 0));
    border-radius: 24px;
  }

  .moderation-stat-card {
    border: 1px solid rgba(31, 41, 55, 0.08);
    border-radius: 20px;
    background: linear-gradient(180deg, rgba(255,255,255,0.96), rgba(248,250,252,0.96));
    box-shadow: 0 20px 40px rgba(15, 23, 42, 0.06);
  }

  .moderation-legend-card,
  .moderation-create-card,
  .moderation-table-card {
    border: 1px solid rgba(31, 41, 55, 0.08);
    border-radius: 24px;
    box-shadow: 0 20px 40px rgba(15, 23, 42, 0.06);
  }

  .moderation-legend-card {
    background:
      radial-gradient(circle at top right, rgba(37, 99, 235, 0.14), transparent 32%),
      linear-gradient(180deg, #ffffff, #f8fafc);
  }

  .moderation-create-card {
    position: sticky;
    top: 92px;
    background:
      radial-gradient(circle at top left, rgba(14, 165, 233, 0.12), transparent 28%),
      linear-gradient(180deg, #ffffff, #f8fafc);
  }

  .moderation-table-card {
    background: #ffffff;
  }

  .rule-row {
    border: 1px solid rgba(31, 41, 55, 0.08);
    border-radius: 20px;
    background: linear-gradient(180deg, #ffffff, #fbfdff);
    box-shadow: 0 10px 25px rgba(15, 23, 42, 0.04);
  }

  .rule-row + .rule-row {
    margin-top: 1rem;
  }

  .rule-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr) minmax(0, 1.1fr) minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1.15fr);
    gap: 0.9rem;
  }

  .rule-chip {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: 0.35rem 0.7rem;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.02em;
  }

  .rule-mini-label {
    display: block;
    font-size: 0.72rem;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 0.45rem;
  }

  .rule-helper-list li + li {
    margin-top: 0.7rem;
  }

  @media (max-width: 1199.98px) {
    .moderation-create-card {
      position: static;
    }

    .rule-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }

  @media (max-width: 767.98px) {
    .rule-grid {
      grid-template-columns: minmax(0, 1fr);
    }
  }
</style>

<div class="moderation-rule-shell p-1">
  <div class="row g-3 mb-1">
    <div class="col-md-6 col-xl-3">
      <div class="moderation-stat-card h-100">
        <div class="card-body">
          <div class="text-muted small text-uppercase fw-semibold mb-2">Active Rules</div>
          <div class="display-6 fw-semibold">{{ $activeCount }}</div>
          <div class="text-muted small mt-2">Rules currently affecting live chat and moderation decisions.</div>
        </div>
      </div>
    </div>
    <div class="col-md-6 col-xl-3">
      <div class="moderation-stat-card h-100">
        <div class="card-body">
          <div class="text-muted small text-uppercase fw-semibold mb-2">Review Rules</div>
          <div class="display-6 fw-semibold">{{ $reviewCount }}</div>
          <div class="text-muted small mt-2">Messages that create moderation queue work instead of immediate removal.</div>
        </div>
      </div>
    </div>
    <div class="col-md-6 col-xl-3">
      <div class="moderation-stat-card h-100">
        <div class="card-body">
          <div class="text-muted small text-uppercase fw-semibold mb-2">Kick or Block</div>
          <div class="display-6 fw-semibold">{{ $kickOrBlockCount }}</div>
          <div class="text-muted small mt-2">High-impact actions. Keep these few and deliberate.</div>
        </div>
      </div>
    </div>
    <div class="col-md-6 col-xl-3">
      <div class="moderation-stat-card h-100">
        <div class="card-body">
          <div class="text-muted small text-uppercase fw-semibold mb-2">Critical Rules</div>
          <div class="display-6 fw-semibold">{{ $criticalCount }}</div>
          <div class="text-muted small mt-2">Most sensitive rules. Best used for severe abuse or scams only.</div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 align-items-start">
    <div class="col-xl-4">
      <div class="card moderation-create-card mb-3">
        <div class="card-header border-0 pb-0">
          <div class="d-flex align-items-center justify-content-between gap-3">
            <div>
              <h4 class="mb-1">Create Rule</h4>
              <div class="text-muted small">Add one moderation rule with a clear trigger and action.</div>
            </div>
            <span class="rule-chip bg-dark-subtle text-dark border border-dark-subtle">Live</span>
          </div>
        </div>
        <div class="card-body pt-3">
          <form method="post" action="{{ route('admin.moderation.rules.store') }}" class="row g-3">
            @csrf
            <div class="col-12">
              <label class="rule-mini-label">Rule Key</label>
              <input class="form-control" name="rule_key" placeholder="spam_repeat_kick_3_in_1m" required>
            </div>
            <div class="col-12">
              <label class="rule-mini-label">Rule Type</label>
              <select class="form-select" name="rule_type">
                @foreach($ruleTypes as $item)
                  <option value="{{ $item }}">{{ $item }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-12">
              <label class="rule-mini-label">Pattern</label>
              <input class="form-control" name="pattern" placeholder="literal phrase or keyword">
              <div class="text-muted small mt-1">Used for `bad_word` and `custom`. Matching is plain phrase matching, not regex.</div>
            </div>
            <div class="col-md-6">
              <label class="rule-mini-label">Threshold</label>
              <input class="form-control" type="number" min="1" name="threshold" placeholder="3">
            </div>
            <div class="col-md-6">
              <label class="rule-mini-label">Window (Minutes)</label>
              <input class="form-control" type="number" min="1" name="duration_minutes" placeholder="1">
            </div>
            <div class="col-md-6">
              <label class="rule-mini-label">Action</label>
              <select class="form-select" name="action">
                @foreach($actions as $item)
                  <option value="{{ $item }}">{{ $item }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-md-6">
              <label class="rule-mini-label">Severity</label>
              <select class="form-select" name="severity">
                @foreach($severities as $item)
                  <option value="{{ $item }}">{{ ucfirst($item) }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_active" value="1" checked id="rule-is-active">
                <label class="form-check-label fw-semibold" for="rule-is-active">Activate immediately</label>
              </div>
            </div>
            <div class="col-12">
              <button class="btn btn-primary w-100">Create Rule</button>
            </div>
          </form>
        </div>
      </div>

      <div class="card moderation-legend-card">
        <div class="card-header border-0 pb-0">
          <h5 class="mb-1">How Rules Work</h5>
          <div class="text-muted small">The engine reads active rules from newest to oldest and stops on the first match.</div>
        </div>
        <div class="card-body">
          <ul class="list-unstyled rule-helper-list mb-0">
            <li>
              <div class="fw-semibold">1. Message is sanitized first</div>
              <div class="text-muted small">Extra spaces are collapsed and HTML tags are stripped before matching.</div>
            </li>
            <li>
              <div class="fw-semibold">2. Type decides the trigger</div>
              <div class="text-muted small">
                `bad_word` and `custom` use literal phrase matching.
                `spam` and `flooding` use threshold plus time window.
                `link` detects URLs and domains automatically.
              </div>
            </li>
            <li>
              <div class="fw-semibold">3. First matched rule wins</div>
              <div class="text-muted small">Once a rule matches, its action is applied and lower rules are not checked for that message.</div>
            </li>
            <li>
              <div class="fw-semibold">4. Action decides what happens</div>
              <div class="text-muted small">
                `warn` blocks the message and warns the user.
                `review` creates a moderation report.
                `kick` removes the user from the current room.
                `block` permanently blocks that user from the host.
              </div>
            </li>
            <li>
              <div class="fw-semibold">5. Severity is for admin clarity</div>
              <div class="text-muted small">Severity helps humans prioritize. The actual behavior comes from the action field.</div>
            </li>
          </ul>
        </div>
      </div>
    </div>

    <div class="col-xl-8">
      <div class="card moderation-table-card">
        <div class="card-header border-0 pb-0">
          <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
            <div>
              <h4 class="mb-1">Configured Rules</h4>
              <div class="text-muted small">Edit action, severity, thresholds, or disable risky rules without deleting history.</div>
            </div>
            <div class="d-flex flex-wrap gap-2">
              @foreach($ruleTypes as $item)
                <span class="rule-chip bg-light text-dark border">{{ $item }}</span>
              @endforeach
            </div>
          </div>
        </div>
        <div class="card-body pt-3">
          <div class="row g-2 mb-3">
            @foreach($typeHelp as $type => $help)
              <div class="col-md-6">
                <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                  <div class="fw-semibold mb-1">{{ $type }}</div>
                  <div class="text-muted small">{{ $help }}</div>
                </div>
              </div>
            @endforeach
          </div>

          @forelse($rules as $rule)
            <div class="rule-row p-3">
              <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
                <div>
                  <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <span class="rule-chip {{ $actionBadge($rule->action) }}">{{ strtoupper($rule->action) }}</span>
                    <span class="rule-chip {{ $severityBadge($rule->severity) }}">{{ strtoupper($rule->severity) }}</span>
                    <span class="rule-chip {{ $rule->is_active ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-secondary-subtle text-secondary border border-secondary-subtle' }}">
                      {{ $rule->is_active ? 'ACTIVE' : 'INACTIVE' }}
                    </span>
                  </div>
                  <div class="fw-semibold fs-6">{{ $rule->rule_key }}</div>
                  <div class="text-muted small">
                    {{ $typeHelp[$rule->rule_type] ?? 'Rule behavior depends on its type and action.' }}
                  </div>
                </div>
                <form method="post" action="{{ route('admin.moderation.rules.destroy', $rule) }}" onsubmit="return confirm('Delete this rule?')">
                  @csrf
                  @method('DELETE')
                  <button class="btn btn-sm btn-light border text-danger">Delete</button>
                </form>
              </div>

              <form id="rule-{{ $rule->id }}" method="post" action="{{ route('admin.moderation.rules.update', $rule) }}">
                @csrf
                @method('PUT')

                <div class="rule-grid">
                  <div>
                    <label class="rule-mini-label">Rule Key</label>
                    <input class="form-control form-control-sm" name="rule_key" value="{{ $rule->rule_key }}" required>
                  </div>
                  <div>
                    <label class="rule-mini-label">Type</label>
                    <select class="form-select form-select-sm" name="rule_type">
                      @foreach($ruleTypes as $item)
                        <option value="{{ $item }}" @selected($rule->rule_type === $item)>{{ $item }}</option>
                      @endforeach
                    </select>
                  </div>
                  <div>
                    <label class="rule-mini-label">Pattern</label>
                    <input class="form-control form-control-sm" name="pattern" value="{{ $rule->pattern }}" placeholder="literal phrase or keyword">
                  </div>
                  <div>
                    <label class="rule-mini-label">Threshold</label>
                    <input class="form-control form-control-sm" type="number" min="1" name="threshold" value="{{ $rule->threshold }}" placeholder="3">
                  </div>
                  <div>
                    <label class="rule-mini-label">Window (Minutes)</label>
                    <input class="form-control form-control-sm" type="number" min="1" name="duration_minutes" value="{{ $rule->duration_minutes }}" placeholder="1">
                  </div>
                  <div>
                    <label class="rule-mini-label">Action</label>
                    <select class="form-select form-select-sm" name="action">
                      @foreach($actions as $item)
                        <option value="{{ $item }}" @selected($rule->action === $item)>{{ $item }}</option>
                      @endforeach
                    </select>
                  </div>
                </div>

                <div class="row g-3 mt-1 align-items-end">
                  <div class="col-md-4">
                    <label class="rule-mini-label">Severity</label>
                    <select class="form-select form-select-sm" name="severity">
                      @foreach($severities as $item)
                        <option value="{{ $item }}" @selected($rule->severity === $item)>{{ ucfirst($item) }}</option>
                      @endforeach
                    </select>
                  </div>
                  <div class="col-md-4">
                    <div class="border rounded-4 px-3 py-2 h-100 d-flex align-items-center">
                      <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="rule-active-{{ $rule->id }}" {{ $rule->is_active ? 'checked' : '' }}>
                        <label class="form-check-label fw-semibold" for="rule-active-{{ $rule->id }}">
                          {{ $rule->is_active ? 'Active now' : 'Keep disabled' }}
                        </label>
                      </div>
                    </div>
                  </div>
                  <div class="col-md-4 text-md-end">
                    <button class="btn btn-primary w-100">Save Rule</button>
                  </div>
                </div>
              </form>
            </div>
          @empty
            <div class="text-center text-muted py-5">
              <div class="fw-semibold mb-1">No rules configured.</div>
              <div class="small">Create your first moderation rule from the panel on the left.</div>
            </div>
          @endforelse
        </div>
        <div class="card-footer border-0 pt-0">
          {{ $rules->withQueryString()->links() }}
        </div>
      </div>
    </div>
  </div>
</div>
@endsection
