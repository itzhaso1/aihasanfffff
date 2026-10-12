import 'package:flutter/material.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/features/privacy/pin_codec.dart';

Future<String?> showCreatePinDialog(BuildContext context) {
  return showDialog<String>(
    context: context,
    barrierDismissible: false,
    builder: (context) => const _CreatePinDialog(),
  );
}

Future<bool> showVerifyPinDialog(
  BuildContext context, {
  required String title,
  required String subtitle,
  required bool allowBiometric,
  required bool Function(String pin) verify,
  required Future<bool> Function(String reason) biometric,
}) {
  return showDialog<bool>(
    context: context,
    barrierDismissible: true,
    builder: (context) => _VerifyPinDialog(
      title: title,
      subtitle: subtitle,
      allowBiometric: allowBiometric,
      verify: verify,
      biometric: biometric,
    ),
  ).then((value) => value == true);
}

Future<bool> showChangePinDialog(
  BuildContext context, {
  required bool Function(String pin) verify,
  required Future<String?> Function(String current, String next) change,
}) {
  return showDialog<bool>(
    context: context,
    barrierDismissible: false,
    builder: (context) => _ChangePinDialog(verify: verify, change: change),
  ).then((value) => value == true);
}

class _CreatePinDialog extends StatefulWidget {
  const _CreatePinDialog();

  @override
  State<_CreatePinDialog> createState() => _CreatePinDialogState();
}

class _CreatePinDialogState extends State<_CreatePinDialog> {
  String _first = '';
  String _pin = '';
  String? _error;

  void _submit() {
    if (!PinCodec.isValid(_pin)) {
      setState(() => _error = 'استخدم 4 أرقام.');
      return;
    }
    if (_first.isEmpty) {
      setState(() {
        _first = _pin;
        _pin = '';
        _error = null;
      });
      return;
    }
    if (_pin != _first) {
      setState(() {
        _first = '';
        _pin = '';
        _error = 'الرمزان غير متطابقين. أعد المحاولة.';
      });
      return;
    }
    Navigator.of(context).pop(_pin);
  }

  @override
  Widget build(BuildContext context) {
    return _PinScaffold(
      title: 'رمز PIN',
      subtitle: _first.isEmpty ? 'اختر 4 أرقام لقفل الدردشات والتطبيق' : 'أدخل الرمز مرة أخرى للتأكيد',
      pin: _pin,
      error: _error,
      onChanged: (value) => setState(() {
        _pin = value;
        _error = null;
      }),
      onSubmit: _submit,
      onCancel: () => Navigator.of(context).pop(),
    );
  }
}

class _VerifyPinDialog extends StatefulWidget {
  const _VerifyPinDialog({
    required this.title,
    required this.subtitle,
    required this.allowBiometric,
    required this.verify,
    required this.biometric,
  });

  final String title;
  final String subtitle;
  final bool allowBiometric;
  final bool Function(String pin) verify;
  final Future<bool> Function(String reason) biometric;

  @override
  State<_VerifyPinDialog> createState() => _VerifyPinDialogState();
}

class _VerifyPinDialogState extends State<_VerifyPinDialog> {
  String _pin = '';
  String? _error;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    if (widget.allowBiometric) {
      WidgetsBinding.instance.addPostFrameCallback((_) => _useBiometric());
    }
  }

  Future<void> _useBiometric() async {
    if (_busy) return;
    setState(() => _busy = true);
    final ok = await widget.biometric(widget.subtitle);
    if (!mounted) return;
    if (ok) {
      Navigator.of(context).pop(true);
      return;
    }
    setState(() => _busy = false);
  }

  void _submit() {
    if (widget.verify(_pin)) {
      Navigator.of(context).pop(true);
      return;
    }
    setState(() {
      _pin = '';
      _error = 'الرمز غير صحيح.';
    });
  }

  @override
  Widget build(BuildContext context) {
    return _PinScaffold(
      title: widget.title,
      subtitle: widget.subtitle,
      pin: _pin,
      error: _error,
      busy: _busy,
      onChanged: (value) => setState(() {
        _pin = value;
        _error = null;
      }),
      onSubmit: _submit,
      onCancel: () => Navigator.of(context).pop(false),
      onBiometric: widget.allowBiometric ? _useBiometric : null,
    );
  }
}

class _ChangePinDialog extends StatefulWidget {
  const _ChangePinDialog({required this.verify, required this.change});

  final bool Function(String pin) verify;
  final Future<String?> Function(String current, String next) change;

  @override
  State<_ChangePinDialog> createState() => _ChangePinDialogState();
}

class _ChangePinDialogState extends State<_ChangePinDialog> {
  String _current = '';
  String _next = '';
  String _pin = '';
  String? _error;
  bool _busy = false;

  Future<void> _submit() async {
    if (!PinCodec.isValid(_pin)) {
      setState(() => _error = 'استخدم 4 أرقام.');
      return;
    }
    if (_current.isEmpty) {
      if (!widget.verify(_pin)) {
        setState(() {
          _pin = '';
          _error = 'الرمز الحالي غير صحيح.';
        });
        return;
      }
      setState(() {
        _current = _pin;
        _pin = '';
        _error = null;
      });
      return;
    }
    if (_next.isEmpty) {
      setState(() {
        _next = _pin;
        _pin = '';
        _error = null;
      });
      return;
    }
    if (_pin != _next) {
      setState(() {
        _next = '';
        _pin = '';
        _error = 'الرمزان غير متطابقين.';
      });
      return;
    }
    setState(() => _busy = true);
    final error = await widget.change(_current, _next);
    if (!mounted) return;
    if (error != null) {
      setState(() {
        _busy = false;
        _current = '';
        _next = '';
        _pin = '';
        _error = error;
      });
      return;
    }
    Navigator.of(context).pop(true);
  }

  String get _subtitle {
    if (_current.isEmpty) return 'أدخل الرمز الحالي';
    if (_next.isEmpty) return 'أدخل الرمز الجديد';
    return 'أكّد الرمز الجديد';
  }

  @override
  Widget build(BuildContext context) {
    return _PinScaffold(
      title: 'تغيير رمز PIN',
      subtitle: _subtitle,
      pin: _pin,
      error: _error,
      busy: _busy,
      onChanged: (value) => setState(() {
        _pin = value;
        _error = null;
      }),
      onSubmit: _submit,
      onCancel: () => Navigator.of(context).pop(false),
    );
  }
}

class _PinScaffold extends StatelessWidget {
  const _PinScaffold({
    required this.title,
    required this.subtitle,
    required this.pin,
    required this.onChanged,
    required this.onSubmit,
    required this.onCancel,
    this.error,
    this.busy = false,
    this.onBiometric,
  });

  final String title;
  final String subtitle;
  final String pin;
  final String? error;
  final bool busy;
  final ValueChanged<String> onChanged;
  final VoidCallback onSubmit;
  final VoidCallback onCancel;
  final VoidCallback? onBiometric;

  @override
  Widget build(BuildContext context) {
    return Dialog(
      backgroundColor: HasimTokens.cardBg(context),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 360),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 20, 20, 16),
          child: PinEntryBody(
            title: title,
            subtitle: subtitle,
            pin: pin,
            error: error,
            busy: busy,
            onChanged: onChanged,
            onSubmit: onSubmit,
            onCancel: onCancel,
            onBiometric: onBiometric,
          ),
        ),
      ),
    );
  }
}

class PinEntryBody extends StatelessWidget {
  const PinEntryBody({
    super.key,
    required this.title,
    required this.subtitle,
    required this.pin,
    required this.onChanged,
    required this.onSubmit,
    this.error,
    this.busy = false,
    this.onCancel,
    this.onBiometric,
  });

  final String title;
  final String subtitle;
  final String pin;
  final String? error;
  final bool busy;
  final ValueChanged<String> onChanged;
  final VoidCallback onSubmit;
  final VoidCallback? onCancel;
  final VoidCallback? onBiometric;

  void _push(String digit) {
    if (busy || pin.length >= PinCodec.length) return;
    final next = '$pin$digit';
    onChanged(next);
    if (next.length == PinCodec.length) onSubmit();
  }

  void _pop() {
    if (busy || pin.isEmpty) return;
    onChanged(pin.substring(0, pin.length - 1));
  }

  @override
  Widget build(BuildContext context) {
    final muted = HasimTokens.mutedColor(context);
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(Icons.pin_outlined, color: HasimTokens.chatGreen, size: 32),
        const SizedBox(height: 10),
        Text(title, style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
        const SizedBox(height: 6),
        Text(subtitle, textAlign: TextAlign.center, style: TextStyle(color: muted, height: 1.4)),
        const SizedBox(height: 16),
        Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            for (var i = 0; i < PinCodec.length; i++)
              Container(
                width: 14,
                height: 14,
                margin: const EdgeInsets.symmetric(horizontal: 6),
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: i < pin.length ? HasimTokens.chatGreen : Colors.transparent,
                  border: Border.all(color: HasimTokens.chatGreen),
                ),
              ),
          ],
        ),
        if (error != null) ...[
          const SizedBox(height: 10),
          Text(error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
        ],
        const SizedBox(height: 12),
        for (final row in const [
          ['1', '2', '3'],
          ['4', '5', '6'],
          ['7', '8', '9'],
        ])
          Row(
            children: [
              for (final digit in row)
                Expanded(
                  child: _PinKey(label: digit, onTap: busy ? null : () => _push(digit)),
                ),
            ],
          ),
        Row(
          children: [
            Expanded(
              child: onBiometric == null
                  ? const SizedBox(height: 56)
                  : _PinKey(icon: Icons.fingerprint_rounded, onTap: busy ? null : onBiometric),
            ),
            Expanded(child: _PinKey(label: '0', onTap: busy ? null : () => _push('0'))),
            Expanded(child: _PinKey(icon: Icons.backspace_outlined, onTap: busy ? null : _pop)),
          ],
        ),
        TextButton(onPressed: busy ? null : onCancel, child: const Text('إلغاء')),
      ],
    );
  }
}

class _PinKey extends StatelessWidget {
  const _PinKey({this.label, this.icon, this.onTap});

  final String? label;
  final IconData? icon;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.all(4),
      child: Material(
        color: HasimTokens.pageBg(context),
        borderRadius: BorderRadius.circular(14),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(14),
          child: SizedBox(
            height: 52,
            child: Center(
              child: icon != null
                  ? Icon(icon, color: HasimTokens.chatGreen)
                  : Text(label ?? '', style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700)),
            ),
          ),
        ),
      ),
    );
  }
}

class LockedContentGate extends StatelessWidget {
  const LockedContentGate({
    super.key,
    required this.title,
    required this.subtitle,
    required this.onUnlock,
  });

  final String title;
  final String subtitle;
  final VoidCallback onUnlock;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.pin_outlined, size: 42, color: HasimTokens.chatGreen),
            const SizedBox(height: 12),
            Text(title, style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
            const SizedBox(height: 6),
            Text(
              subtitle,
              textAlign: TextAlign.center,
              style: TextStyle(color: HasimTokens.mutedColor(context), height: 1.4),
            ),
            const SizedBox(height: 16),
            FilledButton.icon(
              onPressed: onUnlock,
              icon: const Icon(Icons.fingerprint_rounded),
              label: const Text('فتح'),
            ),
          ],
        ),
      ),
    );
  }
}

class AppLockScreen extends StatelessWidget {
  const AppLockScreen({super.key, required this.onVerified, this.onBiometric});

  final bool Function(String pin) onVerified;
  final Future<bool> Function()? onBiometric;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: HasimTokens.pageBg(context),
      body: SafeArea(
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 360),
            child: _AppLockEntry(onVerified: onVerified, onBiometric: onBiometric),
          ),
        ),
      ),
    );
  }
}

class _AppLockEntry extends StatefulWidget {
  const _AppLockEntry({required this.onVerified, this.onBiometric});

  final bool Function(String pin) onVerified;
  final Future<bool> Function()? onBiometric;

  @override
  State<_AppLockEntry> createState() => _AppLockEntryState();
}

class _AppLockEntryState extends State<_AppLockEntry> {
  String _pin = '';
  String? _error;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    if (widget.onBiometric != null) {
      WidgetsBinding.instance.addPostFrameCallback((_) => _useBiometric());
    }
  }

  Future<void> _useBiometric() async {
    final action = widget.onBiometric;
    if (action == null || _busy) return;
    setState(() => _busy = true);
    final ok = await action();
    if (!mounted) return;
    if (!ok) setState(() => _busy = false);
  }

  void _submit() {
    if (widget.onVerified(_pin)) return;
    setState(() {
      _pin = '';
      _error = 'الرمز غير صحيح.';
    });
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.all(24),
      child: PinEntryBody(
        title: 'حاسم مقفل',
        subtitle: 'أدخل رمز PIN أو استخدم البصمة',
        pin: _pin,
        error: _error,
        busy: _busy,
        onChanged: (value) => setState(() {
          _pin = value;
          _error = null;
        }),
        onSubmit: _submit,
        onBiometric: widget.onBiometric == null ? null : _useBiometric,
      ),
    );
  }
}
