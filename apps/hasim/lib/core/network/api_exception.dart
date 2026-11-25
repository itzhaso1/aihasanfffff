import 'api_error_translator.dart';

class ApiException implements Exception {
  ApiException(
    this.message, {
    this.statusCode,
    this.errors,
    this.errorCode,
    this.retryAfterSeconds,
    this.isCancelled = false,
  });

  /// Original backend message (for logs and debugging).
  final String message;
  final int? statusCode;
  final Map<String, dynamic>? errors;
  final String? errorCode;
  final int? retryAfterSeconds;
  final bool isCancelled;

  /// Arabic user-facing message; does not mutate API payloads.
  String get userMessage => ApiErrorTranslator.format(
    message: message,
    statusCode: statusCode,
    errorCode: errorCode,
    errors: errors,
  );

  @override
  String toString() => message;
}
