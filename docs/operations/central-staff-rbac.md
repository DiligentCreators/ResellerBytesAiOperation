# Central staff RBAC

Central staff authorization is an application-level boundary. It is separate from PostgreSQL's rb_runtime, rb_migrator, and rb_readonly database identities (and from the dedicated billing operations identity).

## Identity and authority

- Configure exactly one initial super-admin identity with OPERATIONS_OWNER_EMAIL.
- The configured account is resolved by normalized email and always receives every central permission; it does not need a central_staff_access row.
- Staff accounts are separate users records created by the super admin from the Central Staff page. Each receives an explicit permission allowlist.
- Staff cannot create staff, change any permission assignment, change their own permission set, or delete the super admin. Staff administration endpoints require the configured super-admin identity, independently of assigned staff permissions.
- The super admin's configured email cannot be changed through profile settings, and the super-admin account cannot be deleted through profile settings.
- If OPERATIONS_OWNER_EMAIL is blank or does not match an existing account, no user receives super-admin privileges. Set it to the verified owner account before enabling central administration.

## Permission vocabulary

The fixed allowlist is:

- tenants — central tenant administration
- billing — central billing operations
- support — central support operations
- settings — central platform configuration (currently includes platform mail transport)
- audit — central audit and evidence access

Unknown permissions are rejected by validation and by the authorization service. Route/controller boundaries must use the central.permission:<permission> middleware or CentralStaffAuthorization::can(); hiding navigation items is not an authorization control. The existing central mail-settings routes are gated by settings. Tenant workspace routes remain governed by tenant membership and are deliberately not gated by central-staff permissions. The tenants, billing, support, and audit permission keys are available for their corresponding central feature endpoints; this branch does not introduce new tenant provisioning, billing UI, support UI, or a central audit UI.

## Audit evidence

Creating, changing, and deleting staff accounts writes an immutable audit event in the same database transaction as the corresponding account/access change. Permission-change events record the before/after allowlists. Audit records contain no password material. Failed authorization attempts do not mutate staff access.

## Operations

1. Set OPERATIONS_OWNER_EMAIL in the secret-managed application environment to the sole super-admin email.
2. Run reviewed migrations using rb_migrator, never the runtime login.
3. Sign in as the configured owner with verified email and enforced TOTP.
4. Open Central Staff, create individual staff accounts, and grant only required permissions.
5. Deliver the strong temporary password through an approved secure channel; staff must enroll and confirm TOTP before operational routes are available, and should rotate the temporary password after first sign-in.
6. Review immutable audit events after every staff creation, permission change, or removal.
7. Test each role against both permitted and forbidden endpoints before enabling it operationally.

This application RBAC does not grant PostgreSQL privileges and must never be used as a substitute for database role separation.
