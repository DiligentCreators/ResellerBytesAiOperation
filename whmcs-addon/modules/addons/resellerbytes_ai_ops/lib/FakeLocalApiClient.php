<?php

namespace ResellerBytesAiOps;

/**
 * Test double for RemediationRunner LocalAPI calls.
 */
final class FakeLocalApiClient implements LocalApiClient
{
    /** @var list<array{command: string, values: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, array<string, mixed>|callable> */
    private array $responses = [];

    /**
     * @param  array<string, mixed>|callable  $response
     */
    public function respond(string $command, array|callable $response): void
    {
        $this->responses[$command] = $response;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function call(string $command, array $values = []): array
    {
        $this->calls[] = ['command' => $command, 'values' => $values];

        $response = $this->responses[$command] ?? ['result' => 'success'];
        if (is_callable($response)) {
            $resolved = $response($values, $command);

            return is_array($resolved) ? $resolved : ['result' => 'error'];
        }

        return $response;
    }
}
