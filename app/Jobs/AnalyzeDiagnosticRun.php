<?php

namespace App\Jobs;

use App\Models\DiagnosticFinding;
use App\Models\DiagnosticRun;
use App\Services\Diagnostics\WhmcsDiagnosticAnalyzer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class AnalyzeDiagnosticRun implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 90;

    /**
     * @var array<int, int>
     */
    public array $backoff = [60, 300, 900];

    public function __construct(public string $diagnosticRunId) {}

    public function handle(WhmcsDiagnosticAnalyzer $analyzer): void
    {
        $run = DB::transaction(function (): ?DiagnosticRun {
            $run = DiagnosticRun::query()
                ->whereKey($this->diagnosticRunId)
                ->lockForUpdate()
                ->first();

            if (! $run || $run->status === 'analyzed') {
                return null;
            }

            if ($run->status === 'analyzing') {
                if ($run->analysis_started_at?->greaterThan(now()->subMinutes(10))) {
                    return null;
                }
            } elseif (! in_array($run->status, ['received', 'queued', 'analysis_failed'], true)) {
                return null;
            }

            $run->forceFill([
                'status' => 'analyzing',
                'analysis_started_at' => now(),
                'analysis_error_code' => null,
                'analysis_attempts' => $run->analysis_attempts + 1,
            ])->save();

            return $run->fresh();
        });

        if (! $run) {
            return;
        }

        try {
            $result = $analyzer->analyze($run);
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => 'analysis_failed',
                'analysis_error_code' => $this->safeErrorCode($exception),
            ])->save();

            throw $exception;
        }

        DB::transaction(function () use ($run, $result): void {
            $lockedRun = DiagnosticRun::query()
                ->whereKey($run->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedRun->findings()->delete();

            $createdFingerprints = [];

            foreach ($result['findings'] as $findingData) {
                $evidencePaths = array_values(array_unique($findingData['evidence_paths']));
                sort($evidencePaths, SORT_STRING);

                $fingerprint = hash('sha256', implode("\n", [
                    $findingData['category'],
                    mb_strtolower($findingData['title']),
                    implode('|', $evidencePaths),
                ]));

                if (isset($createdFingerprints[$fingerprint])) {
                    continue;
                }

                $createdFingerprints[$fingerprint] = true;
                $evidence = [];

                foreach ($evidencePaths as $path) {
                    $evidence[$path] = data_get($lockedRun->payload, $path);
                }

                $finding = DiagnosticFinding::create([
                    'diagnostic_run_id' => $lockedRun->id,
                    'fingerprint' => $fingerprint,
                    'category' => $findingData['category'],
                    'severity' => $findingData['severity'],
                    'title' => $findingData['title'],
                    'summary' => $findingData['summary'],
                    'evidence' => $evidence,
                    'confidence' => $findingData['confidence'],
                    'status' => 'open',
                ]);

                foreach ($findingData['recommendations'] as $recommendation) {
                    $finding->recommendations()->create([
                        'recommendation' => $recommendation['recommendation'],
                        'rationale' => $recommendation['rationale'],
                        'risk_level' => $recommendation['risk_level'],
                        'requires_manual_action' => true,
                    ]);
                }
            }

            $lockedRun->forceFill([
                'status' => 'analyzed',
                'analysis_model' => (string) config('services.openai.model', 'gpt-5.6-sol'),
                'analysis_prompt_version' => WhmcsDiagnosticAnalyzer::PROMPT_VERSION,
                'analysis_response_id' => $result['response_id'],
                'analysis_summary' => $result['summary'],
                'no_findings_reason' => $result['no_findings_reason'],
                'analysis_completed_at' => now(),
                'analysis_error_code' => null,
                'analysis_usage' => $result['usage'],
            ])->save();
        });
    }

    public function failed(?Throwable $exception): void
    {
        DiagnosticRun::query()
            ->whereKey($this->diagnosticRunId)
            ->where('status', '!=', 'analyzed')
            ->update([
                'status' => 'analysis_failed',
                'analysis_error_code' => $this->safeErrorCode($exception),
                'updated_at' => now(),
            ]);
    }

    private function safeErrorCode(?Throwable $exception): string
    {
        $allowed = [
            'provider_not_configured',
            'provider_request_failed',
            'provider_response_incomplete',
            'provider_output_missing',
            'provider_output_invalid_json',
            'provider_output_invalid_shape',
            'provider_output_schema_invalid',
            'provider_no_findings_reason_missing',
            'provider_no_findings_reason_unexpected',
            'provider_evidence_reference_invalid',
        ];

        $message = $exception?->getMessage() ?? '';

        return in_array($message, $allowed, true) ? $message : 'analysis_failed';
    }
}
