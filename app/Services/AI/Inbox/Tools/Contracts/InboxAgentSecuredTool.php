<?php

namespace App\Services\AI\Inbox\Tools\Contracts;

use App\Services\AI\Inbox\Tools\InboxAgentToolSecurityProfile;

interface InboxAgentSecuredTool extends InboxAgentTool
{
    public function securityProfile(): InboxAgentToolSecurityProfile;
}
