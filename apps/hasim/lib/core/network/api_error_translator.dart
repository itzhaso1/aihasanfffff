import 'package:flutter/foundation.dart';

/// Central Arabic mapping for Laravel/API validation and field labels.
class ApiErrorTranslator {
  ApiErrorTranslator._();

  static const Map<String, String> fieldLabels = {
    'is_active': 'حالة التفعيل',
    'is_featured': 'مميز',
    'is_bestseller': 'الأكثر مبيعًا',
    'is_new': 'جديد',
    'is_recommended': 'موصى به',
    'is_searchable': 'قابل للبحث',
    'is_visible': 'ظاهر',
    'name': 'الاسم',
    'price': 'السعر',
    'stock': 'المخزون',
    'sku': 'رمز SKU',
    'barcode': 'الباركود',
    'email': 'البريد الإلكتروني',
    'password': 'كلمة المرور',
    'password_confirmation': 'تأكيد كلمة المرور',
    'phone': 'رقم الجوال',
    'workspace_name': 'اسم النشاط',
    'description': 'الوصف',
    'display_name': 'الاسم الظاهر',
    'professional_summary': 'الملخص المهني',
    'country_code': 'الدولة',
    'nationality': 'الجنسية',
    'sales_experience_level': 'مستوى الخبرة',
    'availability_status': 'التوفر',
    'profile_photo': 'الصورة الشخصية',
    'skill_ids': 'المهارات',
    'language_codes': 'اللغات',
    'organization_name': 'الجهة',
    'started_on': 'تاريخ البداية',
    'ended_on': 'تاريخ النهاية',
    'is_current': 'الخبرة الحالية',
    'legal_name': 'الاسم القانوني',
    'date_of_birth': 'تاريخ الميلاد',
    'identity_number': 'رقم الهوية',
    'address': 'العنوان',
    'document_type_code': 'نوع المستند',
    'document': 'الوثيقة',
    'verification': 'التحقق',
    'skills': 'المهارات',
    'languages': 'اللغات',
    'experience': 'الخبرة',
    'content': 'المحتوى',
    'message': 'الرسالة',
    'inventory_policy': 'طريقة التوفر',
    'classification': 'التصنيف',
    'digital_subtype': 'النوع الرقمي',
    'currency': 'العملة',
    'reservation_key': 'مفتاح الحجز',
    'secret': 'السر',
    'unit_type': 'نوع الوحدة',
  };

  static const String genericFailure =
      'حدث خطأ أثناء تنفيذ العملية. يرجى المحاولة مرة أخرى.';

  static String format({
    required String message,
    int? statusCode,
    String? errorCode,
    Map<String, dynamic>? errors,
  }) {
    if (errors != null && errors.isNotEmpty) {
      final lines = _linesFromErrorsMap(errors);
      if (lines.isNotEmpty) return lines.join('\n');
    }

    if (_looksArabic(message) && message.trim().isNotEmpty) {
      return message.trim();
    }

    final fromMessage = translateValidationMessage(message);
    if (fromMessage != null) return fromMessage;

    if (statusCode == 401 || message.trim() == 'Unauthenticated.') {
      return 'انتهت جلسة تسجيل الدخول، يرجى إعادة تسجيل الدخول.';
    }
    if (statusCode == 402 || errorCode == 'feature_not_available') {
      return 'هذه الميزة غير متاحة في الباقة الحالية.';
    }
    if (statusCode == 403 || message.trim() == 'This action is unauthorized.') {
      return 'لا تملك صلاحية تنفيذ هذا الإجراء.';
    }
    if (statusCode == 404 ||
        message.trim().toLowerCase().contains('no query results for model') ||
        message.trim() == 'Not Found.') {
      return 'العنصر المطلوب غير موجود أو تم حذفه.';
    }
    if (statusCode == 405) {
      return 'الإجراء المطلوب غير مدعوم في هذا المسار.';
    }
    if (statusCode == 413 || message.toLowerCase().contains('too large')) {
      return 'حجم الملف المرفق كبير جداً ويتجاوز الحد المسموح به.';
    }
    if (statusCode == 419) {
      return 'انتهت صلاحية الجلسة، يرجى إعادة المحاولة.';
    }
    if (statusCode == 429) {
      return 'محاولات كثيرة. حاول لاحقاً.';
    }
    if (statusCode != null && statusCode >= 500) {
      return 'حدث خطأ في الخادم ($statusCode). يرجى المحاولة لاحقاً.';
    }

    if (message.trim().isNotEmpty) {
      _logUnknown(message, errors);
    }
    return genericFailure;
  }

  static List<String> _linesFromErrorsMap(Map<String, dynamic> errors) {
    final lines = <String>[];
    errors.forEach((rawKey, value) {
      final key = _normalizeFieldKey(rawKey);
      final messages = _messageList(value);
      for (final raw in messages) {
        lines.add(translateFieldError(key, raw));
      }
    });
    return lines;
  }

  static String translateFieldError(String fieldKey, String rawMessage) {
    final label = labelForField(fieldKey);
    final translated = translateValidationMessage(
      rawMessage,
      fieldKey: fieldKey,
    );
    if (translated != null) {
      if (translated.startsWith('$label:')) return translated;
      return '$label: $translated';
    }
    if (_looksArabic(rawMessage)) {
      return '$label: ${rawMessage.trim()}';
    }
    _logUnknown(rawMessage, null, fieldKey: fieldKey);
    return '$label: ${_ruleFallbackFromText(rawMessage)}';
  }

  static String? translateValidationMessage(String raw, {String? fieldKey}) {
    final text = raw.trim();
    if (text.isEmpty) return null;
    if (_looksArabic(text)) return text;

    final label = fieldKey != null ? labelForField(fieldKey) : null;
    final lower = text.toLowerCase();

    final attribute = _extractAttributeFromMessage(text) ?? fieldKey;
    final attrLabel = attribute != null
        ? labelForField(_normalizeFieldKey(attribute))
        : label;

    if (lower.contains('must be true or false')) {
      return '${attrLabel ?? 'هذا الحقل'}: يجب أن تكون القيمة نعم أو لا.';
    }
    if (RegExp(r'\bis required\b').hasMatch(lower) ||
        lower.contains('field is required')) {
      return '${attrLabel ?? 'هذا الحقل'}: ${_ruleMessage('required')}';
    }
    if (lower.contains('must be a valid email') ||
        lower.contains('valid email address')) {
      return '${attrLabel ?? 'هذا الحقل'}: ${_ruleMessage('email')}';
    }
    if (lower.contains('must be a number') && !lower.contains('integer')) {
      return '${attrLabel ?? 'هذا الحقل'}: ${_ruleMessage('numeric')}';
    }
    if (lower.contains('must be an integer') ||
        lower.contains('must be a integer')) {
      return '${attrLabel ?? 'هذا الحقل'}: ${_ruleMessage('integer')}';
    }
    if (lower.contains('must be a string') ||
        lower.contains('must be string')) {
      return '${attrLabel ?? 'هذا الحقل'}: ${_ruleMessage('string')}';
    }
    if (lower.contains('must be a boolean') ||
        lower.contains('must be boolean')) {
      return '${attrLabel ?? 'هذا الحقل'}: ${_ruleMessage('boolean')}';
    }
    if (lower.contains('must be a valid url') || lower.contains('valid url')) {
      return '${attrLabel ?? 'هذا الحقل'}: ${_ruleMessage('url')}';
    }
    if (lower.contains('must be an image')) {
      return '${attrLabel ?? 'هذا الحقل'}: يرجى اختيار صورة بصيغة مدعومة.';
    }
    if (lower.contains('file of type') ||
        lower.contains('must be a file') ||
        lower.contains('must be of type')) {
      return '${attrLabel ?? 'هذا الحقل'}: نوع الملف غير مدعوم.';
    }
    if (lower.contains('may not be greater than') &&
        (lower.contains('kilobytes') || lower.contains('megabytes'))) {
      return '${attrLabel ?? 'هذا الحقل'}: حجم الملف يتجاوز الحد المسموح.';
    }
    if (lower.contains('confirmation') &&
        (lower.contains('match') || lower.contains('does not match'))) {
      return '${attrLabel ?? 'تأكيد كلمة المرور'}: تأكيد كلمة المرور غير مطابق.';
    }
    if (lower.contains('has already been taken') ||
        lower.contains('already been taken')) {
      return '${attrLabel ?? 'هذا الحقل'}: ${_ruleMessage('unique')}';
    }
    if (lower.contains('selected') && lower.contains('is invalid')) {
      return '${attrLabel ?? 'هذا الحقل'}: ${_ruleMessage('exists')}';
    }
    if (RegExp(r'must be at least \d+').hasMatch(lower) ||
        lower.contains('must be greater than')) {
      return '${attrLabel ?? 'هذا الحقل'}: ${_ruleMessage('min')}';
    }
    if (RegExp(r'may not be greater than').hasMatch(lower) ||
        lower.contains('must not be greater than')) {
      return '${attrLabel ?? 'هذا الحقل'}: ${_ruleMessage('max')}';
    }
    if (lower == 'the given data was invalid.' ||
        lower == 'validation failed.') {
      return 'بيانات غير صالحة.';
    }
    if (lower == 'unauthenticated.') {
      return 'انتهت جلسة تسجيل الدخول، يرجى إعادة تسجيل الدخول.';
    }
    if (lower == 'this action is unauthorized.' || lower == 'unauthorized.') {
      return 'لا تملك صلاحية تنفيذ هذا الإجراء.';
    }
    if (lower == 'worker engagement is not valid for this conversation.') {
      return 'ارتباط الموظف غير صالح لهذه المحادثة.';
    }
    if (lower == 'user is not a member of this workspace.') {
      return 'المستخدم ليس عضواً في مساحة العمل هذه.';
    }
    if (lower == 'only open conversations can receive worker replies.') {
      return 'يمكن الرد فقط على المحادثات المفتوحة.';
    }
    if (lower == 'cannot assign closed conversation.') {
      return 'لا يمكن إسناد محادثة مغلقة.';
    }
    if (lower == 'workspace not found.') {
      return 'مساحة العمل غير موجودة.';
    }
    if (lower == 'conversation is already closed.') {
      return 'المحادثة مغلقة بالفعل.';
    }
    if (lower == 'conversation not found.' || lower == 'not found.') {
      return 'المحادثة غير موجودة أو تم حذفها.';
    }
    if (lower == 'invalid credentials.') {
      return 'بيانات تسجيل الدخول غير صحيحة.';
    }
    if (lower == 'handoff is only allowed on open conversations.') {
      return 'التحويل مسموح فقط في المحادثات المفتوحة.';
    }
    if (lower == 'no active handoff to resolve.') {
      return 'لا يوجد تحويل نشط لإنهائه.';
    }
    if (lower == 'handoff must be resolved before resuming ai.') {
      return 'يجب إنهاء التحويل قبل تفعيل الذكاء الاصطناعي.';
    }

    return null;
  }

  static String labelForField(String fieldKey) {
    final key = _normalizeFieldKey(fieldKey);
    if (fieldLabels.containsKey(key)) return fieldLabels[key]!;
    final last = key.split('.').last;
    if (fieldLabels.containsKey(last)) return fieldLabels[last]!;
    final snake = last.replaceAll(' ', '_');
    if (fieldLabels.containsKey(snake)) return fieldLabels[snake]!;

    final base = key.replaceAll(RegExp(r'\.\d+'), '');
    final baseLast = base.split('.').last;
    if (fieldLabels.containsKey(baseLast)) return fieldLabels[baseLast]!;

    return 'هذا الحقل';
  }

  static String _normalizeFieldKey(String rawKey) {
    var key = rawKey.trim();
    if (key.startsWith('.')) key = key.substring(1);
    key = key.replaceAll(RegExp(r'\[\d+\]'), '');
    return key;
  }

  static String? _extractAttributeFromMessage(String text) {
    final fieldMatch = RegExp(
      r'^The (.+?) field ',
      caseSensitive: false,
    ).firstMatch(text);
    if (fieldMatch != null) {
      return fieldMatch.group(1)!.trim().replaceAll(' ', '_');
    }
    final selectedMatch = RegExp(
      r'^The selected (.+?) is invalid',
      caseSensitive: false,
    ).firstMatch(text);
    if (selectedMatch != null) {
      return selectedMatch.group(1)!.trim().replaceAll(' ', '_');
    }
    return null;
  }

  static List<String> _messageList(dynamic value) {
    if (value is List) {
      return value.map((e) => e.toString()).where((e) => e.isNotEmpty).toList();
    }
    if (value != null) return [value.toString()];
    return const [];
  }

  static String _ruleMessage(String rule) {
    switch (rule) {
      case 'required':
        return 'هذا الحقل مطلوب.';
      case 'email':
        return 'يرجى إدخال بريد إلكتروني صحيح.';
      case 'string':
        return 'يجب أن تكون القيمة نصًا.';
      case 'integer':
        return 'يجب أن تكون القيمة رقمًا صحيحًا.';
      case 'numeric':
        return 'يجب أن تكون القيمة رقمًا.';
      case 'boolean':
        return 'يجب أن تكون القيمة نعم أو لا.';
      case 'min':
        return 'القيمة المدخلة أقل من الحد المسموح.';
      case 'max':
        return 'القيمة المدخلة أكبر من الحد المسموح.';
      case 'unique':
        return 'هذه القيمة مستخدمة بالفعل.';
      case 'exists':
        return 'القيمة المحددة غير موجودة.';
      case 'url':
        return 'يرجى إدخال رابط صحيح.';
      default:
        return 'قيمة غير صالحة.';
    }
  }

  static String _ruleFallbackFromText(String raw) {
    final lower = raw.toLowerCase();
    if (lower.contains('required')) return _ruleMessage('required');
    if (lower.contains('true or false')) return _ruleMessage('boolean');
    if (lower.contains('email')) return _ruleMessage('email');
    if (lower.contains('integer')) return _ruleMessage('integer');
    if (lower.contains('number')) return _ruleMessage('numeric');
    return _ruleMessage('string');
  }

  static bool _looksArabic(String text) {
    return RegExp(r'[\u0600-\u06FF]').hasMatch(text);
  }

  static void _logUnknown(
    String raw,
    Map<String, dynamic>? errors, {
    String? fieldKey,
  }) {
    if (!kDebugMode) return;
    debugPrint(
      '[ApiErrorTranslator] unknown validation message'
      '${fieldKey != null ? ' field=$fieldKey' : ''}: $raw'
      '${errors != null ? ' errors=$errors' : ''}',
    );
  }
}
