<?php

namespace App\Enums\Finance;

enum FinanceAgentToolAccessMode: string
{
    case Read = 'read';
    case Write = 'write';
}
