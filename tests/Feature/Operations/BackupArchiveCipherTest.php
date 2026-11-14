<?php

declare(strict_types=1);

use App\Services\Operations\Backups\BackupArchiveCipher;
use RuntimeException;

it('encrypts and decrypts a backup stream with authenticated integrity', function () {
    $source = tempnam(sys_get_temp_dir(), 'rb-backup-source-');
    $encrypted = tempnam(sys_get_temp_dir(), 'rb-backup-encrypted-');
    $decrypted = tempnam(sys_get_temp_dir(), 'rb-backup-decrypted-');
    $key = random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);

    file_put_contents($source, str_repeat('ResellerBytes backup rehearsal payload', 4096));
    $cipher = app(BackupArchiveCipher::class);
    $metadata = $cipher->encryptFile($source, $encrypted, $key);
    $cipher->decryptFile($encrypted, $decrypted, $key);

    expect(file_get_contents($decrypted))->toBe(file_get_contents($source))
        ->and($metadata['plaintext_sha256'])->toBe(hash_file('sha256', $source))
        ->and($metadata['encrypted_sha256'])->toBe(hash_file('sha256', $encrypted));

    @unlink($source);
    @unlink($encrypted);
    @unlink($decrypted);
});

it('rejects a tampered backup archive', function () {
    $source = tempnam(sys_get_temp_dir(), 'rb-backup-source-');
    $encrypted = tempnam(sys_get_temp_dir(), 'rb-backup-encrypted-');
    $decrypted = tempnam(sys_get_temp_dir(), 'rb-backup-decrypted-');
    $key = random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);

    file_put_contents($source, 'authenticated backup data');
    app(BackupArchiveCipher::class)->encryptFile($source, $encrypted, $key);

    $contents = file_get_contents($encrypted);
    expect($contents)->toBeString();
    $contents[strlen($contents) - 1] = chr(ord($contents[strlen($contents) - 1]) ^ 1);
    file_put_contents($encrypted, $contents);

    expect(fn () => app(BackupArchiveCipher::class)->decryptFile($encrypted, $decrypted, $key))
        ->toThrow(RuntimeException::class);

    @unlink($source);
    @unlink($encrypted);
    @unlink($decrypted);
});


it('rejects a backup archive when the decryption key is incorrect', function () {
    $source = tempnam(sys_get_temp_dir(), 'rb-backup-source-');
    $encrypted = tempnam(sys_get_temp_dir(), 'rb-backup-encrypted-');
    $decrypted = tempnam(sys_get_temp_dir(), 'rb-backup-decrypted-');

    file_put_contents($source, 'backup data must remain confidential');
    $cipher = app(BackupArchiveCipher::class);
    $cipher->encryptFile(
        $source,
        $encrypted,
        random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES),
    );

    expect(fn () => $cipher->decryptFile(
        $encrypted,
        $decrypted,
        random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES),
    ))->toThrow(RuntimeException::class);

    @unlink($source);
    @unlink($encrypted);
    @unlink($decrypted);
});


it('rejects a backup archive encrypted with a different key', function () {
    $source = tempnam(sys_get_temp_dir(), 'rb-backup-source-');
    $encrypted = tempnam(sys_get_temp_dir(), 'rb-backup-encrypted-');
    $decrypted = tempnam(sys_get_temp_dir(), 'rb-backup-decrypted-');
    $key = random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);
    $wrongKey = random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);

    file_put_contents($source, 'backup key separation check');
    $cipher = app(BackupArchiveCipher::class);
    $cipher->encryptFile($source, $encrypted, $key);

    expect(fn () => $cipher->decryptFile($encrypted, $decrypted, $wrongKey))
        ->toThrow(RuntimeException::class);

    @unlink($source);
    @unlink($encrypted);
    @unlink($decrypted);
});
