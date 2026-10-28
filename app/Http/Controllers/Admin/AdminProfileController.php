<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

class AdminProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $admin = $request->user();
        abort_unless($admin->canAccessAdminPanel(), 403);

        return Inertia::render('Admin/Profile/Edit', [
            'admin' => [
                'name' => $admin->name,
                'photoUrl' => $admin->profile_photo_path
                    ? Storage::disk('public')->url($admin->profile_photo_path)
                    : null,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $admin = $request->user();
        abort_unless($admin->canAccessAdminPanel(), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_photo' => ['required', 'boolean'],
        ]);

        $name = trim($data['name']);
        $oldPhotoPath = $admin->profile_photo_path;
        $newPhotoPath = null;

        if ($request->hasFile('photo')) {
            $newPhotoPath = $request->file('photo')->storePublicly('profile-photos/'.$admin->id, 'public');

            if (! is_string($newPhotoPath)) {
                throw new RuntimeException('Could not store the admin profile photo.');
            }
        }

        try {
            $admin->forceFill([
                'name' => $name,
                'profile_photo_path' => $newPhotoPath ?? ($data['remove_photo'] ? null : $oldPhotoPath),
            ])->saveOrFail();
        } catch (Throwable $exception) {
            if (is_string($newPhotoPath) && ! Storage::disk('public')->delete($newPhotoPath)) {
                Log::warning('Could not remove an uncommitted admin profile photo.', [
                    'user_id' => $admin->id,
                    'path' => $newPhotoPath,
                ]);
            }

            throw $exception;
        }

        $photoWasReplacedOrRemoved = $newPhotoPath !== null || $data['remove_photo'];
        if ($photoWasReplacedOrRemoved && is_string($oldPhotoPath) && ! Storage::disk('public')->delete($oldPhotoPath)) {
            Log::warning('Could not remove the previous admin profile photo.', [
                'user_id' => $admin->id,
                'path' => $oldPhotoPath,
            ]);
        }

        return to_route('admin.profile.edit')->with('status', 'پروفایل شما با موفقیت به‌روزرسانی شد.');
    }
}
