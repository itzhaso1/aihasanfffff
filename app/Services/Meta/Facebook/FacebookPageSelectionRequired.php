<?php

namespace App\Services\Meta\Facebook;

use RuntimeException;

final class FacebookPageSelectionRequired extends RuntimeException
{
    public function __construct(public readonly string $attemptId)
    {
        parent::__construct('Multiple Facebook Pages are available. Select a Page to finish connecting.');
    }
}
