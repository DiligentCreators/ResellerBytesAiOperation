<?php

namespace App\Support;

final class WhmcsAddonVersion
{
    /**
     * Read the packaged WHMCS addon version from SignedApiClient::ADDON_VERSION.
     * Cached once per request; falls back to config when the source is missing or unparsable.
     */
    public static function packaged(): string
    {
        return once(static function (): string {
            $path = base_path('whmcs-addon/modules/addons/resellerbytes_ai_ops/lib/SignedApiClient.php');

            if (! is_file($path)) {
                return (string) config('services.whmcs_addon.version', '1.2.1');
            }

            $contents = file_get_contents($path);

            if ($contents === false) {
                return (string) config('services.whmcs_addon.version', '1.2.1');
            }

            if (! preg_match(
                '/public\s+const\s+ADDON_VERSION\s*=\s*[\'"]([0-9A-Za-z.+_-]+)[\'"]\s*;/',
                $contents,
                $matches,
            )) {
                return (string) config('services.whmcs_addon.version', '1.2.1');
            }

            return $matches[1];
        });
    }
}
