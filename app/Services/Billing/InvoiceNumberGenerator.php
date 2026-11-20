<?php

namespace App\Services\Billing;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class InvoiceNumberGenerator
{
    public function next(?int $year = null): string
    {
        $year ??= (int) now('UTC')->format('Y');
        $prefix = (string) config('billing.invoice_number_prefix', 'RB');

        return DB::transaction(function () use ($year, $prefix): string {
            $row = DB::table('billing_invoice_sequences')->where('year', $year)->lockForUpdate()->first();

            if ($row === null) {
                DB::table('billing_invoice_sequences')->insert([
                    'year' => $year,
                    'last_number' => 0,
                    'created_at' => now('UTC'),
                    'updated_at' => now('UTC'),
                ]);
                $row = DB::table('billing_invoice_sequences')->where('year', $year)->lockForUpdate()->first();
            }

            if ($row === null) {
                throw new RuntimeException('billing_invoice_sequence_unavailable');
            }

            $next = ((int) $row->last_number) + 1;
            DB::table('billing_invoice_sequences')->where('year', $year)->update([
                'last_number' => $next,
                'updated_at' => now('UTC'),
            ]);

            return sprintf('%s-%d-%06d', $prefix, $year, $next);
        }, 3);
    }
}
