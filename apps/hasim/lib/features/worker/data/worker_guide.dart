class WorkerGuideFaq {
  const WorkerGuideFaq({required this.question, required this.answer});

  final String question;
  final String answer;

  factory WorkerGuideFaq.fromJson(Map<String, dynamic> json) {
    return WorkerGuideFaq(
      question: _guideText(json['question']) ?? '',
      answer: _guideText(json['answer']) ?? '',
    );
  }
}

class WorkerGuide {
  const WorkerGuide({
    this.companyOverview,
    this.productsContext,
    this.responsibilities = const [],
    this.workflowSteps = const [],
    this.importantRules = const [],
    this.customerGuidance = const [],
    this.escalationRules = const [],
    this.workScheduleNotes,
    this.importantNotes,
    this.faqItems = const [],
  });

  final String? companyOverview;
  final String? productsContext;
  final List<String> responsibilities;
  final List<String> workflowSteps;
  final List<String> importantRules;
  final List<String> customerGuidance;
  final List<String> escalationRules;
  final String? workScheduleNotes;
  final String? importantNotes;
  final List<WorkerGuideFaq> faqItems;

  bool get hasContent {
    return [
          companyOverview,
          productsContext,
          workScheduleNotes,
          importantNotes,
        ].any((value) => value?.trim().isNotEmpty == true) ||
        responsibilities.isNotEmpty ||
        workflowSteps.isNotEmpty ||
        importantRules.isNotEmpty ||
        customerGuidance.isNotEmpty ||
        escalationRules.isNotEmpty ||
        faqItems.isNotEmpty;
  }

  factory WorkerGuide.fromJson(Map<String, dynamic> json) {
    return WorkerGuide(
      companyOverview: _guideText(json['company_overview']),
      productsContext: _guideText(json['products_context']),
      responsibilities: _guideStrings(json['responsibilities']),
      workflowSteps: _guideStrings(json['workflow_steps']),
      importantRules: _guideStrings(json['important_rules']),
      customerGuidance: _guideStrings(json['customer_guidance']),
      escalationRules: _guideStrings(json['escalation_rules']),
      workScheduleNotes: _guideText(json['work_schedule_notes']),
      importantNotes: _guideText(json['important_notes']),
      faqItems: [
        if (json['faq_items'] is List)
          for (final item in json['faq_items'] as List)
            if (item is Map)
              WorkerGuideFaq.fromJson(Map<String, dynamic>.from(item)),
      ],
    );
  }
}

String? _guideText(dynamic value) {
  final text = value?.toString().trim() ?? '';
  return text.isEmpty ? null : text;
}

List<String> _guideStrings(dynamic value) {
  if (value is! List) {
    return const [];
  }

  return [
    for (final item in value)
      if (_guideText(item) != null) _guideText(item)!,
  ];
}
