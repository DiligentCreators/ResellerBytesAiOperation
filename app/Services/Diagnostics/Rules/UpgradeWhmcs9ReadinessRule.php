<?php

namespace App\Services\Diagnostics\Rules;

use App\Models\WhmcsIntegration;

class UpgradeWhmcs9ReadinessRule extends AbstractIssueRule
{
    public function key(): string
    {
        return 'upgrade.whmcs9_readiness';
    }

    public function detect(array $payload, WhmcsIntegration $integration): ?DetectedIssue
    {
        $version = $payload['system']['whmcs_version'] ?? null;
        if (! is_string($version) || $version === '' || strtolower($version) === 'unknown') {
            return null;
        }

        // Only relevant for 8.x installs considering a 9.x move.
        if ($this->compareVersions($version, '9.0.0') >= 0) {
            return null;
        }

        $modules = $this->whmcsBlock($payload, 'modules');
        if ($modules === null) {
            return null;
        }

        $risks = [];
        if (($modules['custom_template_overrides'] ?? null) === true) {
            $risks['custom_template_overrides'] = true;
        }
        if (is_numeric($modules['custom_hooks_count'] ?? null) && (int) $modules['custom_hooks_count'] > 0) {
            $risks['custom_hooks_count'] = (int) $modules['custom_hooks_count'];
        }

        $php = $payload['php'] ?? null;
        $ioncube = is_array($php) && is_string($php['ioncube_version'] ?? null) ? $php['ioncube_version'] : null;
        $minIoncube = (int) ($this->arraySetting('monitoring.ioncube_minimum_versions')['9.1'] ?? 15);
        if ($ioncube !== null) {
            $major = (int) explode('.', $ioncube)[0];
            if ($major > 0 && $major < $minIoncube) {
                $risks['ioncube_version'] = $ioncube;
                $risks['ioncube_minimum_for_9_1'] = $minIoncube;
            }
        }

        if ($risks === []) {
            return null;
        }

        return new DetectedIssue(
            $this->key(),
            $this->catalogSeverity($this->key()),
            count($risks),
            [
                'system.whmcs_version' => $version,
                'whmcs.modules.risks' => $risks,
            ],
        );
    }
}
