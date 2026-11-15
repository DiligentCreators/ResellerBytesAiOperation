<?php

namespace App\Http\Controllers;

use App\Models\PlatformMailSettings;
use App\Services\CentralStaffAuthorization;
use App\Services\Mail\CentralOutboundMailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PlatformMailSettingsController extends Controller
{
    private function authorizePlatformAdmin(Request $request): void
    {
        abort_unless(app(CentralStaffAuthorization::class)->can($request->user(), 'settings'), 403);
    }

    private function effectiveConfiguration(?PlatformMailSettings $settings): array
    {
        $fallback = [
            'from_address' => config('platform_mail.from_address'),
            'from_name' => config('platform_mail.from_name'),
            'host' => config('platform_mail.smtp.host'),
            'port' => config('platform_mail.smtp.port'),
            'encryption' => config('platform_mail.smtp.encryption'),
            'username' => config('platform_mail.smtp.username'),
            'password' => config('platform_mail.smtp.password'),
            'server_token' => config('platform_mail.postmark.server_token'),
            'message_stream' => config('platform_mail.postmark.message_stream'),
        ];
        $saved = $settings?->configuration ?? [];
        foreach ($saved as $key => $value) {
            if ($value !== null && $value !== '') {
                $fallback[$key] = $value;
            }
        }
        foreach (($saved['_cleared'] ?? []) as $key => $cleared) {
            if ($cleared === true) {
                $fallback[$key] = '';
            }
        }
        return $fallback;
    }

    public function show(Request $request): Response
    {
        $this->authorizePlatformAdmin($request);
        $settings = PlatformMailSettings::query()->find(1);
        $config = $this->effectiveConfiguration($settings);
        return Inertia::render('platform/mail-settings', [
            'driver' => $settings?->driver ?? config('platform_mail.driver', 'log'),
            'from_address' => $config['from_address'] ?? '',
            'from_name' => $config['from_name'] ?? '',
            'smtp_host' => $config['host'] ?? '',
            'smtp_port' => $config['port'] ?? 587,
            'smtp_encryption' => $config['encryption'] ?? 'tls',
            'smtp_username_set' => ! empty($config['username']),
            'smtp_password_set' => ! empty($config['password']),
            'postmark_token_set' => ! empty($config['server_token']),
            'postmark_message_stream' => $config['message_stream'] ?? 'outbound',
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizePlatformAdmin($request);
        $data = $request->validate([
            'driver' => ['required', 'in:log,smtp,postmark'],
            'from_address' => ['required_if:driver,smtp,postmark', 'nullable', 'email:rfc', 'max:255'],
            'from_name' => ['required_if:driver,smtp,postmark', 'nullable', 'string', 'max:120'],
            'smtp_host' => ['required_if:driver,smtp', 'nullable', 'string', 'max:255'],
            'smtp_port' => ['required_if:driver,smtp', 'nullable', 'integer', 'between:1,65535'],
            'smtp_encryption' => ['required_if:driver,smtp', 'nullable', 'in:tls,ssl'],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:4096'],
            'clear_smtp_password' => ['sometimes', 'boolean'],
            'clear_smtp_username' => ['sometimes', 'boolean'],
            'postmark_server_token' => ['nullable', 'string', 'max:4096'],
            'clear_postmark_token' => ['sometimes', 'boolean'],
            'postmark_message_stream' => ['required_if:driver,postmark', 'nullable', 'string', 'max:255'],
        ]);

        $existing = PlatformMailSettings::query()->find(1);
        $config = $existing?->configuration ?? [];
        $config['from_address'] = $data['from_address'] ?? '';
        $config['from_name'] = $data['from_name'] ?? '';
        $cleared = $config['_cleared'] ?? [];
        if (filter_var($data['clear_smtp_password'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            unset($config['password']);
            $cleared['password'] = true;
        }
        if (filter_var($data['clear_smtp_username'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            unset($config['username']);
            $cleared['username'] = true;
        }
        if (filter_var($data['clear_postmark_token'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            unset($config['server_token']);
            $cleared['server_token'] = true;
        }

        if ($data['driver'] === 'smtp') {
            $config = array_merge($config, [
                'host' => $data['smtp_host'],
                'port' => (int) $data['smtp_port'],
                'encryption' => $data['smtp_encryption'],
                'username' => (($data['smtp_username'] ?? '') !== '') ? $data['smtp_username'] : ($config['username'] ?? (empty($cleared['username']) ? (config('platform_mail.smtp.username') ?? '') : '')),
            ]);
            if (($data['smtp_username'] ?? '') !== '') {
                unset($cleared['username']);
            }
            if (($data['smtp_password'] ?? '') !== '') {
                $config['password'] = $data['smtp_password'];
                unset($cleared['password']);
            }
            $effectivePassword = $config['password'] ?? (empty($cleared['password']) ? config('platform_mail.smtp.password') : '');
            if (($config['username'] ?? '') !== '' && empty($effectivePassword)) {
                throw ValidationException::withMessages(['smtp_password' => 'A password is required when SMTP authentication is enabled.']);
            }
            if (($config['username'] ?? '') === '' && ! empty($effectivePassword)) {
                throw ValidationException::withMessages(['smtp_username' => 'A username is required when an SMTP password is configured.']);
            }
        }

        if ($data['driver'] === 'postmark') {
            $config['message_stream'] = $data['postmark_message_stream'];
            if (($data['postmark_server_token'] ?? '') !== '') {
                $config['server_token'] = $data['postmark_server_token'];
                unset($cleared['server_token']);
            }
            if (empty($config['server_token']) && (empty($cleared['server_token']) && empty(config('platform_mail.postmark.server_token')))) {
                throw ValidationException::withMessages(['postmark_server_token' => 'A Postmark server token is required.']);
            }
        }
        $config['_cleared'] = $cleared;

        DB::transaction(function () use ($data, $config, $request): void {
            PlatformMailSettings::query()->updateOrCreate(['id' => 1], [
                'driver' => $data['driver'], 'configuration' => $config,
                'updated_by_user_id' => $request->user()->getKey(),
            ]);
        });
        return back()->with('status', 'Platform mail settings saved. Secrets are encrypted and will not be shown again.');
    }

    public function test(Request $request, CentralOutboundMailer $mailer): RedirectResponse
    {
        $this->authorizePlatformAdmin($request);
        $data = $request->validate(['recipient' => ['required', 'email:rfc', 'max:255']]);
        try {
            $mailer->send($data['recipient'], 'ResellerBytes mail configuration test',
                '<p>Your central outbound email configuration is working.</p>',
                'Your central outbound email configuration is working.');
        } catch (Throwable) {
            throw ValidationException::withMessages(['recipient' => 'Test delivery failed. Check the selected transport configuration and connectivity.']);
        }
        $driver = PlatformMailSettings::query()->find(1)?->driver ?? config('platform_mail.driver', 'log');
        $status = $driver === 'log'
            ? 'Log driver is active; a redacted preview was recorded and no email was sent.'
            : 'Test email accepted by the configured mail driver.';
        return back()->with('status', $status);
    }
}
