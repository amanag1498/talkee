@extends('layouts.admin-berry')
@section('title','Call Reports')

@section('content')
<style>
  .call-admin-hero {
    background: linear-gradient(135deg, #1f2937 0%, #334155 100%);
    color: #fff;
    border: 0;
    overflow: hidden;
  }

  .call-admin-hero .metric-chip {
    background: rgba(255, 255, 255, 0.12);
    border: 1px solid rgba(255, 255, 255, 0.14);
    border-radius: 999px;
    color: #fff;
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.45rem 0.8rem;
    font-size: 0.85rem;
  }

  .call-admin-card {
    border: 1px solid rgba(15, 23, 42, 0.08);
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.05);
  }

  .call-admin-kpi {
    min-height: 128px;
  }

  .call-admin-kpi .icon-wrap {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
  }

  .call-admin-filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 0.85rem;
  }

  .call-admin-filter-grid .form-label {
    font-size: 0.78rem;
    font-weight: 600;
    color: #64748b;
    margin-bottom: 0.35rem;
  }

  .call-admin-tabbar {
    display: flex;
    flex-wrap: wrap;
    gap: 0.65rem;
  }

  .call-admin-tabbar .tab-pill {
    border-radius: 999px;
    padding: 0.6rem 0.95rem;
    border: 1px solid rgba(148, 163, 184, 0.25);
    color: #334155;
    background: #fff;
    text-decoration: none;
    font-weight: 600;
    font-size: 0.9rem;
  }

  .call-admin-tabbar .tab-pill.active {
    background: #111827;
    color: #fff;
    border-color: #111827;
  }

  .call-admin-insight {
    border: 1px dashed rgba(148, 163, 184, 0.55);
    border-radius: 16px;
    padding: 1rem;
    height: 100%;
    background: #f8fafc;
  }

  .call-admin-table th {
    white-space: nowrap;
  }

  .call-admin-table td {
    vertical-align: middle;
  }

  .call-badge-soft {
    border-radius: 999px;
    padding: 0.38rem 0.7rem;
    font-size: 0.78rem;
    font-weight: 700;
  }

  .call-badge-soft.audio { background: #dbeafe; color: #1d4ed8; }
  .call-badge-soft.video { background: #ede9fe; color: #6d28d9; }
  .call-badge-soft.requested,
  .call-badge-soft.ringing { background: #fef3c7; color: #b45309; }
  .call-badge-soft.accepted { background: #dcfce7; color: #15803d; }
  .call-badge-soft.ended { background: #e2e8f0; color: #334155; }
  .call-badge-soft.rejected,
  .call-badge-soft.missed,
  .call-badge-soft.failed { background: #fee2e2; color: #b91c1c; }
</style>

@include('partials.call-report-table', ['layout' => 'admin'])
@endsection
