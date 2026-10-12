import 'api_error_translator.dart';

class ApiException implements Exception {
  ApiException(this.message, {this.statusCode, this.errors, this.errorCode});

  /// Original backend message (for logs and debugging).
  final String message;
  final int? statusCode;
  final Map<String, dynamic>? errors;
  final String? errorCode;

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
