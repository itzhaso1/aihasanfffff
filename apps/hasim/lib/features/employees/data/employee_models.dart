import 'package:equatable/equatable.dart';
import 'package:hasim/core/config/app_config.dart';

class EmployeeMemberModel extends Equatable {
  const EmployeeMemberModel({
    required this.id,
    required this.userId,
    required this.name,
    this.email,
    this.phone,
    this.avatarUrl,
    required this.membershipRole,
    required this.status,
    this.isOwner = false,
    this.createdAt,
  });

  final int id;
  final int userId;
  final String name;
  final String? email;
  final String? phone;
  final String? avatarUrl;
  final String membershipRole;
  final String status;
  final bool isOwner;
  final String? createdAt;

  String get roleLabel {
    return switch (membershipRole.toLowerCase()) {
      'owner' => 'مالك مساحة العمل',
      'admin' => 'مدير النظام',
      'manager' => 'مدير العمليات',
      'agent' => 'وكيل خدمة العملاء',
      'accountant' => 'محاسب',
      'receptionist' => 'موظف استقبال',
      'staff_doctor' => 'طبيب / اختصاصي',
      'staff' => 'موظف',
      _ => membershipRole,
    };
  }

  String get statusLabel {
    return switch (status.toLowerCase()) {
      'active' => 'نشط',
      'invited' => 'مدعو',
      'suspended' => 'معلق',
      _ => status,
    };
  }

  factory EmployeeMemberModel.fromJson(Map<String, dynamic> json) {
    return EmployeeMemberModel(
      id: (json['id'] as num).toInt(),
      userId: (json['user_id'] as num?)?.toInt() ?? 0,
      name: json['name']?.toString() ?? 'موظف',
      email: json['email']?.toString(),
      phone: json['phone']?.toString(),
      avatarUrl: AppConfig.resolvePublicUrl(json['avatar_url']?.toString()),
      membershipRole: json['membership_role']?.toString() ?? 'agent',
      status: json['status']?.toString() ?? 'active',
      isOwner: json['is_owner'] == true,
      createdAt: json['created_at']?.toString(),
    );
  }

  @override
  List<Object?> get props => [
    id,
    userId,
    name,
    email,
    membershipRole,
    status,
    isOwner,
  ];
}

class EmployeeInvitationModel extends Equatable {
  const EmployeeInvitationModel({
    required this.id,
    required this.email,
    required this.role,
    required this.status,
    this.expiresAt,
    this.createdAt,
  });

  final int id;
  final String email;
  final String role;
  final String status;
  final String? expiresAt;
  final String? createdAt;

  String get roleLabel {
    return switch (role.toLowerCase()) {
      'admin' => 'مدير النظام',
      'manager' => 'مدير العمليات',
      'agent' => 'وكيل خدمة العملاء',
      'accountant' => 'محاسب',
      'receptionist' => 'موظف استقبال',
      'staff_doctor' => 'طبيب / اختصاصي',
      'staff' => 'موظف',
      _ => role,
    };
  }

  factory EmployeeInvitationModel.fromJson(Map<String, dynamic> json) {
    return EmployeeInvitationModel(
      id: (json['id'] as num).toInt(),
      email: json['email']?.toString() ?? '',
      role: json['role']?.toString() ?? 'agent',
      status: json['status']?.toString() ?? 'pending',
      expiresAt: json['expires_at']?.toString(),
      createdAt: json['created_at']?.toString(),
    );
  }

  @override
  List<Object?> get props => [id, email, role, status, expiresAt];
}
