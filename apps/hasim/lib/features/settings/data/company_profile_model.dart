import 'package:equatable/equatable.dart';

class CompanyProfileModel extends Equatable {
  const CompanyProfileModel({
    this.companyName = '',
    this.about = '',
    this.offerings = '',
    this.services = '',
    this.audience = '',
    this.replyGuidelines = '',
  });

  static const nameMax = 120;
  static const aboutMax = 2000;
  static const offeringsMax = 2000;
  static const servicesMax = 2000;
  static const audienceMax = 1000;
  static const replyGuidelinesMax = 2000;

  final String companyName;
  final String about;
  final String offerings;
  final String services;
  final String audience;
  final String replyGuidelines;

  factory CompanyProfileModel.fromJson(Map<String, dynamic> json) {
    return CompanyProfileModel(
      companyName: json['company_name']?.toString() ?? '',
      about: json['about']?.toString() ?? '',
      offerings: json['offerings']?.toString() ?? '',
      services: json['services']?.toString() ?? '',
      audience: json['audience']?.toString() ?? '',
      replyGuidelines: json['reply_guidelines']?.toString() ?? '',
    );
  }

  Map<String, dynamic> toUpdateJson() => {
    'company_name': companyName.trim(),
    'about': about.trim(),
    'offerings': offerings.trim(),
    'services': services.trim(),
    'audience': audience.trim(),
    'reply_guidelines': replyGuidelines.trim(),
  };

  CompanyProfileModel copyWith({
    String? companyName,
    String? about,
    String? offerings,
    String? services,
    String? audience,
    String? replyGuidelines,
  }) {
    return CompanyProfileModel(
      companyName: companyName ?? this.companyName,
      about: about ?? this.about,
      offerings: offerings ?? this.offerings,
      services: services ?? this.services,
      audience: audience ?? this.audience,
      replyGuidelines: replyGuidelines ?? this.replyGuidelines,
    );
  }

  String? fieldError(String value, int max) {
    if (value.trim().length > max) {
      return 'تجاوز الحد الأقصى ($max حرفاً).';
    }
    return null;
  }

  @override
  List<Object?> get props => [
    companyName,
    about,
    offerings,
    services,
    audience,
    replyGuidelines,
  ];
}
