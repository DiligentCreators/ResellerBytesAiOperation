<?php

namespace ResellerBytesAiOps;

interface LocalApiClient
{
    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function call(string $command, array $values = []): array;
}
