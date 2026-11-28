<?php

namespace ResellerBytesAiOps;

use RuntimeException;

/**
 * HTTP/API failure that still exposes a letters-only FailureCode message,
 * optionally with sanitized validation field paths (never values).
 */
final class ApiClientException extends RuntimeException
{
    /** @var list<string> */
    private array $fieldKeys;

    /**
     * @param  list<string>  $fieldKeys
     */
    public function __construct(string $code, array $fieldKeys = [])
    {
        parent::__construct($code);
        $this->fieldKeys = array_values($fieldKeys);
    }

    /**
     * @return list<string>
     */
    public function fieldKeys(): array
    {
        return $this->fieldKeys;
    }

    /**
     * Admin alert suffix: " Fields: key1, key2."
     */
    public function fieldsAlertSuffix(): string
    {
        if ($this->fieldKeys === []) {
            return '';
        }

        return ' Fields: '.implode(', ', $this->fieldKeys).'.';
    }

    /**
     * Activity log suffix: " fields: key1, key2"
     */
    public function fieldsLogSuffix(): string
    {
        if ($this->fieldKeys === []) {
            return '';
        }

        return ' fields: '.implode(', ', $this->fieldKeys);
    }
}
