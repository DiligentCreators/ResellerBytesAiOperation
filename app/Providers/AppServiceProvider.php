<?php

namespace App\Providers;

use App\Contracts\AuditCheckpointObjectStore;
use App\Services\Audit\S3AuditCheckpointObjectStore;
use App\Services\Mail\CentralOutboundMailer;
use App\Services\Mail\PlatformMailTransport;
use App\Services\Operations\QueueJobMetricsTracker;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AuditCheckpointObjectStore::class, S3AuditCheckpointObjectStore::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Mail::extend('platform', fn () => new PlatformMailTransport(app(CentralOutboundMailer::class)));

        $this->configureDefaults();
        $this->registerQueueMetricsListeners();
    }

    private function registerQueueMetricsListeners(): void
    {
        Event::listen(JobQueued::class, static function (JobQueued $event): void {
            app(QueueJobMetricsTracker::class)->recordQueued(
                $event->connectionName,
                $event->queue,
                $event->id,
                $event->delay,
            );
        });

        Event::listen(JobProcessing::class, static function (JobProcessing $event): void {
            app(QueueJobMetricsTracker::class)->markStarted(
                $event->connectionName,
                $event->job->getJobId(),
            );
        });

        Event::listen(JobReleasedAfterException::class, static function (JobReleasedAfterException $event): void {
            app(QueueJobMetricsTracker::class)->recordQueued(
                $event->connectionName,
                $event->job->getQueue(),
                $event->job->getJobId(),
                $event->backoff,
            );
        });

        Event::listen(JobProcessed::class, static function (JobProcessed $event): void {
            $tracker = app(QueueJobMetricsTracker::class);

            if ($event->job->isReleased()) {
                $tracker->recordQueued(
                    $event->connectionName,
                    $event->job->getQueue(),
                    $event->job->getJobId(),
                );

                return;
            }

            $tracker->markFinished($event->connectionName, $event->job->getJobId());
        });

        Event::listen(JobFailed::class, static function (JobFailed $event): void {
            app(QueueJobMetricsTracker::class)->markFinished(
                $event->connectionName,
                $event->job->getJobId(),
            );
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
