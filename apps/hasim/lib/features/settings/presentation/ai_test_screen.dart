import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/mobile/hasim_mobile_scaffold.dart';
import 'package:hasim/features/settings/data/ai_employee_model.dart';
import 'package:hasim/features/settings/data/ai_setting_model.dart';
import 'package:hasim/features/settings/providers/ai_employees_controller.dart';

class AiTestScreen extends ConsumerStatefulWidget {
  const AiTestScreen({super.key, this.employeeId});

  final int? employeeId;

  @override
  ConsumerState<AiTestScreen> createState() => _AiTestScreenState();
}

class _AiTestScreenState extends ConsumerState<AiTestScreen> {
  final _message = TextEditingController(text: 'مرحبا، عرفني بنفسك');
  AiEmployeeTestResult? _result;
  String? _error;
  bool _running = false;
  double _progress = 0;

  @override
  void dispose() {
    _message.dispose();
    super.dispose();
  }

  Future<void> _run() async {
    setState(() {
      _running = true;
      _error = null;
      _progress = 0.15;
    });
    try {
      final employeeId =
          widget.employeeId ??
          ref.read(aiEmployeesControllerProvider).selectedId;
      if (employeeId == null) {
        setState(
          () => _error = 'اختر موظفاً من إعدادات الموظفين ثم أعد الاختبار.',
        );
        return;
      }
      final result = await ref
          .read(aiEmployeesControllerProvider.notifier)
          .test(employeeId, _message.text.trim());
      if (!mounted) return;
      if (result == null) {
        setState(
          () => _error =
              ref.read(aiEmployeesControllerProvider).error ??
              'تعذر تشغيل الاختبار.',
        );
        return;
      }
      setState(() {
        _result = result;
        _progress = 1;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _error = e.userMessage);
    } catch (_) {
      if (!mounted) return;
      setState(() => _error = 'تعذر تشغيل الاختبار.');
    } finally {
      if (mounted) setState(() => _running = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final body = ListView(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 28),
      children: [
        _ProbeCard(
          child: Row(
            children: [
              Icon(
                _result == null
                    ? Icons.circle_outlined
                    : (_result!.failed ? Icons.cancel : Icons.check_circle),
                color: _result == null
                    ? HasimTokens.mutedColor(context)
                    : (_result!.failed
                          ? HasimTokens.danger
                          : const Color(0xFF067E6B)),
              ),
              const SizedBox(width: 8),
              Text(
                _result == null
                    ? 'لم يُختبر الاتصال بعد'
                    : (_result!.failed ? 'غير متصل' : 'متصل'),
                style: GoogleFonts.cairo(
                  fontWeight: FontWeight.w800,
                  color: _result == null
                      ? HasimTokens.inkColor(context)
                      : (_result!.failed
                            ? HasimTokens.danger
                            : const Color(0xFF067E6B)),
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 12),
        if (_running) ...[
          LinearProgressIndicator(value: _progress < 1 ? null : 1),
          const SizedBox(height: 12),
        ],
        for (final check in _checks)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: _ProbeCard(
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          check.label,
                          style: GoogleFonts.cairo(fontWeight: FontWeight.w700),
                        ),
                        if (check.detail.isNotEmpty)
                          Text(
                            check.detail,
                            style: GoogleFonts.cairo(
                              fontSize: 12,
                              color: HasimTokens.mutedColor(context),
                            ),
                          ),
                      ],
                    ),
                  ),
                  Text(
                    check.elapsedMs > 0 ? '${check.elapsedMs} ms' : '—',
                    style: GoogleFonts.cairo(fontSize: 12),
                  ),
                  const SizedBox(width: 8),
                  Text(
                    _result == null ? 'بانتظار' : _checkLabel(check),
                    style: GoogleFonts.cairo(
                      fontWeight: FontWeight.w700,
                      color: _result == null
                          ? HasimTokens.mutedColor(context)
                          : _checkColor(context, check),
                    ),
                  ),
                ],
              ),
            ),
          ),
        const SizedBox(height: 8),
        TextField(
          controller: _message,
          style: GoogleFonts.cairo(),
          decoration: const InputDecoration(
            labelText: 'اكتب رسالة للاختبار',
            hintText: 'مرحبا، عرفني بنفسك',
          ),
        ),
        const SizedBox(height: 12),
        SizedBox(
          height: 48,
          child: FilledButton(
            onPressed: _running ? null : _run,
            child: Text(_running ? 'جارٍ الاختبار' : 'تشغيل الاختبار'),
          ),
        ),
        const SizedBox(height: 8),
        SizedBox(
          height: 48,
          child: OutlinedButton(
            onPressed: _running ? null : _run,
            child: const Text('تشغيل جميع الاختبارات'),
          ),
        ),
        if (_error != null) ...[
          const SizedBox(height: 12),
          Text(_error!, style: GoogleFonts.cairo(color: HasimTokens.danger)),
        ],
        if (_result != null) ...[
          const SizedBox(height: 12),
          _ProbeCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'نتيجة الاختبار',
                  style: GoogleFonts.cairo(fontWeight: FontWeight.w800),
                ),
                const SizedBox(height: 8),
                Text(
                  (_result!.text ?? '').isEmpty
                      ? (_result!.error ?? 'لا يوجد رد.')
                      : _result!.text!,
                  style: GoogleFonts.cairo(height: 1.4),
                ),
                const SizedBox(height: 8),
                Text(
                  'زمن الاستجابة: ${_result!.elapsedMs} ms',
                  style: GoogleFonts.cairo(fontSize: 13),
                ),
                if (_result!.tokens != null)
                  Text(
                    'Tokens: ${_result!.tokens}',
                    style: GoogleFonts.cairo(fontSize: 13),
                  ),
              ],
            ),
          ),
          if (_result!.replied) ...[
            const SizedBox(height: 12),
            _ProbeCard(
              child: Text(
                'الذكاء الاصطناعي جاهز للعمل ✓',
                style: GoogleFonts.cairo(
                  fontWeight: FontWeight.w800,
                  color: const Color(0xFF067E6B),
                ),
              ),
            ),
          ],
        ],
      ],
    );

    if (HasimLayout.isCompact(context)) {
      return HasimMobileScaffold(title: 'اختبار الذكاء الاصطناعي', body: body);
    }
    return Scaffold(
      backgroundColor: HasimTokens.pageBg(context),
      appBar: AppBar(title: const Text('اختبار الذكاء الاصطناعي')),
      body: body,
    );
  }

  List<AiProbeCheck> get _checks {
    if (_result != null && _result!.checks.isNotEmpty) {
      return _result!.checks;
    }
    return const [
      AiProbeCheck(id: 'provider', label: 'اختبار الاتصال بالمزود', ok: false),
      AiProbeCheck(id: 'message', label: 'اختبار إرسال رسالة', ok: false),
      AiProbeCheck(id: 'latency', label: 'اختبار سرعة الاستجابة', ok: false),
      AiProbeCheck(id: 'model', label: 'النموذج المستخدم', ok: false),
    ];
  }

  String _checkLabel(AiProbeCheck check) {
    if (check.warning && check.ok) return 'تحذير';
    return check.ok ? 'ناجح' : 'فشل';
  }

  Color _checkColor(BuildContext context, AiProbeCheck check) {
    if (check.warning && check.ok) return const Color(0xFFB45309);
    return check.ok ? const Color(0xFF067E6B) : HasimTokens.danger;
  }
}

class _ProbeCard extends StatelessWidget {
  const _ProbeCard({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: HasimTokens.cardBg(context),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: HasimTokens.lineColor(context)),
      ),
      child: child,
    );
  }
}
