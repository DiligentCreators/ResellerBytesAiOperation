<?php

namespace App\Services\SafeActions;

use App\Models\DiagnosticFinding;
use App\Services\Diagnostics\WhmcsIssueCatalog;

class FindingSafeActionMapper
{
    public function __construct(private readonly SafeActionRegistry $registry) {}

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     risk_level: string,
     *     requires_password_confirm: bool,
     *     execution_target: string,
     *     entitlement: string,
     *     bulk_allowed: bool,
     *     max_items: int,
     *     auto_allowed: bool
     * }>
     */
    public function suggestedActions(DiagnosticFinding $finding): array
    {
        $keys = $this->catalogActionKeys($finding);

        if ($keys === []) {
            $keys = $this->legacyTitleEvidenceKeys($finding);
        }

        $keys[] = 'pull_and_run_diagnostic_collect';

        $suggested = [];
        foreach (array_unique($keys) as $key) {
            if (! $this->registry->isAllowlisted($key)) {
                continue;
            }

            $definition = $this->registry->get($key);
            $suggested[] = [
                'key' => $definition['key'],
                'label' => $definition['label'],
                'description' => $definition['description'],
                'risk_level' => $definition['risk_level'],
                'requires_password_confirm' => $definition['requires_password_confirm'],
                'execution_target' => $definition['execution_target'],
                'entitlement' => $definition['entitlement'],
                'bulk_allowed' => $definition['bulk_allowed'],
                'max_items' => $definition['max_items'],
                'auto_allowed' => $definition['auto_allowed'],
            ];
        }

        return $suggested;
    }

    /**
     * @return list<string>
     */
    private function catalogActionKeys(DiagnosticFinding $finding): array
    {
        $issueKey = $finding->issue_key;
        if (! is_string($issueKey) || $issueKey === '') {
            return [];
        }

        return WhmcsIssueCatalog::actionKeys($issueKey);
    }

    /**
     * Fallback matching for AI findings without an issue_key.
     *
     * @return list<string>
     */
    private function legacyTitleEvidenceKeys(DiagnosticFinding $finding): array
    {
        $keys = [];
        $evidence = is_array($finding->evidence) ? $finding->evidence : [];
        $title = strtolower($finding->title);

        if ($this->evidenceIsTrue($evidence, 'system.configuration_file_writable')
            || str_contains($title, 'configuration file is writable')
            || str_contains($title, 'configuration.php')) {
            $keys[] = 'harden_configuration_php_mode';
        }

        if ($this->evidenceIsFalse($evidence, 'system.error_display_disabled')
            || str_contains($title, 'error display')
            || str_contains($title, 'display_errors')) {
            $keys[] = 'disable_php_display_errors';
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function evidenceIsTrue(array $evidence, string $path): bool
    {
        return array_key_exists($path, $evidence) && $evidence[$path] === true;
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function evidenceIsFalse(array $evidence, string $path): bool
    {
        return array_key_exists($path, $evidence) && $evidence[$path] === false;
    }
}
