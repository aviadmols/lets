<?php

namespace App\Mail;

use App\Models\MerchantMailSettings;

/**
 * Upcoming-charge reminder, sent reminder_offset_hours BEFORE next_charge_at by
 * DispatchRemindersCommand. Ported from the reference engine's
 * RecurringPaymentReminderMail.
 */
final class RecurringPaymentReminderMail extends PlanMail
{
    // === CONSTANTS ===
    protected function templateKey(): string
    {
        return MerchantMailSettings::TEMPLATE_RECURRING_PAYMENT_REMINDER;
    }
}
