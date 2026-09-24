import 'package:flutter/material.dart';
import 'package:hasim_finance/core/network/api_exception.dart';
import 'package:hasim_finance/core/theme/finance_tokens.dart';

const Duration _snackDuration = Duration(milliseconds: 2600);
const double _snackMaxWidth = 320;

void showSnack(BuildContext context, String message) {
  final text = _ensureArabic(message);
  final messenger = ScaffoldMessenger.of(context);
  messenger.hideCurrentSnackBar();
  messenger.showSnackBar(
    SnackBar(
      content: Text(
        text,
        maxLines: 2,
        overflow: TextOverflow.ellipsis,
        style: Theme.of(context).textTheme.bodySmall?.copyWith(
              color: context.hs.text,
              fontWeight: FontWeight.w600,
              height: 1.35,
            ),
      ),
      behavior: SnackBarBehavior.floating,
      duration: _snackDuration,
      elevation: 2,
      backgroundColor: context.hs.card,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(FinanceTokens.radius),
        side: BorderSide(color: context.hs.border),
      ),
      margin: _snackMargin(context),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
    ),
  );
}

void showApiError(BuildContext context, Object error) {
  showSnack(context, arabicApiMessage(error));
}

EdgeInsets _snackMargin(BuildContext context) {
  final size = MediaQuery.sizeOf(context);
  final top = MediaQuery.paddingOf(context).top + 12;
  const left = 16.0;
  final right = (size.width - _snackMaxWidth - left).clamp(left, size.width);
  return EdgeInsets.only(top: top, left: left, right: right);
}

String arabicApiMessage(Object error) {
  if (error is ApiException) {
    final fieldError = _firstFieldError(error.errors);
    if (fieldError != null) return _ensureArabic(fieldError);

    if (error.message.trim().isNotEmpty) {
      return _ensureArabic(error.message);
    }
  }

  final raw = error.toString().replaceFirst(RegExp(r'^Exception:\s*'), '').trim();
  if (raw.isEmpty) return 'حدث خطأ. يرجى المحاولة مرة أخرى.';
  return _ensureArabic(raw);
}

String? _firstFieldError(Map<String, dynamic>? errors) {
  if (errors == null || errors.isEmpty) return null;
  for (final value in errors.values) {
    if (value is List && value.isNotEmpty) return value.first.toString();
    if (value != null) return value.toString();
  }
  return null;
}

String _ensureArabic(String message) {
  final trimmed = message.trim();
  if (trimmed.isEmpty) return 'حدث خطأ. يرجى المحاولة مرة أخرى.';
  if (_containsArabic(trimmed)) return trimmed;
  return _translateKnown(trimmed);
}

bool _containsArabic(String text) => RegExp(r'[\u0600-\u06FF]').hasMatch(text);

String _translateKnown(String message) {
  final normalized = message.trim();
  return switch (normalized) {
    'Unauthenticated.' => 'انتهت جلسة تسجيل الدخول.',
    'Workspace context is required.' => 'يلزم اختيار منشأة مالية.',
    'You are not allowed to access this financial resource.' => 'لا تملك صلاحية تنفيذ هذا الإجراء.',
    'Invoice is already paid.' => 'الفاتورة مسددة بالكامل.',
    'Validation failed.' => 'بيانات غير صالحة.',
    'Server Error' => 'حدث خطأ في الخادم.',
    'Not Found' => 'العنصر المطلوب غير موجود.',
    'Forbidden' => 'لا تملك صلاحية تنفيذ هذا الإجراء.',
    'Unauthorized' => 'انتهت جلسة تسجيل الدخول.',
    'Network Error' => 'تعذر الاتصال بالخادم. تحقق من الشبكة.',
    'Connection timeout' => 'انتهت مهلة الاتصال. حاول مجدداً.',
    'Connection error' => 'تعذر الاتصال بالخادم. تحقق من الشبكة.',
    _ when normalized.toLowerCase().contains('network') => 'تعذر الاتصال بالخادم. تحقق من الشبكة.',
    _ when normalized.toLowerCase().contains('timeout') => 'انتهت مهلة الاتصال. حاول مجدداً.',
    _ when normalized.toLowerCase().contains('unauthenticated') => 'انتهت جلسة تسجيل الدخول.',
    _ when normalized.toLowerCase().contains('forbidden') => 'لا تملك صلاحية تنفيذ هذا الإجراء.',
    _ when normalized.toLowerCase().contains('not found') => 'العنصر المطلوب غير موجود.',
    _ => 'حدث خطأ. يرجى المحاولة مرة أخرى.',
  };
}
