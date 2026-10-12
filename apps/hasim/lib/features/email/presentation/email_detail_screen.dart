import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/utils/relative_time.dart';
import 'package:hasim/core/widgets/hasim_primary_button.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/email/presentation/email_compose_screen.dart';
import 'package:hasim/features/email/presentation/widgets/email_detail_cards.dart';
import 'package:hasim/features/worker/providers/worker_capability_access.dart';

class EmailDetailScreen extends ConsumerStatefulWidget {
  const EmailDetailScreen({super.key, required this.id});
  final int id;
  @override
  ConsumerState<EmailDetailScreen> createState() => _EmailDetailScreenState();
}

class _EmailDetailScreenState extends ConsumerState<EmailDetailScreen> {
  EmailMessageModel? _mail;
  List<EmailAccountModel> _accounts = const [];
  String? _error;
  bool _loading = true;
  bool _senderInContacts = true;
  bool _checkingContact = false;
  bool _addingContact = false;
  bool _starring = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    if (ref.read(authControllerProvider).isIndependentWorker) {
      try {
        final permission = await ref.read(
          workerWorkspaceCapabilityProvider('email.view').future,
        );
        if (!permission.workspaceSelected || !permission.allowed) {
          await ref.read(emailRepositoryProvider).clearLocalWorkspaceData();
          if (!mounted) return;
          setState(() {
            _error = 'ليس لديك صلاحيات الوصول إلى البريد.';
            _loading = false;
          });
          return;
        }
      } catch (_) {
        if (!mounted) return;
        setState(() {
          _error = 'تعذر التحقق من صلاحيات البريد حالياً.';
          _loading = false;
        });
        return;
      }
    }

    final cached = await ref
        .read(emailRepositoryProvider)
        .cachedMessage(widget.id);
    if (!mounted) return;
    if (cached != null) {
      setState(() {
        _mail = cached;
        _loading = cached.body == null || cached.body!.isEmpty;
        _error = null;
      });
    } else {
      setState(() {
        _loading = true;
        _error = null;
      });
    }
    try {
      final mail = await ref.read(emailRepositoryProvider).show(widget.id);
      List<EmailAccountModel> accounts = const [];
      try {
        accounts = await ref.read(emailRepositoryProvider).accounts();
      } catch (_) {
        // Accounts list is optional for rendering the message body.
      }
      if (!mounted) return;
      setState(() {
        _mail = mail;
        _accounts = accounts;
        _loading = false;
      });
      if (!mail.isRead) {
        try {
          await ref.read(emailRepositoryProvider).markRead(mail.id);
        } catch (_) {
          // Mark-read is best-effort; detail view still shows the message.
        }
      }
      await _checkSenderContact(mail.sender);
    } on ApiException catch (e) {
      if (e.statusCode == 403) {
        await ref.read(emailRepositoryProvider).clearLocalWorkspaceData();
      }
      if (!mounted) return;
      setState(() {
        _error = e.userMessage;
        _loading = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _error = 'تعذر فتح الرسالة.';
        _loading = false;
      });
    }
  }

  Future<void> _checkSenderContact(String sender) async {
    final email = _extractEmail(sender);
    if (email == null) {
      setState(() => _senderInContacts = true);
      return;
    }
    setState(() => _checkingContact = true);
    try {
      final matches = await ref
          .read(contactRepositoryProvider)
          .findByEmail(email);
      if (!mounted) return;
      setState(() {
        _senderInContacts = matches.isNotEmpty;
        _checkingContact = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _senderInContacts = true; // hide CTA on lookup failure
        _checkingContact = false;
      });
    }
  }

  String? _extractEmail(String raw) {
    final angle = RegExp(r'<([^>]+)>').firstMatch(raw);
    final candidate = (angle?.group(1) ?? raw).trim();
    if (!candidate.contains('@')) return null;
    return candidate;
  }

  String _accountLabel(EmailMessageModel mail) {
    if (mail.emailAccountId == null) return '';
    for (final account in _accounts) {
      if (account.id == mail.emailAccountId) {
        return account.email.isNotEmpty ? account.email : account.name;
      }
    }
    return '${mail.emailAccountId}';
  }

  String _visibleBody(EmailMessageModel mail) {
    final raw = mail.body ?? mail.preview ?? '';
    final trimmed = raw.trim();
    if (!RegExp(r'</?[a-zA-Z]').hasMatch(trimmed)) {
      return raw;
    }
    return trimmed
        .replaceAll(RegExp(r'<br\s*/?>', caseSensitive: false), '\n')
        .replaceAll(RegExp(r'</p>', caseSensitive: false), '\n\n')
        .replaceAll(RegExp(r'<[^>]+>'), ' ')
        .replaceAll('&nbsp;', ' ')
        .replaceAll('&amp;', '&')
        .replaceAll('&lt;', '<')
        .replaceAll('&gt;', '>')
        .replaceAll(RegExp(r'[ \t]+\n'), '\n')
        .replaceAll(RegExp(r'\n{3,}'), '\n\n')
        .trim();
  }

  Future<void> _addSenderContact() async {
    final mail = _mail;
    if (mail == null) return;
    final email = _extractEmail(mail.sender);
    if (email == null) return;
    final name = mail.sender.contains('<')
        ? mail.sender.split('<').first.trim().replaceAll('"', '')
        : email.split('@').first;
    setState(() => _addingContact = true);
    try {
      final created = await ref
          .read(contactRepositoryProvider)
          .create(name: name.isEmpty ? email : name, email: email);
      if (!mounted) return;
      setState(() {
        _senderInContacts = true;
        _addingContact = false;
      });
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: const Text('تمت الإضافة إلى جهات الاتصال'),
          action: SnackBarAction(
            label: 'عرض',
            onPressed: () => context.push('/contacts/${created.id}'),
          ),
        ),
      );
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _addingContact = false);
      if (e.statusCode == 422) {
        setState(() => _senderInContacts = true);
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(e.userMessage)));
      } else {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(e.userMessage)));
      }
    } catch (_) {
      if (mounted) {
        setState(() => _addingContact = false);
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(const SnackBar(content: Text('تعذر الإضافة')));
      }
    }
  }

  void _reply() {
    final mail = _mail;
    if (mail == null) return;
    Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) =>
            EmailComposeScreen(replyTo: mail, accountId: mail.emailAccountId),
      ),
    );
  }

  void _forward() {
    final mail = _mail;
    if (mail == null) return;
    final subject = mail.subject?.trim().isNotEmpty == true
        ? mail.subject!
        : '(بدون موضوع)';
    final quoted = _visibleBody(mail);
    Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => EmailComposeScreen(
          accountId: mail.emailAccountId,
          prefillSubject: subject.toLowerCase().startsWith('fwd:')
              ? subject
              : 'Fwd: $subject',
          prefillBody: quoted.isEmpty
              ? null
              : '---------- الرسالة المحوّلة ----------\n$quoted',
        ),
      ),
    );
  }

  Future<void> _toggleStar() async {
    final mail = _mail;
    if (mail == null || _starring) return;
    setState(() => _starring = true);
    try {
      await ref.read(emailRepositoryProvider).star(mail.id);
      if (!mounted) return;
      await _load();
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(const SnackBar(content: Text('تعذر تحديث النجمة')));
      }
    } finally {
      if (mounted) setState(() => _starring = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final mint = HasimTokens.pageBg(context);
    final mail = _mail;
    final canCompose = !ref.watch(authControllerProvider).isIndependentWorker;

    return Scaffold(
      backgroundColor: mint,
      appBar: AppBar(
        automaticallyImplyLeading: false,
        backgroundColor: mint,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        centerTitle: true,
        leading: IconButton(
          tooltip: 'رجوع',
          onPressed: () => context.pop(),
          icon: const Icon(Icons.arrow_forward_rounded),
        ),
        title: Text(
          'تفاصيل الرسالة',
          style: Theme.of(context).textTheme.titleLarge?.copyWith(
            fontWeight: FontWeight.w800,
            color: HasimTokens.inkColor(context),
          ),
        ),
        actions: [
          if (canCompose)
            IconButton(
              tooltip: 'إعادة توجيه',
              onPressed: mail == null ? null : _forward,
              icon: const Icon(Icons.reply_rounded),
            ),
          IconButton(
            tooltip: mail?.isStarred == true ? 'إزالة النجمة' : 'تمييز بنجمة',
            onPressed: mail == null || _starring ? null : _toggleStar,
            icon: Icon(
              mail?.isStarred == true
                  ? Icons.star_rounded
                  : Icons.star_border_rounded,
              color: mail?.isStarred == true ? const Color(0xFFE6B325) : null,
            ),
          ),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
          ? Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(_error!, textAlign: TextAlign.center),
                    const SizedBox(height: 12),
                    HasimPrimaryButton(
                      label: 'إعادة المحاولة',
                      onPressed: _load,
                    ),
                  ],
                ),
              ),
            )
          : ListView(
              padding: const EdgeInsets.fromLTRB(
                16,
                8,
                16,
                HasimTokens.pageBottom,
              ),
              children: [
                MessageInfoCard(
                  subject: mail!.subject?.isNotEmpty == true
                      ? mail.subject!
                      : '(بدون موضوع)',
                  sender: mail.sender,
                  recipient: mail.recipient,
                  accountLabel: _accountLabel(mail),
                  timeLabel: relativeTimeAr(mail.createdAt),
                ),
                const SizedBox(height: 14),
                MessageBodyCard(body: _visibleBody(mail)),
                if (canCompose) ...[
                  const SizedBox(height: 22),
                  HasimPrimaryButton(
                    label: 'رد',
                    icon: Icons.reply_rounded,
                    expanded: true,
                    onPressed: _reply,
                  ),
                ],
                if (canCompose && !_senderInContacts && !_checkingContact) ...[
                  const SizedBox(height: 10),
                  HasimOutlineButton(
                    label: _addingContact
                        ? 'جارٍ الإضافة...'
                        : 'إضافة إلى جهات الاتصال',
                    icon: Icons.person_add_alt_1_rounded,
                    expanded: true,
                    enabled: !_addingContact,
                    busy: _addingContact,
                    onPressed: _addSenderContact,
                  ),
                ],
                if (canCompose) ...[
                  const SizedBox(height: 8),
                  Center(
                    child: TextButton(
                      onPressed: () => context.push('/email/compose'),
                      child: const Text(
                        'رسالة جديدة',
                        style: TextStyle(
                          color: AppTheme.brand,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                    ),
                  ),
                ],
              ],
            ),
    );
  }
}
