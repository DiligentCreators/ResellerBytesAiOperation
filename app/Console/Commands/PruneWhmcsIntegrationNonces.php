<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneWhmcsIntegrationNonces extends Command
{
    protected $signature = 'whmcs:nonces:prune';

    protected $description = 'Delete expired WHMCS integration request nonces in bounded batches';

    private const BATCH_SIZE = 1000;

    private const MAX_ROWS_PER_RUN = 10000;

    public function handle(): int
    {
        $deleted = 0;

        while ($deleted < self::MAX_ROWS_PER_RUN) {
            $limit = min(self::BATCH_SIZE, self::MAX_ROWS_PER_RUN - $deleted);

            $ids = DB::table('integration_request_nonces')
                ->where('expires_at', '<', now())
                ->orderBy('id')
                ->limit($limit)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += DB::table('integration_request_nonces')
                ->whereIn('id', $ids)
                ->delete();
        }

        $this->info("Deleted {$deleted} expired WHMCS integration nonce(s).");

        return self::SUCCESS;
    }
}
