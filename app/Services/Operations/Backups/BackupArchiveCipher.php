<?php

declare(strict_types=1);

namespace App\Services\Operations\Backups;

use RuntimeException;

final class BackupArchiveCipher
{
    private const MAGIC = 'RBBAK1';

    private const CHUNK_BYTES = 65536;

    /**
     * @return array{plaintext_sha256: string, encrypted_sha256: string, encrypted_bytes: int}
     */
    public function encryptFile(string $sourcePath, string $destinationPath, string $key): array
    {
        $this->assertKey($key);
        $input = fopen($sourcePath, 'rb');
        if ($input === false) {
            throw new RuntimeException('Unable to open backup archive source file.');
        }

        $output = fopen($destinationPath, 'wb');
        if ($output === false) {
            fclose($input);
            throw new RuntimeException('Unable to open backup archive destination file.');
        }

        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            $this->writeAll($output, self::MAGIC.$header);

            while (! feof($input)) {
                $chunk = fread($input, self::CHUNK_BYTES);

                if ($chunk === false) {
                    throw new RuntimeException('Unable to read the database dump.');
                }

                if ($chunk === '') {
                    break;
                }

                $encrypted = sodium_crypto_secretstream_xchacha20poly1305_push(
                    $state,
                    $chunk,
                    '',
                    SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
                );
                $this->writeFrame($output, $encrypted);
            }

            $final = sodium_crypto_secretstream_xchacha20poly1305_push(
                $state,
                '',
                '',
                SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL,
            );
            $this->writeFrame($output, $final);
        } finally {
            fclose($input);
            fclose($output);
        }

        $encryptedHash = hash_file('sha256', $destinationPath);
        $plaintextHash = hash_file('sha256', $sourcePath);
        $encryptedBytes = filesize($destinationPath);

        if (! is_string($encryptedHash) || ! is_string($plaintextHash) || $encryptedBytes === false) {
            throw new RuntimeException('Unable to calculate backup archive checksums.');
        }

        return [
            'plaintext_sha256' => $plaintextHash,
            'encrypted_sha256' => $encryptedHash,
            'encrypted_bytes' => $encryptedBytes,
        ];
    }

    public function decryptFile(string $sourcePath, string $destinationPath, string $key): void
    {
        $this->assertKey($key);
        $input = fopen($sourcePath, 'rb');
        if ($input === false) {
            throw new RuntimeException('Unable to open encrypted backup archive source file.');
        }

        $output = fopen($destinationPath, 'wb');
        if ($output === false) {
            fclose($input);
            throw new RuntimeException('Unable to open decrypted backup archive destination file.');
        }

        try {
            $prefix = $this->readExact($input, strlen(self::MAGIC) + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            if (! str_starts_with($prefix, self::MAGIC)) {
                throw new RuntimeException('Backup archive format is not recognized.');
            }

            $header = substr($prefix, strlen(self::MAGIC));
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            $finalSeen = false;

            while (! feof($input)) {
                $lengthBytes = fread($input, 4);

                if ($lengthBytes === false) {
                    throw new RuntimeException('Unable to read encrypted backup frame.');
                }

                if ($lengthBytes === '') {
                    break;
                }

                if (strlen($lengthBytes) !== 4) {
                    throw new RuntimeException('Encrypted backup frame length is truncated.');
                }

                $unpackedLength = unpack('Nlength', $lengthBytes);
                $length = is_array($unpackedLength) ? (int) ($unpackedLength['length'] ?? 0) : 0;
                if ($length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES
                    || $length > self::CHUNK_BYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
                    throw new RuntimeException('Encrypted backup frame length is invalid.');
                }

                $encrypted = $this->readExact($input, $length);
                $decrypted = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $encrypted);

                if ($decrypted === false) {
                    throw new RuntimeException('Backup archive authentication failed.');
                }

                [$plain, $tag] = $decrypted;
                $this->writeAll($output, $plain);

                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    $finalSeen = true;
                    break;
                }

                if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE) {
                    throw new RuntimeException('Backup archive contains an unsupported frame tag.');
                }
            }

            if (! $finalSeen || fread($input, 1) !== '') {
                throw new RuntimeException('Backup archive is incomplete or contains trailing data.');
            }
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    private function assertKey(string $key): void
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('Backup encryption key must decode to exactly 32 bytes.');
        }
    }

    /**
     * @param  resource  $stream
     */
    private function writeFrame($stream, string $ciphertext): void
    {
        $this->writeAll($stream, pack('N', strlen($ciphertext)).$ciphertext);
    }

    /**
     * @param  resource  $stream
     */
    private function writeAll($stream, string $data): void
    {
        $offset = 0;
        $length = strlen($data);

        while ($offset < $length) {
            $written = fwrite($stream, substr($data, $offset));

            if ($written === false || $written === 0) {
                throw new RuntimeException('Unable to write backup archive data.');
            }

            $offset += $written;
        }
    }

    /**
     * @param  resource  $stream
     */
    private function readExact($stream, int $length): string
    {
        $data = '';

        while (strlen($data) < $length) {
            $remaining = max(1, $length - strlen($data));
            $chunk = fread($stream, $remaining);

            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Encrypted backup archive is truncated.');
            }

            $data .= $chunk;
        }

        return $data;
    }
}
