<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class BillingSeller
{
    /**
     * @return array{company_name: string, address: string, email: string, tax_id: string}
     */
    public static function details(): array
    {
        $fallback = [
            'company_name' => (string) config('billing.seller.company_name', 'ResellerBytes'),
            'address' => (string) config('billing.seller.address', ''),
            'email' => (string) config('billing.seller.email', 'billing@resellerbytes.com'),
            'tax_id' => (string) config('billing.seller.tax_id', ''),
        ];

        try {
            if (! Schema::hasTable('platform_billing_settings')) {
                return $fallback;
            }

            $row = DB::table('platform_billing_settings')->where('id', 1)->first();
            if ($row === null || $row->seller === null) {
                return $fallback;
            }

            $seller = is_string($row->seller)
                ? json_decode($row->seller, true)
                : (array) $row->seller;

            if (! is_array($seller)) {
                return $fallback;
            }

            return [
                'company_name' => (string) ($seller['company_name'] ?? $fallback['company_name']),
                'address' => (string) ($seller['address'] ?? $fallback['address']),
                'email' => (string) ($seller['email'] ?? $fallback['email']),
                'tax_id' => (string) ($seller['tax_id'] ?? $fallback['tax_id']),
            ];
        } catch (Throwable) {
            return $fallback;
        }
    }
}
