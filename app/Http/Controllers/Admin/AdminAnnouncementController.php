<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverOfficialAnnouncement;
use App\Models\OfficialAnnouncement;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AdminAnnouncementController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('manage notifications'), 403);

        $announcements = OfficialAnnouncement::query()
            ->with('publisher:id,name')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/Announcements/Index', [
            'admin' => ['name' => $request->user()->name],
            'announcements' => [
                'data' => $announcements->getCollection()->map(fn (OfficialAnnouncement $announcement): array => [
                    'id' => $announcement->id,
                    'title' => $announcement->title,
                    'body' => $announcement->body,
                    'priority' => $announcement->priority,
                    'sendPush' => $announcement->send_push,
                    'recipientCount' => $announcement->recipient_count,
                    'deliveryStatus' => $announcement->delivery_status,
                    'publishedAt' => $announcement->published_at?->toIso8601String(),
                    'deliveredAt' => $announcement->delivered_at?->toIso8601String(),
                    'publisher' => $announcement->publisher?->name,
                ]),
                'links' => $announcements->linkCollection()->toArray(),
                'currentPage' => $announcements->currentPage(),
                'lastPage' => $announcements->lastPage(),
                'total' => $announcements->total(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('manage notifications'), 403);
        $request->merge([
            'title' => trim((string) $request->input('title', '')),
            'body' => trim((string) $request->input('body', '')),
        ]);
        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'body' => ['required', 'string', 'min:10', 'max:5000'],
            'priority' => ['required', Rule::in(['normal', 'important', 'urgent'])],
            'send_push' => ['sometimes', 'boolean'],
        ]);

        $recipientMaxUserId = User::query()->max('id');
        $announcement = OfficialAnnouncement::query()->create([
            'title' => $data['title'],
            'body' => $data['body'],
            'priority' => $data['priority'],
            'send_push' => (bool) ($data['send_push'] ?? false),
            'published_by' => $request->user()->getKey(),
            'recipient_max_user_id' => $recipientMaxUserId,
            'recipient_count' => $recipientMaxUserId === null
                ? 0
                : User::query()->where('id', '<=', $recipientMaxUserId)->count(),
            'delivery_status' => 'queued',
            'published_at' => now(),
        ]);

        activity('notifications')
            ->performedOn($announcement)
            ->causedBy($request->user())
            ->event('official_announcement_published')
            ->withProperties([
                'priority' => $announcement->priority,
                'send_push' => $announcement->send_push,
                'recipient_count' => $announcement->recipient_count,
            ])
            ->log('Official announcement published');

        try {
            DeliverOfficialAnnouncement::dispatch((int) $announcement->getKey());
        } catch (Throwable $exception) {
            $announcement->forceFill(['delivery_status' => 'failed'])->saveOrFail();

            throw $exception;
        }

        return to_route('admin.announcements.index')->with('status', 'اطلاعیه منتشر شد و برای تحویل به صندوق کاربران در صف قرار گرفت.');
    }

    public function retry(Request $request, OfficialAnnouncement $announcement): RedirectResponse
    {
        abort_unless($request->user()->can('manage notifications'), 403);

        try {
            $queued = DB::transaction(function () use ($announcement, $request): bool {
                $lockedAnnouncement = OfficialAnnouncement::query()
                    ->lockForUpdate()
                    ->findOrFail($announcement->getKey());
                if ($lockedAnnouncement->delivery_status !== 'failed') {
                    return false;
                }

                $lockedAnnouncement->forceFill([
                    'delivery_status' => 'queued',
                    'delivered_at' => null,
                ])->saveOrFail();

                activity('notifications')
                    ->performedOn($lockedAnnouncement)
                    ->causedBy($request->user())
                    ->event('official_announcement_delivery_retried')
                    ->log('Official announcement delivery retried');

                DeliverOfficialAnnouncement::dispatch((int) $lockedAnnouncement->getKey());

                return true;
            });
        } catch (Throwable $exception) {
            OfficialAnnouncement::query()
                ->whereKey($announcement->getKey())
                ->where('delivery_status', 'queued')
                ->update(['delivery_status' => 'failed', 'updated_at' => now()]);

            throw $exception;
        }

        return to_route('admin.announcements.index')->with(
            'status',
            $queued
                ? 'تحویل اطلاعیه دوباره در صف قرار گرفت.'
                : 'فقط اطلاعیه‌های ناموفق قابل تلاش دوباره هستند.',
        );
    }
}
