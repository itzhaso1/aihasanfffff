<?php

namespace App\Enums;

enum ConversationHandoffReason: string
{
    case CustomerRequest = 'customer_request';
    case PaymentIssue = 'payment_issue';
    case RefundRequest = 'refund_request';
    case CancelRequest = 'cancel_request';
    case DigitalDeliveryFailure = 'digital_delivery_failure';
    case HighValueLead = 'high_value_lead';
    case AiUncertainty = 'ai_uncertainty';
    case RepeatedAiFailure = 'repeated_ai_failure';
    case SensitiveSupport = 'sensitive_support';
    case AgentRequested = 'agent_requested';
    case SystemTrigger = 'system_trigger';
}
