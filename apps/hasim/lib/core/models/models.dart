import 'dart:math' as math;

import 'package:equatable/equatable.dart';
import 'package:hasim/core/config/app_config.dart';
import 'package:hasim/core/utils/quoted_reply.dart';

class UserModel extends Equatable {
  const UserModel({
    required this.id,
    required this.name,
    this.email,
    this.phone,
    this.locale,
    this.timezone,
    this.avatarUrl,
  });

  final int id;
  final String name;
  final String? email;
  final String? phone;
  final String? locale;
  final String? timezone;
  final String? avatarUrl;

  factory UserModel.fromJson(Map<String, dynamic> json) => UserModel(
    id: (json['id'] as num).toInt(),
    name: json['name']?.toString() ?? '',
    email: json['email']?.toString(),
    phone: json['phone']?.toString(),
    locale: json['locale']?.toString(),
    timezone: json['timezone']?.toString(),
    avatarUrl: AppConfig.resolvePublicUrl(json['avatar_url']?.toString()),
  );

  UserModel copyWith({
    String? name,
    String? email,
    String? phone,
    String? locale,
    String? timezone,
    String? avatarUrl,
  }) {
    return UserModel(
      id: id,
      name: name ?? this.name,
      email: email ?? this.email,
      phone: phone ?? this.phone,
      locale: locale ?? this.locale,
      timezone: timezone ?? this.timezone,
      avatarUrl: avatarUrl ?? this.avatarUrl,
    );
  }

  @override
  List<Object?> get props => [id, name, email, phone, avatarUrl];
}

class WorkspaceModel extends Equatable {
  const WorkspaceModel({
    required this.id,
    required this.name,
    this.type,
    this.slug,
    this.role,
    this.isOwner = false,
  });

  final int id;
  final String name;
  final String? type;
  final String? slug;
  final String? role;
  final bool isOwner;

  factory WorkspaceModel.fromJson(Map<String, dynamic> json) => WorkspaceModel(
    id: (json['id'] as num).toInt(),
    name: json['name']?.toString() ?? '',
    type: json['type']?.toString(),
    slug: json['slug']?.toString(),
    role: json['role']?.toString() ?? json['membership_role']?.toString(),
    isOwner: json['is_owner'] == true,
  );

  @override
  List<Object?> get props => [id, name, type, slug, role, isOwner];
}

class CustomerNoteModel extends Equatable {
  const CustomerNoteModel({
    required this.id,
    required this.body,
    this.createdAt,
    this.userName,
  });

  final int id;
  final String body;
  final String? createdAt;
  final String? userName;

  factory CustomerNoteModel.fromJson(Map<String, dynamic> json) =>
      CustomerNoteModel(
        id: (json['id'] as num?)?.toInt() ?? 0,
        body: json['body']?.toString() ?? '',
        createdAt: json['created_at']?.toString(),
        userName: json['user_name']?.toString(),
      );

  Map<String, dynamic> toJson() => {
    'id': id,
    'body': body,
    if (createdAt != null) 'created_at': createdAt,
    if (userName != null) 'user_name': userName,
  };

  @override
  List<Object?> get props => [id, body, createdAt, userName];
}

class CustomerModel extends Equatable {
  const CustomerModel({
    required this.id,
    required this.name,
    this.phone,
    this.status = 'needs_review',
    this.statusLabel,
    this.email,
    this.whatsapp,
    this.city,
    this.ordersCount = 0,
    this.totalPurchases = '0.00',
    this.lastOrderAt,
    this.lastConversationAt,
    this.notes = const [],
    this.createdAt,
  });

  final int id;
  final String name;
  final String? phone;
  final String status;
  final String? statusLabel;
  final String? email;
  final String? whatsapp;
  final String? city;
  final int ordersCount;
  final String totalPurchases;
  final String? lastOrderAt;
  final String? lastConversationAt;
  final List<CustomerNoteModel> notes;
  final String? createdAt;

  String get displayStatus =>
      statusLabel ??
      switch (status) {
        'needs_review' => 'يحتاج إلى مراجعة',
        'completed' => 'مكتمل',
        'awaiting_payment' => 'بانتظار الدفع',
        'paid' => 'تم الدفع',
        'in_progress' => 'قيد التنفيذ',
        'delivered' => 'مكتمل التسليم',
        'cancelled' => 'ملغي',
        _ => status,
      };

  factory CustomerModel.fromJson(Map<String, dynamic> json) => CustomerModel(
    id: asInt(json['id']),
    name: json['name']?.toString() ?? '',
    phone: json['phone']?.toString(),
    status: json['status']?.toString() ?? 'needs_review',
    statusLabel: json['status_label']?.toString(),
    email: json['email']?.toString(),
    whatsapp: json['whatsapp']?.toString(),
    city: json['city']?.toString(),
    ordersCount: asInt(json['orders_count'], 0),
    totalPurchases: json['total_purchases']?.toString() ?? '0.00',
    lastOrderAt: json['last_order_at']?.toString(),
    lastConversationAt: json['last_conversation_at']?.toString(),
    notes:
        (json['notes'] as List?)
            ?.whereType<Map>()
            .map(
              (e) => CustomerNoteModel.fromJson(Map<String, dynamic>.from(e)),
            )
            .toList() ??
        const [],
    createdAt: json['created_at']?.toString(),
  );

  Map<String, dynamic> toJson() => {
    'id': id,
    'name': name,
    if (phone != null) 'phone': phone,
    'status': status,
    if (statusLabel != null) 'status_label': statusLabel,
    if (email != null) 'email': email,
    if (whatsapp != null) 'whatsapp': whatsapp,
    if (city != null) 'city': city,
    'orders_count': ordersCount,
    'total_purchases': totalPurchases,
    if (lastOrderAt != null) 'last_order_at': lastOrderAt,
    if (lastConversationAt != null) 'last_conversation_at': lastConversationAt,
    'notes': notes.map((n) => n.toJson()).toList(),
    if (createdAt != null) 'created_at': createdAt,
  };

  @override
  List<Object?> get props => [
    id,
    name,
    phone,
    status,
    statusLabel,
    email,
    whatsapp,
    city,
    ordersCount,
    totalPurchases,
    lastOrderAt,
    lastConversationAt,
    notes,
    createdAt,
  ];
}

class MessageAttachmentModel extends Equatable {
  const MessageAttachmentModel({
    required this.id,
    required this.kind,
    required this.originalName,
    this.mimeType,
    this.sizeBytes,
    this.durationMs,
    this.status,
    this.downloadUrl,
    this.previewUrl,
  });

  final int id;
  final String kind;
  final String originalName;
  final String? mimeType;
  final int? sizeBytes;
  final int? durationMs;
  final String? status;
  final String? downloadUrl;
  final String? previewUrl;

  String? get displayUrl {
    final preview = previewUrl;
    if (preview != null && preview.isNotEmpty) return preview;
    final download = downloadUrl;
    if (download != null && download.isNotEmpty) return download;
    return null;
  }

  factory MessageAttachmentModel.fromJson(Map<String, dynamic> json) =>
      MessageAttachmentModel(
        id: (json['id'] as num).toInt(),
        kind: json['kind']?.toString() ?? 'file',
        originalName: json['original_name']?.toString() ?? 'ملف',
        mimeType: json['mime_type']?.toString(),
        sizeBytes: (json['size_bytes'] as num?)?.toInt(),
        durationMs: (json['duration_ms'] as num?)?.toInt(),
        status: json['status']?.toString(),
        downloadUrl: json['download_url']?.toString(),
        previewUrl: json['preview_url']?.toString(),
      );

  MessageAttachmentModel copyWith({
    String? downloadUrl,
    String? previewUrl,
    String? status,
  }) {
    return MessageAttachmentModel(
      id: id,
      kind: kind,
      originalName: originalName,
      mimeType: mimeType,
      sizeBytes: sizeBytes,
      durationMs: durationMs,
      status: status ?? this.status,
      downloadUrl: downloadUrl ?? this.downloadUrl,
      previewUrl: previewUrl ?? this.previewUrl,
    );
  }

  @override
  List<Object?> get props => [id, kind, downloadUrl, previewUrl];
}

class MessageLocationModel extends Equatable {
  const MessageLocationModel({
    required this.latitude,
    required this.longitude,
    this.id,
    this.accuracyMeters,
    this.source,
    this.sourceLabel,
    this.platform,
    this.verificationStatus = 'pending_confirmation',
    this.verificationLabel,
    this.formattedAddress,
    this.city,
    this.district,
    this.street,
    this.placeName,
    this.customerNotes,
    this.googleMapsUrl,
  });

  final int? id;
  final double latitude;
  final double longitude;
  final int? accuracyMeters;
  final String? source;
  final String? sourceLabel;
  final String? platform;
  final String verificationStatus;
  final String? verificationLabel;
  final String? formattedAddress;
  final String? city;
  final String? district;
  final String? street;
  final String? placeName;
  final String? customerNotes;
  final String? googleMapsUrl;

  factory MessageLocationModel.fromJson(Map<String, dynamic> json) {
    final lat = (json['latitude'] as num?)?.toDouble() ?? 0.0;
    final lng = (json['longitude'] as num?)?.toDouble() ?? 0.0;
    return MessageLocationModel(
      id: asNullableInt(json['id']),
      latitude: lat,
      longitude: lng,
      accuracyMeters: asNullableInt(json['accuracy_meters']),
      source: json['source']?.toString(),
      sourceLabel: json['source_label']?.toString(),
      platform: json['platform']?.toString(),
      verificationStatus:
          json['verification_status']?.toString() ?? 'pending_confirmation',
      verificationLabel: json['verification_label']?.toString(),
      formattedAddress: json['formatted_address']?.toString(),
      city: json['city']?.toString(),
      district: json['district']?.toString(),
      street: json['street']?.toString(),
      placeName: json['place_name']?.toString(),
      customerNotes: json['customer_notes']?.toString(),
      googleMapsUrl: json['google_maps_url']?.toString() ??
          'https://www.google.com/maps?q=${lat.toStringAsFixed(7)},${lng.toStringAsFixed(7)}',
    );
  }

  Map<String, dynamic> toJson() => {
    if (id != null) 'id': id,
    'latitude': latitude,
    'longitude': longitude,
    if (accuracyMeters != null) 'accuracy_meters': accuracyMeters,
    if (source != null) 'source': source,
    if (sourceLabel != null) 'source_label': sourceLabel,
    if (platform != null) 'platform': platform,
    'verification_status': verificationStatus,
    if (verificationLabel != null) 'verification_label': verificationLabel,
    if (formattedAddress != null) 'formatted_address': formattedAddress,
    if (city != null) 'city': city,
    if (district != null) 'district': district,
    if (street != null) 'street': street,
    if (placeName != null) 'place_name': placeName,
    if (customerNotes != null) 'customer_notes': customerNotes,
    if (googleMapsUrl != null) 'google_maps_url': googleMapsUrl,
  };

  @override
  List<Object?> get props => [
    id,
    latitude,
    longitude,
    accuracyMeters,
    verificationStatus,
    formattedAddress,
    customerNotes,
  ];
}

class MessageModel extends Equatable {
  const MessageModel({
    required this.id,
    required this.conversationId,
    required this.direction,
    required this.messageType,
    required this.content,
    this.aiGenerated = false,
    this.deliveryStatus,
    this.deliveredAt,
    this.readAt,
    this.createdAt,
    this.userName,
    this.customerName,
    this.attachments = const [],
    this.localPending = false,
    this.localSending = false,
    this.localFailed = false,
    this.localUploading = false,
    this.localAttachmentPath,
    this.localError,
    this.clientId,
    this.correlationId,
    this.workerEngagementId,
    this.senderUserId,
    this.participantType,
    this.location,
  });

  final int id;
  final int conversationId;
  final String direction;
  final String messageType;
  final String content;
  final bool aiGenerated;
  final String? deliveryStatus;
  final DateTime? deliveredAt;
  final DateTime? readAt;
  final DateTime? createdAt;
  final String? userName;
  final String? customerName;
  final List<MessageAttachmentModel> attachments;
  final bool localPending;
  final bool localSending;
  final bool localFailed;
  final bool localUploading;
  final String? localAttachmentPath;
  final String? localError;
  final String? clientId;
  final String? correlationId;
  final int? workerEngagementId;
  final int? senderUserId;
  final String? participantType;
  final MessageLocationModel? location;

  OutboundPhase get outboundPhase {
    if (localFailed || deliveryStatus?.toLowerCase() == 'failed') {
      return OutboundPhase.failed;
    }
    if (localSending || localUploading) {
      return OutboundPhase.sending;
    }
    if (localPending) {
      return OutboundPhase.pending;
    }
    final ds = deliveryStatus?.toLowerCase().trim();
    if (ds == 'pending' || ds == 'sending' || ds == 'queued' || ds == 'accepted') {
      return OutboundPhase.accepted;
    }
    if (ds == 'sent') {
      return OutboundPhase.sent;
    }
    if (ds == 'delivered') {
      return OutboundPhase.delivered;
    }
    if (ds == 'read') {
      return OutboundPhase.read;
    }
    if (ds == null) {
      return id > 0 ? OutboundPhase.sent : OutboundPhase.pending;
    }
    return OutboundPhase.unknown;
  }

  bool get isOutbound => direction == 'outbound' && !isInternalNote;

  bool get isInternalNote =>
      direction == 'internal_note' || messageType == 'system';

  bool get isFromEmployee =>
      isOutbound && !aiGenerated && workerEngagementId == null;

  bool get isFromAi => aiGenerated || participantType == 'ai_employee';

  bool get isFromWorker =>
      workerEngagementId != null || participantType == 'independent_worker';

  bool get isFromCustomer => !isOutbound && !isInternalNote;

  String get senderDisplayName {
    if (isFromAi) {
      final name = userName?.trim();
      return (name != null && name.isNotEmpty) ? name : 'مشعل AI';
    }
    if (isFromWorker) {
      final name = userName?.trim();
      return (name != null && name.isNotEmpty) ? name : 'موظف مستقل';
    }
    if (isFromEmployee) {
      final name = userName?.trim();
      return (name != null && name.isNotEmpty) ? name : 'الموظف';
    }
    final name = (customerName?.trim().isNotEmpty == true)
        ? customerName!.trim()
        : (userName?.trim().isNotEmpty == true ? userName!.trim() : 'العميل');
    return name;
  }

  String get senderRoleBadge {
    if (isFromAi) return 'ذكاء اصطناعي';
    if (isFromWorker) return 'موظف مستقل';
    if (isFromEmployee) return 'موظف';
    return 'عميل';
  }

  static Map<String, dynamic> _unwrapMessageJson(Map<String, dynamic> json) {
    final nested = json['data'];
    if (json['id'] == null && nested is Map) {
      return nested is Map<String, dynamic>
          ? nested
          : Map<String, dynamic>.from(nested);
    }
    return json;
  }

  static String? _messageClientId(Map<String, dynamic> json) {
    final raw = json['client_id'] ?? json['idempotency_key'];
    if (raw == null) return null;
    final text = raw.toString().trim();
    if (text.isEmpty || text == 'null') return null;
    return text;
  }

  factory MessageModel.fromJson(Map<String, dynamic> json) {
    final payload = _unwrapMessageJson(json);
    final attachmentsRaw = payload['attachments'];
    final attachmentRows =
        attachmentsRaw is Map && attachmentsRaw['data'] is List
        ? attachmentsRaw['data'] as List
        : attachmentsRaw;
    final attachments = <MessageAttachmentModel>[];
    if (attachmentRows is List) {
      for (final item in attachmentRows) {
        if (item is! Map) continue;
        final row = item is Map<String, dynamic>
            ? item
            : Map<String, dynamic>.from(item);
        final attachmentId = row['id'];
        if (attachmentId is! num) continue;
        try {
          attachments.add(MessageAttachmentModel.fromJson(row));
        } catch (_) {
          // A broken attachment must not drop the customer text around it.
        }
      }
    }

    final clientId = _messageClientId(payload);

    return MessageModel(
      id: asInt(payload['id']),
      conversationId: asInt(payload['conversation_id']),
      direction:
          payload['direction']?.toString().trim().toLowerCase() ?? 'inbound',
      messageType: payload['message_type']?.toString() ?? 'text',
      content: payload['content']?.toString() ?? '',
      aiGenerated:
          payload['ai_generated'] == true || payload['ai_generated'] == 1,
      deliveryStatus: payload['delivery_status']?.toString(),
      deliveredAt: DateTime.tryParse(payload['delivered_at']?.toString() ?? ''),
      readAt: DateTime.tryParse(payload['read_at']?.toString() ?? ''),
      createdAt: DateTime.tryParse(payload['created_at']?.toString() ?? ''),
      userName: payload['sender_name']?.toString() ??
          payload['user_name']?.toString() ??
          (payload['user'] is Map
              ? payload['user']['name']?.toString()
              : null),
      customerName: payload['customer_name']?.toString() ??
          (payload['customer'] is Map
              ? payload['customer']['name']?.toString()
              : null),
      attachments: attachments,
      localError: payload['delivery_error']?.toString() ??
          payload['error_reason']?.toString(),
      clientId: clientId,
      correlationId: payload['correlation_id']?.toString(),
      workerEngagementId: asNullableInt(payload['worker_engagement_id']),
      senderUserId: asNullableInt(payload['user_id']) ??
          (payload['user'] is Map
              ? asNullableInt(payload['user']['id'])
              : null),
      participantType: payload['participant_type']?.toString(),
      location: payload['location'] is Map
          ? MessageLocationModel.fromJson(
              Map<String, dynamic>.from(payload['location'] as Map),
            )
          : null,
    );
  }

  MessageModel copyWith({
    int? id,
    bool? localPending,
    bool? localSending,
    bool? localFailed,
    bool? localUploading,
    String? content,
    String? deliveryStatus,
    bool clearDeliveryStatus = false,
    DateTime? deliveredAt,
    DateTime? readAt,
    List<MessageAttachmentModel>? attachments,
    String? localError,
    bool clearLocalError = false,
    String? clientId,
    String? correlationId,
    DateTime? createdAt,
    String? localAttachmentPath,
    bool clearLocalAttachmentPath = false,
    MessageLocationModel? location,
  }) {
    return MessageModel(
      id: id ?? this.id,
      conversationId: conversationId,
      direction: direction,
      messageType: messageType,
      content: content ?? this.content,
      aiGenerated: aiGenerated,
      deliveryStatus: clearDeliveryStatus
          ? null
          : (deliveryStatus ?? this.deliveryStatus),
      deliveredAt: deliveredAt ?? this.deliveredAt,
      readAt: readAt ?? this.readAt,
      createdAt: createdAt ?? this.createdAt,
      userName: userName,
      customerName: customerName,
      attachments: attachments ?? this.attachments,
      localPending: localPending ?? this.localPending,
      localSending: localSending ?? this.localSending,
      localFailed: localFailed ?? this.localFailed,
      localUploading: localUploading ?? this.localUploading,
      localAttachmentPath: clearLocalAttachmentPath
          ? null
          : (localAttachmentPath ?? this.localAttachmentPath),
      localError: clearLocalError ? null : (localError ?? this.localError),
      clientId: clientId ?? this.clientId,
      correlationId: correlationId ?? this.correlationId,
      workerEngagementId: workerEngagementId,
      senderUserId: senderUserId,
      participantType: participantType,
      location: location ?? this.location,
    );
  }

  Map<String, dynamic> toCacheJson() => {
    'id': id,
    'conversation_id': conversationId,
    'direction': direction,
    'message_type': messageType,
    'content': content,
    'ai_generated': aiGenerated,
    if (deliveryStatus != null) 'delivery_status': deliveryStatus,
    if (localError != null) 'delivery_error': localError,
    if (deliveredAt != null) 'delivered_at': deliveredAt!.toIso8601String(),
    if (readAt != null) 'read_at': readAt!.toIso8601String(),
    'created_at': createdAt?.toIso8601String(),
    if (location != null) 'location': location!.toJson(),
    'attachments': attachments
        .map(
          (a) => {
            'id': a.id,
            'kind': a.kind,
            'original_name': a.originalName,
            'mime_type': a.mimeType,
            'size_bytes': a.sizeBytes,
            'duration_ms': a.durationMs,
            'status': a.status,
            'download_url': a.downloadUrl,
            'preview_url': a.previewUrl,
          },
        )
        .toList(),
  };

  @override
  List<Object?> get props => [
    id,
    clientId,
    content,
    localPending,
    localSending,
    localFailed,
    localError,
    deliveryStatus,
    attachments.length,
    location,
  ];
}

enum OutboundPhase {
  pending,
  sending,
  accepted,
  sent,
  delivered,
  read,
  failed,
  unknown,
}

class MessageStatusUpdate extends Equatable {
  const MessageStatusUpdate({
    required this.id,
    required this.conversationId,
    this.workspaceId,
    this.clientId,
    required this.deliveryStatus,
    this.deliveredAt,
    this.readAt,
    this.errorReason,
  });

  final int id;
  final int conversationId;
  final int? workspaceId;
  final String? clientId;
  final String deliveryStatus;
  final DateTime? deliveredAt;
  final DateTime? readAt;
  final String? errorReason;

  factory MessageStatusUpdate.fromJson(Map<String, dynamic> json) {
    final cid = json['client_id']?.toString().trim() ??
        json['idempotency_key']?.toString().trim();
    return MessageStatusUpdate(
      id: asInt(json['id']),
      conversationId: asInt(json['conversation_id']),
      workspaceId: asNullableInt(json['workspace_id']),
      clientId: (cid != null && cid.isNotEmpty && cid != 'null') ? cid : null,
      deliveryStatus: json['delivery_status']?.toString().toLowerCase() ?? 'sent',
      deliveredAt: DateTime.tryParse(json['delivered_at']?.toString() ?? ''),
      readAt: DateTime.tryParse(json['read_at']?.toString() ?? ''),
      errorReason: json['error_reason']?.toString() ??
          json['delivery_error']?.toString(),
    );
  }

  @override
  List<Object?> get props => [
    id,
    conversationId,
    clientId,
    deliveryStatus,
    deliveredAt,
    readAt,
    errorReason,
  ];
}

class ConversationResponder extends Equatable {
  const ConversationResponder({
    required this.status,
    required this.type,
    required this.name,
    required this.label,
    this.badgeClass = '',
    this.dotClass = '',
    this.isAiGenerating = false,
    this.isTyping = false,
    this.userId,
    this.userInitials,
    this.assignedAtHuman,
    this.reason,
  });

  final String status;
  final String type;
  final String name;
  final String label;
  final String badgeClass;
  final String dotClass;
  final bool isAiGenerating;
  final bool isTyping;
  final int? userId;
  final String? userInitials;
  final String? assignedAtHuman;
  final String? reason;

  factory ConversationResponder.fromJson(Map<String, dynamic> json) {
    return ConversationResponder(
      status: json['status']?.toString() ?? 'waiting',
      type: json['type']?.toString() ?? 'none',
      name: json['name']?.toString() ?? '—',
      label: json['label']?.toString() ?? 'بانتظار الرد',
      badgeClass: json['badge_class']?.toString() ?? '',
      dotClass: json['dot_class']?.toString() ?? '',
      isAiGenerating: json['is_ai_generating'] == true,
      isTyping: json['is_typing'] == true,
      userId: asNullableInt(json['user_id']),
      userInitials: json['user_initials']?.toString(),
      assignedAtHuman: json['assigned_at_human']?.toString(),
      reason: json['reason']?.toString(),
    );
  }

  Map<String, dynamic> toJson() => {
    'status': status,
    'type': type,
    'name': name,
    'label': label,
    'badge_class': badgeClass,
    'dot_class': dotClass,
    'is_ai_generating': isAiGenerating,
    'is_typing': isTyping,
    if (userId != null) 'user_id': userId,
    if (userInitials != null) 'user_initials': userInitials,
    if (assignedAtHuman != null) 'assigned_at_human': assignedAtHuman,
    if (reason != null) 'reason': reason,
  };

  @override
  List<Object?> get props => [
    status,
    type,
    name,
    label,
    badgeClass,
    dotClass,
    isAiGenerating,
    isTyping,
    userId,
    userInitials,
    assignedAtHuman,
    reason,
  ];
}

class ConversationModel extends Equatable {
  const ConversationModel({
    required this.id,
    required this.channel,
    required this.status,
    this.workspaceId,
    this.externalId,
    this.aiEnabled = false,
    this.aiReplying,
    this.lastMessageAt,
    this.customer,
    this.unreadCount = 0,
    this.lastMessage,
    this.muted = false,
    this.archived = false,
    this.customerPriority = 'normal',
    this.paymentStatus = 'unpaid',
    this.reviewStatus = 'no_review_needed',
    this.classificationSource = 'system',
    this.handoffStatus = 'none',
    this.handoffReason,
    this.handoffVersion = 0,
    this.assignedUserId,
    this.assignedUserName,
    this.assignedWorkerEngagementId,
    this.assignedParticipantType,
    this.canReply = true,
    this.responder,
    this.hasUnreadCount = true,
  });

  final int id;
  final int? workspaceId;
  final String channel;
  final String status;
  final String? externalId;
  final bool aiEnabled;
  final bool? aiReplying;
  final String handoffStatus;
  final String? handoffReason;
  final int handoffVersion;
  final int? assignedUserId;
  final String? assignedUserName;
  final int? assignedWorkerEngagementId;
  final String? assignedParticipantType;
  final bool canReply;
  final ConversationResponder? responder;
  final bool hasUnreadCount;
  final DateTime? lastMessageAt;
  final CustomerModel? customer;
  final int unreadCount;
  final MessageModel? lastMessage;
  final bool muted;
  final bool archived;
  final String customerPriority;
  final String paymentStatus;
  final String reviewStatus;
  final String classificationSource;

  String get title => customer?.name ?? externalId ?? 'محادثة #$id';

  String get preview {
    final text = splitQuotedReply(lastMessage?.content.trim() ?? '').body;
    if (text.isNotEmpty) return text;
    return 'لا توجد رسائل';
  }

  String get channelLabel {
    switch (channel) {
      case 'whatsapp':
        return 'واتساب';
      case 'instagram':
        return 'إنستغرام';
      case 'facebook_messenger':
        return 'ماسنجر';
      case 'telegram':
        return 'تلغرام';
      case 'tiktok':
        return 'تيك توك';
      case 'email':
        return 'بريد';
      case 'web':
        return 'ويب';
      case 'manual':
        return 'يدوي';
      default:
        return channel;
    }
  }

  factory ConversationModel.fromJson(Map<String, dynamic> json) {
    final convId = asInt(json['id']);
    MessageModel? last;
    final lmd = json['last_message_data'];
    final lm = json['last_message'];
    if (lmd is Map) {
      try {
        last = MessageModel.fromJson(
          lmd is Map<String, dynamic> ? lmd : Map<String, dynamic>.from(lmd),
        );
      } catch (_) {}
    } else if (lm is Map) {
      try {
        last = MessageModel.fromJson(
          lm is Map<String, dynamic> ? lm : Map<String, dynamic>.from(lm),
        );
      } catch (_) {}
    } else if (lm is String && lm.trim().isNotEmpty) {
      last = MessageModel(
        id: -1,
        conversationId: convId,
        content: lm.trim(),
        direction: json['last_message_direction']?.toString() ?? 'inbound',
        messageType: 'text',
        createdAt: DateTime.tryParse(json['last_message_at']?.toString() ?? ''),
      );
    } else if (json['last_message_preview'] is String &&
        (json['last_message_preview'] as String).trim().isNotEmpty) {
      last = MessageModel(
        id: -1,
        conversationId: convId,
        content: (json['last_message_preview'] as String).trim(),
        direction: json['last_message_direction']?.toString() ?? 'inbound',
        messageType: 'text',
        createdAt: DateTime.tryParse(json['last_message_at']?.toString() ?? ''),
      );
    }
    final customerRaw = json['customer'];
    CustomerModel? customer;
    if (customerRaw is Map) {
      try {
        customer = CustomerModel.fromJson(
          customerRaw is Map<String, dynamic>
              ? customerRaw
              : Map<String, dynamic>.from(customerRaw),
        );
      } catch (_) {}
    }

    ConversationResponder? responder;
    if (json['responder'] is Map) {
      try {
        responder = ConversationResponder.fromJson(
          json['responder'] is Map<String, dynamic>
              ? json['responder']
              : Map<String, dynamic>.from(json['responder']),
        );
      } catch (_) {}
    }

    final assignedUserId = asNullableInt(json['assigned_user_id']) ??
        (json['assigned_user'] is Map
            ? asNullableInt((json['assigned_user'] as Map)['id'])
            : null);

    return ConversationModel(
      id: convId,
      workspaceId: asNullableInt(json['workspace_id']),
      channel: json['channel']?.toString() ?? 'web',
      status: json['status']?.toString() ?? 'open',
      externalId: json['external_id']?.toString(),
      aiEnabled: json['ai_enabled'] == true,
      aiReplying: json.containsKey('ai_replying')
          ? json['ai_replying'] == true
          : null,
      lastMessageAt: DateTime.tryParse(
        json['last_message_at']?.toString() ?? '',
      ),
      customer: customer,
      unreadCount: asInt(json['unread_count'], 0),
      lastMessage: last,
      muted: json['muted'] == true,
      archived: json['archived'] == true,
      customerPriority: json['customer_priority']?.toString() ?? 'normal',
      paymentStatus: json['payment_status']?.toString() ?? 'unpaid',
      reviewStatus: json['review_status']?.toString() ?? 'no_review_needed',
      classificationSource:
          json['classification_source']?.toString() ?? 'system',
      handoffStatus: json['handoff_status']?.toString() ?? 'none',
      handoffReason: json['handoff_reason']?.toString(),
      handoffVersion: asInt(json['handoff_version'], 0),
      assignedUserId: assignedUserId,
      assignedUserName: (json['assigned_user'] is Map
              ? (json['assigned_user'] as Map)['name']?.toString()
              : null) ??
          json['assigned_user_name']?.toString(),
      assignedWorkerEngagementId:
          asNullableInt(json['assigned_worker_engagement_id']),
      assignedParticipantType: json['assigned_participant_type']?.toString(),
      canReply: json['can_reply'] == null ? true : json['can_reply'] == true,
      responder: responder,
      hasUnreadCount: json.containsKey('unread_count'),
    );
  }

  ConversationResponder get effectiveResponder {
    if (responder != null) return responder!;
    if (status == 'closed' || archived) {
      return const ConversationResponder(
        status: 'offline',
        type: 'none',
        name: '—',
        label: 'غير متصل',
      );
    }
    if (isHumanHandoffActive) {
      if (assignedUserId != null || assignedUserName != null) {
        return ConversationResponder(
          status: 'human_active',
          type: 'human',
          name: assignedUserName ?? 'الموظف',
          label: 'الموظف يرد الآن',
          userId: assignedUserId,
          userInitials:
              (assignedUserName != null && assignedUserName!.isNotEmpty)
              ? assignedUserName!.trim().substring(0, 1)
              : 'م',
        );
      }
      return const ConversationResponder(
        status: 'human_needed',
        type: 'none',
        name: 'مشعل AI',
        label: 'تحتاج إلى تدخل بشري',
      );
    }
    if (isAiReplying) {
      return const ConversationResponder(
        status: 'ai_active',
        type: 'ai',
        name: 'مشعل AI',
        label: 'AI يرد الآن',
      );
    }
    return const ConversationResponder(
      status: 'waiting',
      type: 'none',
      name: '—',
      label: 'بانتظار الرد',
    );
  }

  bool get isHumanHandoffActive =>
      handoffStatus != 'none' && handoffStatus != 'resolved';

  /// Backend-effective AI reply state. Falls back to local derivation when
  /// cached rows predate `ai_replying`.
  bool get isAiReplying {
    if (aiReplying != null) {
      return aiReplying!;
    }
    return status == 'open' &&
        !archived &&
        aiEnabled &&
        handoffStatus == 'none';
  }

  Map<String, dynamic> toCacheJson() => {
    'id': id,
    if (workspaceId != null) 'workspace_id': workspaceId,
    'channel': channel,
    'status': status,
    'external_id': externalId,
    'ai_enabled': aiEnabled,
    if (aiReplying != null) 'ai_replying': aiReplying,
    'last_message_at': lastMessageAt?.toIso8601String(),
    'unread_count': unreadCount,
    'muted': muted,
    'archived': archived,
    'customer_priority': customerPriority,
    'payment_status': paymentStatus,
    'review_status': reviewStatus,
    'classification_source': classificationSource,
    'handoff_status': handoffStatus,
    if (handoffReason != null) 'handoff_reason': handoffReason,
    if (handoffVersion > 0) 'handoff_version': handoffVersion,
    if (assignedUserId != null) 'assigned_user_id': assignedUserId,
    if (assignedUserName != null)
      'assigned_user': {'id': assignedUserId, 'name': assignedUserName},
    if (assignedWorkerEngagementId != null)
      'assigned_worker_engagement_id': assignedWorkerEngagementId,
    if (assignedParticipantType != null)
      'assigned_participant_type': assignedParticipantType,
    'can_reply': canReply,
    if (customer != null)
      'customer': {
        'id': customer!.id,
        'name': customer!.name,
        'phone': customer!.phone,
        'email': customer!.email,
      },
    if (lastMessage != null) 'last_message': lastMessage!.toCacheJson(),
    if (responder != null) 'responder': responder!.toJson(),
  };

  ConversationModel copyWith({
    int? workspaceId,
    int? unreadCount,
    DateTime? lastMessageAt,
    MessageModel? lastMessage,
    bool? muted,
    bool? archived,
    String? customerPriority,
    String? paymentStatus,
    String? reviewStatus,
    String? classificationSource,
    String? handoffStatus,
    String? handoffReason,
    int? handoffVersion,
    int? assignedUserId,
    bool clearAssignedUser = false,
    String? assignedUserName,
    int? assignedWorkerEngagementId,
    bool clearAssignedWorkerEngagement = false,
    String? assignedParticipantType,
    bool? aiEnabled,
    bool? aiReplying,
    bool? canReply,
    String? status,
    ConversationResponder? responder,
  }) {
    return ConversationModel(
      id: id,
      workspaceId: workspaceId ?? this.workspaceId,
      channel: channel,
      status: status ?? this.status,
      externalId: externalId,
      aiEnabled: aiEnabled ?? this.aiEnabled,
      aiReplying: aiReplying ?? this.aiReplying,
      lastMessageAt: lastMessageAt ?? this.lastMessageAt,
      customer: customer,
      unreadCount: unreadCount ?? this.unreadCount,
      lastMessage: lastMessage ?? this.lastMessage,
      muted: muted ?? this.muted,
      archived: archived ?? this.archived,
      customerPriority: customerPriority ?? this.customerPriority,
      paymentStatus: paymentStatus ?? this.paymentStatus,
      reviewStatus: reviewStatus ?? this.reviewStatus,
      classificationSource: classificationSource ?? this.classificationSource,
      handoffStatus: handoffStatus ?? this.handoffStatus,
      handoffReason: handoffReason ?? this.handoffReason,
      handoffVersion: handoffVersion ?? this.handoffVersion,
      assignedUserId:
          clearAssignedUser ? null : (assignedUserId ?? this.assignedUserId),
      assignedUserName:
          clearAssignedUser ? null : (assignedUserName ?? this.assignedUserName),
      assignedWorkerEngagementId: clearAssignedWorkerEngagement
          ? null
          : (assignedWorkerEngagementId ?? this.assignedWorkerEngagementId),
      assignedParticipantType: clearAssignedUser
          ? null
          : (assignedParticipantType ?? this.assignedParticipantType),
      canReply: canReply ?? this.canReply,
      responder: responder ?? this.responder,
    );
  }

  /// Keep list/chat fields that classification/handoff responses omit, while accepting server updates.
  ConversationModel applyingServerWrites(ConversationModel remote) {
    final existingLastId = lastMessage?.id ?? 0;
    final remoteLastId = remote.lastMessage?.id ?? 0;
    final keepExistingLast =
        existingLastId > 0 &&
        remoteLastId < existingLastId &&
        (lastMessageAt == null ||
            remote.lastMessageAt == null ||
            !remote.lastMessageAt!.isAfter(lastMessageAt!));
    final keepNewerHandoff =
        handoffVersion > 0 &&
        remote.handoffVersion > 0 &&
        remote.handoffVersion < handoffVersion;
    final nextVersion = math.max(handoffVersion, remote.handoffVersion);
    return ConversationModel(
      id: remote.id,
      workspaceId: remote.workspaceId ?? workspaceId,
      channel: remote.channel,
      status: keepNewerHandoff ? status : remote.status,
      externalId: remote.externalId ?? externalId,
      aiEnabled: keepNewerHandoff ? aiEnabled : remote.aiEnabled,
      aiReplying:
          keepNewerHandoff ? aiReplying : (remote.aiReplying ?? aiReplying),
      lastMessageAt: keepExistingLast
          ? (lastMessageAt ?? remote.lastMessageAt)
          : (remote.lastMessageAt ?? lastMessageAt),
      customer: remote.customer ?? customer,
      unreadCount: unreadCount,
      lastMessage: keepExistingLast
          ? lastMessage
          : (remote.lastMessage ?? lastMessage),
      muted: muted,
      archived: archived,
      customerPriority: remote.customerPriority,
      paymentStatus: remote.paymentStatus,
      reviewStatus: remote.reviewStatus,
      classificationSource: remote.classificationSource,
      handoffStatus: keepNewerHandoff ? handoffStatus : remote.handoffStatus,
      handoffReason: keepNewerHandoff ? handoffReason : remote.handoffReason,
      handoffVersion: nextVersion,
      assignedUserId:
          keepNewerHandoff ? assignedUserId : remote.assignedUserId,
      assignedUserName: keepNewerHandoff
          ? assignedUserName
          : (remote.assignedUserId == null
                ? remote.assignedUserName
                : (remote.assignedUserName ?? assignedUserName)),
      assignedWorkerEngagementId: keepNewerHandoff
          ? assignedWorkerEngagementId
          : (remote.assignedUserId == null
                ? remote.assignedWorkerEngagementId
                : (remote.assignedWorkerEngagementId ??
                      assignedWorkerEngagementId)),
      assignedParticipantType: keepNewerHandoff
          ? assignedParticipantType
          : (remote.assignedUserId == null
                ? remote.assignedParticipantType
                : (remote.assignedParticipantType ?? assignedParticipantType)),
      canReply: canReply,
      responder: keepNewerHandoff ? responder : (remote.responder ?? responder),
    );
  }

  /// Local inbox rows omit classification/handoff; overlay only cache-backed fields.
  ConversationModel overlayingLocalInbox(ConversationModel local) {
    return copyWith(
      unreadCount: local.unreadCount,
      muted: local.muted,
      archived: local.archived,
      lastMessage: local.lastMessage ?? lastMessage,
      lastMessageAt: local.lastMessageAt ?? lastMessageAt,
    );
  }

  @override
  List<Object?> get props => [
    id,
    workspaceId,
    unreadCount,
    lastMessageAt,
    muted,
    archived,
    customerPriority,
    paymentStatus,
    reviewStatus,
    handoffStatus,
    handoffVersion,
    aiEnabled,
    assignedUserId,
    responder,
  ];
}

class HomeSnapshot extends Equatable {
  const HomeSnapshot({
    this.unreadConversations = 0,
    this.unreadEmail = 0,
    this.unreadNotifications = 0,
  });

  final int unreadConversations;
  final int unreadEmail;
  final int unreadNotifications;

  factory HomeSnapshot.fromJson(Map<String, dynamic> json) => HomeSnapshot(
    unreadConversations: (json['unread_conversations'] as num?)?.toInt() ?? 0,
    unreadEmail: (json['unread_email'] as num?)?.toInt() ?? 0,
    unreadNotifications: (json['unread_notifications'] as num?)?.toInt() ?? 0,
  );

  HomeSnapshot copyWith({
    int? unreadConversations,
    int? unreadEmail,
    int? unreadNotifications,
  }) {
    return HomeSnapshot(
      unreadConversations: unreadConversations ?? this.unreadConversations,
      unreadEmail: unreadEmail ?? this.unreadEmail,
      unreadNotifications: unreadNotifications ?? this.unreadNotifications,
    );
  }

  @override
  List<Object?> get props => [
    unreadConversations,
    unreadEmail,
    unreadNotifications,
  ];
}

class EmailMessageModel extends Equatable {
  const EmailMessageModel({
    required this.id,
    required this.sender,
    required this.recipient,
    this.subject,
    this.type,
    this.preview,
    this.body,
    this.isRead = true,
    this.isStarred = false,
    this.createdAt,
    this.emailAccountId,
  });

  final int id;
  final String sender;
  final String recipient;
  final String? subject;
  final String? type;
  final String? preview;
  final String? body;
  final bool isRead;
  final bool isStarred;
  final DateTime? createdAt;
  final int? emailAccountId;

  factory EmailMessageModel.fromJson(Map<String, dynamic> json) =>
      EmailMessageModel(
        id: (json['id'] as num).toInt(),
        sender: json['sender']?.toString() ?? '',
        recipient: json['recipient']?.toString() ?? '',
        subject: json['subject']?.toString(),
        type: json['type']?.toString(),
        preview: json['preview']?.toString(),
        body: json['body']?.toString(),
        isRead: json['is_read'] != false,
        isStarred: json['is_starred'] == true,
        createdAt: DateTime.tryParse(json['created_at']?.toString() ?? ''),
        emailAccountId: (json['email_account_id'] as num?)?.toInt(),
      );

  EmailMessageModel copyWith({bool? isRead, bool? isStarred}) =>
      EmailMessageModel(
        id: id,
        sender: sender,
        recipient: recipient,
        subject: subject,
        type: type,
        preview: preview,
        body: body,
        isRead: isRead ?? this.isRead,
        isStarred: isStarred ?? this.isStarred,
        createdAt: createdAt,
        emailAccountId: emailAccountId,
      );

  @override
  List<Object?> get props => [id, isRead, isStarred];
}

class AppNotificationModel extends Equatable {
  const AppNotificationModel({
    required this.id,
    required this.type,
    this.data = const {},
    this.readAt,
    this.createdAt,
  });

  final String id;
  final String type;
  final Map<String, dynamic> data;
  final DateTime? readAt;
  final DateTime? createdAt;

  bool get isRead => readAt != null;

  String get title {
    final t = data['title'] ?? data['subject'] ?? type;
    return t.toString();
  }

  String get body {
    final b = data['body'] ?? data['message'] ?? '';
    return b.toString();
  }

  factory AppNotificationModel.fromJson(Map<String, dynamic> json) =>
      AppNotificationModel(
        id: json['id']?.toString() ?? '',
        type: json['type']?.toString() ?? '',
        data: json['data'] is Map<String, dynamic>
            ? json['data'] as Map<String, dynamic>
            : {},
        readAt: DateTime.tryParse(json['read_at']?.toString() ?? ''),
        createdAt: DateTime.tryParse(json['created_at']?.toString() ?? ''),
      );

  @override
  List<Object?> get props => [id, readAt];
}

class DeviceSessionModel extends Equatable {
  const DeviceSessionModel({
    required this.id,
    required this.name,
    this.deviceName,
    this.deviceType,
    this.lastUsedAt,
    this.isCurrent = false,
  });

  final int id;
  final String name;
  final String? deviceName;
  final String? deviceType;
  final DateTime? lastUsedAt;
  final bool isCurrent;

  factory DeviceSessionModel.fromJson(Map<String, dynamic> json) =>
      DeviceSessionModel(
        id: (json['id'] as num).toInt(),
        name: json['name']?.toString() ?? 'جلسة',
        deviceName: json['device_name']?.toString(),
        deviceType: json['device_type']?.toString(),
        lastUsedAt: DateTime.tryParse(json['last_used_at']?.toString() ?? ''),
        isCurrent: json['is_current'] == true,
      );

  @override
  List<Object?> get props => [id];
}

class MobileParticipant {
  const MobileParticipant({required this.type, this.workerProfileId});

  final String type;
  final int? workerProfileId;

  bool get isIndependentWorker => type == 'independent_worker';

  factory MobileParticipant.fromJson(Map<String, dynamic>? json) {
    final type = json?['type']?.toString();
    final rawId = json?['worker_profile_id'];
    return MobileParticipant(
      type: type == null || type.isEmpty ? 'company_staff' : type,
      workerProfileId: rawId is num ? rawId.toInt() : null,
    );
  }
}

class LoginResult {
  LoginResult({
    required this.token,
    required this.user,
    required this.workspaces,
    required this.participant,
    this.workspace,
  });

  final String token;
  final UserModel user;
  final MobileParticipant participant;
  final WorkspaceModel? workspace;
  final List<WorkspaceModel> workspaces;

  factory LoginResult.fromJson(Map<String, dynamic> json) {
    final workspaces = <WorkspaceModel>[];
    final rawList = json['workspaces'];
    if (rawList is List) {
      for (final item in rawList) {
        if (item is Map) {
          workspaces.add(
            WorkspaceModel.fromJson(
              item is Map<String, dynamic>
                  ? item
                  : Map<String, dynamic>.from(item),
            ),
          );
        }
      }
    }

    final userRaw = json['user'];
    if (userRaw is! Map) {
      throw const FormatException('login response missing user object');
    }

    final participantRaw = json['participant'];

    return LoginResult(
      token: json['token']?.toString() ?? '',
      user: UserModel.fromJson(
        userRaw is Map<String, dynamic>
            ? userRaw
            : Map<String, dynamic>.from(userRaw),
      ),
      participant: MobileParticipant.fromJson(
        participantRaw is Map
            ? Map<String, dynamic>.from(participantRaw)
            : null,
      ),
      workspace: json['workspace'] is Map
          ? WorkspaceModel.fromJson(
              json['workspace'] is Map<String, dynamic>
                  ? json['workspace'] as Map<String, dynamic>
                  : Map<String, dynamic>.from(json['workspace'] as Map),
            )
          : null,
      workspaces: workspaces,
    );
  }
}

List<Map<String, dynamic>> asMapList(dynamic raw) {
  if (raw is List) {
    return [
      for (final item in raw)
        if (item is Map)
          (item is Map<String, dynamic>
              ? item
              : Map<String, dynamic>.from(item)),
    ];
  }
  if (raw is Map) {
    final map = raw is Map<String, dynamic>
        ? raw
        : Map<String, dynamic>.from(raw);
    // Laravel Resource::collection sometimes wraps
    if (map['data'] is List) {
      return [
        for (final item in map['data'] as List)
          if (item is Map)
            (item is Map<String, dynamic>
                ? item
                : Map<String, dynamic>.from(item)),
      ];
    }
  }
  return const [];
}

Map<String, dynamic>? asMap(dynamic raw) {
  if (raw is! Map) return null;
  final map = raw is Map<String, dynamic>
      ? raw
      : Map<String, dynamic>.from(raw);
  final nested = map['data'];
  if (nested is Map) {
    return nested is Map<String, dynamic>
        ? nested
        : Map<String, dynamic>.from(nested);
  }
  return map;
}

int asInt(dynamic raw, [int fallback = 0]) {
  if (raw is num) return raw.toInt();
  if (raw is String) return int.tryParse(raw.trim()) ?? fallback;
  return fallback;
}

int? asNullableInt(dynamic raw) {
  if (raw == null) return null;
  if (raw is num) return raw.toInt();
  if (raw is String) return int.tryParse(raw.trim());
  return null;
}

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

  factory EmailAccountModel.fromJson(Map<String, dynamic> json) =>
      EmailAccountModel(
        id: (json['id'] as num).toInt(),
        name: json['name']?.toString() ?? '',
        email: json['email']?.toString() ?? '',
        brandColor: json['brand_color']?.toString(),
        logoUrl: json['logo_url']?.toString(),
      );

  @override
  List<Object?> get props => [id];
}

class ChannelModel extends Equatable {
  const ChannelModel({
    required this.key,
    required this.name,
    this.icon,
    this.connected = false,
    this.status = 'disconnected',
    this.statusLabel,
    this.hint,
    this.manageUrl,
    this.canConnectInApp = false,
    this.canManage = false,
    this.canDisconnect = false,
    this.connectionMode = 'web_only',
    this.displayName,
    this.phoneNumber,
  });

  final String key;
  final String name;
  final String? icon;
  final bool connected;
  final String status;
  final String? statusLabel;
  final String? hint;
  final String? manageUrl;
  final bool canConnectInApp;
  final bool canManage;
  final bool canDisconnect;
  final String connectionMode;
  final String? displayName;
  final String? phoneNumber;

  bool get isNativeConnect =>
      connectionMode == 'oauth' ||
      connectionMode == 'embedded_signup' ||
      connectionMode == 'bot_token';

  factory ChannelModel.fromJson(Map<String, dynamic> json) => ChannelModel(
    key: json['key']?.toString() ?? '',
    name: json['name']?.toString() ?? '',
    icon: json['icon']?.toString(),
    connected: json['connected'] == true,
    status: json['status']?.toString() ?? 'disconnected',
    statusLabel: json['status_label']?.toString(),
    hint: json['hint']?.toString(),
    manageUrl: json['manage_url']?.toString(),
    canConnectInApp: json['can_connect_in_app'] == true,
    canManage: json['can_manage'] == true,
    canDisconnect: json['can_disconnect'] == true,
    connectionMode: json['connection_mode']?.toString() ?? 'web_only',
    displayName: json['display_name']?.toString(),
    phoneNumber: json['phone_number']?.toString(),
  );

  @override
  List<Object?> get props => [
    key,
    status,
    connected,
    displayName,
    phoneNumber,
    canConnectInApp,
    canDisconnect,
    connectionMode,
  ];
}

class PlanCatalogItem extends Equatable {
  const PlanCatalogItem({
    required this.id,
    required this.code,
    required this.name,
    this.description,
    this.tier,
    this.billingPeriod,
    this.price,
    this.currency,
    this.features = const [],
    this.limits = const {},
  });

  final int id;
  final String code;
  final String name;
  final String? description;
  final String? tier;
  final String? billingPeriod;
  final num? price;
  final String? currency;
  final List<String> features;
  final Map<String, dynamic> limits;

  factory PlanCatalogItem.fromJson(Map<String, dynamic> json) =>
      PlanCatalogItem(
        id: (json['id'] as num?)?.toInt() ?? 0,
        code: json['code']?.toString() ?? '',
        name: json['name']?.toString() ?? '',
        description: json['description']?.toString(),
        tier: json['tier']?.toString(),
        billingPeriod: json['billing_period']?.toString(),
        price: json['price'] as num?,
        currency: json['currency']?.toString(),
        features: (json['features'] is List)
            ? (json['features'] as List).map((e) => e.toString()).toList()
            : const [],
        limits: json['limits'] is Map<String, dynamic>
            ? json['limits'] as Map<String, dynamic>
            : const {},
      );

  @override
  List<Object?> get props => [id, code];
}

class PlanSnapshot extends Equatable {
  const PlanSnapshot({
    this.features = const [],
    this.limits = const {},
    this.meters = const {},
    this.raw = const {},
  });

  final List<String> features;
  final Map<String, dynamic> limits;
  final Map<String, dynamic> meters;
  final Map<String, dynamic> raw;

  factory PlanSnapshot.fromJson(Map<String, dynamic> json) {
    final featuresRaw = json['features'];
    List<String> features = const [];
    if (featuresRaw is List) {
      features = featuresRaw.map((e) => e.toString()).toList();
    } else if (featuresRaw is Map) {
      features = featuresRaw.entries
          .where((e) => e.value == true)
          .map((e) => e.key.toString())
          .toList();
    }
    return PlanSnapshot(
      features: features,
      limits: json['limits'] is Map<String, dynamic>
          ? json['limits'] as Map<String, dynamic>
          : const {},
      meters: json['meters'] is Map<String, dynamic>
          ? json['meters'] as Map<String, dynamic>
          : const {},
      raw: json,
    );
  }

  @override
  List<Object?> get props => [features, limits, meters];
}

class PlansCatalog extends Equatable {
  const PlansCatalog({this.plans = const [], this.comparison = const []});

  final List<PlanCatalogItem> plans;
  final List<Map<String, dynamic>> comparison;

  factory PlansCatalog.fromJson(Map<String, dynamic> json) {
    final plans = asMapList(
      json['plans'],
    ).map(PlanCatalogItem.fromJson).toList();
    final comparisonRaw = json['comparison'] ?? json['comparison_rows'];
    final comparison = comparisonRaw is List
        ? comparisonRaw
              .whereType<Map>()
              .map((e) => Map<String, dynamic>.from(e))
              .toList()
        : <Map<String, dynamic>>[];
    return PlansCatalog(plans: plans, comparison: comparison);
  }

  @override
  List<Object?> get props => [plans, comparison];
}

class StoryAuthorModel extends Equatable {
  const StoryAuthorModel({
    required this.id,
    required this.name,
    this.avatarPath,
  });

  final int id;
  final String name;
  final String? avatarPath;

  factory StoryAuthorModel.fromJson(Map<String, dynamic> json) =>
      StoryAuthorModel(
        id: (json['id'] as num?)?.toInt() ?? 0,
        name: json['name']?.toString() ?? '',
        avatarPath:
            json['avatar_path']?.toString() ?? json['avatar_url']?.toString(),
      );

  @override
  List<Object?> get props => [id, name];
}

/// Mobile `GET /stories/{id}/viewers` row. Display name, avatar, and viewed_at only.
class StoryViewerEntry extends Equatable {
  const StoryViewerEntry({
    this.userId,
    required this.name,
    this.avatarUrl,
    this.viewedAt,
  });

  final int? userId;
  final String name;
  final String? avatarUrl;
  final DateTime? viewedAt;

  factory StoryViewerEntry.fromJson(Map<String, dynamic> json) {
    final rawUser = json['user'];
    final user = rawUser is Map
        ? Map<String, dynamic>.from(rawUser)
        : const <String, dynamic>{};
    return StoryViewerEntry(
      userId:
          (json['user_id'] as num?)?.toInt() ?? (user['id'] as num?)?.toInt(),
      name: user['name']?.toString() ?? '',
      avatarUrl: AppConfig.resolvePublicUrl(user['avatar_path']?.toString()),
      viewedAt: DateTime.tryParse(json['viewed_at']?.toString() ?? ''),
    );
  }

  @override
  List<Object?> get props => [userId, name, avatarUrl, viewedAt];
}

class StoryModel extends Equatable {
  const StoryModel({
    required this.id,
    required this.type,
    this.caption,
    this.bodyText,
    this.backgroundColor,
    this.mediaUrl,
    this.mediaMime,
    this.mediaSize,
    this.thumbnailUrl,
    this.visibility = 'workspace',
    this.selectedUserIds = const [],
    this.hiddenUserIds = const [],
    this.expiresAt,
    this.viewsCount = 0,
    this.status,
    this.createdAt,
    this.author,
    this.isMine = false,
    this.viewedByMe = false,
  });

  final int id;
  final String type;
  final String? caption;
  final String? bodyText;
  final String? backgroundColor;
  final String? mediaUrl;
  final String? mediaMime;
  final int? mediaSize;
  final String? thumbnailUrl;
  final String visibility;
  final List<int> selectedUserIds;
  final List<int> hiddenUserIds;
  final DateTime? expiresAt;
  final int viewsCount;
  final String? status;
  final DateTime? createdAt;
  final StoryAuthorModel? author;
  final bool isMine;
  final bool viewedByMe;

  bool get isText => type == 'text';
  bool get isImage => type == 'image';
  bool get isVideo => type == 'video';

  factory StoryModel.fromJson(Map<String, dynamic> json) {
    List<int> ids(dynamic raw) {
      if (raw is! List) return const [];
      return raw.map((e) => (e as num?)?.toInt()).whereType<int>().toList();
    }

    return StoryModel(
      id: (json['id'] as num?)?.toInt() ?? 0,
      type: json['type']?.toString() ?? 'text',
      caption: json['caption']?.toString(),
      bodyText: json['body_text']?.toString(),
      backgroundColor: json['background_color']?.toString(),
      mediaUrl: json['media_url']?.toString(),
      mediaMime: json['media_mime']?.toString(),
      mediaSize: (json['media_size'] as num?)?.toInt(),
      thumbnailUrl: json['thumbnail_url']?.toString(),
      visibility: json['visibility']?.toString() ?? 'workspace',
      selectedUserIds: ids(json['selected_user_ids']),
      hiddenUserIds: ids(json['hidden_user_ids']),
      expiresAt: DateTime.tryParse(json['expires_at']?.toString() ?? ''),
      viewsCount: (json['views_count'] as num?)?.toInt() ?? 0,
      status: json['status']?.toString(),
      createdAt: DateTime.tryParse(json['created_at']?.toString() ?? ''),
      author: json['author'] is Map<String, dynamic>
          ? StoryAuthorModel.fromJson(json['author'] as Map<String, dynamic>)
          : null,
      isMine: json['is_mine'] == true,
      viewedByMe: json['viewed_by_me'] == true || json['is_mine'] == true,
    );
  }

  StoryModel copyWith({bool? viewedByMe, int? viewsCount}) {
    return StoryModel(
      id: id,
      type: type,
      caption: caption,
      bodyText: bodyText,
      backgroundColor: backgroundColor,
      mediaUrl: mediaUrl,
      mediaMime: mediaMime,
      mediaSize: mediaSize,
      thumbnailUrl: thumbnailUrl,
      visibility: visibility,
      selectedUserIds: selectedUserIds,
      hiddenUserIds: hiddenUserIds,
      expiresAt: expiresAt,
      viewsCount: viewsCount ?? this.viewsCount,
      status: status,
      createdAt: createdAt,
      author: author,
      isMine: isMine,
      viewedByMe: viewedByMe ?? this.viewedByMe,
    );
  }

  @override
  List<Object?> get props => [id, type, viewsCount, isMine, viewedByMe, status];
}

class ContactGroupRef extends Equatable {
  const ContactGroupRef({required this.id, required this.name});

  final int id;
  final String name;

  factory ContactGroupRef.fromJson(Map<String, dynamic> json) =>
      ContactGroupRef(
        id: (json['id'] as num?)?.toInt() ?? 0,
        name: json['name']?.toString() ?? '',
      );

  @override
  List<Object?> get props => [id, name];
}

class EmailContactModel extends Equatable {
  const EmailContactModel({
    required this.id,
    required this.name,
    required this.email,
    this.normalizedEmail,
    this.phone,
    this.company,
    this.jobTitle,
    this.notes,
    this.isFavorite = false,
    this.avatarUrl,
    this.createdAt,
    this.updatedAt,
    this.groups = const [],
  });

  final int id;
  final String name;
  final String email;
  final String? normalizedEmail;
  final String? phone;
  final String? company;
  final String? jobTitle;
  final String? notes;
  final bool isFavorite;
  final String? avatarUrl;
  final DateTime? createdAt;
  final DateTime? updatedAt;
  final List<ContactGroupRef> groups;

  factory EmailContactModel.fromJson(Map<String, dynamic> json) {
    final groupsRaw = json['groups'];
    final groups = <ContactGroupRef>[];
    if (groupsRaw is List) {
      for (final item in groupsRaw) {
        if (item is Map<String, dynamic>) {
          groups.add(ContactGroupRef.fromJson(item));
        }
      }
    }

    return EmailContactModel(
      id: (json['id'] as num?)?.toInt() ?? 0,
      name: json['name']?.toString() ?? '',
      email: json['email']?.toString() ?? '',
      normalizedEmail: json['normalized_email']?.toString(),
      phone: json['phone']?.toString(),
      company: json['company']?.toString(),
      jobTitle: json['job_title']?.toString(),
      notes: json['notes']?.toString(),
      isFavorite: json['is_favorite'] == true,
      avatarUrl: json['avatar_url']?.toString(),
      createdAt: DateTime.tryParse(json['created_at']?.toString() ?? ''),
      updatedAt: DateTime.tryParse(json['updated_at']?.toString() ?? ''),
      groups: groups,
    );
  }

  EmailContactModel copyWith({
    bool? isFavorite,
    List<ContactGroupRef>? groups,
  }) {
    return EmailContactModel(
      id: id,
      name: name,
      email: email,
      normalizedEmail: normalizedEmail,
      phone: phone,
      company: company,
      jobTitle: jobTitle,
      notes: notes,
      isFavorite: isFavorite ?? this.isFavorite,
      avatarUrl: avatarUrl,
      createdAt: createdAt,
      updatedAt: updatedAt,
      groups: groups ?? this.groups,
    );
  }

  @override
  List<Object?> get props => [id, email, isFavorite, name];
}

class ContactGroupModel extends Equatable {
  const ContactGroupModel({
    required this.id,
    required this.name,
    this.description,
    this.contactsCount = 0,
    this.createdAt,
    this.updatedAt,
    this.contacts = const [],
  });

  final int id;
  final String name;
  final String? description;
  final int contactsCount;
  final DateTime? createdAt;
  final DateTime? updatedAt;
  final List<EmailContactModel> contacts;

  factory ContactGroupModel.fromJson(Map<String, dynamic> json) {
    final contactsRaw = json['contacts'];
    final contacts = <EmailContactModel>[];
    if (contactsRaw is List) {
      for (final item in contactsRaw) {
        if (item is Map<String, dynamic>) {
          contacts.add(EmailContactModel.fromJson(item));
        }
      }
    }

    return ContactGroupModel(
      id: (json['id'] as num?)?.toInt() ?? 0,
      name: json['name']?.toString() ?? '',
      description: json['description']?.toString(),
      contactsCount:
          (json['contacts_count'] as num?)?.toInt() ?? contacts.length,
      createdAt: DateTime.tryParse(json['created_at']?.toString() ?? ''),
      updatedAt: DateTime.tryParse(json['updated_at']?.toString() ?? ''),
      contacts: contacts,
    );
  }

  @override
  List<Object?> get props => [id, name, contactsCount];
}

class EmailCampaignModel extends Equatable {
  const EmailCampaignModel({
    required this.id,
    this.emailAccountId,
    this.subject,
    this.body,
    required this.status,
    this.recipientCount = 0,
    this.sentCount = 0,
    this.failedCount = 0,
    this.pendingCount,
    this.errorMessage,
    this.queuedAt,
    this.startedAt,
    this.completedAt,
    this.createdAt,
    this.accountName,
    this.accountEmail,
  });

  final int id;
  final int? emailAccountId;
  final String? subject;
  final String? body;
  final String status;
  final int recipientCount;
  final int sentCount;
  final int failedCount;
  final int? pendingCount;
  final String? errorMessage;
  final DateTime? queuedAt;
  final DateTime? startedAt;
  final DateTime? completedAt;
  final DateTime? createdAt;
  final String? accountName;
  final String? accountEmail;

  bool get isTerminal =>
      status == 'completed' || status == 'cancelled' || status == 'failed';

  String get statusLabel {
    switch (status) {
      case 'queued':
        return 'في الانتظار';
      case 'processing':
      case 'sending':
        return 'جارٍ الإرسال';
      case 'completed':
        return 'مكتمل';
      case 'cancelled':
        return 'ملغى';
      case 'failed':
        return 'فشل';
      default:
        return status;
    }
  }

  factory EmailCampaignModel.fromJson(Map<String, dynamic> json) {
    final account = json['account'];
    return EmailCampaignModel(
      id: (json['id'] as num?)?.toInt() ?? 0,
      emailAccountId: (json['email_account_id'] as num?)?.toInt(),
      subject: json['subject']?.toString(),
      body: json['body']?.toString(),
      status: json['status']?.toString() ?? 'queued',
      recipientCount: (json['recipient_count'] as num?)?.toInt() ?? 0,
      sentCount: (json['sent_count'] as num?)?.toInt() ?? 0,
      failedCount: (json['failed_count'] as num?)?.toInt() ?? 0,
      pendingCount: (json['pending_count'] as num?)?.toInt(),
      errorMessage: json['error_message']?.toString(),
      queuedAt: DateTime.tryParse(json['queued_at']?.toString() ?? ''),
      startedAt: DateTime.tryParse(json['started_at']?.toString() ?? ''),
      completedAt: DateTime.tryParse(json['completed_at']?.toString() ?? ''),
      createdAt: DateTime.tryParse(json['created_at']?.toString() ?? ''),
      accountName: account is Map ? account['name']?.toString() : null,
      accountEmail: account is Map ? account['email']?.toString() : null,
    );
  }

  @override
  List<Object?> get props => [
    id,
    status,
    sentCount,
    failedCount,
    recipientCount,
  ];
}

class RecentRecipientModel extends Equatable {
  const RecentRecipientModel({required this.email, this.name, this.contactId});

  final String email;
  final String? name;
  final int? contactId;

  factory RecentRecipientModel.fromJson(Map<String, dynamic> json) =>
      RecentRecipientModel(
        email: json['email']?.toString() ?? '',
        name: json['name']?.toString(),
        contactId:
            (json['contact_id'] as num?)?.toInt() ??
            (json['id'] as num?)?.toInt(),
      );

  @override
  List<Object?> get props => [email, contactId];
}

class PresentEmployeeModel extends Equatable {
  const PresentEmployeeModel({
    required this.id,
    required this.name,
    this.email,
    this.avatarUrl,
    required this.initials,
    this.role = 'participant',
    this.state = 'viewing',
    this.isAssigned = false,
    this.lastSeenAt,
  });

  final int id;
  final String name;
  final String? email;
  final String? avatarUrl;
  final String initials;
  final String role;
  final String state;
  final bool isAssigned;
  final int? lastSeenAt;

  factory PresentEmployeeModel.fromJson(Map<String, dynamic> json) =>
      PresentEmployeeModel(
        id: (json['id'] as num?)?.toInt() ?? 0,
        name: json['name']?.toString() ?? 'موظف',
        email: json['email']?.toString(),
        avatarUrl: AppConfig.resolvePublicUrl(json['avatar_url']?.toString()),
        initials: json['initials']?.toString() ??
            (json['name'] != null && json['name'].toString().isNotEmpty
                ? json['name'].toString().substring(0, 1)
                : 'م'),
        role: json['role']?.toString() ?? 'participant',
        state: json['state']?.toString() ?? 'viewing',
        isAssigned: json['is_assigned'] == true,
        lastSeenAt: (json['last_seen_at'] as num?)?.toInt(),
      );

  Map<String, dynamic> toJson() => {
    'id': id,
    'name': name,
    'email': email,
    'avatar_url': avatarUrl,
    'initials': initials,
    'role': role,
    'state': state,
    'is_assigned': isAssigned,
    'last_seen_at': lastSeenAt,
  };

  PresentEmployeeModel copyWith({
    int? id,
    String? name,
    String? email,
    String? avatarUrl,
    String? initials,
    String? role,
    String? state,
    bool? isAssigned,
    int? lastSeenAt,
  }) {
    return PresentEmployeeModel(
      id: id ?? this.id,
      name: name ?? this.name,
      email: email ?? this.email,
      avatarUrl: avatarUrl ?? this.avatarUrl,
      initials: initials ?? this.initials,
      role: role ?? this.role,
      state: state ?? this.state,
      isAssigned: isAssigned ?? this.isAssigned,
      lastSeenAt: lastSeenAt ?? this.lastSeenAt,
    );
  }

  @override
  List<Object?> get props => [id, name, state, isAssigned];
}
