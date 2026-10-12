import 'dart:typed_data';

import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/config/app_config.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/mobile/hasim_mobile_scaffold.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/core/widgets/hasim_card.dart';
import 'package:hasim/core/widgets/hasim_form_field.dart';
import 'package:hasim/core/widgets/hasim_settings_body.dart';
import 'package:image_picker/image_picker.dart';

class ProfileScreen extends ConsumerStatefulWidget {
  const ProfileScreen({super.key, this.embedded = false});

  final bool embedded;

  @override
  ConsumerState<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends ConsumerState<ProfileScreen> {
  late final TextEditingController _name;
  late final TextEditingController _email;
  late final TextEditingController _phone;
  final _currentPassword = TextEditingController();
  final _password = TextEditingController();
  final _confirm = TextEditingController();
  bool _saving = false;
  bool _changingPassword = false;
  bool _obscureCurrent = true;
  bool _obscureNew = true;
  bool _obscureConfirm = true;
  Uint8List? _avatarBytes;
  bool _uploadingAvatar = false;

  @override
  void initState() {
    super.initState();
    final user = ref.read(authControllerProvider).user;
    _name = TextEditingController(text: user?.name ?? '');
    _email = TextEditingController(text: user?.email ?? '');
    _phone = TextEditingController(text: user?.phone ?? '');
  }

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    _phone.dispose();
    _currentPassword.dispose();
    _password.dispose();
    _confirm.dispose();
    super.dispose();
  }

  Future<void> _saveProfile() async {
    setState(() => _saving = true);
    final ok = await ref.read(authControllerProvider.notifier).updateProfile(
          name: _name.text.trim(),
          email: _email.text.trim().isEmpty ? null : _email.text.trim(),
          phone: _phone.text.trim().isEmpty ? null : _phone.text.trim(),
        );
    if (!mounted) return;
    setState(() => _saving = false);
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(ok ? 'تم حفظ الملف الشخصي' : (ref.read(authControllerProvider).error ?? 'تعذر الحفظ'))),
    );
  }

  Future<void> _changePassword() async {
    if (_password.text != _confirm.text) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('كلمة المرور غير متطابقة')));
      return;
    }
    setState(() => _changingPassword = true);
    try {
      await ref.read(authRepositoryProvider).changePassword(
            currentPassword: _currentPassword.text,
            password: _password.text,
            passwordConfirmation: _confirm.text,
          );
      if (mounted) {
        _currentPassword.clear();
        _password.clear();
        _confirm.clear();
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('تم تحديث كلمة المرور')));
      }
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.userMessage)));
    } catch (_) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('تعذر تغيير كلمة المرور')));
    } finally {
      if (mounted) setState(() => _changingPassword = false);
    }
  }

  Future<void> _avatar() async {
    final picker = ImagePicker();
    final file = await picker.pickImage(
      source: ImageSource.gallery,
      imageQuality: 85,
    );
    if (file == null) {
      return;
    }
    final bytes = await file.readAsBytes();
    if (bytes.isEmpty) {
      return;
    }
    final filename = file.name.trim().isEmpty ? 'avatar.jpg' : file.name;
    setState(() {
      _avatarBytes = bytes;
      _uploadingAvatar = true;
    });
    try {
      final user = await ref.read(authRepositoryProvider).uploadAvatar(
        bytes: bytes,
        filename: filename,
      );
      ref.read(authControllerProvider.notifier).setUser(user);
      if (mounted) {
        setState(() => _uploadingAvatar = false);
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('تم تحديث الصورة')),
        );
      }
    } on ApiException catch (e) {
      if (mounted) {
        setState(() {
          _avatarBytes = null;
          _uploadingAvatar = false;
        });
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(e.userMessage)),
        );
      }
    } catch (_) {
      if (mounted) {
        setState(() {
          _avatarBytes = null;
          _uploadingAvatar = false;
        });
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('تعذر رفع الصورة')),
        );
      }
    }
  }

  Widget _profileAvatar(String? avatarUrl, String? name) {
    final fallback = CircleAvatar(
      radius: 44,
      backgroundColor: AppTheme.brand.withValues(alpha: 0.12),
      child: Text(
        (name != null && name.isNotEmpty) ? name[0] : '?',
        style: const TextStyle(
          fontSize: 30,
          fontWeight: FontWeight.w800,
          color: AppTheme.brand,
        ),
      ),
    );
    if (_avatarBytes != null) {
      return ClipOval(
        child: Image.memory(
          _avatarBytes!,
          width: 88,
          height: 88,
          fit: BoxFit.cover,
        ),
      );
    }
    final url = AppConfig.resolvePublicUrl(avatarUrl);
    if (url == null) {
      return fallback;
    }
    return ClipOval(
      child: CachedNetworkImage(
        imageUrl: url,
        width: 88,
        height: 88,
        fit: BoxFit.cover,
        errorWidget: (_, _, _) => fallback,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final user = ref.watch(authControllerProvider).user;

    final compact = HasimLayout.isCompact(context);
    final content = HasimSettingsBody(
          mobileLayout: compact && !widget.embedded,
          children: [
            HasimCard(
              child: Column(
                children: [
                  _profileAvatar(user?.avatarUrl, user?.name),
                  const SizedBox(height: 12),
                  TextButton.icon(
                    onPressed: _uploadingAvatar ? null : _avatar,
                    icon: const Icon(Icons.camera_alt_outlined, size: 18),
                    label: const Text('تغيير الصورة'),
                    style: TextButton.styleFrom(
                      foregroundColor: HasimTokens.mutedColor(context),
                      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 16),
            HasimCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  HasimFormField(
                    controller: _name,
                    label: 'الاسم',
                    icon: Icons.person_outline_rounded,
                  ),
                  const SizedBox(height: 16),
                  HasimFormField(
                    controller: _email,
                    label: 'البريد',
                    icon: Icons.mail_outline_rounded,
                    textDirection: TextDirection.ltr,
                    textAlign: TextAlign.left,
                  ),
                  const SizedBox(height: 16),
                  HasimFormField(
                    controller: _phone,
                    label: 'الجوال',
                    icon: Icons.phone_outlined,
                    textDirection: TextDirection.ltr,
                    textAlign: TextAlign.left,
                  ),
                  const SizedBox(height: 20),
                  Align(
                    alignment: AlignmentDirectional.centerStart,
                    child: SizedBox(
                      width: 220,
                      height: 46,
                      child: FilledButton(
                        onPressed: _saving ? null : _saveProfile,
                        style: FilledButton.styleFrom(
                          backgroundColor: AppTheme.brand,
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                          elevation: 0,
                        ),
                        child: Text(_saving ? 'جارٍ الحفظ...' : 'حفظ'),
                      ),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 16),
            HasimCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    'تغيير كلمة المرور',
                    style: Theme.of(context).textTheme.titleMedium?.copyWith(
                          fontWeight: FontWeight.w700,
                          color: HasimTokens.inkColor(context),
                        ),
                  ),
                  const SizedBox(height: 16),
                  HasimFormField(
                    controller: _currentPassword,
                    label: 'كلمة المرور الحالية',
                    icon: Icons.lock_outline_rounded,
                    obscureText: _obscureCurrent,
                    onToggleObscure: () => setState(() => _obscureCurrent = !_obscureCurrent),
                  ),
                  const SizedBox(height: 16),
                  HasimFormField(
                    controller: _password,
                    label: 'كلمة المرور الجديدة',
                    icon: Icons.lock_reset_rounded,
                    obscureText: _obscureNew,
                    onToggleObscure: () => setState(() => _obscureNew = !_obscureNew),
                  ),
                  const SizedBox(height: 16),
                  HasimFormField(
                    controller: _confirm,
                    label: 'تأكيد كلمة المرور',
                    icon: Icons.verified_user_outlined,
                    obscureText: _obscureConfirm,
                    onToggleObscure: () => setState(() => _obscureConfirm = !_obscureConfirm),
                  ),
                  const SizedBox(height: 20),
                  Align(
                    alignment: AlignmentDirectional.centerStart,
                    child: SizedBox(
                      width: 220,
                      height: 46,
                      child: OutlinedButton(
                        onPressed: _changingPassword ? null : _changePassword,
                        style: OutlinedButton.styleFrom(
                          foregroundColor: AppTheme.brand,
                          side: BorderSide(color: AppTheme.brand.withValues(alpha: 0.55)),
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                        ),
                        child: Text(_changingPassword ? 'جارٍ التحديث...' : 'تغيير كلمة المرور'),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],
    );

    if (widget.embedded) {
      return content;
    }

    if (compact) {
      return HasimMobileScaffold(title: 'الملف الشخصي', body: content);
    }

    return Scaffold(
      appBar: AppBar(title: const Text('الملف الشخصي')),
      backgroundColor: HasimTokens.pageBg(context),
      body: content,
    );
  }
}
