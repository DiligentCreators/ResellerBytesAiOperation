<?php

namespace App\Services\Diagnostics\Rules;

use App\Models\WhmcsIntegration;

interface IssueRule
{
    public function key(): string;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function detect(array $payload, WhmcsIntegration $integration): ?DetectedIssue;
}
