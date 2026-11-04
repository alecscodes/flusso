<?php

namespace App\Enums;

enum SuggestionDismissalKind: string
{
    /** Hidden for good. */
    case NotRecurring = 'not_recurring';

    /** The owner is only trying the service: hidden until the snooze ends. */
    case Temporary = 'temporary';
}
