import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/cashier_api.dart';
import '../../core/auth/auth_controller.dart';
import '../../core/auth/google_access_token.dart';
import '../../core/pos/application/pos_providers.dart';
import '../../core/pos/pos_errors.dart';
import '../../core/theme/hasim_colors.dart';
import '../../core/theme/hasim_radius.dart';
import '../../core/theme/hasim_spacing.dart';
import '../../core/widgets/hasim_brand_logo.dart';
import '../../core/widgets/hasim_widgets.dart';

/// Offline-only entry: cashier login, kitchen station, or reports station.
class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  var _loading = true;
  var _busy = false;
  var _cloudMode = true;
  var _linked = false;
  var _hasStore = false;
  var _googleWaiting = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _boot());
  }

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _boot() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final wantCloud =
          GoRouterState.of(context).uri.queryParameters['cloud'] == '1';
      final store = await ref.read(localAuthServiceProvider).anyStore();
      if (!mounted) return;
      setState(() {
        _loading = false;
        _hasStore = store != null;
        _cloudMode = store == null || wantCloud;
      });
      try {
        final link = await ref.read(cloudLinkStoreProvider).read();
        if (!mounted) return;
        setState(() => _linked = link?.isLinked == true);
      } catch (_) {
        // Secure storage can stall in widget tests; store presence is enough.
      }
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = 'تعذر فتح الوضع المحلي: $e';
        _loading = false;
      });
    }
  }

  Future<void> _submit() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      if (_cloudMode) {
        await ref
            .read(authControllerProvider.notifier)
            .login(_email.text.trim(), _password.text);
        if (!mounted) return;
        await _refreshStoreFlags();
        return;
      }
      await ref
          .read(authControllerProvider.notifier)
          .loginStandalonePin(
            username: _email.text.trim(),
            pin: _password.text,
          );
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

  Future<void> _refreshStoreFlags() async {
    final store = await ref.read(localAuthServiceProvider).anyStore();
    var linked = false;
    try {
      final link = await ref.read(cloudLinkStoreProvider).read();
      linked = link?.isLinked == true;
    } catch (_) {
      // Secure storage can stall in widget tests.
    }
    if (!mounted) return;
    setState(() {
      _hasStore = store != null;
      _linked = linked;
      if (_linked) _cloudMode = false;
    });
  }

  Future<void> _google() async {
    setState(() {
      _busy = true;
      _googleWaiting = true;
      _error = null;
    });
    try {
      final accessToken = await GoogleAccessTokenClient(
        ref.read(cashierApiProvider),
      ).obtainAccessToken();
      await ref
          .read(authControllerProvider.notifier)
          .socialLogin(provider: 'google', accessToken: accessToken);
      if (!mounted) return;
      await _refreshStoreFlags();
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
      if (mounted) {
        setState(() {
          _busy = false;
          _googleWaiting = false;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: ColoredBox(
        color: HasimColors.page,
        child: SafeArea(
          child: Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 480),
              child: ListView(
                padding: const EdgeInsets.all(HasimSpacing.xl),
                children: [
                  const SizedBox(height: 24),
                  Column(
                    children: [
                      const HasimBrandLogo(width: 240),
                      const SizedBox(height: 8),
                      Text(
                        _cloudMode
                            ? 'تسجيل الدخول بحساب حاسم'
                            : (_linked
                                  ? 'كاشير حاسم — مربوط، والعمل المحلي متاح بدون إنترنت'
                                  : 'كاشير حاسم — أوفلاين بالكامل'),
                        style: Theme.of(context).textTheme.bodySmall,
                        textAlign: TextAlign.center,
                      ),
                    ],
                  ).animate().fadeIn(duration: 280.ms).slideY(begin: 0.06, end: 0),
                  const SizedBox(height: 28),
                  HsCard(
                    padding: const EdgeInsets.all(HasimSpacing.lg),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Text(
                          _cloudMode
                              ? 'حساب حاسم / Laravel'
                              : 'تشغيل محلي بدون إنترنت',
                          style: Theme.of(context).textTheme.titleLarge,
                        ),
                        const SizedBox(height: 4),
                        Text(
                          _cloudMode
                              ? 'الهوية من حساب حاسم. بعد الربط يعمل الكاشير بدون إنترنت بنفس كلمة المرور.'
                              : 'الكاشير للمبيعات. المطبخ والتقارير محطات منفصلة من هذه الشاشة.',
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
                        if (_loading)
                          const Padding(
                            padding: EdgeInsets.symmetric(vertical: 12),
                            child: Center(
                              child: SizedBox(
                                width: 28,
                                height: 28,
                                child: CircularProgressIndicator(
                                  strokeWidth: 2.5,
                                ),
                              ),
                            ),
                          )
                        else ...[
                          TextField(
                            controller: _email,
                            keyboardType: TextInputType.emailAddress,
                            autofillHints: const [AutofillHints.email],
                            decoration: InputDecoration(
                              labelText: _cloudMode
                                  ? 'البريد أو الجوال'
                                  : 'الإيميل',
                            ),
                          ),
                          const SizedBox(height: 12),
                          TextField(
                            controller: _password,
                            obscureText: true,
                            onSubmitted: (_) => _busy ? null : _submit(),
                            decoration: InputDecoration(
                              labelText: _cloudMode
                                  ? 'كلمة مرور الحساب'
                                  : 'كلمة المرور',
                            ),
                          ),
                          const SizedBox(height: 16),
                          SizedBox(
                            height: 48,
                            child: FilledButton.icon(
                              style: FilledButton.styleFrom(
                                backgroundColor: HasimColors.brand,
                                shape: RoundedRectangleBorder(
                                  borderRadius: BorderRadius.circular(
                                    HasimRadius.md,
                                  ),
                                ),
                              ),
                              onPressed: _busy ? null : _submit,
                              icon: Icon(
                                _cloudMode
                                    ? Icons.cloud_sync_outlined
                                    : Icons.storefront_outlined,
                              ),
                              label: _busy
                                  ? const SizedBox(
                                      width: 18,
                                      height: 18,
                                      child: CircularProgressIndicator(
                                        strokeWidth: 2,
                                        color: Colors.white,
                                      ),
                                    )
                                  : Text(
                                      _cloudMode
                                          ? 'ربط الجهاز'
                                          : 'دخول الكاشير',
                                    ),
                            ),
                          ),
                          if (_cloudMode) ...[
                            const SizedBox(height: 12),
                            Row(
                              children: [
                                const Expanded(child: Divider()),
                                Padding(
                                  padding: const EdgeInsets.symmetric(
                                    horizontal: 8,
                                  ),
                                  child: Text(
                                    'أو',
                                    style: Theme.of(
                                      context,
                                    ).textTheme.bodySmall,
                                  ),
                                ),
                                const Expanded(child: Divider()),
                              ],
                            ),
                            const SizedBox(height: 12),
                            SizedBox(
                              height: 48,
                              child: OutlinedButton.icon(
                                style: OutlinedButton.styleFrom(
                                  shape: RoundedRectangleBorder(
                                    borderRadius: BorderRadius.circular(
                                      HasimRadius.md,
                                    ),
                                  ),
                                ),
                                onPressed: _busy ? null : _google,
                                icon: const Icon(Icons.g_mobiledata_rounded),
                                label: Text(
                                  _googleWaiting
                                      ? 'بانتظار Google…'
                                      : 'الدخول عبر Google',
                                ),
                              ),
                            ),
                            if (_googleWaiting) ...[
                              const SizedBox(height: 10),
                              Text(
                                'افتح نافذة المتصفح وأكمل حساب Google، ثم ارجع إلى الكاشير. لا تغلق التطبيق. بعد النجاح قد تُطلب كلمة مرور محلية للجهاز.',
                                style: Theme.of(context).textTheme.bodySmall,
                                textAlign: TextAlign.center,
                              ),
                            ],
                          ],
                          if (!_cloudMode) ...[
                            const SizedBox(height: 10),
                            SizedBox(
                              height: 48,
                              child: OutlinedButton.icon(
                                style: OutlinedButton.styleFrom(
                                  shape: RoundedRectangleBorder(
                                    borderRadius: BorderRadius.circular(
                                      HasimRadius.md,
                                    ),
                                  ),
                                ),
                                onPressed: () => context.go('/kitchen'),
                                icon: const Icon(Icons.soup_kitchen_outlined),
                                label: const Text('دخول المطبخ'),
                              ),
                            ),
                            const SizedBox(height: 10),
                            SizedBox(
                              height: 48,
                              child: OutlinedButton.icon(
                                style: OutlinedButton.styleFrom(
                                  shape: RoundedRectangleBorder(
                                    borderRadius: BorderRadius.circular(
                                      HasimRadius.md,
                                    ),
                                  ),
                                ),
                                onPressed: () => context.go('/reports'),
                                icon: const Icon(Icons.bar_chart_outlined),
                                label: const Text('دخول التقارير'),
                              ),
                            ),
                          ],
                          if (_hasStore) ...[
                            const SizedBox(height: 8),
                            TextButton(
                              onPressed: _busy
                                  ? null
                                  : () => setState(() {
                                      _cloudMode = !_cloudMode;
                                      _error = null;
                                    }),
                              child: Text(
                                _cloudMode
                                    ? 'العودة لتسجيل الدخول المحلي'
                                    : 'ربط الجهاز بحساب حاسم',
                              ),
                            ),
                          ],
                        ],
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
