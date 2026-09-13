import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/features/email/presentation/email_compose_screen.dart';

class EmailDetailScreen extends ConsumerStatefulWidget {
  const EmailDetailScreen({super.key, required this.id});
  final int id;
  @override
  ConsumerState<EmailDetailScreen> createState() => _EmailDetailScreenState();
}

class _EmailDetailScreenState extends ConsumerState<EmailDetailScreen> {
  EmailMessageModel? _mail;
  String? _error;
  bool _loading = true;
  bool _senderInContacts = true;
  bool _checkingContact = false;
  bool _addingContact = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final mail = await ref.read(emailRepositoryProvider).show(widget.id);
      setState(() {
        _mail = mail;
        _loading = false;
      });
      if (!mail.isRead) {
        try {
          await ref.read(emailRepositoryProvider).markRead(mail.id);
        } catch (_) {}
      }
      await _checkSenderContact(mail.sender);
    } on ApiException catch (e) {
      setState(() {
        _error = e.message;
        _loading = false;
      });
    } catch (_) {
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
      final matches = await ref.read(contactRepositoryProvider).findByEmail(email);
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
      final created = await ref.read(contactRepositoryProvider).create(
            name: name.isEmpty ? email : name,
            email: email,
          );
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
      // Duplicate → treat as already present
      if (e.statusCode == 422) {
        setState(() => _senderInContacts = true);
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
      } else {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
      }
    } catch (_) {
      if (mounted) {
        setState(() => _addingContact = false);
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('تعذر الإضافة')));
      }
    }
  }

  void _reply() {
    final mail = _mail;
    if (mail == null) return;
    Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => EmailComposeScreen(replyTo: mail, accountId: mail.emailAccountId),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('تفاصيل الرسالة'),
        actions: [
          IconButton(
            tooltip: 'رد',
            onPressed: _mail == null ? null : _reply,
            icon: const Icon(Icons.reply),
          ),
          IconButton(
            onPressed: _mail == null
                ? null
                : () async {
                    await ref.read(emailRepositoryProvider).star(_mail!.id);
                    await _load();
                  },
            icon: Icon(_mail?.isStarred == true ? Icons.star : Icons.star_border),
          ),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(child: Text(_error!))
              : ListView(
                  padding: const EdgeInsets.all(16),
                  children: [
                    Text(
                      _mail!.subject ?? '(بدون موضوع)',
                      style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800),
                    ),
                    const SizedBox(height: 8),
                    Text('من: ${_mail!.sender}'),
                    Text('إلى: ${_mail!.recipient}'),
                    if (_mail!.emailAccountId != null)
                      Text('حساب: ${_mail!.emailAccountId}', style: TextStyle(color: Colors.grey.shade600, fontSize: 12)),
                    const Divider(height: 24),
                    Text(_mail!.body ?? _mail!.preview ?? '', style: const TextStyle(height: 1.5)),
                    const SizedBox(height: 24),
                    FilledButton.icon(
                      onPressed: _reply,
                      icon: const Icon(Icons.reply),
                      label: const Text('رد'),
                    ),
                    if (!_senderInContacts && !_checkingContact)
                      OutlinedButton.icon(
                        onPressed: _addingContact ? null : _addSenderContact,
                        icon: const Icon(Icons.person_add_alt),
                        label: Text(_addingContact ? 'جارٍ الإضافة...' : 'إضافة إلى جهات الاتصال'),
                      ),
                    TextButton(
                      onPressed: () => context.push('/email/compose'),
                      child: const Text('رسالة جديدة'),
                    ),
                  ],
                ),
    );
  }
}
