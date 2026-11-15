# Operational alert delivery and scheduling

## Runtime gate

Alert evaluation is implemented but disabled by default. Set OPERATIONS_ALERTS_ENABLED=true only after the required delivery routes are provisioned in the deployment secret manager and have passed a non-production delivery test.

Required standard routes:

- A valid owner email address in OPERATIONS_ALERT_EMAIL.
- Exactly one primary team route: OPERATIONS_ALERT_SLACK_WEBHOOK_URL or OPERATIONS_ALERT_DISCORD_WEBHOOK_URL.

Critical alerts additionally require at least one configured route:

- OPERATIONS_ALERT_CRITICAL_WHATSAPP_WEBHOOK_URL
- OPERATIONS_ALERT_CRITICAL_SMS_WEBHOOK_URL

All configured critical routes are attempted. Do not place webhook URLs, provider tokens, phone numbers, or real email addresses in source control.


## Central mail transport interaction

The alert dispatcher checks the central platform mail driver before reporting email delivery. When the driver is `log`, it records email as `not_sent` (a redacted preview is not a delivery), keeps the overall notification incomplete, and leaves the alert eligible for retry. Configure an approved SMTP transport before expecting email alerts to be acknowledged. Postmark remains a later launch transport; never place mail credentials in source control or chat.

## Evaluation and deduplication

- Run php artisan operations:check-alerts for one manual evaluation.
- When enabled, Laravel Scheduler evaluates once per minute and uses withoutOverlapping to prevent overlapping scheduled invocations.
- The command reads the normalized metrics snapshot and evaluates AlertThresholdPolicy against the approved thresholds.
- operations_alert_states stores the active state, first/last observation timestamps, last successful notification time, and a redacted allowlisted metric payload.
- A new or reactivated alert is sent immediately. An active alert repeats after OPERATIONS_ALERT_REPEAT_INTERVAL_MINUTES (default 15).
- A delivery claim lease prevents concurrent workers from sending the same alert simultaneously. Failed or incomplete delivery releases the claim and is retried on the next scheduled evaluation.
- Resolved alerts are marked inactive. Recovery notifications are not sent in this phase.
- The state machine is at-least-once across provider failures: if one route accepts a message while another route fails, the successful route may receive a duplicate when the alert is retried. Provider-side idempotency should be added when supported.

## Delivery contracts

Email is sent as an HTML-escaped OperationalAlertMail.

Slack receives a JSON body with a text field. Discord receives a JSON body with a content field. The WhatsApp and SMS endpoints are provider-neutral HTTPS webhook adapters and receive:

- schema_version: 1
- channel: whatsapp or sms
- severity and stable alert code
- message and UTC observation timestamp

A webhook is counted as delivered only when the HTTPS request completes and the provider returns a 2xx response. Redirects are not followed; non-2xx responses, invalid/non-HTTPS URLs, timeouts, and transport exceptions are failures. This prevents a configured HTTPS webhook from redirecting the delivery client to a second destination. Response bodies and webhook URLs are not logged. The receiving bridge is responsible for mapping this contract to the selected WhatsApp/SMS vendor and preserving delivery receipts.

## Operational boundaries

- The scheduler is disabled until OPERATIONS_ALERTS_ENABLED is explicitly set to true.
- Missing required routes cause delivery to remain incomplete; the alert is not marked notified and will retry.
- Alert evaluation and delivery failures do not authorize production remediation, backup restoration, or data deletion.
- This integration does not create cloud accounts, configure a secret manager, or provision vendor accounts.
