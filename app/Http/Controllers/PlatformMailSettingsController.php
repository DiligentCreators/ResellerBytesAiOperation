<?php

namespace App\Http\Controllers;

use App\Models\PlatformMailSettings;
use App\Services\Mail\CentralOutboundMailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PlatformMailSettingsController extends Controller
{
    private function authorizePlatformAdmin(Request $request): void
    {
        $owner = mb_strtolower(trim((string) config('operations.owner_email')));
        if ($owner === '' || ! hash_equals($owner, mb_strtolower((string) $request->user()->email))) {
            abort(403);
        }
    }

    public function show(Request $request): Response
    {
        $this->authorizePlatformAdmin($request);
        $settings = PlatformMailSettings::query()->find(1);
        $config = $settings?->configuration ?? [];

        return Inertia::render('platform/mail-settings', [
            'driver' => $settings?->driver ?? 'log',
            'from_address' => $config['from_address'] ?? '',
            'from_name' => $config['from_name'] ?? '',
            'smtp_host' => $config['host'] ?? '',
            'smtp_port' => $config['port'] ?? 587,
            'smtp_encryption' => $config['encryption'] ?? 'tls',
            'smtp_username' => $config['username'] ?? '',
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
            'smtp_encryption' => ['required_if:driver,smtp', 'nullable', 'in:none,tls,ssl'],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:4096'],
            'clear_smtp_password' => ['sometimes', 'boolean'],
            'postmark_server_token' => ['nullable', 'string', 'max:4096'],
            'clear_postmark_token' => ['sometimes', 'boolean'],
            'postmark_message_stream' => ['required_if:driver,postmark', 'nullable', 'string', 'max:255'],
        ]);

        $existing = PlatformMailSettings::query()->find(1);
        $config = $existing?->configuration ?? [];
        $config['from_address'] = $data['from_address'] ?? '';
        $config['from_name'] = $data['from_name'] ?? '';

        if (($data['clear_smtp_password'] ?? false) === true) {
            unset($config['password']);
        }
        if (($data['clear_postmark_token'] ?? false) === true) {
            unset($config['server_token']);
        }

            $config = array_merge($config, [
                'host' => $data['smtp_host'],
                'port' => (int) $data['smtp_port'],
                'encryption' => $data['smtp_encryption'],
                'username' => $data['smtp_username'] ?? '',
            ]);
            if (($data['smtp_password'] ?? '') !== '') {
                $config['password'] = $data['smtp_password'];
            }
            if (($config['username'] ?? '') !== '' && empty($config['password'])) {
                throw ValidationException::withMessages(['smtp_password' => 'A password is required when SMTP authentication is enabled.']);
            }
        }

        if ($data['driver'] === 'postmark') {
            $config['message_stream'] = $data['postmark_message_stream'];
            if (($data['postmark_server_token'] ?? '') !== '') {
                $config['server_token'] = $data['postmark_server_token'];
            }
            if (empty($config['server_token'])) {
                throw ValidationException::withMessages(['postmark_server_token' => 'A Postmark server token is required.']);
            }
        }

        DB::transaction(function () use ($data, $config, $request): void {
            PlatformMailSettings::query()->updateOrCreate(['id' => 1], [
                'driver' => $data['driver'],
                'configuration' => $config,
                'updated_by_user_id' => $request->user()->getKey(),
            ]);
        });

        return back()->with('status', 'Platform mail settings saved. Secrets are encrypted and will not be shown again.');
    }

    public function test(Request $request, CentralOutboundMailer $mailer): RedirectResponse
    {
        $this->authorizePlatformAdmin($request);
        $data = $request->validate(['recipient' => ['required', 'email:rfc', 'max:255']]);
        $mailer->send($data['recipient'], 'ResellerBytes mail configuration test', '<p>Your central outbound email configuration is working.</p>', 'Your central outbound email configuration is working.');

        return back()->with('status', 'Test email accepted by the configured mail driver.');
    }
}
