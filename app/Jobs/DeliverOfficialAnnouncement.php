<?php

namespace App\Jobs;

use App\Models\OfficialAnnouncement;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifications\ExpoPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class DeliverOfficialAnnouncement implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public readonly int $announcementId)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->announcementId;
    }

    public function handle(ExpoPushService $pushNotifications): void
    {
        $announcement = OfficialAnnouncement::query()->find($this->announcementId);
        if ($announcement === null) {
            return;
        }

        $announcement->forceFill(['delivery_status' => 'sending'])->saveOrFail();

        $users = User::query()
            ->where('id', '<=', $announcement->recipient_max_user_id ?? 0)
            ->orderBy('id')
            ->select(['id']);

        $users->chunkById(200, function ($recipients) use ($announcement, $pushNotifications): void {
            foreach ($recipients as $recipient) {
                $deduplicationKey = "announcement:{$announcement->id}:user:{$recipient->id}";
                $notification = UserNotification::withTrashed()->firstOrCreate(
                    ['deduplication_key' => $deduplicationKey],
                    [
                        'user_id' => $recipient->id,
                        'category' => 'announcement',
                        'priority' => $announcement->priority,
                        'title' => $announcement->title,
                        'body' => $announcement->body,
                        'event' => 'announcement.published',
                        'data' => ['announcement_id' => $announcement->id],
                    ],
                );

                if ($notification->wasRecentlyCreated && $announcement->send_push) {
                    $pushNotifications->sendForInboxNotification($notification);
                }
            }
        });

        $announcement->forceFill([
            'delivery_status' => 'delivered',
            'delivered_at' => now(),
        ])->saveOrFail();
    }

    public function failed(Throwable $exception): void
    {
        OfficialAnnouncement::query()
            ->whereKey($this->announcementId)
            ->where('delivery_status', '!=', 'delivered')
            ->update(['delivery_status' => 'failed', 'updated_at' => now()]);
    }
}
