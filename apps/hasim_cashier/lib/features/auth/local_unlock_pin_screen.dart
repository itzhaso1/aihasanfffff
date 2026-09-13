import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/cashier_api.dart';
import '../../core/auth/auth_controller.dart';
import '../../core/pos/pos_errors.dart';
import '../../core/theme/hasim_colors.dart';
import '../../core/theme/hasim_radius.dart';
import '../../core/theme/hasim_spacing.dart';
import '../../core/widgets/hasim_brand_logo.dart';
import '../../core/widgets/hasim_widgets.dart';

/// Local device PIN after Google bind. Laravel stays the identity source.
class LocalUnlockPinScreen extends ConsumerStatefulWidget {
  const LocalUnlockPinScreen({super.key});

  @override
  ConsumerState<LocalUnlockPinScreen> createState() =>
      _LocalUnlockPinScreenState();
}

class _LocalUnlockPinScreenState extends ConsumerState<LocalUnlockPinScreen> {
  final _pin = TextEditingController();
  final _confirm = TextEditingController();
  var _busy = false;
  String? _error;

  @override
  void dispose() {
    _pin.dispose();
    _confirm.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final pin = _pin.text.trim();
    final confirm = _confirm.text.trim();
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      if (pin.length < 4) {
        throw ApiException('كلمة المرور المحلية يجب أن تكون 4 أحرف على الأقل.');
      }
      if (pin != confirm) {
        throw ApiException('كلمتا المرور المحلية غير متطابقتين.');
      }
      await ref
          .read(authControllerProvider.notifier)
          .completeLocalUnlockPin(pin);
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e is PosException
            ? e.messageAr
            : e is ApiException
                ? e.message
                : e.toString();
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _cancel() async {
    await ref.read(authControllerProvider.notifier).abortCloudSetup();
    if (!mounted) return;
    context.go('/login');
  }

  @override
  Widget build(BuildContext context) {
    final email = ref.watch(authControllerProvider).valueOrNull?.email ?? '';
    return Scaffold(
      body: DecoratedBox(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: [HasimColors.brandSoft, Color(0xFFF8FAFC), Colors.white],
          ),
        ),
        child: SafeArea(
          child: Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 480),
              child: ListView(
                padding: const EdgeInsets.all(HasimSpacing.xl),
                children: [
                  const SizedBox(height: 24),
                  const Center(child: HasimBrandLogo(width: 220)),
                  const SizedBox(height: 28),
                  HsCard(
                    padding: const EdgeInsets.all(HasimSpacing.lg),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        const Text(
                          'كلمة مرور محلية للجهاز',
                          style: TextStyle(
                            fontSize: 20,
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          email.isEmpty
                              ? 'حساب Google مربوط. ضع كلمة مرور محلية لفتح الكاشير بدون إنترنت.'
                              : 'حساب Google مربوط ($email). ضع كلمة مرور محلية لفتح الكاشير بدون إنترنت.',
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                        const SizedBox(height: 16),
                        if (_error != null) ...[
                          Container(
                            padding: const EdgeInsets.all(10),
                            decoration: BoxDecoration(
                              color: HasimColors.dangerSoft,
                              borderRadius: BorderRadius.circular(
                                HasimRadius.sm,
                              ),
                              border: Border.all(
                                color: const Color(0xFFFECDD3),
                              ),
                            ),
                            child: Text(
                              _error!,
                              style: const TextStyle(
                                color: HasimColors.danger,
                                fontSize: 12,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                          ),
                          const SizedBox(height: 12),
                        ],
                        TextField(
                          controller: _pin,
                          obscureText: true,
                          decoration: const InputDecoration(
                            labelText: 'كلمة المرور المحلية (4 أحرف على الأقل)',
                          ),
                        ),
                        const SizedBox(height: 12),
                        TextField(
                          controller: _confirm,
                          obscureText: true,
                          onSubmitted: (_) => _busy ? null : _submit(),
                          decoration: const InputDecoration(
                            labelText: 'تأكيد كلمة المرور',
                          ),
                        ),
                        const SizedBox(height: 16),
                        SizedBox(
                          height: 48,
                          child: FilledButton(
                            style: FilledButton.styleFrom(
                              backgroundColor: HasimColors.brand,
                              shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(
                                  HasimRadius.md,
                                ),
                              ),
                            ),
                            onPressed: _busy ? null : _submit,
                            child: _busy
                                ? const SizedBox(
                                    width: 18,
                                    height: 18,
                                    child: CircularProgressIndicator(
                                      strokeWidth: 2,
                                      color: Colors.white,
                                    ),
                                  )
                                : const Text('حفظ ودخول الكاشير'),
                          ),
                        ),
                        TextButton(
                          onPressed: _busy ? null : _cancel,
                          child: const Text('إلغاء'),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
