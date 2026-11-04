<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * "This account is short or at its threshold", kept in the database only
 * (the app sends no mail to users).
 */
class LiquidityAlert extends Notification
{
    /**
     * @param  array<string, mixed>  $forecast  One account's row from LiquidityService::forecast().
     */
    public function __construct(
        public array $forecast,
        public string $kind,
        public string $message,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'message' => $this->message,
            'account_id' => $this->forecast['account_id'],
            'account_name' => $this->forecast['account_name'],
            'currency' => $this->forecast['currency'],
            'balance' => $this->forecast['balance'],
            'alert_threshold' => $this->forecast['alert_threshold'],
            'due_total' => $this->forecast['due_total'],
            'shortfall' => $this->forecast['shortfall'],
            'needed_by' => $this->forecast['needed_by'],
        ];
    }
}
