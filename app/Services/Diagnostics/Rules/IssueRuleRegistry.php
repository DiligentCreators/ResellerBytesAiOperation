<?php

namespace App\Services\Diagnostics\Rules;

use App\Services\Diagnostics\WhmcsIssueCatalog;

class IssueRuleRegistry
{
    /**
     * @param  list<IssueRule>  $rules
     */
    public function __construct(private readonly array $rules) {}

    /**
     * @return list<IssueRule>
     */
    public function all(): array
    {
        $sorted = $this->rules;

        usort($sorted, function (IssueRule $left, IssueRule $right): int {
            $leftPriority = WhmcsIssueCatalog::getInt($left->key(), 'priority', 999);
            $rightPriority = WhmcsIssueCatalog::getInt($right->key(), 'priority', 999);

            return $leftPriority <=> $rightPriority;
        });

        return $sorted;
    }

    /**
     * @return list<class-string<IssueRule>>
     */
    public static function ruleClasses(): array
    {
        return [
            CronNotRunningRule::class,
            CronDailyMissedRule::class,
            CronDailyNotCompletingRule::class,
            HeartbeatMissedRule::class,
            CronOverlapDuplicatesRule::class,
            ModuleQueueFailedRule::class,
            GatewayWebhookNotMarkingPaidRule::class,
            GatewayCallbackFailuresRule::class,
            DomainSyncErrorsRule::class,
            DomainRenewalFailedRule::class,
            DomainBadExpiryRule::class,
            DomainActiveExpiredRule::class,
            UpgradeWhmcs9ReadinessRule::class,
            SecurityPatchLagRule::class,
            SecurityCve202667398Rule::class,
            CronPhpMismatchRule::class,
            InvoiceNotGeneratedRule::class,
            OrdersPendingPaidRule::class,
            ServiceSuspendedButPaidRule::class,
            ServiceActiveButOverdueRule::class,
            ServiceOrphanRule::class,
            InvoiceOverdueNoReminderRule::class,
            EmailPipingFailedRule::class,
            EmailSendingFailedRule::class,
            TicketsSlaBreachRule::class,
            PhpVersionUnsupportedRule::class,
            PhpExtensionMissingRule::class,
            IoncubeOutdatedRule::class,
            PhpDisplayErrorsOnRule::class,
            SecurityConfigWritableRule::class,
            SecurityAdminDirDefaultRule::class,
            StorageDiskLowRule::class,
            DbSizeGrowthRule::class,
            AddonOutdatedRule::class,
            WhmcsVersionOutdatedRule::class,
        ];
    }
}
