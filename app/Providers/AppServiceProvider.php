<?php

namespace App\Providers;

use App\Contracts\AuditCheckpointObjectStore;
use App\Events\CriticalFindingRaised;
use App\Events\FixActionAutoQueued;
use App\Events\FixActionFailed;
use App\Events\InstallationHeartbeatStateChanged;
use App\Listeners\RecomputeHealthScoreOnHeartbeatChange;
use App\Services\Alerts\AlertDispatcher;
use App\Services\Audit\S3AuditCheckpointObjectStore;
use App\Services\Diagnostics\Rules\IssueRule;
use App\Services\Diagnostics\Rules\IssueRuleRegistry;
use App\Services\Mail\CentralOutboundMailer;
use App\Services\Mail\PlatformMailTransport;
use App\Services\Operations\QueueJobMetricsTracker;
use App\Services\PlatformSettings;
use App\Support\PlatformSettings\PlatformSettingsRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
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
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AuditCheckpointObjectStore::class, S3AuditCheckpointObjectStore::class);
        $this->app->singleton(PlatformSettingsRegistry::class);
        $this->app->singleton(PlatformSettings::class);
        $this->app->singleton(IssueRuleRegistry::class, function ($app): IssueRuleRegistry {
            $rules = array_map(
                static fn (string $class): IssueRule => $app->make($class),
                IssueRuleRegistry::ruleClasses(),
            );

            return new IssueRuleRegistry($rules);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Mail::extend('platform', fn () => new PlatformMailTransport(app(CentralOutboundMailer::class)));

        $this->configureTrustedProxies();
        $this->configureDefaults();
        $this->registerQueueMetricsListeners();
        Event::listen(InstallationHeartbeatStateChanged::class, RecomputeHealthScoreOnHeartbeatChange::class);
        Event::listen(
            InstallationHeartbeatStateChanged::class,
            [AlertDispatcher::class, 'handleHeartbeatStateChanged'],
        );
        Event::listen(
            CriticalFindingRaised::class,
            [AlertDispatcher::class, 'handleCriticalFindingRaised'],
        );
        Event::listen(
            FixActionFailed::class,
            [AlertDispatcher::class, 'handleFixActionFailed'],
        );
        Event::listen(
            FixActionAutoQueued::class,
            [AlertDispatcher::class, 'handleFixActionAutoQueued'],
        );
        $this->overlayPlatformSettings();
    }

    /**
     * Push DB-backed platform settings onto config() so existing consumers see them.
     * Guarded so artisan, migrations, and fresh installs never break.
     */
    private function overlayPlatformSettings(): void
    {
        try {
            $this->app->make(PlatformSettings::class)->overlayOntoConfig();
        } catch (Throwable) {
            //
        }
    }

    /**
     * Empty TRUSTED_PROXIES keeps forwarded headers untrusted (safe default).
     * Set comma-separated IPs/CIDRs or * behind Cloudflare/load balancers.
     */
    private function configureTrustedProxies(): void
    {
        $trustedProxies = trim((string) config('app.trusted_proxies', ''));
        if ($trustedProxies === '') {
            return;
        }

        TrustProxies::at(
            $trustedProxies === '*'
                ? '*'
                : array_values(array_filter(array_map(
                    static fn (string $proxy): string => trim($proxy),
                    explode(',', $trustedProxies),
                ))),
        );
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

        Model::preventLazyLoading(! $this->app->isProduction());

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
