<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NotificationController extends Controller
{
    private const CATEGORIES = [
        'account_access',
        'security',
        'finance',
        'subscription',
        'service',
        'announcement',
    ];

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $filters = $request->validate([
            'category' => ['sometimes', 'string', 'in:'.implode(',', self::CATEGORIES)],
            'read' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $query = $user->inboxNotifications()->latest('id');
        if (isset($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (array_key_exists('read', $filters)) {
            $filters['read']
                ? $query->whereNotNull('read_at')
                : $query->whereNull('read_at');
        }

        $page = $query->paginate((int) ($filters['per_page'] ?? 20))->through(
            fn (UserNotification $notification): array => $this->serialize($notification),
        );

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'unread_count' => $user->inboxNotifications()->whereNull('read_at')->count(),
            'read_count' => $user->inboxNotifications()->whereNotNull('read_at')->count(),
        ]);
    }

    public function markRead(Request $request, int $notification): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $item = $user->inboxNotifications()->findOrFail($notification);
        $item->read_at ??= now();
        $item->save();

        return response()->json(['notification' => $this->serialize($item)]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $updated = $user->inboxNotifications()
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);

        return response()->json(['updated' => $updated, 'unread_count' => 0]);
    }

    public function destroy(Request $request, int $notification): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $undoToken = (string) Str::uuid();

        $deletedAt = DB::transaction(function () use ($user, $notification, $undoToken) {
            $item = $user->inboxNotifications()->findOrFail($notification);
            $item->forceFill(['deletion_batch_uuid' => $undoToken])->saveOrFail();
            $item->delete();

            return $item->fresh()->deleted_at;
        });

        return response()->json([
            'deleted_count' => 1,
            'undo_token' => $undoToken,
            'undo_expires_at' => $deletedAt?->copy()->addSeconds(30)->toISOString(),
        ]);
    }

    public function destroyRead(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $undoToken = (string) Str::uuid();
        $deletedAt = now()->startOfSecond();

        $deleted = $user->inboxNotifications()
            ->whereNotNull('read_at')
            ->update([
                'deleted_at' => $deletedAt,
                'deletion_batch_uuid' => $undoToken,
                'updated_at' => $deletedAt,
            ]);

        return response()->json([
            'deleted_count' => $deleted,
            'undo_token' => $deleted > 0 ? $undoToken : null,
            'undo_expires_at' => $deleted > 0 ? $deletedAt->copy()->addSeconds(30)->toISOString() : null,
        ]);
    }

    public function restore(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validate([
            'undo_token' => ['required', 'uuid'],
        ]);

        $restored = $user->inboxNotifications()
            ->withTrashed()
            ->where('deletion_batch_uuid', $validated['undo_token'])
            ->whereNotNull('deleted_at')
            ->where('deleted_at', '>=', now()->subSeconds(30))
            ->update([
                'deleted_at' => null,
                'deletion_batch_uuid' => null,
                'updated_at' => now(),
            ]);

        return response()->json(['restored_count' => $restored]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(UserNotification $notification): array
    {
        return [
            'id' => $notification->getKey(),
            'category' => $notification->category,
            'priority' => $notification->priority,
            'title' => $notification->title,
            'body' => $notification->body,
            'event' => $notification->event,
            'data' => $notification->data,
            'read_at' => $notification->read_at?->toISOString(),
            'created_at' => $notification->created_at?->toISOString(),
        ];
    }
}
