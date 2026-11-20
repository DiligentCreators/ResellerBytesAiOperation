<?php

namespace App\Http\Controllers;

use App\Models\PlatformAiSettings;
use App\Services\Ai\OpenAiConfiguration;
use App\Services\CentralStaffAuthorization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PlatformAiSettingsController extends Controller
{
    private function authorizeSettings(Request $request): void
    {
        abort_unless(app(CentralStaffAuthorization::class)->can($request->user(), 'settings'), 403);
    }

    public function show(Request $request, OpenAiConfiguration $configuration): Response
    {
        $this->authorizeSettings($request);
        $config = $configuration->effective();

        return Inertia::render('platform/ai-settings', [
            'api_key_set' => filled($config['api_key'] ?? null),
            'model' => $configuration->model(),
            'reasoning_effort' => $configuration->reasoningEffort(),
            'timeout' => $configuration->timeout(),
            'allowed_models' => OpenAiConfiguration::ALLOWED_MODELS,
            'reasoning_efforts' => OpenAiConfiguration::REASONING_EFFORTS,
            'signing_configured' => filled($config['signing_secret_key'] ?? null) && filled($config['signing_public_key'] ?? null),
            'signing_public_key' => (string) ($config['signing_public_key'] ?? ''),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function update(Request $request, OpenAiConfiguration $configuration): RedirectResponse
    {
        $this->authorizeSettings($request);

        $data = $request->validate([
            'api_key' => ['nullable', 'string', 'max:4096'],
            'clear_api_key' => ['sometimes', 'boolean'],
            'model' => ['required', 'string', Rule::in(OpenAiConfiguration::ALLOWED_MODELS)],
            'reasoning_effort' => ['required', 'string', Rule::in(OpenAiConfiguration::REASONING_EFFORTS)],
            'timeout' => ['required', 'integer', 'between:5,300'],
            'signing_secret_key' => ['nullable', 'string', 'max:4096'],
            'signing_public_key' => ['nullable', 'string', 'max:4096'],
            'clear_signing_keys' => ['sometimes', 'boolean'],
        ]);

        $existing = PlatformAiSettings::query()->find(1);
        /** @var array<string, mixed> $config */
        $config = $existing instanceof PlatformAiSettings ? (array) $existing->configuration : [];
        $cleared = is_array($config['_cleared'] ?? null) ? $config['_cleared'] : [];

        $config['model'] = $data['model'];
        $config['reasoning_effort'] = $data['reasoning_effort'];
        $config['timeout'] = (int) $data['timeout'];

        if (filter_var($data['clear_api_key'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            unset($config['api_key']);
            $cleared['api_key'] = true;
        } elseif (filled($data['api_key'] ?? null)) {
            $config['api_key'] = $data['api_key'];
            unset($cleared['api_key']);
        }

        if (filter_var($data['clear_signing_keys'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            unset($config['signing_secret_key'], $config['signing_public_key']);
            $cleared['signing_secret_key'] = true;
            $cleared['signing_public_key'] = true;
        } elseif (filled($data['signing_secret_key'] ?? null) || filled($data['signing_public_key'] ?? null)) {
            $secret = filled($data['signing_secret_key'] ?? null)
                ? (string) $data['signing_secret_key']
                : (string) ($config['signing_secret_key'] ?? $configuration->signingSecretKey());
            $public = filled($data['signing_public_key'] ?? null)
                ? (string) $data['signing_public_key']
                : (string) ($config['signing_public_key'] ?? $configuration->signingPublicKey());

            $this->assertValidSigningPair($secret, $public);
            $config['signing_secret_key'] = $secret;
            $config['signing_public_key'] = $public;
            unset($cleared['signing_secret_key'], $cleared['signing_public_key']);
        }

        $config['_cleared'] = $cleared;

        DB::transaction(function () use ($request, $config): void {
            PlatformAiSettings::query()->updateOrCreate(['id' => 1], [
                'configuration' => $config,
                'updated_by_user_id' => $request->user()?->getKey(),
            ]);
        });

        return redirect()
            ->route('platform.ai-settings.show')
            ->with('status', 'Platform AI and addon signing settings saved.');
    }

    public function generateSigningKeys(Request $request): RedirectResponse
    {
        $this->authorizeSettings($request);

        if (! function_exists('sodium_crypto_sign_keypair')) {
            return back()->withErrors(['signing_secret_key' => 'The PHP Sodium extension is required to generate signing keys.']);
        }

        $keypair = sodium_crypto_sign_keypair();
        $secret = base64_encode(sodium_crypto_sign_secretkey($keypair));
        $public = base64_encode(sodium_crypto_sign_publickey($keypair));

        $existing = PlatformAiSettings::query()->find(1);
        /** @var array<string, mixed> $config */
        $config = $existing instanceof PlatformAiSettings ? (array) $existing->configuration : [];
        $cleared = is_array($config['_cleared'] ?? null) ? $config['_cleared'] : [];
        $config['signing_secret_key'] = $secret;
        $config['signing_public_key'] = $public;
        unset($cleared['signing_secret_key'], $cleared['signing_public_key']);
        $config['_cleared'] = $cleared;

        DB::transaction(function () use ($request, $config): void {
            PlatformAiSettings::query()->updateOrCreate(['id' => 1], [
                'configuration' => $config,
                'updated_by_user_id' => $request->user()?->getKey(),
            ]);
        });

        return redirect()
            ->route('platform.ai-settings.show')
            ->with('status', 'Ed25519 signing key pair generated. Download is now available for customers.');
    }

    private function assertValidSigningPair(string $secretBase64, string $publicBase64): void
    {
        if (! function_exists('sodium_crypto_sign_publickey_from_secretkey')) {
            throw ValidationException::withMessages([
                'signing_secret_key' => 'The PHP Sodium extension is required for addon package signing.',
            ]);
        }

        $secretKey = base64_decode($secretBase64, true);
        $publicKey = base64_decode($publicBase64, true);

        if ($secretKey === false || $secretKey === '' || strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
            || $publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw ValidationException::withMessages([
                'signing_secret_key' => 'Provide a valid base64 Ed25519 secret and public key pair.',
            ]);
        }

        $derived = sodium_crypto_sign_publickey_from_secretkey($secretKey);
        if (! hash_equals($derived, $publicKey)) {
            throw ValidationException::withMessages([
                'signing_public_key' => 'The signing public and private keys do not match.',
            ]);
        }
    }
}
