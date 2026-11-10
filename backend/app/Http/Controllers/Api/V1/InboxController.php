<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Notifications\InboxPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Validation\Rule;

class InboxController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'filter' => ['nullable', Rule::in(['all', 'unread'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $user = $request->user();
        $query = $user->notifications()->latest();

        if (($data['filter'] ?? 'all') === 'unread') {
            $query->whereNull('read_at');
        }

        $page = $query->paginate((int) ($data['per_page'] ?? 25));

        return response()->json([
            'data' => collect($page->items())
                ->map(fn (DatabaseNotification $notification): array => $this->payload($notification))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $record = $request->user()
            ->notifications()
            ->whereKey($notification)
            ->firstOrFail();

        if ($record->read_at === null) {
            $record->markAsRead();
        }

        return response()->json([
            'id' => $record->id,
            'read_at' => $record->read_at?->toIso8601String(),
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()
            ->unreadNotifications()
            ->update(['read_at' => now('UTC')]);

        return response()->json([
            'unread_count' => 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(DatabaseNotification $notification): array
    {
        return InboxPayload::make($notification);
    }
}
