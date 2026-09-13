import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/features/email/presentation/widgets/recipient_picker_sheet.dart';

class EmailComposeScreen extends ConsumerStatefulWidget {
  const EmailComposeScreen({
    super.key,
    this.replyTo,
    this.prefillTo,
    this.prefillSubject,
    this.accountId,
  });

  final EmailMessageModel? replyTo;
  final String? prefillTo;
  final String? prefillSubject;
  final int? accountId;

  @override
  ConsumerState<EmailComposeScreen> createState() => _EmailComposeScreenState();
}

class _EmailComposeScreenState extends ConsumerState<EmailComposeScreen> {
  final _subject = TextEditingController();
  final _body = TextEditingController();
  final _cc = TextEditingController();
  final _bcc = TextEditingController();
  List<EmailAccountModel> _accounts = [];
  int? _accountId;
  bool _sending = false;
  bool _loadingAccounts = true;
  bool _showCcBcc = false;
  RecipientSelection _recipients = const RecipientSelection();

  @override
  void initState() {
    super.initState();
    final prefill = widget.prefillTo ?? widget.replyTo?.sender;
    if (prefill != null && prefill.trim().isNotEmpty) {
      _recipients = RecipientSelection(emails: [prefill.trim()]);
    }
    _subject.text = widget.prefillSubject ??
        (widget.replyTo?.subject == null ? '' : 'Re: ${widget.replyTo!.subject}');
    _accountId = widget.accountId ?? widget.replyTo?.emailAccountId;
    _loadAccounts();
  }

  Future<void> _loadAccounts() async {
    try {
      final accounts = await ref.read(emailRepositoryProvider).accounts();
      if (!mounted) return;
      setState(() {
        _accounts = accounts;
        _accountId ??= accounts.isNotEmpty ? accounts.first.id : null;
        _loadingAccounts = false;
      });
    } catch (_) {
      if (mounted) setState(() => _loadingAccounts = false);
    }
  }

  @override
  void dispose() {
    _subject.dispose();
    _body.dispose();
    _cc.dispose();
    _bcc.dispose();
    super.dispose();
  }

  Future<void> _pickRecipients() async {
    final result = await RecipientPickerSheet.show(context, initial: _recipients);
    if (result != null && mounted) {
      setState(() => _recipients = result);
    }
  }

  List<Widget> _recipientChips() {
    final chips = <Widget>[];
    if (_recipients.allContacts) {
      chips.add(InputChip(
        label: Text('جميع جهات الاتصال (${_recipients.allContactsCount})'),
        onDeleted: () => setState(() => _recipients = const RecipientSelection()),
      ));
    }
    for (final g in _recipients.groups) {
      chips.add(InputChip(
        label: Text('مجموعة: ${g.name}'),
        onDeleted: () => setState(() {
          _recipients = RecipientSelection(
            contacts: _recipients.contacts,
            groups: _recipients.groups.where((x) => x.id != g.id).toList(),
            emails: _recipients.emails,
          );
        }),
      ));
    }
    for (final c in _recipients.contacts) {
      chips.add(InputChip(
        label: Text(c.name),
        onDeleted: () => setState(() {
          _recipients = RecipientSelection(
            contacts: _recipients.contacts.where((x) => x.id != c.id).toList(),
            groups: _recipients.groups,
            emails: _recipients.emails,
          );
        }),
      ));
    }
    for (final e in _recipients.emails) {
      chips.add(InputChip(
        label: Text(e, textDirection: TextDirection.ltr),
        onDeleted: () => setState(() {
          _recipients = RecipientSelection(
            contacts: _recipients.contacts,
            groups: _recipients.groups,
            emails: _recipients.emails.where((x) => x != e).toList(),
          );
        }),
      ));
    }
    return chips;
  }

  Future<void> _send() async {
    if (_accountId == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('اختر حساب الإرسال أولاً.')),
      );
      return;
    }
    if (_recipients.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('أضف مستلماً واحداً على الأقل.')),
      );
      return;
    }
    if (_subject.text.trim().isEmpty || _body.text.trim().isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('الموضوع والنص مطلوبان.')),
      );
      return;
    }

    setState(() => _sending = true);
    try {
      final useCampaign = _recipients.isCampaign;

      if (useCampaign) {
        final campaign = await ref.read(campaignRepositoryProvider).create(
              emailAccountId: _accountId!,
              subject: _subject.text.trim(),
              body: _body.text.trim(),
              contactIds: _recipients.contacts.map((c) => c.id).toList(),
              groupIds: _recipients.groups.map((g) => g.id).toList(),
              emails: _recipients.emails,
              allContacts: _recipients.allContacts,
              confirmAll: _recipients.allContacts,
            );
        if (mounted) {
          context.pushReplacement('/email/campaigns/${campaign.id}');
        }
        return;
      }

      // Single recipient path — keep POST /emails
      final to = _recipients.contacts.isNotEmpty
          ? _recipients.contacts.first.email
          : _recipients.emails.first;
      await ref.read(emailRepositoryProvider).send(
            emailAccountId: _accountId!,
            to: to,
            subject: _subject.text.trim(),
            body: _body.text.trim(),
            replyToMessageId: widget.replyTo?.id,
          );
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('تم إرسال الرسالة')));
        context.pop();
      }
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    } catch (_) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('تعذر الإرسال')));
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final chips = _recipientChips();
    return Scaffold(
      appBar: AppBar(title: Text(widget.replyTo == null ? 'رسالة جديدة' : 'رد')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (_loadingAccounts)
            const LinearProgressIndicator(minHeight: 2)
          else if (_accounts.isEmpty)
            const Text('لا توجد حسابات بريد مرتبطة. أضف حساباً من لوحة الإدارة.')
          else
            DropdownMenu<int>(
              initialSelection: _accountId,
              label: const Text('حساب الإرسال'),
              expandedInsets: EdgeInsets.zero,
              onSelected: (v) => setState(() => _accountId = v),
              dropdownMenuEntries: [
                for (final a in _accounts)
                  DropdownMenuEntry(value: a.id, label: '${a.name} · ${a.email}'),
              ],
            ),
          const SizedBox(height: 12),
          InputDecorator(
            decoration: InputDecoration(
              labelText: 'إلى',
              suffixIcon: IconButton(
                tooltip: 'اختيار مستلمين',
                onPressed: _pickRecipients,
                icon: const Icon(Icons.person_search_outlined),
              ),
            ),
            child: chips.isEmpty
                ? GestureDetector(
                    onTap: _pickRecipients,
                    child: Text('اضغط لاختيار مستلمين', style: TextStyle(color: Colors.grey.shade600)),
                  )
                : Wrap(spacing: 6, runSpacing: 6, children: chips),
          ),
          Align(
            alignment: AlignmentDirectional.centerStart,
            child: TextButton(
              onPressed: () => setState(() => _showCcBcc = !_showCcBcc),
              child: Text(_showCcBcc ? 'إخفاء CC/BCC' : 'إظهار CC/BCC'),
            ),
          ),
          if (_showCcBcc) ...[
            TextField(
              controller: _cc,
              decoration: const InputDecoration(labelText: 'CC', helperText: 'اختياري — للإرسال الفردي فقط حالياً'),
              textDirection: TextDirection.ltr,
              textAlign: TextAlign.left,
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _bcc,
              decoration: const InputDecoration(labelText: 'BCC', helperText: 'اختياري — للإرسال الفردي فقط حالياً'),
              textDirection: TextDirection.ltr,
              textAlign: TextAlign.left,
            ),
            const SizedBox(height: 12),
          ],
          TextField(controller: _subject, decoration: const InputDecoration(labelText: 'الموضوع')),
          const SizedBox(height: 12),
          TextField(controller: _body, decoration: const InputDecoration(labelText: 'النص'), minLines: 8, maxLines: 16),
          const SizedBox(height: 16),
          FilledButton(
            onPressed: _sending ? null : _send,
            child: Text(_sending ? 'جارٍ الإرسال...' : (_recipients.isCampaign ? 'جدولة حملة' : 'إرسال')),
          ),
        ],
      ),
    );
  }
}
