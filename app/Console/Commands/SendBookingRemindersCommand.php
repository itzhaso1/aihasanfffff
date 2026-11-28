<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Booking\BookingNotificationService;
use Illuminate\Console\Command;

class SendBookingRemindersCommand extends Command
{
    protected $signature = 'bookings:send-reminders {--workspace= : Optional workspace ID filter}';

    protected $description = 'Dispatch due customer booking reminders across active conversation channels';

    public function handle(BookingNotificationService $notifications): int
    {
        $workspaceId = $this->option('workspace') !== null ? (int) $this->option('workspace') : null;
        $sent = $notifications->sendDueReminders($workspaceId);

        $this->info("Sent {$sent} booking reminder(s).");

        return self::SUCCESS;
    }
}
