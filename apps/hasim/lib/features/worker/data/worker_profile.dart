import 'dart:typed_data';

import 'package:hasim/core/network/api_error_translator.dart';
import 'package:hasim/core/network/api_exception.dart';

class WorkerUploadFile {
  const WorkerUploadFile({
    required this.bytes,
    required this.filename,
    this.mimeType,
  });

  final Uint8List bytes;
  final String filename;
  final String? mimeType;
}

class WorkerValidationErrors {
  const WorkerValidationErrors(this.messages);

  static const empty = WorkerValidationErrors({});

  final Map<String, String> messages;

  factory WorkerValidationErrors.fromException(ApiException error) {
    final rawErrors = error.errors;
    if (rawErrors == null || rawErrors.isEmpty) {
      return empty;
    }

    return WorkerValidationErrors({
      for (final entry in rawErrors.entries)
        entry.key: _messageList(entry.value)
            .map(
              (message) =>
                  ApiErrorTranslator.translateFieldError(entry.key, message),
            )
            .join('\n'),
    });
  }

  bool get isEmpty => messages.isEmpty;

  String? forField(String fieldKey) {
    final exact = messages[fieldKey];
    if (exact != null) return exact;

    for (final entry in messages.entries) {
      if (entry.key.startsWith('$fieldKey.') ||
          entry.key.startsWith('$fieldKey[')) {
        return entry.value;
      }
    }

    return null;
  }

  List<String> forPrefix(String prefix) {
    return [
      for (final entry in messages.entries)
        if (entry.key == prefix ||
            entry.key.startsWith('$prefix.') ||
            entry.key.startsWith('$prefix['))
          entry.value,
    ];
  }

  static List<String> _messageList(dynamic value) {
    if (value is List) {
      return value
          .map((item) => item.toString().trim())
          .where((item) => item.isNotEmpty)
          .toList();
    }
    final text = value?.toString().trim();
    return text == null || text.isEmpty ? const [] : [text];
  }
}

class WorkerReviewIssue {
  const WorkerReviewIssue({
    required this.id,
    required this.targetType,
    required this.reason,
    required this.status,
    this.targetId,
    this.fieldKey,
    this.createdAt,
    this.resolvedAt,
  });

  final int id;
  final String targetType;
  final int? targetId;
  final String? fieldKey;
  final String reason;
  final String status;
  final String? createdAt;
  final String? resolvedAt;

  factory WorkerReviewIssue.fromJson(Map<String, dynamic> json) {
    return WorkerReviewIssue(
      id: (json['id'] as num).toInt(),
      targetType: json['target_type']?.toString() ?? '',
      targetId: json['target_id'] is num
          ? (json['target_id'] as num).toInt()
          : null,
      fieldKey: _nullableText(json['field_key']),
      reason: json['reason']?.toString() ?? '',
      status: json['status']?.toString() ?? '',
      createdAt: _nullableText(json['created_at']),
      resolvedAt: _nullableText(json['resolved_at']),
    );
  }
}

class WorkerVerificationNotice {
  const WorkerVerificationNotice({
    required this.title,
    required this.next,
    this.reason,
  });

  final String title;
  final String next;
  final String? reason;

  factory WorkerVerificationNotice.fromJson(Map<String, dynamic> json) {
    return WorkerVerificationNotice(
      title: json['title']?.toString() ?? '',
      next: json['next']?.toString() ?? '',
      reason: _nullableText(json['reason']),
    );
  }
}

class WorkerSkillItem {
  const WorkerSkillItem({
    required this.id,
    required this.skillKey,
    required this.displayName,
  });

  final int id;
  final String skillKey;
  final String displayName;

  factory WorkerSkillItem.fromJson(Map<String, dynamic> json) {
    return WorkerSkillItem(
      id: (json['id'] as num).toInt(),
      skillKey: json['skill_key']?.toString() ?? '',
      displayName: json['display_name']?.toString() ?? '',
    );
  }
}

class WorkerLanguageItem {
  const WorkerLanguageItem({required this.code, this.displayName});

  final String code;
  final String? displayName;

  factory WorkerLanguageItem.catalog(Map<String, dynamic> json) {
    return WorkerLanguageItem(
      code: json['code']?.toString() ?? '',
      displayName: _nullableText(json['display_name']),
    );
  }

  factory WorkerLanguageItem.saved(Map<String, dynamic> json) {
    return WorkerLanguageItem(code: json['language_code']?.toString() ?? '');
  }

  String label(List<WorkerLanguageItem> catalog) {
    for (final item in catalog) {
      if (item.code == code && (item.displayName?.isNotEmpty ?? false)) {
        return item.displayName!;
      }
    }
    return displayName?.isNotEmpty == true ? displayName! : code;
  }
}

class WorkerExperience {
  const WorkerExperience({
    required this.id,
    required this.title,
    required this.organizationName,
    required this.isCurrent,
    this.startedOn,
    this.endedOn,
    this.description,
  });

  final int id;
  final String title;
  final String organizationName;
  final String? startedOn;
  final String? endedOn;
  final bool isCurrent;
  final String? description;

  factory WorkerExperience.fromJson(Map<String, dynamic> json) {
    return WorkerExperience(
      id: (json['id'] as num).toInt(),
      title: json['title']?.toString() ?? '',
      organizationName: json['organization_name']?.toString() ?? '',
      startedOn: _nullableText(json['started_on']),
      endedOn: _nullableText(json['ended_on']),
      isCurrent: _asBool(json['is_current']),
      description: _nullableText(json['description']),
    );
  }
}

class WorkerProfile {
  const WorkerProfile({
    required this.id,
    required this.displayName,
    this.profilePhoto,
    required this.countryCode,
    required this.nationality,
    required this.verificationStatus,
    required this.verificationStatusLabel,
    required this.lifecycleStatus,
    required this.lifecycleStatusLabel,
    required this.availabilityStatus,
    required this.availabilityStatusLabel,
    required this.professionalSummary,
    required this.salesExperienceLevel,
    required this.requiredDocumentTypes,
    required this.skills,
    required this.languages,
    required this.experiences,
    required this.verificationIssues,
    this.verificationNotice,
  });

  final int id;
  final String displayName;
  final String? profilePhoto;
  final String countryCode;
  final String nationality;
  final String verificationStatus;
  final String verificationStatusLabel;
  final String lifecycleStatus;
  final String lifecycleStatusLabel;
  final String availabilityStatus;
  final String availabilityStatusLabel;
  final WorkerVerificationNotice? verificationNotice;
  final String professionalSummary;
  final String salesExperienceLevel;
  final List<String> requiredDocumentTypes;
  final List<WorkerSkillItem> skills;
  final List<WorkerLanguageItem> languages;
  final List<WorkerExperience> experiences;
  final List<WorkerReviewIssue> verificationIssues;

  bool get uploadBlocked =>
      verificationStatus == 'approved' || lifecycleStatus == 'suspended';

  factory WorkerProfile.fromJson(Map<String, dynamic> json) {
    final notice = json['verification_notice'];
    return WorkerProfile(
      id: (json['id'] as num).toInt(),
      displayName: json['display_name']?.toString() ?? '',
      profilePhoto: _nullableText(json['profile_photo']),
      countryCode: json['country_code']?.toString() ?? '',
      nationality: json['nationality']?.toString() ?? '',
      verificationStatus: json['verification_status']?.toString() ?? '',
      verificationStatusLabel:
          json['verification_status_label']?.toString() ?? '',
      lifecycleStatus: json['lifecycle_status']?.toString() ?? '',
      lifecycleStatusLabel: json['lifecycle_status_label']?.toString() ?? '',
      availabilityStatus: json['availability_status']?.toString() ?? '',
      availabilityStatusLabel:
          json['availability_status_label']?.toString() ?? '',
      verificationNotice: notice is Map
          ? WorkerVerificationNotice.fromJson(Map<String, dynamic>.from(notice))
          : null,
      professionalSummary: json['professional_summary']?.toString() ?? '',
      salesExperienceLevel: json['sales_experience_level']?.toString() ?? '',
      requiredDocumentTypes: [
        for (final item
            in json['required_document_types'] is List
                ? json['required_document_types'] as List
                : const [])
          item.toString(),
      ],
      skills: _mapList(json['skills'], WorkerSkillItem.fromJson),
      languages: _mapList(json['languages'], WorkerLanguageItem.saved),
      experiences: _mapList(json['experiences'], WorkerExperience.fromJson),
      verificationIssues: _mapList(
        json['verification_issues'],
        WorkerReviewIssue.fromJson,
      ),
    );
  }
}

class WorkerIdentity {
  const WorkerIdentity({
    this.legalName,
    this.dateOfBirth,
    this.identityNumber,
    this.address,
  });

  final String? legalName;
  final String? dateOfBirth;
  final String? identityNumber;
  final String? address;

  bool get isEmpty =>
      (legalName == null || legalName!.isEmpty) &&
      (dateOfBirth == null || dateOfBirth!.isEmpty) &&
      (identityNumber == null || identityNumber!.isEmpty) &&
      (address == null || address!.isEmpty);

  factory WorkerIdentity.fromJson(Map<String, dynamic> json) {
    return WorkerIdentity(
      legalName: _nullableText(json['legal_name']),
      dateOfBirth: _nullableText(json['date_of_birth']),
      identityNumber: _nullableText(json['identity_number']),
      address: _nullableText(json['address']),
    );
  }
}

class WorkerVerificationDocument {
  const WorkerVerificationDocument({
    required this.id,
    required this.documentTypeCode,
    required this.originalName,
    required this.status,
    this.mimeType,
    this.sizeBytes,
    this.rejectionReason,
  });

  final int id;
  final String documentTypeCode;
  final String originalName;
  final String? mimeType;
  final int? sizeBytes;
  final String status;
  final String? rejectionReason;

  factory WorkerVerificationDocument.fromJson(Map<String, dynamic> json) {
    return WorkerVerificationDocument(
      id: (json['id'] as num).toInt(),
      documentTypeCode: json['document_type_code']?.toString() ?? '',
      originalName: json['original_name']?.toString() ?? '',
      mimeType: _nullableText(json['mime_type']),
      sizeBytes: json['size_bytes'] is num
          ? (json['size_bytes'] as num).toInt()
          : null,
      status: json['status']?.toString() ?? '',
      rejectionReason: _nullableText(json['rejection_reason']),
    );
  }
}

class WorkerProfessionalUpdate {
  const WorkerProfessionalUpdate({
    required this.displayName,
    required this.countryCode,
    required this.nationality,
    required this.professionalSummary,
    required this.salesExperienceLevel,
    required this.availabilityStatus,
  });

  final String displayName;
  final String countryCode;
  final String nationality;
  final String professionalSummary;
  final String salesExperienceLevel;
  final String availabilityStatus;

  Map<String, String> toJson() {
    return {
      'display_name': displayName,
      'country_code': countryCode,
      'nationality': nationality,
      'professional_summary': professionalSummary,
      'sales_experience_level': salesExperienceLevel,
      'availability_status': availabilityStatus,
    };
  }
}

class WorkerExperienceInput {
  const WorkerExperienceInput({
    required this.title,
    required this.organizationName,
    required this.startedOn,
    required this.isCurrent,
    this.endedOn,
    this.description,
  });

  final String title;
  final String organizationName;
  final String startedOn;
  final String? endedOn;
  final bool isCurrent;
  final String? description;

  Map<String, dynamic> toJson() {
    return {
      'title': title,
      'organization_name': organizationName,
      'started_on': startedOn,
      'ended_on': isCurrent ? null : endedOn,
      'is_current': isCurrent,
      'description': description,
    };
  }
}

class WorkerIdentityInput {
  const WorkerIdentityInput({
    required this.legalName,
    required this.dateOfBirth,
    required this.identityNumber,
    this.address,
  });

  final String legalName;
  final String dateOfBirth;
  final String identityNumber;
  final String? address;

  Map<String, dynamic> toJson() {
    return {
      'legal_name': legalName,
      'date_of_birth': dateOfBirth,
      'identity_number': identityNumber,
      'address': address,
    };
  }
}

String? _nullableText(dynamic value) {
  final text = value?.toString();
  if (text == null || text.isEmpty || text == 'null') return null;
  return text;
}

bool _asBool(dynamic value) {
  if (value is bool) return value;
  if (value is num) return value != 0;
  final text = value?.toString().toLowerCase();
  return text == '1' || text == 'true';
}

List<T> _mapList<T>(dynamic raw, T Function(Map<String, dynamic> json) parse) {
  if (raw is! List) return const [];
  return [
    for (final item in raw)
      if (item is Map) parse(Map<String, dynamic>.from(item)),
  ];
}
