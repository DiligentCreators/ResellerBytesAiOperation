<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use JsonException;
use RuntimeException;

/**
 * Central (non-tenant) platform setting row.
 *
 * @property string $key
 * @property string|null $value JSON-encoded payload; ciphertext when is_secret
 * @property bool $is_secret
 * @property int|null $updated_by_user_id
 */
class PlatformSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'value',
        'is_secret',
        'updated_by_user_id',
    ];

    protected $hidden = [
        'value',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_secret' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * Decode the stored value (decrypting secrets first).
     */
    public function decodedValue(): mixed
    {
        $raw = $this->attributes['value'] ?? null;

        if ($raw === null || $raw === '') {
            return null;
        }

        if ($this->is_secret) {
            $raw = Crypt::decryptString((string) $raw);
        }

        try {
            return json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('platform_setting_value_invalid: '.$this->key, 0, $exception);
        }
    }

    /**
     * Encode a PHP value for persistence (encrypting when secret).
     */
    public static function encodeValue(mixed $value, bool $isSecret): string
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        if ($isSecret) {
            return Crypt::encryptString($json);
        }

        return $json;
    }
}
