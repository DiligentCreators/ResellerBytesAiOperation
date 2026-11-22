<?php

namespace App\Services\Alerts;

/**
 * Plain-text alert payload. Never include secrets or PII.
 */
final class AlertMessage
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $installationName = null,
        public readonly ?string $fixCenterUrl = null,
    ) {}

    public function plainText(): string
    {
        $lines = [$this->title, '', $this->body];

        if (is_string($this->installationName) && $this->installationName !== '') {
            $lines[] = '';
            $lines[] = 'Installation: '.$this->installationName;
        }

        if (is_string($this->fixCenterUrl) && $this->fixCenterUrl !== '') {
            $lines[] = '';
            $lines[] = 'Open Fix Center: '.$this->fixCenterUrl;
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{title: string, body: string, installation: string|null, fix_center_url: string|null}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'installation' => $this->installationName,
            'fix_center_url' => $this->fixCenterUrl,
        ];
    }
}
