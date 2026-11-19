import 'package:equatable/equatable.dart';

class EmailAccountModel extends Equatable {
  const EmailAccountModel({
    required this.id,
    required this.name,
    required this.email,
    this.brandColor,
    this.logoUrl,
  });

  final int id;
  final String name;
  final String email;
  final String? brandColor;
  final String? logoUrl;

  factory EmailAccountModel.fromJson(Map<String, dynamic> json) {
    return EmailAccountModel(
      id: (json['id'] as num).toInt(),
      name: json['name']?.toString() ?? '',
      email: json['email']?.toString() ?? '',
      brandColor: json['brand_color']?.toString(),
      logoUrl: json['logo_url']?.toString(),
    );
  }

  @override
  List<Object?> get props => [id, name, email];
}

class EmailAttachmentModel extends Equatable {
  const EmailAttachmentModel({
    required this.id,
    required this.fileType,
    this.fileSize,
  });

  final int id;
  final String fileType;
  final int? fileSize;

  factory EmailAttachmentModel.fromJson(Map<String, dynamic> json) {
    return EmailAttachmentModel(
      id: (json['id'] as num).toInt(),
      fileType: json['file_type']?.toString() ?? 'application/octet-stream',
      fileSize: (json['file_size'] as num?)?.toInt(),
    );
  }

  @override
  List<Object?> get props => [id, fileType, fileSize];
}

class EmailMessageModel extends Equatable {
  const EmailMessageModel({
    required this.id,
    this.emailAccountId,
    required this.sender,
    required this.recipient,
    this.subject,
    this.body,
    this.preview,
    this.type = 'inbound',
    this.deliveryStatus,
    this.isRead = false,
    this.isStarred = false,
    this.folder,
    this.createdAt,
    this.attachments = const [],
    this.account,
  });

  final int id;
  final int? emailAccountId;
  final String sender;
  final String recipient;
  final String? subject;
  final String? body;
  final String? preview;
  final String type;
  final String? deliveryStatus;
  final bool isRead;
  final bool isStarred;
  final String? folder;
  final String? createdAt;
  final List<EmailAttachmentModel> attachments;
  final EmailAccountModel? account;

  String get displaySubject =>
      (subject != null && subject!.trim().isNotEmpty) ? subject! : '(بدون موضوع)';

  factory EmailMessageModel.fromJson(Map<String, dynamic> json) {
    final rawAttachments = json['attachments'] as List? ?? [];
    final attachments = rawAttachments
        .whereType<Map<String, dynamic>>()
        .map(EmailAttachmentModel.fromJson)
        .toList();

    EmailAccountModel? account;
    if (json['account'] is Map) {
      account = EmailAccountModel.fromJson(
        Map<String, dynamic>.from(json['account'] as Map),
      );
    }

    return EmailMessageModel(
      id: (json['id'] as num).toInt(),
      emailAccountId: (json['email_account_id'] as num?)?.toInt(),
      sender: json['sender']?.toString() ?? '',
      recipient: json['recipient']?.toString() ?? '',
      subject: json['subject']?.toString(),
      body: json['body']?.toString(),
      preview: json['preview']?.toString(),
      type: json['type']?.toString() ?? 'inbound',
      deliveryStatus: json['delivery_status']?.toString(),
      isRead: json['is_read'] == true,
      isStarred: json['is_starred'] == true,
      folder: json['folder']?.toString(),
      createdAt: json['created_at']?.toString(),
      attachments: attachments,
      account: account,
    );
  }

  EmailMessageModel copyWith({
    bool? isRead,
    bool? isStarred,
  }) {
    return EmailMessageModel(
      id: id,
      emailAccountId: emailAccountId,
      sender: sender,
      recipient: recipient,
      subject: subject,
      body: body,
      preview: preview,
      type: type,
      deliveryStatus: deliveryStatus,
      isRead: isRead ?? this.isRead,
      isStarred: isStarred ?? this.isStarred,
      folder: folder,
      createdAt: createdAt,
      attachments: attachments,
      account: account,
    );
  }

  @override
  List<Object?> get props => [
    id,
    emailAccountId,
    sender,
    recipient,
    subject,
    type,
    isRead,
    isStarred,
    createdAt,
  ];
}
