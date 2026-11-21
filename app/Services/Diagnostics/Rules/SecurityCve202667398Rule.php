<?php

namespace App\Services\Diagnostics\Rules;

use App\Models\WhmcsIntegration;

class SecurityCve202667398Rule extends AbstractIssueRule
{
    public function key(): string
    {
        return 'security.cve_2026_67398';
    }

    public function detect(array $payload, WhmcsIntegration $integration): ?DetectedIssue
    {
        $version = $payload['system']['whmcs_version'] ?? null;
        if (! is_string($version) || $version === '' || strtolower($version) === 'unknown') {
            return null;
        }

        $gateways = $this->whmcsBlock($payload, 'gateways');
        if ($gateways === null || ! is_array($gateways['active'] ?? null)) {
            return null;
        }

        $hasTwoCheckout = false;
        foreach ($gateways['active'] as $name) {
            if (! is_string($name)) {
                continue;
            }
            $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', $name) ?? '');
            if (in_array($normalized, ['2checkout', 'twocheckout'], true)) {
                $hasTwoCheckout = true;
                break;
            }
        }

        if (! $hasTwoCheckout) {
            return null;
        }

        $vulnerable = false;
        if ($this->compareVersions($version, '9.0.0') >= 0) {
            $vulnerable = $this->compareVersions($version, '9.0.8') < 0;
        } elseif ($this->compareVersions($version, '8.0.0') >= 0) {
            $vulnerable = $this->compareVersions($version, '8.13.7') < 0;
        }

        if (! $vulnerable) {
            return null;
        }

        return new DetectedIssue(
            $this->key(),
            $this->catalogSeverity($this->key()),
            1,
            [
                'system.whmcs_version' => $version,
                'whmcs.gateways.active' => array_values(array_filter(
                    $gateways['active'],
                    static fn (mixed $name): bool => is_string($name),
                )),
                'advisory' => 'CVE-2026-67398',
            ],
        );
    }
}
