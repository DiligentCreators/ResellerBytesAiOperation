<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use App\Services\Audit\AuditEventWriter;
use App\Services\CentralStaffAuthorization;
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
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $ownerEmail = mb_strtolower(trim((string) config('operations.owner_email')));
        if ($ownerEmail !== '' && hash_equals($ownerEmail, mb_strtolower((string) $user->email))
            && array_key_exists('email', $request->validated())
            && ! hash_equals($ownerEmail, mb_strtolower((string) $request->validated()['email']))) {
            throw ValidationException::withMessages(['email' => 'The configured super-admin identity cannot be changed from the profile page.']);
        }

        $user->fill($request->validated());
        $changedFields = array_keys($user->getDirty());
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
            $changedFields[] = 'email_verified_at';
        }

        DB::transaction(function () use ($user, $changedFields): void {
            $user->save();
            if ($changedFields !== []) {
                app(AuditEventWriter::class)->append([
                    'actor_type' => 'user', 'actor_id' => (string) $user->getKey(),
                    'event_type' => 'operations_user.profile_updated', 'subject_type' => 'user',
                    'subject_id' => (string) $user->getKey(),
                    'metadata' => ['changed_fields' => array_values(array_unique($changedFields))],
                    'occurred_at' => now(),
                ]);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $userId = $request->user()->getKey();
        if (app(CentralStaffAuthorization::class)->isSuperAdmin($request->user())) {
            throw ValidationException::withMessages(['password' => 'The configured super admin account cannot be deleted.']);
        }

        DB::transaction(function () use ($userId): void {
            $userIds = User::query()->orderBy('id')->lockForUpdate()->pluck('id');
            if ($userIds->count() <= 1) {
                throw ValidationException::withMessages([
                    'password' => 'The last operations account cannot be deleted. Create another operator account first.',
                ]);
            }
            app(AuditEventWriter::class)->append([
                'actor_type' => 'user', 'actor_id' => (string) $userId,
                'event_type' => 'operations_user.deleted', 'subject_type' => 'user',
                'subject_id' => (string) $userId, 'metadata' => [], 'occurred_at' => now(),
            ]);
            User::query()->whereKey($userId)->delete();
        });

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
