<?php

namespace ResellerBytesAiOps;

interface ActivityLogger
{
    public function log(string $message): void;
}
