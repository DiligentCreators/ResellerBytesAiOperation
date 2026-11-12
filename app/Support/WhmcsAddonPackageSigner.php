<?php

namespace App\Support;

use RuntimeException;

class WhmcsAddonPackageSigner
{
    public function isConfigured(): bool
    {
        try {
            $this->keyPair();

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public function publicKeyBase64(): string
    {
        [, $publicKey] = $this->keyPair();

        return base64_encode($publicKey);
    }

    public function fingerprint(): string
    {
        [, $publicKey] = $this->keyPair();

        return hash('sha256', $publicKey);
    }

    public function sign(string $message): string
    {
        [$secretKey] = $this->keyPair();

        return base64_encode(sodium_crypto_sign_detached($message, $secretKey));
    }

    /**
     * @return array{non-empty-string, non-empty-string}
     */
    private function keyPair(): array
    {
        if (! function_exists('sodium_crypto_sign_detached')) {
            throw new RuntimeException('The PHP Sodium extension is required for addon package signing.');
        }

        $secretKey = base64_decode((string) config('services.whmcs_addon.signing_secret_key'), true);
        $publicKey = base64_decode((string) config('services.whmcs_addon.signing_public_key'), true);

        if ($secretKey === false || $secretKey === '' || strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
            || $publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new RuntimeException('A valid WHMCS addon Ed25519 signing key pair is not configured.');
        }

        /** @var non-empty-string $secretKey */
        $secretKey = $secretKey;

        $derivedPublicKey = sodium_crypto_sign_publickey_from_secretkey($secretKey);

        if (! hash_equals($derivedPublicKey, $publicKey)) {
            throw new RuntimeException('The WHMCS addon signing public and private keys do not match.');
        }

        return [$secretKey, $publicKey];
    }
}
