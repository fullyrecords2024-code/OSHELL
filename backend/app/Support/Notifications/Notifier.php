<?php

namespace App\Support\Notifications;

use App\Mail\OshelNotificationMail;
use App\Models\Appointment;
use App\Models\AppointmentWaitlistEntry;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use App\Support\Settings\MailSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class Notifier
{
    /** @var array<int, Organization|null> */
    private array $organizations = [];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(string $group, ?string $to, ?string $recipientName, array $payload): bool
    {
        $payload['group'] = $group;
        $payload['reply_to'] = $payload['reply_to'] ?? $to;
        $payload['reply_to_name'] = $payload['reply_to_name'] ?? $recipientName;

        $inboxUser = null;
        if (($payload['inbox'] ?? true) !== false) {
            $inboxUser = app(Inbox::class)->deliverToRecipient(
                $to,
                $payload['organization_id'] ?? null,
                $payload,
            );
        }

        if (isset($payload['inbox_permission'])) {
            app(Inbox::class)->deliverToPermission(
                (string) $payload['inbox_permission'],
                $payload['organization_id'] ?? null,
                $payload['branch_id'] ?? null,
                $payload,
                $inboxUser === null ? [] : [(int) $inboxUser->id],
            );
        }

        if (! NotificationSettings::enabled($group) || ! $this->validEmail($to)) {
            return false;
        }

        $organization = $this->organization($payload['organization_id'] ?? null);
        $branch = $this->branch($payload['branch_id'] ?? null);

        try {
            MailSettings::apply();

            Mail::to($to)->send(new OshelNotificationMail(
                subjectLine: (string) ($payload['subject'] ?? 'Oshel notification'),
                heading: (string) ($payload['heading'] ?? $payload['subject'] ?? 'Oshel notification'),
                preheader: $payload['preheader'] ?? null,
                recipientName: $recipientName,
                intro: $payload['intro'] ?? [],
                details: array_filter(
                    $payload['details'] ?? [],
                    static fn ($value): bool => $value !== null && $value !== '',
                ),
                items: $payload['items'] ?? [],
                totals: $payload['totals'] ?? [],
                action: $payload['action'] ?? null,
                footnote: $payload['footnote'] ?? null,
                clinicName: $organization?->name ?: (string) config('app.name', 'Oshel Skin Care & Beauty Clinic'),
                clinicEmail: $organization?->email ?: $branch?->email,
                clinicPhone: $organization?->phone ?: $branch?->phone,
            ));

            return true;
        } catch (Throwable $exception) {
            Log::error('Transactional email could not be sent.', [
                'group' => $group,
                'recipient' => $to,
                'subject' => $payload['subject'] ?? null,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    public function customerRegistered(Customer $customer): bool
    {
        $customer->loadMissing('registeredBranch');

        $portalUser = User::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower((string) $customer->email)])
            ->first();
        $verified = $portalUser?->email_verified_at !== null;

        return $this->send('customer_account', $customer->email, $customer->name, [
            'organization_id' => $customer->organization_id,
            'branch_id' => $customer->registered_branch_id,
            'subject' => 'Welcome to '.(string) config('app.name', 'Oshel'),
            'heading' => 'Your account is ready',
            'preheader' => $verified ? 'Your Oshel account is ready to use.' : 'Your Oshel account has been created.',
            'intro' => [
                'Your Oshel account has been created for '.$customer->email.'.',
                $verified
                    ? 'You can book appointments, place online orders, and keep track of your visits at any time.'
                    : 'Sign in with your email address to book appointments, place online orders, and keep track of your visits.',
            ],
            'details' => [
                'Name' => $customer->name,
                'Email' => $customer->email,
                'Preferred branch' => $customer->registeredBranch?->name,
            ],
            'action' => $this->action('Visit Oshel'),
            'footnote' => 'If you did not create this account, please contact us so we can remove it.',
        ]);
    }

    public function passwordChanged(User $user): void
    {
        $this->send('customer_account', $user->email, $user->name, [
            'organization_id' => $user->organization_id,
            'branch_id' => $user->branch_id,
            'subject' => 'Your Oshel password was changed',
            'heading' => 'Password changed',
            'preheader' => 'Your account password was updated.',
            'intro' => [
                'The password for your Oshel account was changed successfully.',
            ],
            'details' => [
                'Account' => $user->email,
                'Changed at' => now('UTC')->format('D, j M Y H:i').' UTC',
            ],
            'action' => $this->action('Sign in'),
            'footnote' => 'If you did not make this change, contact us immediately and reset your password.',
        ]);
    }

    public function appointmentBooked(Appointment $appointment): void
    {
        $appointment->loadMissing(['customer', 'branch', 'service', 'resources']);
        $customer = $appointment->customer;
        if ($customer === null) {
            return;
        }

        $branch = $appointment->branch;
        $depositRequired = $appointment->status === 'awaiting_deposit' && (float) $appointment->deposit_due > 0;
        $intro = $depositRequired
            ? [
                'Your appointment has been reserved. To confirm the booking, the deposit below must be paid within 15 minutes. Contact the branch if you need help completing the deposit.',
                'The slot is not confirmed until the deposit is received.',
            ]
            : ['Your appointment is confirmed. We look forward to seeing you.'];

        $this->send('appointments', $customer->email, $customer->name, [
            'organization_id' => $appointment->organization_id,
            'branch_id' => $appointment->branch_id,
            'inbox_permission' => 'appointments.view',
            'subject' => ($depositRequired ? 'Appointment held' : 'Appointment confirmed').' — '.$appointment->appointment_number,
            'heading' => $depositRequired ? 'Your appointment is being held' : 'Your appointment is confirmed',
            'preheader' => $this->appointmentWhen($appointment),
            'intro' => $intro,
            'details' => [
                'Reference' => $appointment->appointment_number,
                'Service' => $appointment->service?->name,
                'Date & time' => $this->appointmentWhen($appointment),
                'Branch' => $branch ? $branch->name.($branch->city ? ', '.$branch->city : '') : null,
                'Staff member' => $this->staffName($appointment),
                'Price' => $this->money($appointment->service_price, $appointment->currency_code),
                'Deposit due' => $depositRequired ? $this->money($appointment->deposit_due, $appointment->currency_code) : null,
                'Status' => $depositRequired ? 'Awaiting deposit' : 'Confirmed',
            ],
            'action' => $this->action('Visit Oshel'),
            'footnote' => $depositRequired
                ? 'If the deposit is not received in time, the slot may be released. Please contact the branch to arrange payment.'
                : 'Please arrive a few minutes early. Contact the branch if you need to change or cancel.',
        ]);
    }

    public function appointmentRescheduled(Appointment $previous, Appointment $next): void
    {
        $next->loadMissing(['customer', 'branch', 'service', 'resources']);
        $customer = $next->customer;
        if ($customer === null) {
            return;
        }

        $this->send('appointments', $customer->email, $customer->name, [
            'organization_id' => $next->organization_id,
            'branch_id' => $next->branch_id,
            'inbox_permission' => 'appointments.view',
            'subject' => 'Appointment rescheduled — '.$next->appointment_number,
            'heading' => 'Your appointment has been rescheduled',
            'preheader' => 'New time: '.$this->appointmentWhen($next),
            'intro' => ['Your appointment has been moved to the date and time below. The previous slot has been released.'],
            'details' => [
                'Reference' => $next->appointment_number,
                'Service' => $next->service?->name,
                'Previous time' => $this->appointmentWhen($previous),
                'New time' => $this->appointmentWhen($next),
                'Branch' => $next->branch?->name,
                'Staff member' => $this->staffName($next),
                'Status' => $next->status === 'awaiting_deposit' ? 'Awaiting deposit' : 'Confirmed',
            ],
            'action' => $this->action('Visit Oshel'),
            'footnote' => 'If the new time does not work for you, contact the branch as soon as possible.',
        ]);
    }

    public function appointmentCancelled(Appointment $appointment, ?string $reason = null): void
    {
        $appointment->loadMissing(['customer', 'branch', 'service']);
        $customer = $appointment->customer;
        if ($customer === null) {
            return;
        }

        $this->send('appointments', $customer->email, $customer->name, [
            'organization_id' => $appointment->organization_id,
            'branch_id' => $appointment->branch_id,
            'inbox_permission' => 'appointments.view',
            'subject' => 'Appointment cancelled — '.$appointment->appointment_number,
            'heading' => 'Your appointment has been cancelled',
            'preheader' => $this->appointmentWhen($appointment),
            'intro' => ['The appointment below has been cancelled. No further action is needed unless you would like to rebook.'],
            'details' => [
                'Reference' => $appointment->appointment_number,
                'Service' => $appointment->service?->name,
                'Original time' => $this->appointmentWhen($appointment),
                'Branch' => $appointment->branch?->name,
                'Reason' => $reason,
            ],
            'action' => $this->action('Book another appointment'),
            'footnote' => 'Any deposit already paid will be handled by the branch in line with the cancellation policy.',
        ]);
    }

    public function appointmentDepositReceived(Appointment $appointment): void
    {
        $appointment->loadMissing(['customer', 'branch', 'service']);
        $customer = $appointment->customer;
        if ($customer === null) {
            return;
        }

        $this->send('payments', $customer->email, $customer->name, [
            'organization_id' => $appointment->organization_id,
            'branch_id' => $appointment->branch_id,
            'inbox_permission' => 'payments.reconcile',
            'subject' => 'Deposit received — '.$appointment->appointment_number,
            'heading' => 'Your appointment is confirmed',
            'preheader' => 'We have received your deposit.',
            'intro' => ['Thank you. We have recorded your deposit and your appointment is now confirmed.'],
            'details' => [
                'Reference' => $appointment->appointment_number,
                'Service' => $appointment->service?->name,
                'Date & time' => $this->appointmentWhen($appointment),
                'Branch' => $appointment->branch?->name,
                'Deposit' => $this->money($appointment->deposit_due, $appointment->currency_code),
            ],
            'action' => $this->action('Visit Oshel'),
            'footnote' => 'Please arrive a few minutes early. Contact the branch if you need to change or cancel.',
        ]);
    }

    public function appointmentReminder(Appointment $appointment, int $hoursBefore): void
    {
        $appointment->loadMissing(['customer', 'branch', 'service', 'resources']);
        $customer = $appointment->customer;
        if ($customer === null) {
            return;
        }

        $when = $hoursBefore >= 24
            ? 'tomorrow'
            : ($hoursBefore >= 2 ? 'in about '.$hoursBefore.' hours' : 'soon');

        $this->send('appointments', $customer->email, $customer->name, [
            'organization_id' => $appointment->organization_id,
            'branch_id' => $appointment->branch_id,
            'subject' => 'Reminder: your appointment is '.$when,
            'heading' => 'Your appointment is coming up',
            'preheader' => $this->appointmentWhen($appointment),
            'intro' => ['This is a friendly reminder about your upcoming appointment.'],
            'details' => [
                'Reference' => $appointment->appointment_number,
                'Service' => $appointment->service?->name,
                'Date & time' => $this->appointmentWhen($appointment),
                'Branch' => $appointment->branch?->name,
                'Staff member' => $this->staffName($appointment),
            ],
            'action' => $this->action('Visit Oshel'),
            'footnote' => 'If you can no longer make it, please contact the branch so the slot can be offered to someone else.',
        ]);
    }

    public function waitlistReceived(AppointmentWaitlistEntry $entry): void
    {
        $entry->loadMissing(['customer', 'branch', 'service']);
        $customer = $entry->customer;
        if ($customer === null) {
            return;
        }

        $this->send('appointments', $customer->email, $customer->name, [
            'organization_id' => $entry->organization_id,
            'branch_id' => $entry->branch_id,
            'inbox_permission' => 'waitlist.view',
            'subject' => 'You are on the waitlist',
            'heading' => 'We have added you to the waitlist',
            'preheader' => 'We will contact you if a suitable slot opens.',
            'intro' => ['Thank you for your interest. We have added you to the waitlist and will contact you if a suitable appointment becomes available.'],
            'details' => [
                'Service' => $entry->service?->name,
                'Branch' => $entry->branch?->name,
                'Preferred date' => $entry->preferred_date?->format('D, j M Y'),
                'Notes' => $entry->notes,
            ],
            'footnote' => 'You can contact the branch at any time to check availability.',
        ]);
    }

    public function waitlistOffered(AppointmentWaitlistEntry $entry): void
    {
        $entry->loadMissing(['customer', 'branch', 'service']);
        $customer = $entry->customer;
        if ($customer === null) {
            return;
        }

        $this->send('appointments', $customer->email, $customer->name, [
            'organization_id' => $entry->organization_id,
            'branch_id' => $entry->branch_id,
            'inbox_permission' => 'waitlist.manage',
            'subject' => 'A slot is available for you',
            'heading' => 'A waitlist slot has opened',
            'preheader' => 'Contact the branch to claim this appointment.',
            'intro' => ['Good news — a slot has opened for the service you were waiting for. Please contact the branch as soon as possible to confirm it.'],
            'details' => [
                'Service' => $entry->service?->name,
                'Branch' => $entry->branch?->name,
                'Preferred date' => $entry->preferred_date?->format('D, j M Y'),
            ],
            'action' => $this->action('Visit Oshel'),
            'footnote' => 'Slots are offered on a first-come basis.',
        ]);
    }

    public function orderPlaced(object $order): void
    {
        $customer = $this->customer($order->customer_id ?? null);
        if ($customer === null) {
            return;
        }

        $lines = DB::table('ecommerce_order_lines')
            ->where('order_id', $order->id)
            ->get(['name_snapshot', 'quantity', 'line_total']);

        $this->send('orders', $customer->email, $customer->name, [
            'organization_id' => $order->organization_id,
            'branch_id' => $order->branch_id,
            'inbox_permission' => 'ecommerce.orders.view',
            'subject' => 'We received your order '.$order->order_number,
            'heading' => 'Your order has been received',
            'preheader' => 'Complete payment to start fulfilment.',
            'intro' => [
                'Thank you for your order. We have reserved your items while you complete payment.',
                'Your order will be processed as soon as the payment is verified. Stock is held until '.$this->dateTime($order->reservation_expires_at, 'UTC').'.',
            ],
            'details' => [
                'Order' => $order->order_number,
                'Placed' => $this->dateTime($order->created_at, 'UTC'),
                'Fulfilment' => $this->fulfilmentLabel($order->fulfillment_type),
                'Branch' => $this->branchName($order->branch_id),
                'Payment status' => 'Awaiting payment',
            ],
            'items' => $this->orderItems($lines),
            'totals' => $this->orderTotals($order),
            'action' => $this->action('Visit Oshel'),
            'footnote' => 'If you do not complete payment before the reservation expires, the items will be released and the order cancelled.',
        ]);
    }

    public function orderPaid(object $order, object $transaction): void
    {
        $customer = $this->customer($order->customer_id ?? null);
        if ($customer === null) {
            return;
        }

        $lines = DB::table('ecommerce_order_lines')
            ->where('order_id', $order->id)
            ->get(['name_snapshot', 'quantity', 'line_total']);

        $this->send('payments', $customer->email, $customer->name, [
            'organization_id' => $order->organization_id,
            'branch_id' => $order->branch_id,
            'inbox_permission' => 'payments.reconcile',
            'subject' => 'Payment confirmed for '.$order->order_number,
            'heading' => 'Payment received — thank you',
            'preheader' => 'We are preparing your order.',
            'intro' => ['We have verified your payment and your order is now confirmed. We will let you know when its status changes.'],
            'details' => [
                'Order' => $order->order_number,
                'Amount paid' => $this->money($transaction->amount, $transaction->currency_code),
                'Payment method' => strtoupper((string) $transaction->provider),
                'Provider reference' => $transaction->provider_reference ?? $transaction->merchant_reference,
                'Paid at' => $this->dateTime($transaction->paid_at ?? now('UTC'), 'UTC'),
                'Fulfilment' => $this->fulfilmentLabel($order->fulfillment_type),
                'Branch' => $this->branchName($order->branch_id),
            ],
            'items' => $this->orderItems($lines),
            'totals' => $this->orderTotals($order),
            'action' => $this->action('Visit Oshel'),
            'footnote' => 'Keep this email as your payment receipt. Contact us if you need a formal invoice.',
        ]);
    }

    public function orderStatusChanged(object $order, string $status): void
    {
        $customer = $this->customer($order->customer_id ?? null);
        if ($customer === null) {
            return;
        }

        $label = $this->orderStatusLabel($status);
        $intro = match ($status) {
            'processing' => ['We are preparing your order. We will let you know when it is ready.'],
            'ready_for_collection' => ['Your order is ready for collection at '.$this->branchName($order->branch_id).'. Please bring your order number when you visit.'],
            'dispatched' => ['Your order has been dispatched. The delivery team will contact you using the details on your order.'],
            'completed' => ['Your order is complete. Thank you for shopping with us.'],
            'cancelled' => ['Your order has been cancelled. If a payment was taken, the branch will contact you about the refund.'],
            default => ['The status of your order has changed.'],
        };

        $this->send('orders', $customer->email, $customer->name, [
            'organization_id' => $order->organization_id,
            'branch_id' => $order->branch_id,
            'subject' => 'Order '.$order->order_number.' — '.$label,
            'heading' => $label,
            'preheader' => $intro[0] ?? null,
            'intro' => $intro,
            'details' => [
                'Order' => $order->order_number,
                'Status' => $label,
                'Fulfilment' => $this->fulfilmentLabel($order->fulfillment_type),
                'Branch' => $this->branchName($order->branch_id),
                'Total' => $this->money($order->total_amount, $order->currency_code),
            ],
            'action' => $status === 'completed' ? $this->action('Visit Oshel') : null,
        ]);
    }

    public function returnRequested(object $order): void
    {
        $customer = $this->customer($order->customer_id ?? null);
        if ($customer === null) {
            return;
        }

        $this->send('orders', $customer->email, $customer->name, [
            'organization_id' => $order->organization_id,
            'branch_id' => $order->branch_id,
            'inbox_permission' => 'ecommerce.returns.manage',
            'subject' => 'Return request received for '.$order->order_number,
            'heading' => 'We received your return request',
            'preheader' => 'Our team will review it and contact you.',
            'intro' => ['We have received your return request. Our team will review it and let you know the outcome.'],
            'details' => [
                'Order' => $order->order_number,
                'Reason' => $order->return_reason,
                'Status' => 'Awaiting review',
            ],
        ]);
    }

    public function returnDecision(object $order, string $decision): void
    {
        $customer = $this->customer($order->customer_id ?? null);
        if ($customer === null) {
            return;
        }

        $approved = $decision === 'approve';

        $this->send('orders', $customer->email, $customer->name, [
            'organization_id' => $order->organization_id,
            'branch_id' => $order->branch_id,
            'subject' => ($approved ? 'Return approved' : 'Return request declined').' — '.$order->order_number,
            'heading' => $approved ? 'Your return has been approved' : 'Your return request was declined',
            'preheader' => $order->order_number,
            'intro' => $approved
                ? ['Your return has been approved. The refund will be processed through the original payment method and may take a few business days to appear.']
                : ['After reviewing your request, we are unable to approve this return. Contact the branch if you would like to discuss the decision.'],
            'details' => [
                'Order' => $order->order_number,
                'Decision' => $approved ? 'Approved' : 'Declined',
                'Reason provided' => $order->return_reason,
            ],
        ]);
    }

    public function paymentIssue(object $order, object $transaction): void
    {
        $customer = $this->customer($order->customer_id ?? null);
        $status = (string) $transaction->status;
        $label = $status === 'paid_review' ? 'Payment needs review' : ($status === 'reversed' ? 'Payment reversed' : 'Payment not completed');

        if ($customer !== null) {
            $this->send('payments', $customer->email, $customer->name, [
                'organization_id' => $order->organization_id,
                'branch_id' => $order->branch_id,
                'subject' => $label.' — '.$order->order_number,
                'heading' => $label,
                'preheader' => 'Please contact the clinic before trying again.',
                'intro' => [$status === 'paid_review'
                    ? 'We received a payment after the order reservation changed. Please contact the clinic so we can confirm it before you place another order.'
                    : 'The payment for your order was not completed or was reversed by the provider. You can try another payment method or contact us for help.'],
                'details' => [
                    'Order' => $order->order_number,
                    'Amount' => $this->money($transaction->amount, $transaction->currency_code),
                    'Payment method' => strtoupper((string) $transaction->provider),
                    'Reference' => $transaction->provider_reference ?? $transaction->merchant_reference,
                ],
                'footnote' => 'Do not send card or M-Pesa credentials by email.',
            ]);
        }

        $this->internalAlert(
            'Payment needs attention — '.$order->order_number,
            $label,
            ['An online payment could not be matched automatically and needs staff review.'],
            [
                'Order' => $order->order_number,
                'Amount' => $this->money($transaction->amount, $transaction->currency_code),
                'Provider' => strtoupper((string) $transaction->provider),
                'Status' => $status,
                'Reference' => $transaction->provider_reference ?? $transaction->merchant_reference,
            ],
            $order->organization_id ?? null,
            $order->branch_id ?? null,
            'payments.reconcile',
        );
    }

    public function posReceipt(object $sale): void
    {
        $customer = $this->customer($sale->customer_id ?? null);
        if ($customer === null) {
            return;
        }

        $lines = DB::table('pos_sale_lines')->where('sale_id', $sale->id)->get(['item_name', 'quantity', 'line_total']);
        $payments = DB::table('pos_sale_payments')->where('sale_id', $sale->id)->get(['tender_type', 'amount', 'reference']);

        $details = [
            'Receipt' => $sale->receipt_number,
            'Date' => $this->dateTime($sale->sold_at, 'UTC'),
            'Branch' => $this->branchName($sale->branch_id),
            'Cashier' => DB::table('users')->where('id', $sale->cashier_id)->value('name'),
        ];
        foreach ($payments as $payment) {
            $details['Paid by '.strtoupper((string) $payment->tender_type)] = $this->money($payment->amount, $sale->currency_code).($payment->reference ? ' ('.$payment->reference.')' : '');
        }

        $this->send('pos_receipts', $customer->email, $customer->name, [
            'organization_id' => $sale->organization_id,
            'branch_id' => $sale->branch_id,
            'subject' => 'Your receipt '.$sale->receipt_number,
            'heading' => 'Thank you for your purchase',
            'preheader' => 'Your e-receipt from '.$this->branchName($sale->branch_id),
            'intro' => ['Thank you for visiting us. Here is a copy of your receipt.'],
            'details' => $details,
            'items' => $lines->map(static fn ($line): array => [
                'name' => (string) $line->item_name,
                'quantity' => rtrim(rtrim(number_format((float) $line->quantity, 3, '.', ''), '0'), '.'),
                'amount' => number_format((float) $line->line_total, 2).' '.$sale->currency_code,
            ])->all(),
            'totals' => [
                ['label' => 'Subtotal', 'value' => $this->money($sale->subtotal, $sale->currency_code)],
                ['label' => 'Discount', 'value' => '-'.$this->money($sale->discount_amount, $sale->currency_code)],
                ['label' => 'Total', 'value' => $this->money($sale->total_amount, $sale->currency_code), 'emphasis' => true],
            ],
            'footnote' => 'Please keep this receipt for returns or exchanges.',
        ]);
    }

    public function posRefund(object $sale, string $disposition, string $amount): void
    {
        $customer = $this->customer($sale->customer_id ?? null);
        if ($customer === null) {
            return;
        }

        $voided = $disposition === 'voided';

        $this->send('pos_receipts', $customer->email, $customer->name, [
            'organization_id' => $sale->organization_id,
            'branch_id' => $sale->branch_id,
            'inbox_permission' => 'pos.refund.approve',
            'subject' => ($voided ? 'Sale voided' : 'Refund processed').' — '.$sale->receipt_number,
            'heading' => $voided ? 'Your sale has been voided' : 'Your refund has been processed',
            'preheader' => $sale->receipt_number,
            'intro' => [$voided
                ? 'The sale below has been voided. Any tender collected will be returned using the original payment method.'
                : 'We have processed a refund for the sale below. Please allow a few business days for it to appear.'],
            'details' => [
                'Receipt' => $sale->receipt_number,
                'Original total' => $this->money($sale->total_amount, $sale->currency_code),
                'Amount' => $this->money($amount, $sale->currency_code),
                'Branch' => $this->branchName($sale->branch_id),
            ],
        ]);
    }

    public function leaveDecision(object $leave, object $employeeUser, bool $approved): void
    {
        $this->send('hr', $employeeUser->email ?? null, $employeeUser->name ?? null, [
            'organization_id' => $leave->organization_id,
            'branch_id' => $leave->branch_id,
            'subject' => 'Leave request '.($approved ? 'approved' : 'declined'),
            'heading' => 'Your leave request was '.($approved ? 'approved' : 'declined'),
            'preheader' => $this->dateRange($leave->starts_on, $leave->ends_on),
            'intro' => [$approved
                ? 'Your leave request has been approved. Please make sure your shifts are covered before you leave.'
                : 'Your leave request was reviewed and could not be approved. Speak to your manager if you need more information.'],
            'details' => [
                'Leave type' => ucfirst((string) $leave->leave_type),
                'Dates' => $this->dateRange($leave->starts_on, $leave->ends_on),
                'Status' => ucfirst((string) $leave->status),
                'Reason' => $leave->reason,
            ],
        ]);
    }

    public function payslip(object $run, object $line, object $employeeUser, object $employeeProfile): void
    {
        $components = json_decode((string) $line->components, true);

        $this->send('hr', $employeeUser->email ?? null, $employeeUser->name ?? null, [
            'organization_id' => $run->organization_id,
            'branch_id' => $run->branch_id,
            'inbox_url' => '#staff/payroll',
            'subject' => 'Payslip for '.$this->dateRange($run->period_starts_on, $run->period_ends_on),
            'heading' => 'Your payslip is available',
            'preheader' => 'Net pay '.$this->money($line->net_amount, $line->currency_code),
            'intro' => ['Payroll for the period below has been marked as paid. This is a summary of your payslip.'],
            'details' => [
                'Employee number' => $employeeProfile->employee_number ?? null,
                'Job title' => $employeeProfile->job_title ?? null,
                'Pay period' => $this->dateRange($run->period_starts_on, $run->period_ends_on),
                'Paid on' => $run->paid_at ? $this->dateTime($run->paid_at, 'UTC') : null,
            ],
            'items' => [
                ['name' => 'Base salary', 'quantity' => '', 'amount' => $this->money($line->base_salary, $line->currency_code)],
                ['name' => 'Commission', 'quantity' => '', 'amount' => $this->money($line->commission_total, $line->currency_code)],
                ['name' => 'Additions', 'quantity' => '', 'amount' => $this->money($line->addition_total, $line->currency_code)],
                ['name' => 'Deductions', 'quantity' => '', 'amount' => '-'.$this->money($line->deduction_total, $line->currency_code)],
            ],
            'totals' => [
                ['label' => 'Net pay', 'value' => $this->money($line->net_amount, $line->currency_code), 'emphasis' => true],
            ],
            'footnote' => is_array($components) && $components !== [] ? 'Component detail is available from the payroll office.' : null,
        ]);
    }

    public function requisitionDecision(object $requisition, ?object $requester): void
    {
        if ($requester === null) {
            return;
        }

        $approved = $requisition->status === 'approved';

        $this->send('procurement', $requester->email, $requester->name, [
            'organization_id' => $requisition->organization_id,
            'branch_id' => $requisition->branch_id,
            'subject' => 'Requisition '.$requisition->reference.' '.($approved ? 'approved' : 'rejected'),
            'heading' => 'Your requisition was '.($approved ? 'approved' : 'rejected'),
            'preheader' => $requisition->reference,
            'intro' => [$approved
                ? 'Your purchase requisition has been approved and can now be converted into a purchase order.'
                : 'Your purchase requisition was reviewed and rejected. Contact the approver if you need more information.'],
            'details' => [
                'Requisition' => $requisition->reference,
                'Status' => ucfirst((string) $requisition->status),
                'Branch' => $this->branchName($requisition->branch_id),
                'Reviewed at' => $requisition->reviewed_at ? $this->dateTime($requisition->reviewed_at, 'UTC') : null,
                'Notes' => $requisition->notes,
            ],
        ]);
    }

    public function purchaseOrderApproved(object $order, ?object $creator, ?object $supplier): void
    {
        $items = DB::table('purchase_order_lines')
            ->join('inventory_products', 'inventory_products.id', '=', 'purchase_order_lines.product_id')
            ->where('purchase_order_id', $order->id)
            ->get(['inventory_products.name', 'inventory_products.sku', 'purchase_order_lines.quantity_ordered', 'purchase_order_lines.unit_cost']);

        $payload = [
            'organization_id' => $order->organization_id,
            'branch_id' => $order->branch_id,
            'subject' => 'Purchase order '.$order->reference,
            'heading' => 'Purchase order '.$order->reference,
            'preheader' => 'Please confirm supply and delivery.',
            'intro' => ['Please find the approved purchase order details below. Kindly confirm availability and expected delivery.'],
            'details' => [
                'Reference' => $order->reference,
                'Order date' => $this->dateTime($order->approved_at ?? $order->created_at, 'UTC'),
                'Currency' => $order->currency_code,
                'Delivery branch' => $this->branchName($order->branch_id),
                'Notes' => $order->notes,
            ],
            'items' => $items->map(static fn ($item): array => [
                'name' => (string) $item->name.($item->sku ? ' ('.$item->sku.')' : ''),
                'quantity' => rtrim(rtrim(number_format((float) $item->quantity_ordered, 3, '.', ''), '0'), '.'),
                'amount' => number_format((float) $item->unit_cost, 2).' '.$order->currency_code,
            ])->all(),
            'footnote' => 'Unit prices are shown in '.$order->currency_code.'.',
        ];

        if ($supplier !== null && $this->validEmail($supplier->email ?? null)) {
            $this->send('procurement', $supplier->email, $supplier->contact_name ?: $supplier->name, $payload);
        }

        if ($creator !== null) {
            $this->send('procurement', $creator->email, $creator->name, [
                ...$payload,
                'subject' => 'Purchase order '.$order->reference.' approved',
                'heading' => 'Purchase order approved',
                'preheader' => 'The order was sent to '.($supplier->name ?? 'the supplier'),
                'intro' => ['Purchase order '.$order->reference.' has been approved and sent to the supplier.'],
            ]);
        }
    }

    public function goodsReceived(object $receipt, object $order, ?object $creator): void
    {
        if ($creator === null) {
            return;
        }

        $this->send('procurement', $creator->email, $creator->name, [
            'organization_id' => $order->organization_id,
            'branch_id' => $order->branch_id,
            'subject' => 'Goods received for '.$order->reference,
            'heading' => 'Goods received',
            'preheader' => $receipt->reference,
            'intro' => ['Goods have been received against your purchase order and stock has been updated.'],
            'details' => [
                'Purchase order' => $order->reference,
                'Goods received note' => $receipt->reference,
                'Received at' => $this->dateTime($receipt->received_at, 'UTC'),
                'Branch' => $this->branchName($order->branch_id),
                'Notes' => $receipt->notes,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function enquiryReceived(array $data, ?object $organization): void
    {
        $this->send('customer_account', $data['email'] ?? null, $data['name'] ?? null, [
            'organization_id' => $organization->id ?? null,
            'branch_id' => $data['branch_id'] ?? null,
            'subject' => 'We received your enquiry',
            'heading' => 'Thank you for contacting us',
            'preheader' => 'Our team will respond as soon as possible.',
            'intro' => [
                'Thank you for reaching out. A member of our team will respond to your enquiry using the contact details you provided.',
                'For reference, your message was: '.$data['subject'],
            ],
            'details' => [
                'Subject' => $data['subject'],
                'Message' => $data['message'] ?? null,
            ],
            'footnote' => 'If your enquiry is urgent, please call the clinic directly.',
        ]);

        $this->internalAlert(
            'New website enquiry — '.$data['subject'],
            'New website enquiry',
            ['A new enquiry was submitted through the website and needs a response.'],
            [
                'Name' => $data['name'] ?? null,
                'Email' => $data['email'] ?? null,
                'Phone' => $data['phone'] ?? null,
                'Subject' => $data['subject'] ?? null,
                'Message' => $data['message'] ?? null,
            ],
            $organization->id ?? null,
            $data['branch_id'] ?? null,
            'crm.view',
        );
    }

    /**
     * @param  array<int, string>  $intro
     * @param  array<string, mixed>  $details
     */
    public function internalAlert(
        string $subject,
        string $heading,
        array $intro,
        array $details,
        ?int $organizationId,
        ?int $branchId = null,
        ?string $permission = null,
    ): void {
        $organization = $this->organization($organizationId);
        $email = $organization?->email ?: config('mail.from.address');

        $this->send('internal_alerts', $email, $organization?->name, [
            'organization_id' => $organizationId,
            'branch_id' => $branchId,
            'inbox_permission' => $permission,
            'inbox_url' => match ($permission) {
                'payments.reconcile' => '#staff/payments',
                'crm.view' => '#staff/crm',
                default => null,
            },
            'subject' => $subject,
            'heading' => $heading,
            'intro' => $intro,
            'details' => $details,
            'footnote' => 'This is an internal staff alert.',
        ]);
    }

    private function customer(?int $customerId): ?Customer
    {
        return $customerId === null ? null : Customer::query()->find($customerId);
    }

    private function organization(?int $organizationId): ?Organization
    {
        if ($organizationId === null) {
            return null;
        }

        if (! array_key_exists($organizationId, $this->organizations)) {
            $this->organizations[$organizationId] = Organization::query()->find($organizationId);
        }

        return $this->organizations[$organizationId];
    }

    private function branch(?int $branchId): ?object
    {
        return $branchId === null ? null : DB::table('branches')->where('id', $branchId)->first(['id', 'name', 'city', 'email', 'phone']);
    }

    private function branchName(?int $branchId): ?string
    {
        $branch = $this->branch($branchId);

        return $branch === null ? null : $branch->name;
    }

    private function validEmail(?string $email): bool
    {
        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * @return array{label: string, url: string}|null
     */
    private function action(string $label): ?array
    {
        $url = (string) config('app.frontend_url');

        return $url === '' ? null : ['label' => $label, 'url' => rtrim($url, '/')];
    }

    private function appointmentWhen(Appointment $appointment): ?string
    {
        if ($appointment->starts_at === null) {
            return null;
        }

        $timezone = $appointment->branch?->timezone ?: 'Africa/Nairobi';

        return CarbonImmutable::parse($appointment->starts_at)
            ->setTimezone($timezone)
            ->format('D, j M Y \a\t H:i').' ('.$timezone.')';
    }

    private function staffName(Appointment $appointment): ?string
    {
        $staff = $appointment->resources
            ->first(static fn ($resource): bool => ($resource->resource_type ?? null) === 'staff');

        return $staff?->name;
    }

    private function money(mixed $amount, ?string $currency): string
    {
        return number_format((float) $amount, 2).' '.($currency ?: 'KES');
    }

    private function dateTime(mixed $value, string $timezone): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->setTimezone($timezone)->format('D, j M Y H:i').' ('.$timezone.')';
    }

    private function dateRange(mixed $start, mixed $end): ?string
    {
        if ($start === null || $end === null) {
            return null;
        }

        return CarbonImmutable::parse($start)->format('j M Y').' – '.CarbonImmutable::parse($end)->format('j M Y');
    }

    private function fulfilmentLabel(?string $type): ?string
    {
        return match ($type) {
            'collection' => 'Collection at branch',
            'delivery' => 'Delivery',
            default => $type,
        };
    }

    private function orderStatusLabel(string $status): string
    {
        return match ($status) {
            'processing' => 'Order being prepared',
            'ready_for_collection' => 'Ready for collection',
            'dispatched' => 'Order dispatched',
            'completed' => 'Order completed',
            'cancelled' => 'Order cancelled',
            'confirmed' => 'Order confirmed',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $lines
     * @return array<int, array{name: string, quantity: string, amount: string}>
     */
    private function orderItems($lines): array
    {
        return $lines->map(static fn ($line): array => [
            'name' => (string) $line->name_snapshot,
            'quantity' => rtrim(rtrim(number_format((float) $line->quantity, 3, '.', ''), '0'), '.'),
            'amount' => number_format((float) $line->line_total, 2),
        ])->all();
    }

    /**
     * @return array<int, array{label: string, value: string, emphasis?: bool}>
     */
    private function orderTotals(object $order): array
    {
        $currency = $order->currency_code ?: 'KES';
        $totals = [
            ['label' => 'Subtotal', 'value' => $this->money($order->subtotal, $currency)],
        ];
        if ((float) ($order->discount_amount ?? 0) > 0) {
            $totals[] = ['label' => 'Discount', 'value' => '-'.$this->money($order->discount_amount, $currency)];
        }
        if ((float) ($order->delivery_fee ?? 0) > 0) {
            $totals[] = ['label' => 'Delivery', 'value' => $this->money($order->delivery_fee, $currency)];
        }
        $totals[] = ['label' => 'Total', 'value' => $this->money($order->total_amount, $currency), 'emphasis' => true];

        return $totals;
    }
}
