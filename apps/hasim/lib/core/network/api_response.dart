class ApiResponse<T> {
  const ApiResponse({
    required this.success,
    this.data,
    this.meta,
    this.message,
    this.errors,
  });

  final bool success;
  final T? data;
  final Map<String, dynamic>? meta;
  final String? message;
  final Map<String, dynamic>? errors;

  factory ApiResponse.fromJson(
    Map<String, dynamic> json,
    T Function(dynamic raw)? mapData,
  ) {
    final raw = json['data'];
    return ApiResponse<T>(
      success: json['success'] == true || (json['success'] == null && raw != null),
      data: raw == null || mapData == null ? raw as T? : mapData(raw),
      meta: json['meta'] is Map<String, dynamic>
          ? json['meta'] as Map<String, dynamic>
          : null,
      message: json['message']?.toString(),
      errors: json['errors'] is Map<String, dynamic>
          ? json['errors'] as Map<String, dynamic>
          : null,
    );
  }

  String? get nextCursor => meta?['next_cursor']?.toString();
  String? get prevCursor => meta?['prev_cursor']?.toString();
}
