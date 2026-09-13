import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:google_sign_in/google_sign_in.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';

const hasimLoginArtAsset = 'assets/branding/hasim_login.png';

const _loginPage = Color(0xFFF7FEFC);
const _loginShape = Color(0xFFE8F8F4);
const _loginInk = Color(0xFF1B2C29);
const _loginMuted = Color(0xFF8B9B97);
const _loginLine = Color(0xFFE8F1EE);

class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});
  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _idController = TextEditingController();
  final _passwordController = TextEditingController();
  final _formKey = GlobalKey<FormState>();
  bool _obscure = true;
  bool _googleBusy = false;

  @override
  void dispose() {
    _idController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    final ok = await ref
        .read(authControllerProvider.notifier)
        .login(_idController.text.trim(), _passwordController.text);
    if (!mounted) return;
    if (ok) {
      final auth = ref.read(authControllerProvider);
      if (auth.workspace == null) {
        context.go('/workspaces');
      } else {
        context.go('/conversations');
      }
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
            const SnackBar(content: Text('تم إلغاء تسجيل الدخول عبر Google.')),
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
        context.go(state.workspace == null ? '/workspaces' : '/conversations');
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
            needsSetup ? 'يحتاج إعداد Google' : 'تعذر تسجيل الدخول عبر Google.',
          ),
        ),
      );
    } finally {
      if (mounted) setState(() => _googleBusy = false);
    }
  }

  InputDecoration _fieldDecoration({
    required String hint,
    required IconData leading,
    Widget? trailing,
  }) {
    final radius = BorderRadius.circular(28);
    return InputDecoration(
      hintText: hint,
      hintStyle: const TextStyle(
        color: _loginMuted,
        fontSize: 14,
        fontWeight: FontWeight.w500,
      ),
      prefixIcon: Icon(leading, color: _loginMuted, size: 22),
      suffixIcon: trailing,
      filled: true,
      fillColor: Colors.white,
      contentPadding: const EdgeInsets.symmetric(horizontal: 18, vertical: 16),
      border: OutlineInputBorder(
        borderRadius: radius,
        borderSide: const BorderSide(color: _loginLine),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: radius,
        borderSide: const BorderSide(color: _loginLine),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: radius,
        borderSide: BorderSide(color: AppTheme.brand.withValues(alpha: 0.35)),
      ),
      errorBorder: OutlineInputBorder(
        borderRadius: radius,
        borderSide: const BorderSide(color: Color(0xFFE57373)),
      ),
      focusedErrorBorder: OutlineInputBorder(
        borderRadius: radius,
        borderSide: const BorderSide(color: Color(0xFFE57373)),
      ),
    );
  }

  Widget _whiteField({required Widget child}) {
    return DecoratedBox(
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(28),
        boxShadow: const [
          BoxShadow(
            color: Color(0x08061C18),
            blurRadius: 16,
            offset: Offset(0, 6),
          ),
        ],
      ),
      child: child,
    );
  }

  @override
  Widget build(BuildContext context) {
    final auth = ref.watch(authControllerProvider);
    final theme = Theme.of(context);

    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: const SystemUiOverlayStyle(
        statusBarColor: Colors.transparent,
        statusBarIconBrightness: Brightness.dark,
        statusBarBrightness: Brightness.light,
      ),
      child: Directionality(
        textDirection: TextDirection.rtl,
        child: Scaffold(
          backgroundColor: _loginPage,
          body: Stack(
            fit: StackFit.expand,
            children: [
              const Positioned.fill(
                child: IgnorePointer(
                  child: CustomPaint(painter: _LoginShapesPainter()),
                ),
              ),
              SafeArea(
                child: Center(
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 420),
                    child: SingleChildScrollView(
                      padding: const EdgeInsets.fromLTRB(24, 12, 24, 28),
                      child: Form(
                        key: _formKey,
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            const SizedBox(height: 8),
                            Image.asset(
                              hasimLoginArtAsset,
                              key: const Key('hasim-login-art'),
                              height: 186,
                              fit: BoxFit.contain,
                              filterQuality: FilterQuality.high,
                            ),
                            const SizedBox(height: 28),
                            Text(
                              'مرحباً بك مجدداً',
                              textAlign: TextAlign.center,
                              style: theme.textTheme.headlineSmall?.copyWith(
                                fontWeight: FontWeight.w800,
                                fontSize: 26,
                                color: _loginInk,
                                height: 1.2,
                              ),
                            ),
                            const SizedBox(height: 8),
                            const Text(
                              'سجّل دخولك إلى حسابك لمتابعة أعمالك',
                              textAlign: TextAlign.center,
                              style: TextStyle(
                                color: _loginMuted,
                                fontSize: 14,
                                fontWeight: FontWeight.w500,
                                height: 1.45,
                              ),
                            ),
                            const SizedBox(height: 28),
                            _whiteField(
                              child: TextFormField(
                                controller: _idController,
                                decoration: _fieldDecoration(
                                  hint: 'البريد الإلكتروني أو الجوال',
                                  leading: Icons.person_outline,
                                ),
                                keyboardType: TextInputType.emailAddress,
                                validator: (v) =>
                                    (v == null || v.trim().isEmpty)
                                    ? 'مطلوب'
                                    : null,
                              ),
                            ),
                            const SizedBox(height: 12),
                            _whiteField(
                              child: TextFormField(
                                controller: _passwordController,
                                obscureText: _obscure,
                                decoration: _fieldDecoration(
                                  hint: 'كلمة المرور',
                                  leading: Icons.lock_outline,
                                  trailing: IconButton(
                                    onPressed: () =>
                                        setState(() => _obscure = !_obscure),
                                    icon: Icon(
                                      _obscure
                                          ? Icons.visibility_outlined
                                          : Icons.visibility_off_outlined,
                                      color: _loginMuted,
                                    ),
                                  ),
                                ),
                                validator: (v) =>
                                    (v == null || v.isEmpty) ? 'مطلوب' : null,
                              ),
                            ),
                            Align(
                              alignment: AlignmentDirectional.centerEnd,
                              child: TextButton(
                                onPressed: () =>
                                    context.push('/forgot-password'),
                                style: TextButton.styleFrom(
                                  foregroundColor: AppTheme.brand,
                                  padding: const EdgeInsets.symmetric(
                                    horizontal: 4,
                                    vertical: 8,
                                  ),
                                ),
                                child: const Text(
                                  'نسيت كلمة المرور؟',
                                  style: TextStyle(
                                    fontWeight: FontWeight.w700,
                                    fontSize: 13.5,
                                  ),
                                ),
                              ),
                            ),
                            if (auth.error != null) ...[
                              Text(
                                auth.error!,
                                style: TextStyle(
                                  color: theme.colorScheme.error,
                                ),
                                textAlign: TextAlign.center,
                              ),
                              const SizedBox(height: 8),
                            ],
                            FilledButton(
                              onPressed: auth.loading ? null : _submit,
                              style: FilledButton.styleFrom(
                                backgroundColor: AppTheme.brand,
                                foregroundColor: Colors.white,
                                minimumSize: const Size.fromHeight(52),
                                shape: const StadiumBorder(),
                                textStyle: const TextStyle(
                                  fontSize: 16,
                                  fontWeight: FontWeight.w700,
                                ),
                              ),
                              child: auth.loading
                                  ? const SizedBox(
                                      height: 22,
                                      width: 22,
                                      child: CircularProgressIndicator(
                                        strokeWidth: 2,
                                        color: Colors.white,
                                      ),
                                    )
                                  : const Text('دخول'),
                            ),
                            const SizedBox(height: 22),
                            const Row(
                              children: [
                                Expanded(child: Divider(color: _loginLine)),
                                Padding(
                                  padding: EdgeInsets.symmetric(horizontal: 12),
                                  child: Text(
                                    'أو',
                                    style: TextStyle(
                                      color: _loginMuted,
                                      fontWeight: FontWeight.w600,
                                    ),
                                  ),
                                ),
                                Expanded(child: Divider(color: _loginLine)),
                              ],
                            ),
                            const SizedBox(height: 18),
                            OutlinedButton(
                              onPressed: (auth.loading || _googleBusy)
                                  ? null
                                  : _google,
                              style: OutlinedButton.styleFrom(
                                backgroundColor: Colors.white,
                                foregroundColor: _loginInk,
                                minimumSize: const Size.fromHeight(52),
                                side: const BorderSide(color: _loginLine),
                                shape: const StadiumBorder(),
                              ),
                              child: _googleBusy
                                  ? const SizedBox(
                                      width: 18,
                                      height: 18,
                                      child: CircularProgressIndicator(
                                        strokeWidth: 2,
                                      ),
                                    )
                                  : const Row(
                                      mainAxisAlignment:
                                          MainAxisAlignment.center,
                                      mainAxisSize: MainAxisSize.min,
                                      children: [
                                        _GoogleMark(),
                                        SizedBox(width: 10),
                                        Text(
                                          'الدخول عبر Google',
                                          style: TextStyle(
                                            fontWeight: FontWeight.w700,
                                            fontSize: 15,
                                          ),
                                        ),
                                      ],
                                    ),
                            ),
                            const SizedBox(height: 22),
                            Row(
                              mainAxisAlignment: MainAxisAlignment.center,
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                const Text(
                                  'ليس لديك حساب؟',
                                  style: TextStyle(
                                    fontSize: 13.5,
                                    color: _loginMuted,
                                    fontWeight: FontWeight.w500,
                                  ),
                                ),
                                const SizedBox(width: 4),
                                Text(
                                  'إنشاء حساب',
                                  style: TextStyle(
                                    color: AppTheme.brand,
                                    fontWeight: FontWeight.w700,
                                    fontSize: 13.5,
                                  ),
                                ),
                              ],
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _LoginShapesPainter extends CustomPainter {
  const _LoginShapesPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()..color = _loginShape;

    canvas.drawRRect(
      RRect.fromRectAndRadius(
        const Rect.fromLTWH(-40, 64, 128, 128),
        const Radius.circular(30),
      ),
      paint,
    );
    canvas.drawRRect(
      RRect.fromRectAndRadius(
        const Rect.fromLTWH(36, 28, 92, 92),
        const Radius.circular(24),
      ),
      paint,
    );
    canvas.drawRRect(
      RRect.fromRectAndRadius(
        Rect.fromLTWH(size.width - 64, 80, 120, 120),
        const Radius.circular(28),
      ),
      paint,
    );
    canvas.drawRRect(
      RRect.fromRectAndRadius(
        Rect.fromLTWH(-36, size.height - 150, 140, 140),
        const Radius.circular(32),
      ),
      paint,
    );
    canvas.drawRRect(
      RRect.fromRectAndRadius(
        Rect.fromLTWH(size.width - 96, size.height - 136, 150, 150),
        const Radius.circular(34),
      ),
      paint,
    );
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

class _GoogleMark extends StatelessWidget {
  const _GoogleMark();

  @override
  Widget build(BuildContext context) {
    return const SizedBox(
      width: 22,
      height: 22,
      child: CustomPaint(painter: _GoogleLogoPainter()),
    );
  }
}

class _GoogleLogoPainter extends CustomPainter {
  const _GoogleLogoPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final s = size.shortestSide;
    final c = Offset(s / 2, s / 2);
    final stroke = s * 0.18;
    final rect = Rect.fromCircle(center: c, radius: s / 2 - stroke / 2);
    final paint = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = stroke
      ..strokeCap = StrokeCap.butt;

    paint.color = const Color(0xFFEA4335);
    canvas.drawArc(rect, -1.05, 1.35, false, paint);
    paint.color = const Color(0xFFFBBC05);
    canvas.drawArc(rect, 0.28, 1.15, false, paint);
    paint.color = const Color(0xFF34A853);
    canvas.drawArc(rect, 1.45, 1.2, false, paint);
    paint.color = const Color(0xFF4285F4);
    canvas.drawArc(rect, 2.7, 1.55, false, paint);

    final bar = Paint()..color = const Color(0xFF4285F4);
    canvas.drawRRect(
      RRect.fromRectAndRadius(
        Rect.fromLTWH(c.dx, c.dy - stroke / 2, s / 2 - stroke / 3, stroke),
        Radius.circular(stroke / 4),
      ),
      bar,
    );
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
