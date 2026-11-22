<?php

namespace ResellerBytesAiOps;

final class WhmcsActivityLogger implements ActivityLogger
{
    public function log(string $message): void
    {
        if (function_exists('logActivity')) {
            logActivity($message);
        }
    }
}
