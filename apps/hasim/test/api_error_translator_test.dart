import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/network/api_error_translator.dart';
import 'package:hasim/core/network/api_exception.dart';

void main() {
  test('boolean flags show Arabic labels', () {
    for (final field in [
      'is_active',
      'is_featured',
      'is_bestseller',
      'is_new',
      'is_recommended',
      'is_searchable',
      'is_visible',
    ]) {
      final line = ApiErrorTranslator.translateFieldError(
        field,
        'The ${field.replaceAll('_', ' ')} field must be true or false.',
      );
      expect(line, contains('يجب أن تكون القيمة نعم أو لا'));
      expect(line, isNot(contains('is_')));
      expect(line, isNot(contains('must be true or false')));
    }
    expect(
      ApiErrorTranslator.translateFieldError(
        'is_active',
        'The is active field must be true or false.',
      ),
      'حالة التفعيل: يجب أن تكون القيمة نعم أو لا.',
    );
  });

  test('errors map uses Arabic field names', () {
    final text = ApiErrorTranslator.format(
      message: 'The given data was invalid.',
      statusCode: 422,
      errors: {
        'is_active': ['The is active field must be true or false.'],
        'name': ['الاسم مطلوب'],
      },
    );
    expect(text, contains('حالة التفعيل'));
    expect(text, contains('الاسم: الاسم مطلوب'));
    expect(text, isNot(contains('is_active')));
  });

  test('worker fields and nested paths keep actionable labels', () {
    final text = ApiErrorTranslator.format(
      message: 'The given data was invalid.',
      statusCode: 422,
      errors: {
        'professional_summary': ['The professional summary field is required.'],
        'items.0.name': ['The items.0.name field is required.'],
      },
    );

    expect(text, contains('الملخص المهني'));
    expect(text, contains('الاسم'));
    expect(text, isNot(contains('هذا الحقل:')));
  });

  test('unverified worker authorization message stays explicit', () {
    const message =
        'يجب تأكيد البريد الإلكتروني قبل رفع مستندات التحقق أو إرسال الملف للمراجعة.';
    final error = ApiException(message, statusCode: 403);

    expect(error.userMessage, message);
    expect(error.userMessage, isNot(contains('هذا الحقل')));
    expect(error.userMessage, isNot(contains('This action is unauthorized')));
  });

  test('file validation errors remain useful in Arabic', () {
    expect(
      ApiErrorTranslator.translateFieldError(
        'document',
        'The document must be a file of type: pdf, jpg, jpeg, png, webp.',
      ),
      'الوثيقة: نوع الملف غير مدعوم.',
    );
    expect(
      ApiErrorTranslator.translateFieldError(
        'document',
        'The document may not be greater than 8192 kilobytes.',
      ),
      'الوثيقة: حجم الملف يتجاوز الحد المسموح.',
    );
  });

  test('ApiException userMessage keeps raw message for logs', () {
    const raw = 'The is active field must be true or false.';
    final ex = ApiException(
      raw,
      statusCode: 422,
      errors: {
        'is_active': [raw],
      },
    );
    expect(ex.message, raw);
    expect(ex.userMessage, contains('حالة التفعيل'));
  });

  test('unknown english message falls back to generic arabic', () {
    final text = ApiErrorTranslator.format(
      message: 'Totally unknown server failure XYZ',
    );
    expect(text, ApiErrorTranslator.genericFailure);
  });

  test('duplicate email and rate limit stay Arabic', () {
    expect(
      ApiErrorTranslator.format(
        message: 'The email has already been taken.',
        statusCode: 422,
        errors: {
          'email': ['The email has already been taken.'],
        },
      ),
      'البريد الإلكتروني: هذه القيمة مستخدمة بالفعل.',
    );
    expect(
      ApiErrorTranslator.format(
        message: 'Too Many Attempts.',
        statusCode: 429,
      ),
      'محاولات كثيرة. حاول لاحقاً.',
    );
  });
}
