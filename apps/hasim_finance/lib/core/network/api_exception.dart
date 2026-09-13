class ApiException implements Exception {
  ApiException(this.message, {this.statusCode, this.errors, this.code});

  final String message;
  final int? statusCode;
  final String? code;
  final Map<String, dynamic>? errors;

  bool get isUnauthorized => statusCode == 401;
  bool get isForbidden => statusCode == 403;
  bool get isNotFound => statusCode == 404;
  bool get isConflict => statusCode == 409;
  bool get isValidation => statusCode == 422;
  bool get isRateLimited => statusCode == 429;

  @override
  String toString() => message;
}
