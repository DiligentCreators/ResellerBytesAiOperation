<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\UpdatePlatformSettingsGroupRequest;
use App\Services\CentralStaffAuthorization;
use App\Services\PlatformSettings;
use App\Support\PlatformSettings\PlatformSettingsRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PlatformSettingsController extends Controller
{
    private function authorizeSettings(Request $request): void
    {
        abort_unless(
            app(CentralStaffAuthorization::class)->can($request->user(), 'settings'),
            403,
        );
    }

    public function show(Request $request, PlatformSettings $settings): Response
    {
        $this->authorizeSettings($request);

        $presentation = $settings->presentation();

        return Inertia::render('platform/settings', [
            'groups' => $presentation['groups'],
            'settings' => $presentation['settings'],
            'activeGroup' => $request->string('group')->toString() ?: 'security',
            'status' => $request->session()->get('status'),
        ]);
    }

    public function update(
        UpdatePlatformSettingsGroupRequest $request,
        string $group,
        PlatformSettings $settings,
        PlatformSettingsRegistry $registry,
    ): RedirectResponse {
        if (! $registry->hasGroup($group)) {
            abort(404);
        }

        /** @var array<string, mixed> $incoming */
        $incoming = $request->validatedSettings();

        foreach ($incoming as $key => $value) {
            $definition = $registry->get($key);

            if ($definition->isSecret()) {
                if (! is_string($value) || trim($value) === '') {
                    continue;
                }

                $settings->set($key, trim($value), $request->user());

                continue;
            }

            $settings->set($key, $value, $request->user());
        }

        return redirect()
            ->route('platform.settings.show', ['group' => $group])
            ->with('status', 'Platform settings saved for '.$registry->groupLabel($group).'.');
    }

    public function reset(
        Request $request,
        string $key,
        PlatformSettings $settings,
        PlatformSettingsRegistry $registry,
    ): RedirectResponse {
        $this->authorizeSettings($request);

        if (! $registry->has($key)) {
            throw ValidationException::withMessages([
                'key' => 'Unknown platform setting key.',
            ]);
        }

        $definition = $registry->get($key);
        $settings->forget($key, $request->user());

        return redirect()
            ->route('platform.settings.show', ['group' => $definition->group])
            ->with('status', 'Reverted '.$definition->label.' to .env / default fallback.');
    }
}
