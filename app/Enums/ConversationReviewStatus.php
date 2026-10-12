<?php

namespace App\Enums;

enum ConversationReviewStatus: string
{
    case NeedsReview = 'needs_review';
    case NoReviewNeeded = 'no_review_needed';
}
