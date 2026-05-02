<?php

namespace App\Services;

use App\Models\CallEarningLedger;
use App\Models\CallSession;

class AgencyBackfillService
{
    public function run(): array
    {
        $sessionCount = 0;
        $ledgerCount = 0;

        CallSession::query()
            ->with('host')
            ->whereNull('agency_id')
            ->whereNotNull('host_id')
            ->chunkById(200, function ($rows) use (&$sessionCount) {
                foreach ($rows as $row) {
                    $agencyId = $row->host?->agency_id;
                    if (!$agencyId) {
                        continue;
                    }

                    $row->update(['agency_id' => $agencyId]);
                    $sessionCount++;
                }
            });

        CallEarningLedger::query()
            ->with(['host', 'callSession'])
            ->whereNull('agency_id')
            ->chunkById(200, function ($rows) use (&$ledgerCount) {
                foreach ($rows as $row) {
                    $agencyId = $row->callSession?->agency_id ?: $row->host?->agency_id;
                    if (!$agencyId) {
                        continue;
                    }

                    $row->update(['agency_id' => $agencyId]);
                    $ledgerCount++;
                }
            });

        return [
            'call_sessions_updated' => $sessionCount,
            'call_earning_ledgers_updated' => $ledgerCount,
        ];
    }
}
