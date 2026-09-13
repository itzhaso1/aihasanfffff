class ApiResponse<T> {
  const ApiResponse({
    required this.success,
    this.data,
    this.meta,
    this.message,
    this.errors,
    this.code,
  });

  final bool success;
  final T? data;
  final Map<String, dynamic>? meta;
  final String? message;
  final Map<String, dynamic>? errors;
  final String? code;

  factory ApiResponse.fromJson(
    Map<String, dynamic> json,
    T Function(dynamic raw)? mapData,
  ) {
    final raw = json['data'];
    return ApiResponse<T>(
      success: json['success'] == true || (json['success'] == null && raw != null),
      data: raw == null || mapData == null ? raw as T? : mapData(raw),
      meta: json['meta'] is Map ? Map<String, dynamic>.from(json['meta'] as Map) : null,
      message: json['message']?.toString(),
      errors: json['errors'] is Map ? Map<String, dynamic>.from(json['errors'] as Map) : null,
      code: json['code']?.toString(),
    );
  }

  int get currentPage => int.tryParse('${meta?['current_page'] ?? 1}') ?? 1;
  int get lastPage => int.tryParse('${meta?['last_page'] ?? 1}') ?? 1;
  int get total => int.tryParse('${meta?['total'] ?? 0}') ?? 0;
}
