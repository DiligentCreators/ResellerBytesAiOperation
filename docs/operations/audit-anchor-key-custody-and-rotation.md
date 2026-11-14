# Audit-anchor signing key custody and rotation

Status: custody procedure documented; no production key has been generated or provisioned
Scope: Ed25519 key pair used only to sign external audit-chain checkpoints

## Security properties

- The Ed25519 private key authorizes checkpoint signatures. Treat it as a production signing secret with restricted, logged access.
- The public key is a verification trust root. Distribute it to independent verifiers through a separately authenticated channel and record its SHA-256 fingerprint.
- The audit-anchor key pair must be independent of the WHMCS addon package-signing key, Laravel `APP_KEY`, WHMCS HMAC integration secrets, database credentials, and CI test keys.
- The application needs the private key only for checkpoint export. A read-only verifier needs the public key only.
- Never put private key material in Git, PR comments, chat, issue trackers, build artifacts, shell command arguments, or general application logs.

## Current implementation boundary

The application reads base64-encoded `AUDIT_ANCHOR_PRIVATE_KEY` and `AUDIT_ANCHOR_PUBLIC_KEY`. Export fails closed if the pair is missing, malformed, mismatched, or equal to the configured WHMCS addon signing key. Verification requires only the public key and validates the detached signature and environment.

The current checkpoint envelope does not carry a key identifier and the verifier supports one configured public key at a time. Therefore, production key rotation is **not yet a routine zero-downtime operation**. Do not replace the configured public key until a versioned key registry / key-id transition and historical verification workflow have been implemented and tested. Retain the old public key and fingerprint indefinitely for verification of checkpoints signed by that key.

## Initial key generation

Generate the pair on a dedicated, access-controlled key-custody workstation or approved HSM/secret-management workflow. Prefer a cryptographic provider that can keep the private key non-exportable; the current application integration accepts a base64 secret key, so a non-exportable signer requires a future signing-provider adapter.

If using the repository's libsodium-compatible format, the following PHP CLI snippet writes the secret key only to a new local file and prints only the public fingerprint. Run it from an encrypted, access-controlled temporary directory with backups/sync disabled:

```php
<?php

declare(strict_types=1);

$directory = getenv('AUDIT_KEY_OUTPUT_DIR');
if (! is_string($directory) || $directory === '' || ! is_dir($directory)) {
    throw new RuntimeException('A pre-created secure output directory is required.');
}

$privatePath = $directory.'/audit-anchor.private.b64';
$publicPath = $directory.'/audit-anchor.public.b64';
if (file_exists($privatePath) || file_exists($publicPath)) {
    throw new RuntimeException('Refusing to overwrite an existing key file.');
}

umask(0077);
$keypair = sodium_crypto_sign_keypair();
$secret = base64_encode(sodium_crypto_sign_secretkey($keypair));
$public = base64_encode(sodium_crypto_sign_publickey($keypair));

$privateHandle = fopen($privatePath, 'x');
if ($privateHandle === false) {
    throw new RuntimeException('Unable to create private key file.');
}

try {
    chmod($privatePath, 0600);
    if (fwrite($privateHandle, $secret) !== strlen($secret)) {
        throw new RuntimeException('Unable to write complete private key file.');
    }
} finally {
    fclose($privateHandle);
}

$publicHandle = fopen($publicPath, 'x');
if ($publicHandle === false) {
    throw new RuntimeException('Unable to create public key file.');
}

try {
    chmod($publicPath, 0644);
    if (fwrite($publicHandle, $public) !== strlen($public)) {
        throw new RuntimeException('Unable to write complete public key file.');
    }
} finally {
    fclose($publicHandle);
}

echo 'Public key SHA-256: '.hash('sha256', base64_decode($public, true)).PHP_EOL;
```

Immediately import the private value into the approved secret manager under a dedicated secret name and access policy. Verify the stored secret by reading it back through an authorized, non-logging secret-manager workflow; do not print it. Provision the public key and fingerprint independently to the verifier. Securely remove the temporary private-key file and confirm it is not recoverable from workstation backups or sync folders, following the key-custody policy.

## Configuration and separation checks

- [ ] Store `AUDIT_ANCHOR_PRIVATE_KEY` only in the production secret manager and inject it only into the checkpoint-export process that requires signing.
- [ ] Store `AUDIT_ANCHOR_PUBLIC_KEY` in the verifier configuration through an independently authenticated channel.
- [ ] Confirm the application environment has no fallback or auto-generation behavior for missing keys.
- [ ] Confirm the private and public keys form a valid pair using the application's export/verify checks in a non-production environment.
- [ ] Confirm the audit-anchor key differs from the WHMCS addon signing key and every other application secret.
- [ ] Restrict secret read access to the named checkpoint signer operator/service; log secret access without logging secret values.
- [ ] Restrict public-key replacement and trust-root changes to the independent security owner.
- [ ] Record key version label, public fingerprint, generation date, custody owner, secret-manager reference, and verifier deployment references. Do not record the private value.

## Rotation and compromise response

### Planned rotation

1. Treat rotation as a coordinated release requiring a new versioned checkpoint envelope with a key identifier, trusted multi-key verifier, and explicit old-to-new trust transition.
2. Generate the successor key pair in the independent custody boundary. Do not generate it on the application server.
3. Publish the successor public-key fingerprint and key identifier to the independent verifier before enabling signatures from the new key.
4. Produce and externally retain a transition checkpoint signed by the old key and successor-key proof according to the versioned protocol.
5. Update the signer and verifier configuration in a reviewed change window. Verify a checkpoint with the new key and verify historical checkpoints with the archived old public key.
6. Revoke the old private-key secret only after the transition is independently verified. Retain the old public key and its fingerprint for the lifetime of all artifacts it signed.
7. Record the transition evidence in the external immutable audit store.

These steps are a target procedure, not an executable current capability. The key-id and multi-key verification support must be implemented before production rotation.

### Suspected compromise

- Stop checkpoint signing and preserve all existing signed objects and access logs.
- Notify the independent security owner and incident-response lead through the approved channel.
- Revoke the compromised private-key secret and related signer access; do not delete previously signed immutable checkpoints.
- Establish the last known-good checkpoint and independently verify its signature and chain prefix.
- Do not trust new checkpoints signed by the compromised key. Do not silently overwrite the trusted public key.
- Use the reviewed key-transition implementation and independently witnessed recovery procedure to establish a new trust root.
- Record scope, time window, key fingerprint, affected checkpoint range, verification results, and corrective actions without exposing private key material.

## Operational release gates

- [ ] Separate cloud/security account and immutable checkpoint bucket are provisioned.
- [ ] Independent Ed25519 key custody and secret-manager access policies are approved.
- [ ] A separate read-only verifier can validate signatures without private-key access.
- [ ] Checkpoint upload uses conditional-create semantics and immutable object retention.
- [ ] Key fingerprint, checkpoint digest, object version, and retention read-back are recorded outside the application database.
- [ ] Key rotation and compromise-response exercises pass before production anchoring is enabled.

## Shahrukh ke liye pending

- Provision the independent security account and secret-manager/key-custody boundary.
- Generate or authorize generation of the production Ed25519 key pair and retain the private key only in the approved secret manager/HSM. Do not send it through chat or commit it.
- Provide the non-secret key reference/fingerprint and approve the named signing and verification operators.
- Approve the production Object Lock bucket and independent verifier access.

No production key, password, secret, or cloud credential has been generated or used by this repository work.
