<?php

namespace ResellerBytesAiOps;

use RuntimeException;

final class WhmcsLocalApiClient implements LocalApiClient
{
    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function call(string $command, array $values = []): array
    {
        if (! function_exists('localAPI')) {
            throw new RuntimeException('local_api_unavailable');
        }

        $result = localAPI($command, $values);

        return is_array($result) ? $result : ['result' => 'error', 'message' => 'invalid_localapi_response'];
    }
}
