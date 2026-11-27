<?php

namespace ResellerBytesAiOps;

use RuntimeException;

final class CurlHttpTransport implements HttpTransport
{
    /**
     * {@inheritdoc}
     */
    public function execute(string $url, array $options, int $maxResponseBytes): array
    {
        if (! function_exists('curl_init')) {
            throw new RuntimeException('curl_extension_unavailable');
        }

        $handle = curl_init($url);

        if ($handle === false) {
            throw new RuntimeException('http_client_initialization_failed');
        }

        $responseBody = '';
        $responseTooLarge = false;

        $options[CURLOPT_RETURNTRANSFER] = false;
        $options[CURLOPT_WRITEFUNCTION] = static function ($curlHandle, string $chunk) use (&$responseBody, &$responseTooLarge, $maxResponseBytes): int {
            if ($responseTooLarge) {
                return 0;
            }

            $chunkLength = strlen($chunk);
            $remaining = $maxResponseBytes - strlen($responseBody);

            if ($chunkLength > $remaining) {
                if ($remaining > 0) {
                    $responseBody .= substr($chunk, 0, $remaining);
                }
                $responseTooLarge = true;

                return 0;
            }

            $responseBody .= $chunk;

            return $chunkLength;
        };

        if (! curl_setopt_array($handle, $options)) {
            curl_close($handle);

            throw new RuntimeException('http_client_configuration_failed');
        }

        $executed = curl_exec($handle);
        $errno = (int) curl_errno($handle);
        $httpStatus = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        return [
            'executed' => $executed === true,
            'errno' => $errno,
            'http_status' => $httpStatus,
            'body' => $responseBody,
            'response_too_large' => $responseTooLarge,
        ];
    }
}
