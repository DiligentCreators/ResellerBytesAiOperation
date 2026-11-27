<?php

namespace ResellerBytesAiOps;

/**
 * Injectable HTTP transport seam for SignedApiClient.
 *
 * Implementations must honour HTTPS-only TLS settings supplied by the caller
 * and must never read more than $maxResponseBytes of the response body.
 */
interface HttpTransport
{
    /**
     * @param  array<int, mixed>  $options
     * @return array{
     *     executed: bool,
     *     errno: int,
     *     http_status: int,
     *     body: string,
     *     response_too_large: bool
     * }
     */
    public function execute(string $url, array $options, int $maxResponseBytes): array;
}
