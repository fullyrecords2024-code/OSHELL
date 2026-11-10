<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\OshelNotificationMail;
use App\Models\Branch;
use App\Models\MailMessage;
use App\Models\Organization;
use App\Models\User;
use App\Support\Notifications\Inbox;
use App\Support\Notifications\InboxPayload;
use App\Support\Notifications\NotificationSettings;
use App\Support\Settings\MailSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Throwable;

class MailboxController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'folder' => ['nullable', Rule::in(['inbox', 'sent'])],
            'category' => ['nullable', Rule::in(array_keys(NotificationSettings::GROUPS))],
            'filter' => ['nullable', Rule::in(['all', 'unread'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $user = $request->user();
        $folder = $data['folder'] ?? 'inbox';
        $category = $data['category'] ?? null;
        $perPage = (int) ($data['per_page'] ?? 25);

        if ($folder === 'sent') {
            $page = MailMessage::query()
                ->where('user_id', $user->id)
                ->when($category !== null, fn ($query) => $query->where('category', $category))
                ->latest()
                ->paginate($perPage);
            $items = collect($page->items())
                ->map(fn (MailMessage $message): array => $this->sentPayload($message))
                ->values()
                ->all();
        } else {
            $query = $user->notifications()->latest();
            if ($category !== null) {
                $query->where('data->group', $category);
            }
            if (($data['filter'] ?? 'all') === 'unread') {
                $query->whereNull('read_at');
            }
            $page = $query->paginate($perPage);
            $items = collect($page->items())
                ->map(fn ($notification): array => [...InboxPayload::make($notification), 'folder' => 'inbox'])
                ->values()
                ->all();
        }

        return response()->json([
            'folder' => $folder,
            'data' => $items,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'unread_count' => $user->unreadNotifications()->count(),
            'categories' => collect(NotificationSettings::GROUPS)
                ->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])
                ->values()
                ->all(),
        ]);
    }

    public function reply(Request $request, string $notification): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $user = $request->user();
        $record = $user->notifications()->whereKey($notification)->firstOrFail();
        $payload = $record->data;
        $to = trim((string) ($payload['reply_to'] ?? ''));

        abort_unless(filter_var($to, FILTER_VALIDATE_EMAIL) !== false, 422, 'This message does not include an email address to reply to.');

        $title = (string) ($payload['title'] ?? 'Oshel message');
        $subject = str_starts_with($title, 'Re:') ? $title : 'Re: '.$title;
        $category = (string) ($payload['group'] ?? 'internal_alerts');
        if (! array_key_exists($category, NotificationSettings::GROUPS)) {
            $category = 'internal_alerts';
        }

        $result = $this->sendEmail(
            $user,
            $to,
            $payload['reply_to_name'] ?? null,
            $subject,
            $data['message'],
            $category,
            $record->id,
        );

        if ($record->read_at === null) {
            $record->markAsRead();
        }

        return response()->json([
            'delivered' => $result['delivered'],
            'message' => $this->sentPayload($result['record']),
            'unread_count' => $user->unreadNotifications()->count(),
        ], 201);
    }

    public function compose(Request $request): JsonResponse
    {
        $data = $request->validate([
            'to' => ['nullable', 'email', 'max:255'],
            'subject' => ['required', 'string', 'max:160'],
            'message' => ['required', 'string', 'max:5000'],
            'category' => ['nullable', Rule::in(array_keys(NotificationSettings::GROUPS))],
        ]);

        $user = $request->user();
        $to = trim((string) ($data['to'] ?? ''));
        $recipientName = null;

        if ($user->customer_id !== null) {
            $organization = $user->organization_id ? Organization::query()->find($user->organization_id) : null;
            $to = (string) ($organization?->email ?? '');
            $recipientName = $organization?->name;
            abort_unless(filter_var($to, FILTER_VALIDATE_EMAIL) !== false, 422, 'The clinic has not published an email address for messages.');
        } else {
            abort_unless(filter_var($to, FILTER_VALIDATE_EMAIL) !== false, 422, 'Enter a valid recipient email address.');
        }

        $result = $this->sendEmail(
            $user,
            $to,
            $recipientName,
            $data['subject'],
            $data['message'],
            $data['category'] ?? 'internal_alerts',
            null,
        );

        return response()->json([
            'delivered' => $result['delivered'],
            'message' => $this->sentPayload($result['record']),
        ], 201);
    }

    /**
     * @return array{record: MailMessage, delivered: bool}
     */
    private function sendEmail(User $sender, string $to, ?string $recipientName, string $subject, string $body, string $category, ?string $relatedNotificationId): array
    {
        $organization = $sender->organization_id ? Organization::query()->find($sender->organization_id) : null;
        $branch = $sender->branch_id ? Branch::query()->find($sender->branch_id) : null;
        $status = 'sent';

        try {
            MailSettings::apply();

            Mail::to($to)->send(new OshelNotificationMail(
                subjectLine: $subject,
                heading: $subject,
                preheader: 'A message from '.($organization?->name ?: (string) config('app.name', 'Oshel Skin Care & Beauty Clinic')),
                recipientName: $recipientName,
                intro: [$body],
                details: [
                    'From' => $sender->name,
                    'Sender email' => $sender->email,
                    'Category' => NotificationSettings::GROUPS[$category] ?? null,
                ],
                footnote: 'Replying to this email continues the conversation with the clinic.',
                clinicName: $organization?->name ?: (string) config('app.name', 'Oshel Skin Care & Beauty Clinic'),
                clinicEmail: $organization?->email ?: $branch?->email,
                clinicPhone: $organization?->phone ?: $branch?->phone,
            ));
        } catch (Throwable $exception) {
            $status = 'failed';
            Log::error('Mailbox email could not be sent.', [
                'recipient' => $to,
                'user_id' => $sender->id,
                'subject' => $subject,
                'error' => $exception->getMessage(),
            ]);
        }

        $record = MailMessage::create([
            'organization_id' => $sender->organization_id,
            'branch_id' => $sender->branch_id,
            'user_id' => $sender->id,
            'direction' => 'outbound',
            'recipient_email' => $to,
            'recipient_name' => $recipientName,
            'subject' => $subject,
            'body' => $body,
            'category' => $category,
            'related_notification_id' => $relatedNotificationId,
            'status' => $status,
        ]);

        app(Inbox::class)->deliverToRecipient($to, $sender->organization_id, [
            'group' => $category,
            'subject' => $subject,
            'inbox_title' => $subject,
            'inbox_message' => $body,
            'inbox_severity' => $status === 'sent' ? 'info' : 'warning',
            'reply_to' => $sender->email,
            'reply_to_name' => $sender->name,
        ]);

        return ['record' => $record, 'delivered' => $status === 'sent'];
    }

    /**
     * @return array<string, mixed>
     */
    private function sentPayload(MailMessage $message): array
    {
        return [
            'id' => 'sent-'.$message->id,
            'mail_id' => $message->id,
            'title' => $message->subject,
            'subject' => $message->subject,
            'message' => $message->body,
            'group' => $message->category,
            'group_label' => NotificationSettings::GROUPS[$message->category] ?? 'Mail',
            'severity' => 'info',
            'url' => null,
            'audience' => 'sent',
            'reply_to' => $message->recipient_email,
            'reply_to_name' => $message->recipient_name,
            'recipient_email' => $message->recipient_email,
            'recipient_name' => $message->recipient_name,
            'status' => $message->status,
            'related_notification_id' => $message->related_notification_id,
            'occurred_at' => $message->created_at?->toIso8601String(),
            'read_at' => $message->created_at?->toIso8601String(),
            'created_at' => $message->created_at?->toIso8601String(),
            'folder' => 'sent',
        ];
    }
}
