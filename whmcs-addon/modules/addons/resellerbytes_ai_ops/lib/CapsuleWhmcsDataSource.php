<?php

namespace ResellerBytesAiOps;

use Throwable;
use WHMCS\Database\Capsule;

final class CapsuleWhmcsDataSource implements WhmcsDataSource
{
    public function configurationValue(string $setting): ?string
    {
        try {
            if (! class_exists(Capsule::class)) {
                return null;
            }

            $row = Capsule::table('tblconfiguration')
                ->where('setting', $setting)
                ->first(['value']);

            $value = is_object($row) ? ($row->value ?? null) : null;

            return is_string($value) ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function databaseVersion(): ?string
    {
        try {
            if (! class_exists(Capsule::class)) {
                return null;
            }

            $rows = Capsule::connection()->select('SELECT VERSION() AS version');
            $version = $rows[0]->version ?? null;

            if (! is_string($version) || $version === '') {
                return null;
            }

            if (preg_match('/\A[A-Za-z0-9._+-]+/', trim($version), $matches) !== 1) {
                return null;
            }

            return Text::truncateBytes($matches[0], 64);
        } catch (Throwable) {
            return null;
        }
    }

    public function localApi(string $command, array $params = []): ?array
    {
        try {
            if (! function_exists('localAPI')) {
                return null;
            }

            $result = localAPI($command, $params);

            return is_array($result) ? $result : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function cronAggregates(): ?array
    {
        try {
            if (! class_exists(Capsule::class)) {
                return null;
            }

            $lastDaily = $this->configurationValue('lastDailyCronInvocationTime');
            $lastDailyRunAt = null;
            if (is_string($lastDaily) && $lastDaily !== '') {
                $timestamp = strtotime($lastDaily.' UTC');
                if ($timestamp !== false) {
                    $lastDailyRunAt = gmdate('Y-m-d\TH:i:s\Z', $timestamp);
                }
            }

            $starts = null;
            $completions = null;

            try {
                $starts = (int) Capsule::table('tbllog_register')
                    ->where('name', 'like', '%Daily Cron%')
                    ->where('created_at', '>=', gmdate('Y-m-d H:i:s', time() - 86400))
                    ->count();
            } catch (Throwable) {
                try {
                    $starts = (int) Capsule::table('tblactivitylog')
                        ->where('description', 'like', '%Cron Job: Starting%')
                        ->where('date', '>=', gmdate('Y-m-d H:i:s', time() - 86400))
                        ->count();
                } catch (Throwable) {
                    $starts = null;
                }
            }

            try {
                $completions = (int) Capsule::table('tblactivitylog')
                    ->where('description', 'like', '%Cron Job: Completed%')
                    ->where('date', '>=', gmdate('Y-m-d H:i:s', time() - 86400))
                    ->count();
            } catch (Throwable) {
                $completions = null;
            }

            return [
                'last_daily_run_at' => $lastDailyRunAt,
                'daily_starts_24h' => $starts,
                'daily_completions_24h' => $completions,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    public function moduleQueueFromTable(): ?array
    {
        try {
            if (! class_exists(Capsule::class)) {
                return null;
            }

            $rows = Capsule::table('tblmodulequeue')
                ->where('completed', 0)
                ->orderBy('id')
                ->limit(50)
                ->get(['id', 'service_type', 'service_id', 'module_name', 'module_action', 'created_at', 'last_attempt', 'num_retries', 'last_error']);

            $items = [];
            foreach ($rows as $row) {
                $created = isset($row->created_at) ? strtotime((string) $row->created_at.' UTC') : false;
                $age = $created === false ? 0 : max(0, (int) floor((time() - $created) / 60));
                $items[] = [
                    'id' => (int) ($row->id ?? 0),
                    'service_type' => (string) ($row->service_type ?? 'service'),
                    'service_id' => (int) ($row->service_id ?? 0),
                    'action' => (string) ($row->module_action ?? 'other'),
                    'age_minutes' => $age,
                    'retries' => (int) ($row->num_retries ?? 0),
                    'error' => (string) ($row->last_error ?? ''),
                ];
            }

            return $items;
        } catch (Throwable) {
            return null;
        }
    }

    public function billingAggregates(): ?array
    {
        try {
            if (! class_exists(Capsule::class)) {
                return null;
            }

            $today = gmdate('Y-m-d');
            $overdue = Capsule::table('tblinvoices')
                ->where('status', 'Unpaid')
                ->where('duedate', '<', $today)
                ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total), 0) as total')
                ->first();

            $overdueIds = Capsule::table('tblinvoices')
                ->where('status', 'Unpaid')
                ->where('duedate', '<', $today)
                ->orderBy('id')
                ->limit(50)
                ->pluck('id')
                ->all();

            // Best-effort: overdue unpaid invoices without a recent overdue-notice activity log hit.
            $reminded = [];
            try {
                $reminded = Capsule::table('tblactivitylog')
                    ->where(function ($query): void {
                        $query->where('description', 'like', '%First Invoice Overdue Notice%')
                            ->orWhere('description', 'like', '%Invoice Payment Reminder%');
                    })
                    ->where('date', '>=', gmdate('Y-m-d H:i:s', time() - 30 * 86400))
                    ->limit(500)
                    ->pluck('description')
                    ->all();
            } catch (Throwable) {
                $reminded = [];
            }

            $remindedIds = [];
            foreach ($reminded as $description) {
                if (is_string($description) && preg_match_all('/\b(\d+)\b/', $description, $matches) > 0) {
                    foreach ($matches[1] as $match) {
                        $remindedIds[] = (int) $match;
                    }
                }
            }
            $remindedIds = array_flip($remindedIds);
            $reminderIds = [];
            foreach ($overdueIds as $invoiceId) {
                $id = (int) $invoiceId;
                if (! isset($remindedIds[$id])) {
                    $reminderIds[] = $id;
                }
            }
            $reminderIds = SnapshotRedactor::boundIdList($reminderIds);

            $dueServices = Capsule::table('tblhosting')
                ->whereIn('domainstatus', ['Active', 'Suspended'])
                ->where('nextduedate', '<=', $today)
                ->where('nextduedate', '!=', '0000-00-00')
                ->whereNotExists(function ($query) use ($today): void {
                    $query->selectRaw('1')
                        ->from('tblinvoiceitems')
                        ->join('tblinvoices', 'tblinvoices.id', '=', 'tblinvoiceitems.invoiceid')
                        ->whereColumn('tblinvoiceitems.relid', 'tblhosting.id')
                        ->where('tblinvoiceitems.type', 'Hosting')
                        ->where('tblinvoices.status', '!=', 'Cancelled')
                        ->where('tblinvoices.duedate', '>=', $today);
                })
                ->orderBy('id')
                ->limit(50)
                ->pluck('id')
                ->all();

            $duplicateGroups = (int) Capsule::table('tblinvoiceitems as ii')
                ->join('tblinvoices as i', 'i.id', '=', 'ii.invoiceid')
                ->where('i.status', '!=', 'Cancelled')
                ->where('i.duedate', '>=', gmdate('Y-m-d', time() - 30 * 86400))
                ->where('ii.type', 'Hosting')
                ->groupBy('ii.relid', 'i.duedate')
                ->havingRaw('COUNT(*) >= 2')
                ->get()
                ->count();

            $recovered = Capsule::table('tblinvoices')
                ->where('status', 'Paid')
                ->where('datepaid', '>=', gmdate('Y-m-d H:i:s', time() - 7 * 86400))
                ->whereColumn('duedate', '<', 'datepaid')
                ->selectRaw('COALESCE(SUM(total), 0) as total')
                ->first();

            return [
                'overdue_unpaid_count' => (int) ($overdue->cnt ?? count($overdueIds)),
                'overdue_unpaid_total' => $this->decimalString($overdue->total ?? 0),
                'overdue_without_reminder' => [
                    'count' => count($reminderIds),
                    'invoice_ids' => $reminderIds,
                ],
                'due_services_without_invoice' => [
                    'count' => count($dueServices),
                    'service_ids' => SnapshotRedactor::boundIdList($dueServices),
                ],
                'duplicate_invoice_groups_30d' => $duplicateGroups,
                'recovered_paid_7d_total' => $this->decimalString($recovered->total ?? 0),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    public function serviceAggregates(): ?array
    {
        try {
            if (! class_exists(Capsule::class)) {
                return null;
            }

            $suspendedPaid = Capsule::table('tblhosting as h')
                ->join('tblinvoiceitems as ii', function ($join): void {
                    $join->on('ii.relid', '=', 'h.id')->where('ii.type', '=', 'Hosting');
                })
                ->join('tblinvoices as i', 'i.id', '=', 'ii.invoiceid')
                ->where('h.domainstatus', 'Suspended')
                ->where('i.status', 'Paid')
                ->distinct()
                ->orderBy('h.id')
                ->limit(50)
                ->pluck('h.id')
                ->all();

            $suspendDays = (int) ($this->configurationValue('AutoSuspensionDays') ?? 0);
            $graceDate = gmdate('Y-m-d', time() - max(0, $suspendDays) * 86400);
            $activePastGrace = Capsule::table('tblhosting')
                ->where('domainstatus', 'Active')
                ->where('nextduedate', '<', $graceDate)
                ->where('nextduedate', '!=', '0000-00-00')
                ->where(function ($query): void {
                    $query->whereNull('overideautosuspend')
                        ->orWhere('overideautosuspend', 0)
                        ->orWhere('overideautosuspend', '');
                })
                ->orderBy('id')
                ->limit(50)
                ->pluck('id')
                ->all();

            $orphans = Capsule::table('tblhosting')
                ->whereNotIn('userid', function ($query): void {
                    $query->select('id')->from('tblclients');
                })
                ->orderBy('id')
                ->limit(50)
                ->pluck('id')
                ->all();

            $pendingOrders = Capsule::table('tblorders as o')
                ->join('tblinvoices as i', 'i.id', '=', 'o.invoiceid')
                ->where('o.status', 'Pending')
                ->where('i.status', 'Paid')
                ->orderBy('o.id')
                ->limit(50)
                ->get(['o.id', 'o.date']);

            $orderIds = [];
            $oldestAge = null;
            foreach ($pendingOrders as $order) {
                $orderIds[] = (int) $order->id;
                $created = isset($order->date) ? strtotime((string) $order->date.' UTC') : false;
                if ($created !== false) {
                    $age = (int) floor((time() - $created) / 60);
                    $oldestAge = $oldestAge === null ? $age : max($oldestAge, $age);
                }
            }

            return [
                'suspended_with_paid_invoices' => [
                    'count' => count($suspendedPaid),
                    'service_ids' => SnapshotRedactor::boundIdList($suspendedPaid),
                ],
                'active_past_suspend_grace' => [
                    'count' => count($activePastGrace),
                    'service_ids' => SnapshotRedactor::boundIdList($activePastGrace),
                ],
                'orphan' => [
                    'count' => count($orphans),
                    'service_ids' => SnapshotRedactor::boundIdList($orphans),
                ],
                'pending_paid_orders' => [
                    'count' => count($orderIds),
                    'order_ids' => SnapshotRedactor::boundIdList($orderIds),
                    'oldest_age_minutes' => $oldestAge,
                ],
            ];
        } catch (Throwable) {
            return null;
        }
    }

    public function domainAggregates(): ?array
    {
        try {
            if (! class_exists(Capsule::class)) {
                return null;
            }

            $today = gmdate('Y-m-d');
            $activeExpired = Capsule::table('tbldomains')
                ->where('status', 'Active')
                ->where('expirydate', '<', $today)
                ->where('expirydate', '!=', '0000-00-00')
                ->orderBy('id')
                ->limit(50)
                ->pluck('id')
                ->all();

            $badExpiry = Capsule::table('tbldomains')
                ->where(function ($query): void {
                    $query->whereNull('expirydate')
                        ->orWhere('expirydate', '0000-00-00')
                        ->orWhere('expirydate', '');
                })
                ->whereIn('status', ['Active', 'Grace', 'Redemption'])
                ->orderBy('id')
                ->limit(50)
                ->pluck('id')
                ->all();

            $syncErrors = (int) Capsule::table('tblactivitylog')
                ->where('description', 'like', '%Domain Sync%')
                ->where('description', 'like', '%error%')
                ->where('date', '>=', gmdate('Y-m-d H:i:s', time() - 7 * 86400))
                ->count();

            $renewalFailed = [];
            try {
                $renewalFailed = Capsule::table('tblactivitylog')
                    ->where('description', 'like', '%Domain Renewal%')
                    ->where('description', 'like', '%Fail%')
                    ->where('date', '>=', gmdate('Y-m-d H:i:s', time() - 7 * 86400))
                    ->orderBy('id')
                    ->limit(50)
                    ->pluck('id')
                    ->all();
            } catch (Throwable) {
                $renewalFailed = [];
            }

            return [
                'active_expired' => [
                    'count' => count($activeExpired),
                    'domain_ids' => SnapshotRedactor::boundIdList($activeExpired),
                ],
                'bad_expiry' => [
                    'count' => count($badExpiry),
                    'domain_ids' => SnapshotRedactor::boundIdList($badExpiry),
                ],
                'sync_errors_7d' => $syncErrors,
                'renewal_failed_7d' => [
                    'count' => count($renewalFailed),
                    'domain_ids' => SnapshotRedactor::boundIdList($renewalFailed),
                ],
            ];
        } catch (Throwable) {
            return null;
        }
    }

    public function ticketAggregates(int $thresholdHours): ?array
    {
        try {
            if (! class_exists(Capsule::class)) {
                return null;
            }

            $cutoff = gmdate('Y-m-d H:i:s', time() - max(1, $thresholdHours) * 3600);
            $rows = Capsule::table('tbltickets')
                ->whereNotIn('status', ['Closed', 'Answered'])
                ->where(function ($query) use ($cutoff): void {
                    $query->where('lastreply', '<', $cutoff)
                        ->orWhere(function ($inner) use ($cutoff): void {
                            $inner->whereNull('lastreply')->where('date', '<', $cutoff);
                        });
                })
                ->orderBy('id')
                ->limit(50)
                ->get(['id', 'lastreply', 'date']);

            $ids = [];
            $oldestHours = null;
            foreach ($rows as $row) {
                $ids[] = (int) $row->id;
                $stamp = strtotime((string) (($row->lastreply ?? null) ?: ($row->date ?? '')).' UTC');
                if ($stamp !== false) {
                    $hours = (int) floor((time() - $stamp) / 3600);
                    $oldestHours = $oldestHours === null ? $hours : max($oldestHours, $hours);
                }
            }

            return [
                'threshold_hours' => max(1, $thresholdHours),
                'sla_breach' => [
                    'count' => count($ids),
                    'ticket_ids' => SnapshotRedactor::boundIdList($ids),
                    'oldest_hours' => $oldestHours,
                ],
            ];
        } catch (Throwable) {
            return null;
        }
    }

    public function mailAggregates(): ?array
    {
        try {
            if (! class_exists(Capsule::class)) {
                return null;
            }

            $piping = (int) Capsule::table('tblticketmaillog')
                ->where('status', 'like', '%Fail%')
                ->where('date', '>=', gmdate('Y-m-d H:i:s', time() - 7 * 86400))
                ->count();

            $sending = (int) Capsule::table('tblactivitylog')
                ->where(function ($query): void {
                    $query->where('description', 'like', '%Email Sending Failed%')
                        ->orWhere('description', 'like', '%Email Failed%');
                })
                ->where('date', '>=', gmdate('Y-m-d H:i:s', time() - 86400))
                ->count();

            return [
                'piping_failed_7d' => $piping,
                'sending_failed_24h' => $sending,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    public function gatewayAggregates(): ?array
    {
        try {
            if (! class_exists(Capsule::class)) {
                return null;
            }

            $active = Capsule::table('tblpaymentgateways')
                ->where('setting', 'name')
                ->orderBy('gateway')
                ->limit(30)
                ->pluck('gateway')
                ->all();

            $failures = $this->gatewayCountMap('%Fail%', 7);
            $signatureFailures = $this->gatewayCountMap('%signature%', 7);
            $unmatched = [];

            $successLogs = Capsule::table('tblgatewaylog')
                ->where('result', 'like', '%Success%')
                ->where('date', '>=', gmdate('Y-m-d H:i:s', time() - 7 * 86400))
                ->get(['gateway', 'result']);

            foreach ($successLogs as $log) {
                $gateway = (string) ($log->gateway ?? '');
                $sanitizedGateway = Identifier::sanitize($gateway);
                if ($sanitizedGateway === null) {
                    continue;
                }
                $hasTxn = Capsule::table('tblaccounts')
                    ->where('gateway', $gateway)
                    ->where('date', '>=', gmdate('Y-m-d H:i:s', time() - 7 * 86400))
                    ->exists();
                if (! $hasTxn) {
                    $unmatched[$sanitizedGateway] = ($unmatched[$sanitizedGateway] ?? 0) + 1;
                }
            }

            return [
                'active' => Identifier::sanitizeNameList(array_map('strval', $active)),
                'failures_7d' => Identifier::sanitizeCountMap($failures),
                'signature_failures_7d' => Identifier::sanitizeCountMap($signatureFailures),
                'unmatched_success_7d' => Identifier::sanitizeCountMap($unmatched),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    public function moduleInventory(): ?array
    {
        try {
            if (! class_exists(Capsule::class)) {
                return null;
            }

            $registrars = Capsule::table('tblregistrars')
                ->where('setting', 'Username')
                ->orderBy('registrar')
                ->limit(30)
                ->pluck('registrar')
                ->all();

            $list = [];
            foreach ($registrars as $name) {
                $sanitized = Identifier::sanitize((string) $name);
                if ($sanitized === null) {
                    continue;
                }

                $list[] = [
                    'name' => $sanitized,
                    'version' => null,
                ];
            }

            $customTemplates = null;
            $hooksCount = null;
            if (defined('ROOTDIR')) {
                $root = rtrim((string) ROOTDIR, '/\\');
                $customTemplates = is_dir($root.DIRECTORY_SEPARATOR.'templates'.DIRECTORY_SEPARATOR.'custom');
                $hooksFile = $root.DIRECTORY_SEPARATOR.'includes'.DIRECTORY_SEPARATOR.'hooks.php';
                $hooksCount = is_file($hooksFile) ? 1 : 0;
                $hooksDir = $root.DIRECTORY_SEPARATOR.'includes'.DIRECTORY_SEPARATOR.'hooks';
                if (is_dir($hooksDir)) {
                    $files = glob($hooksDir.DIRECTORY_SEPARATOR.'*.php') ?: [];
                    $hooksCount = count($files);
                }
            }

            return [
                'registrars' => $list,
                'custom_template_overrides' => $customTemplates,
                'custom_hooks_count' => $hooksCount,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    public function automationSettings(): ?array
    {
        try {
            $suspend = $this->configurationValue('AutoSuspensionDays');
            $invoiceDays = $this->configurationValue('CreateInvoiceDaysBefore');

            return [
                'suspend_days' => is_numeric($suspend) ? (int) $suspend : null,
                'invoice_generation_days' => is_numeric($invoiceDays) ? (int) $invoiceDays : null,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    public function securityFlags(): ?array
    {
        try {
            if (! defined('ROOTDIR')) {
                return null;
            }

            $root = rtrim((string) ROOTDIR, '/\\');
            $adminDefault = is_dir($root.DIRECTORY_SEPARATOR.'admin');
            $installPresent = is_dir($root.DIRECTORY_SEPARATOR.'install');

            return [
                'admin_dir_default' => $adminDefault,
                'install_dir_present' => $installPresent,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    public function hostDiskStats(string $root): ?array
    {
        try {
            if ($root === '' || ! is_dir($root)) {
                return null;
            }

            $free = @disk_free_space($root);
            $total = @disk_total_space($root);
            if ($free === false || $total === false || $total <= 0) {
                return null;
            }

            return [
                'disk_free_percent' => round(($free / $total) * 100, 2),
                'disk_free_bytes' => (int) $free,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    public function dbSizeStats(): ?array
    {
        try {
            if (! class_exists(Capsule::class)) {
                return null;
            }

            $database = Capsule::connection()->getDatabaseName();
            if (! is_string($database) || $database === '') {
                return null;
            }

            $rows = Capsule::connection()->select(
                'SELECT table_name AS name, ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
                 FROM information_schema.TABLES
                 WHERE table_schema = ?
                 ORDER BY (data_length + index_length) DESC
                 LIMIT 5',
                [$database],
            );

            $top = [];
            $total = 0.0;
            foreach ($rows as $row) {
                $size = (float) ($row->size_mb ?? 0);
                $total += $size;
                $top[] = [
                    'name' => (string) ($row->name ?? ''),
                    'size_mb' => $size,
                ];
            }

            $sum = Capsule::connection()->selectOne(
                'SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
                 FROM information_schema.TABLES WHERE table_schema = ?',
                [$database],
            );

            return [
                'db_size_mb' => (float) ($sum->size_mb ?? $total),
                'db_top_tables' => $top,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, int>
     */
    private function gatewayCountMap(string $like, int $days): array
    {
        $rows = Capsule::table('tblgatewaylog')
            ->where('result', 'like', $like)
            ->where('date', '>=', gmdate('Y-m-d H:i:s', time() - $days * 86400))
            ->groupBy('gateway')
            ->selectRaw('gateway, COUNT(*) as cnt')
            ->limit(30)
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $name = (string) ($row->gateway ?? '');
            $sanitized = Identifier::sanitize($name);
            if ($sanitized !== null) {
                $map[$sanitized] = ($map[$sanitized] ?? 0) + (int) ($row->cnt ?? 0);
            }
        }

        return $map;
    }

    private function decimalString(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '0.00';
        }

        return number_format((float) $value, 2, '.', '');
    }
}
