# Production pilot safety checklist

This checklist is mandatory before installing or running the WHMCS addon against the live ResellerBytes installation.

## Preconditions

- [ ] The Laravel application and ingestion API have passed automated tests and security review.
- [ ] The exact addon release and SHA-256 checksum have been reviewed.
- [ ] The addon has been statically inspected; no shell execution, SQL writes, WHMCS business API calls, or outbound destinations other than the configured operations API are present.
- [ ] A current, restorable backup of the WHMCS files and database has been confirmed by the operator.
- [ ] A maintenance window and rollback owner have been identified.
- [ ] The integration secret has been provisioned through the authorized operations workflow and stored securely.
- [ ] The HTTPS certificate and destination hostname have been independently verified.
- [ ] The collector schema and redaction rules have been reviewed against the exact payload that will be transmitted.

## First-run restrictions

- [ ] Install only the reviewed addon version.
- [ ] Do not enable a cron or scheduled run.
- [ ] Run the collector manually once.
- [ ] Confirm the addon makes only the signed HTTPS POST to the operations API.
- [ ] Confirm the snapshot is accepted and visible in the operations dashboard.
- [ ] Confirm no customer, invoice, transaction, credential, API token, or raw configuration data is present in the payload.
- [ ] Confirm the existing 20i hosting module and ResellerClub registrar module remain unchanged.
- [ ] Review all findings with an operator before considering any manual remediation.

## Immediate rollback triggers

Stop the pilot and remove/disable the addon if any of the following occurs:

- Unexpected WHMCS database writes or business-state changes
- Unexpected outbound network requests
- Any customer, billing, domain, hosting, or payment data is collected
- PHP errors, elevated resource use, slow requests, or cron degradation attributable to the addon
- Invalid signatures, unexplained retries, duplicate snapshot corruption, or unexpected API responses
- Any discrepancy between reviewed addon code and the installed package

After stopping, preserve relevant sanitized logs, revoke the integration secret, remove the addon using the reviewed rollback procedure, and verify normal WHMCS operation.

## Not permitted during the pilot

Do not change PHP versions, cron commands, file permissions, OPcache, WHMCS directory paths, module-debugging settings, 20i settings, ResellerClub settings, billing, customer accounts, domains, or hosting services based on an AI recommendation.

The first pilot validates collection and transport only. It does not authorize repairs.
