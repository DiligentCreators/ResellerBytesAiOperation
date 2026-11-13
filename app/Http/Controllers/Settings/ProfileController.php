<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Services\Audit\AuditEventWriter;
use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $userId = $request->user()->getKey();

        DB::transaction(function () use ($userId): void {
            $userIds = User::query()
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');

            if ($userIds->count() <= 1) {
                throw ValidationException::withMessages([
                    'password' => 'The last operations account cannot be deleted. Create another operator account first.',
                ]);
            }

            app(AuditEventWriter::class)->append([
                'actor_type' => 'user',
                'actor_id' => (string) $userId,
                'event_type' => 'operations_user.deleted',
                'subject_type' => 'user',
                'subject_id' => (string) $userId,
                'metadata' => [],
                'occurred_at' => now(),
            ]);

            User::query()->whereKey($userId)->delete();
        });

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
