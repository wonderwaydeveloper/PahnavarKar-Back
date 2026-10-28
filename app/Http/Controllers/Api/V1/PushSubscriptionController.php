<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PushSubscriptionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        [$user, $device] = $this->currentUserDevice($request);

        return response()->json([
            'enabled' => $device !== null && $user->pushSubscriptions()
                ->where('user_device_id', $device->id)
                ->exists(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'push_token' => ['required', 'string', 'max:512', 'regex:/^(Expo|Exponent)PushToken\\[[^\\]]+\\]$/'],
            'platform' => ['required', 'in:android,ios'],
        ]);
        [, $device] = $this->currentUserDevice($request);

        if ($device === null || $device->platform !== $data['platform']) {
            throw ValidationException::withMessages([
                'device' => 'دستگاه فعال برای ثبت اعلان پیدا نشد.',
            ]);
        }

        DB::transaction(function () use ($data, $device, $user): void {
            $device->pushSubscriptions()->delete();
            PushSubscription::query()->updateOrCreate(
                ['push_token' => $data['push_token']],
                [
                    'user_id' => $user->id,
                    'user_device_id' => $device->id,
                    'platform' => $data['platform'],
                ],
            );
        });

        return response()->json(['enabled' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        [, $device] = $this->currentUserDevice($request);
        $device?->pushSubscriptions()->delete();

        return response()->json(['enabled' => false]);
    }

    /**
     * @return array{User, UserDevice|null}
     */
    private function currentUserDevice(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        $tokenName = $user->currentAccessToken()?->name;

        if (! is_string($tokenName) || ! str_starts_with($tokenName, 'device:')) {
            throw ValidationException::withMessages([
                'device' => 'نشست دستگاه معتبر نیست.',
            ]);
        }

        $device = $user->devices()
            ->where('device_id', substr($tokenName, 7))
            ->where('is_active', true)
            ->first();

        return [$user, $device];
    }
}
