<?php

use ResellerBytesAiOps\Text;

require_once __DIR__.'/../modules/addons/resellerbytes_ai_ops/lib/Text.php';

function assertText(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$char = "\u{00E9}"; // é (2 bytes in UTF-8)
$source = str_repeat($char, 150); // 300 bytes
assertText(strlen($source) === 300, 'Fixture must be exactly 300 bytes.');

$truncated = Text::truncateBytes($source, 200);
assertText(strlen($truncated) <= 200, 'Truncated value must stay within the byte budget.');
assertText($truncated !== '', 'Truncated value must not be empty.');
assertText(mb_check_encoding($truncated, 'UTF-8'), 'Truncated value must remain valid UTF-8.');

$encoded = json_encode(['value' => $truncated], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
assertText(is_string($encoded), 'json_encode must succeed for the truncated UTF-8 value.');

echo "WHMCS addon text truncation tests passed.\n";
