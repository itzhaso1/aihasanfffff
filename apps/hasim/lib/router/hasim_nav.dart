/// ثوابت تنقل تطبيق حاسم — مصدر واحد للتبويبات والمسار الأولي.
abstract final class HasimNav {
  static const initialShellRoute = '/conversations';

  /// ترتيب التبويبات في RTL (يمين → يسار بصريًا عبر Directionality).
  static const bottomLabels = <String>['المحادثات', 'التحديثات'];

  static const conversationsIndex = 0;
  static const updatesIndex = 1;
  static const tabCount = 2;
}
