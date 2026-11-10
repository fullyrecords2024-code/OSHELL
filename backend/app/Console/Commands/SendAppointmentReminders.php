<?php

namespace App\Console\Commands;

use App\Models\AppointmentReminder;
use App\Support\Notifications\NotificationSettings;
use App\Support\Notifications\Notifier;
use Illuminate\Console\Command;

class SendAppointmentReminders extends Command
{
    protected $signature = 'oshel:appointment-reminders {--limit=200}';

    protected $description = 'Send due email reminders for upcoming appointments';

    public function handle(Notifier $notifier): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $sent = 0;
        $failed = 0;
        $skipped = 0;

        if (! NotificationSettings::enabled('appointments')) {
            AppointmentReminder::query()
                ->where('channel', 'email')
                ->where('status', 'pending')
                ->where('scheduled_at', '<=', now('UTC'))
                ->update([
                    'status' => 'cancelled',
                    'failure_reason' => 'Appointment emails are disabled in notification rules.',
                    'updated_at' => now('UTC'),
                ]);

            $this->info('Appointment reminders are disabled in notification rules.');

            return self::SUCCESS;
        }

        AppointmentReminder::query()
            ->where('channel', 'email')
            ->where('status', 'pending')
            ->where('scheduled_at', '<=', now('UTC'))
            ->with(['appointment.branch', 'appointment.service', 'appointment.customer', 'appointment.resources'])
            ->orderBy('scheduled_at')
            ->limit($limit)
            ->get()
            ->each(function (AppointmentReminder $reminder) use ($notifier, &$sent, &$failed, &$skipped): void {
                $appointment = $reminder->appointment;
                if (
                    $appointment === null
                    || in_array($appointment->status, ['cancelled', 'completed', 'no_show', 'rescheduled'], true)
                    || $appointment->starts_at === null
                    || $appointment->starts_at->isPast()
                    || $appointment->customer?->email === null
                ) {
                    $reminder->update([
                        'status' => 'cancelled',
                        'failure_reason' => 'Appointment is no longer eligible for a reminder.',
                    ]);
                    $skipped++;

                    return;
                }

                $hoursBefore = (int) round($appointment->starts_at->diffInHours($reminder->scheduled_at, true));
                $hoursBefore = $hoursBefore >= 12 ? 24 : max(1, $hoursBefore);

                if ($notifier->appointmentReminder($appointment, $hoursBefore)) {
                    $reminder->update(['status' => 'sent', 'sent_at' => now('UTC'), 'failure_reason' => null]);
                    $sent++;
                } else {
                    $reminder->update([
                        'status' => 'failed',
                        'failure_reason' => 'The reminder email could not be delivered.',
                    ]);
                    $failed++;
                }
            });

        $this->info("Appointment reminders: {$sent} sent, {$failed} failed, {$skipped} skipped.");

        return self::SUCCESS;
    }
}
