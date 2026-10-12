import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:google_sign_in/google_sign_in.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/hasim_primary_button.dart';
import 'package:hasim/core/widgets/mobile/hasim_mobile_scaffold.dart';
import 'package:hasim/features/auth/presentation/login_screen.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';

class RegisterScreen extends ConsumerStatefulWidget {
  const RegisterScreen({super.key});

  @override
  ConsumerState<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends ConsumerState<RegisterScreen> {
  final _formKey = GlobalKey<FormState>();
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _phone = TextEditingController();
  final _workspaceName = TextEditingController();
  final _password = TextEditingController();
  final _passwordConfirmation = TextEditingController();
  bool _obscurePassword = true;
  bool _obscureConfirmation = true;
  bool _googleBusy = false;
  bool _submitting = false;

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    _phone.dispose();
    _workspaceName.dispose();
    _password.dispose();
    _passwordConfirmation.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_submitting) return;
    if (!_formKey.currentState!.validate()) return;
    setState(() => _submitting = true);
    final ok = await ref
        .read(authControllerProvider.notifier)
        .register(
          name: _name.text.trim(),
          email: _email.text.trim(),
          password: _password.text,
          passwordConfirmation: _passwordConfirmation.text,
          phone: _phone.text.trim().isEmpty ? null : _phone.text.trim(),
          workspaceName: _workspaceName.text.trim().isEmpty
              ? null
              : _workspaceName.text.trim(),
        );
    if (!mounted) return;
    setState(() => _submitting = false);
    if (ok) {
      final auth = ref.read(authControllerProvider);
      context.go(auth.authenticatedHome);
    }
  }

  Future<void> _google() async {
    setState(() => _googleBusy = true);
    try {
      final google = GoogleSignIn(scopes: const ['email', 'profile']);
      final account = await google.signIn();
      if (account == null) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('تم إلغاء التسجيل عبر Google.')),
          );
        }
        return;
      }
      final auth = await account.authentication;
      final token = auth.accessToken ?? auth.idToken;
      if (token == null || token.isEmpty) {
        if (mounted) {
          ScaffoldMessenger.of(
            context,
          ).showSnackBar(const SnackBar(content: Text('يحتاج إعداد Google')));
        }
        return;
      }
      final ok = await ref
          .read(authControllerProvider.notifier)
          .socialLogin(provider: 'google', accessToken: token);
      if (!mounted) return;
      if (ok) {
        final state = ref.read(authControllerProvider);
        context.go(state.authenticatedHome);
      }
    } catch (e) {
      if (!mounted) return;
      final msg = e.toString().toLowerCase();
      final needsSetup =
          msg.contains('client') ||
          msg.contains('platform') ||
          msg.contains('missing') ||
          msg.contains('not been configured') ||
          msg.contains('sign_in_failed') ||
          msg.contains('10:') ||
          msg.contains('12500');
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            needsSetup ? 'يحتاج إعداد Google' : 'تعذر التسجيل عبر Google.',
          ),
        ),
      );
    } finally {
      if (mounted) setState(() => _googleBusy = false);
    }
  }

  InputDecoration _decoration({
    required String hint,
    required IconData leading,
    Widget? trailing,
  }) {
    final radius = BorderRadius.circular(HasimTokens.radiusMd);
    final muted = HasimTokens.mutedColor(context);
    return InputDecoration(
      hintText: hint,
      hintStyle: TextStyle(color: muted, fontSize: 14, fontWeight: FontWeight.w500),
      prefixIcon: Icon(leading, color: muted, size: 22),
      suffixIcon: trailing,
      filled: true,
      fillColor: HasimTokens.cardBg(context),
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      border: OutlineInputBorder(
        borderRadius: radius,
        borderSide: BorderSide(color: HasimTokens.lineColor(context)),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: radius,
        borderSide: BorderSide(color: HasimTokens.lineColor(context)),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: radius,
        borderSide: const BorderSide(color: HasimTokens.primary, width: 1.4),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final auth = ref.watch(authControllerProvider);
    final busy = auth.loading || _submitting || _googleBusy;
    final muted = HasimTokens.mutedColor(context);

    return Directionality(
      textDirection: TextDirection.rtl,
      child: HasimMobileScaffold(
        title: 'إنشاء حساب',
        body: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 420),
            child: SingleChildScrollView(
              padding: const EdgeInsets.fromLTRB(24, 8, 24, 28),
              child: Form(
                key: _formKey,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Text(
                      'أنشئ حساب حاسم من التطبيق مباشرة.',
                      style: TextStyle(color: muted, height: 1.45, fontSize: 14),
                    ),
                    const SizedBox(height: 20),
                    TextFormField(
                      key: const Key('register-name'),
                      controller: _name,
                      decoration: _decoration(
                        hint: 'الاسم الكامل',
                        leading: Icons.person_outline,
                      ),
                      textInputAction: TextInputAction.next,
                      validator: (v) =>
                          (v == null || v.trim().isEmpty) ? 'مطلوب' : null,
                    ),
                    const SizedBox(height: 12),
                    TextFormField(
                      key: const Key('register-email'),
                      controller: _email,
                      decoration: _decoration(
                        hint: 'البريد الإلكتروني',
                        leading: Icons.email_outlined,
                      ),
                      keyboardType: TextInputType.emailAddress,
                      textDirection: TextDirection.ltr,
                      textAlign: TextAlign.left,
                      textInputAction: TextInputAction.next,
                      validator: (v) {
                        if (v == null || v.trim().isEmpty) return 'مطلوب';
                        if (!v.contains('@')) return 'بريد غير صالح';
                        return null;
                      },
                    ),
                    const SizedBox(height: 12),
                    TextFormField(
                      key: const Key('register-phone'),
                      controller: _phone,
                      decoration: _decoration(
                        hint: 'رقم الجوال (اختياري)',
                        leading: Icons.phone_outlined,
                      ),
                      keyboardType: TextInputType.phone,
                      textInputAction: TextInputAction.next,
                    ),
                    const SizedBox(height: 12),
                    TextFormField(
                      key: const Key('register-workspace-name'),
                      controller: _workspaceName,
                      decoration: _decoration(
                        hint: 'اسم النشاط (اختياري)',
                        leading: Icons.storefront_outlined,
                      ),
                      textInputAction: TextInputAction.next,
                    ),
                    const SizedBox(height: 12),
                    TextFormField(
                      key: const Key('register-password'),
                      controller: _password,
                      obscureText: _obscurePassword,
                      decoration: _decoration(
                        hint: 'كلمة المرور',
                        leading: Icons.lock_outline,
                        trailing: IconButton(
                          onPressed: () => setState(
                            () => _obscurePassword = !_obscurePassword,
                          ),
                          icon: Icon(
                            _obscurePassword
                                ? Icons.visibility_outlined
                                : Icons.visibility_off_outlined,
                            color: muted,
                          ),
                        ),
                      ),
                      textInputAction: TextInputAction.next,
                      validator: (v) {
                        if (v == null || v.isEmpty) return 'مطلوب';
                        if (v.length < 8) {
                          return 'كلمة المرور يجب ألا تقل عن 8 أحرف';
                        }
                        return null;
                      },
                    ),
                    const SizedBox(height: 12),
                    TextFormField(
                      key: const Key('register-password-confirmation'),
                      controller: _passwordConfirmation,
                      obscureText: _obscureConfirmation,
                      decoration: _decoration(
                        hint: 'تأكيد كلمة المرور',
                        leading: Icons.lock_outline,
                        trailing: IconButton(
                          onPressed: () => setState(
                            () => _obscureConfirmation = !_obscureConfirmation,
                          ),
                          icon: Icon(
                            _obscureConfirmation
                                ? Icons.visibility_outlined
                                : Icons.visibility_off_outlined,
                            color: muted,
                          ),
                        ),
                      ),
                      validator: (v) {
                        if (v == null || v.isEmpty) return 'مطلوب';
                        if (v != _password.text) {
                          return 'تأكيد كلمة المرور غير مطابق';
                        }
                        return null;
                      },
                    ),
                    if (auth.error != null) ...[
                      const SizedBox(height: 12),
                      Text(
                        auth.error!,
                        key: const Key('register-error'),
                        style: TextStyle(color: Theme.of(context).colorScheme.error),
                        textAlign: TextAlign.center,
                      ),
                    ],
                    const SizedBox(height: 20),
                    HasimPrimaryButton(
                      key: const Key('register-submit'),
                      label: 'إنشاء حساب',
                      expanded: true,
                      enabled: !busy,
                      busy: auth.loading || _submitting,
                      onPressed: _submit,
                    ),
                    const SizedBox(height: 18),
                    Row(
                      children: [
                        Expanded(child: Divider(color: HasimTokens.lineColor(context))),
                        Padding(
                          padding: const EdgeInsets.symmetric(horizontal: 12),
                          child: Text(
                            'أو',
                            style: TextStyle(
                              color: muted,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ),
                        Expanded(child: Divider(color: HasimTokens.lineColor(context))),
                      ],
                    ),
                    const SizedBox(height: 16),
                    OutlinedButton(
                      key: const Key('register-google'),
                      onPressed: busy ? null : _google,
                      style: OutlinedButton.styleFrom(
                        backgroundColor: HasimTokens.cardBg(context),
                        foregroundColor: HasimTokens.inkColor(context),
                        minimumSize: const Size.fromHeight(52),
                        side: BorderSide(color: HasimTokens.lineColor(context)),
                        shape: const StadiumBorder(),
                      ),
                      child: _googleBusy
                          ? const SizedBox(
                              width: 18,
                              height: 18,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : const FittedBox(
                              fit: BoxFit.scaleDown,
                              child: Row(
                                mainAxisAlignment: MainAxisAlignment.center,
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  HasimGoogleMark(),
                                  SizedBox(width: 10),
                                  Text(
                                    'التسجيل عبر Google',
                                    style: TextStyle(
                                      fontWeight: FontWeight.w700,
                                      fontSize: 15,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                    ),
                    const SizedBox(height: 16),
                    TextButton(
                      onPressed: busy ? null : () => context.go('/login'),
                      child: Text(
                        'لديك حساب؟ تسجيل الدخول',
                        style: TextStyle(
                          color: AppTheme.brand,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      'بإنشاء الحساب فإنك توافق على شروط حاسم وسياسة الخصوصية.',
                      textAlign: TextAlign.center,
                      style: TextStyle(color: muted, fontSize: 12, height: 1.5),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
