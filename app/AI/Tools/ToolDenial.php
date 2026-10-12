<?php

declare(strict_types=1);

namespace App\AI\Tools;

use App\AI\Core\Decision\ToolCallRecord;

final class ToolDenial
{
    public const UNKNOWN = 'unknown_tool';

    public const DISABLED = 'tool_disabled';

    public const NOT_OFFERED = ToolCallRecord::NOT_OFFERED;

    public const SKILL_DENIED = 'skill_denied';

    public const PERMISSION_DENIED = 'permission_denied';

    public const SANDBOX_WRITE = 'sandbox_write_denied';

    public const CONFIRMATION_REQUIRED = 'confirmation_required';

    public const RISK_DENIED = 'risk_denied';

    public const FORBIDDEN_ARGUMENT = 'forbidden_identity_argument';

    public const INVALID_ARGUMENTS = 'invalid_arguments';

    public const ARGUMENT_TOO_LARGE = 'argument_too_large';

    public const CALL_LIMIT = 'tool_call_limit';

    public const DUPLICATE_CALL = 'duplicate_tool_call';

    public const EXECUTION_ERROR = 'execution_error';

    public const WORKSPACE_DENIED = 'workspace_denied';

    public const CONVERSATION_REQUIRED = 'conversation_required';
}
