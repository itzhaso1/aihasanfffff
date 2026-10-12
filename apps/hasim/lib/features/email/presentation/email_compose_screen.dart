import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/hasim_primary_button.dart';
import 'package:hasim/features/email/data/email_repository.dart';
import 'package:hasim/features/email/presentation/widgets/compose_fields.dart';
import 'package:hasim/features/email/presentation/widgets/recipient_picker_sheet.dart';

class EmailComposeScreen extends ConsumerStatefulWidget {
  const EmailComposeScreen({
    super.key,
    this.replyTo,
    this.prefillTo,
    this.prefillSubject,
    this.prefillBody,
    this.accountId,
  });

  final EmailMessageModel? replyTo;
  final String? prefillTo;
  final String? prefillSubject;
  final String? prefillBody;
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
  bool _sent = false;
  EmailRepository? _emailRepo;
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
    if (widget.prefillBody != null && widget.prefillBody!.isNotEmpty) {
      _body.text = widget.prefillBody!;
    }
    _accountId = widget.accountId ?? widget.replyTo?.emailAccountId;
    _subject.addListener(_onFormChanged);
    _body.addListener(_onFormChanged);
    _loadAccounts();
    if (widget.replyTo == null && (widget.prefillTo == null || widget.prefillTo!.isEmpty)) {
      _loadLocalDraft();
    }
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _emailRepo = ref.read(emailRepositoryProvider);
  }

  Future<void> _loadLocalDraft() async {
    final draft = await ref.read(emailRepositoryProvider).composeDraft();
    if (!mounted || draft == null) return;
    if (_subject.text.isEmpty) _subject.text = draft.subject;
    if (_body.text.isEmpty) _body.text = draft.body;
  }

  void _onFormChanged() {
    if (mounted) setState(() {});
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
    final recipients = _recipients.emails.join(', ');
    final subject = _subject.text;
    final body = _body.text;
    if (!_sent && (subject.trim().isNotEmpty || body.trim().isNotEmpty)) {
      _emailRepo?.saveComposeDraft(
        recipients: recipients,
        subject: subject,
        body: body,
      );
    }
    _subject.removeListener(_onFormChanged);
    _body.removeListener(_onFormChanged);
    _subject.dispose();
    _body.dispose();
    _cc.dispose();
    _bcc.dispose();
    super.dispose();
  }

  EmailAccountModel? get _selectedAccount {
    if (_accountId == null) return null;
    for (final account in _accounts) {
      if (account.id == _accountId) return account;
    }
    return _accounts.isEmpty ? null : _accounts.first;
  }

  bool get _canSend =>
      !_sending &&
      _accountId != null &&
      !_recipients.isEmpty &&
      _subject.text.trim().isNotEmpty &&
      _body.text.trim().isNotEmpty;

  Future<void> _pickRecipients() async {
    final result = await RecipientPickerSheet.show(context, initial: _recipients);
    if (result != null && mounted) {
      setState(() => _recipients = result);
    }
  }

  Future<void> _pickAccount() async {
    if (_accounts.isEmpty) return;
    final selected = await showModalBottomSheet<int>(
      context: context,
      showDragHandle: true,
      builder: (ctx) {
        return SafeArea(
          child: ListView(
            shrinkWrap: true,
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 4, 20, 12),
                child: Text(
                  'حساب الإرسال',
                  style: Theme.of(ctx).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800),
                ),
              ),
              for (final account in _accounts)
                ListTile(
                  leading: CircleAvatar(
                    backgroundColor: HasimTokens.mintFill(ctx),
                    child: const Icon(Icons.mail_outline_rounded, color: AppTheme.brand, size: 18),
                  ),
                  title: Text(account.name, maxLines: 1, overflow: TextOverflow.ellipsis),
                  subtitle: Text(account.email, textDirection: TextDirection.ltr),
                  trailing: account.id == _accountId ? const Icon(Icons.check_rounded, color: AppTheme.brand) : null,
                  onTap: () => Navigator.pop(ctx, account.id),
                ),
            ],
          ),
        );
      },
    );
    if (selected != null && mounted) {
      setState(() => _accountId = selected);
    }
  }

  List<Widget> _recipientChips() {
    final chips = <Widget>[];
    if (_recipients.allContacts) {
      chips.add(_chip(
        'جميع جهات الاتصال (${_recipients.allContactsCount})',
        () => setState(() => _recipients = const RecipientSelection()),
      ));
    }
    for (final g in _recipients.groups) {
      chips.add(_chip('مجموعة: ${g.name}', () {
        setState(() {
          _recipients = RecipientSelection(
            contacts: _recipients.contacts,
            groups: _recipients.groups.where((x) => x.id != g.id).toList(),
            emails: _recipients.emails,
          );
        });
      }));
    }
    for (final c in _recipients.contacts) {
      chips.add(_chip(c.name, () {
        setState(() {
          _recipients = RecipientSelection(
            contacts: _recipients.contacts.where((x) => x.id != c.id).toList(),
            groups: _recipients.groups,
            emails: _recipients.emails,
          );
        });
      }));
    }
    for (final e in _recipients.emails) {
      chips.add(_chip(e, () {
        setState(() {
          _recipients = RecipientSelection(
            contacts: _recipients.contacts,
            groups: _recipients.groups,
            emails: _recipients.emails.where((x) => x != e).toList(),
          );
        });
      }, ltr: true));
    }
    return chips;
  }

  Widget _chip(String label, VoidCallback onDeleted, {bool ltr = false}) {
    return InputChip(
      label: Text(label, textDirection: ltr ? TextDirection.ltr : TextDirection.rtl),
      onDeleted: onDeleted,
      backgroundColor: HasimTokens.mintFill(context),
      side: BorderSide(color: HasimTokens.fieldBorder),
      deleteIconColor: HasimTokens.mutedColor(context),
    );
  }

  void _insertIntoBody(String value) {
    final text = _body.text;
    final selection = _body.selection;
    final start = selection.isValid ? selection.start : text.length;
    final end = selection.isValid ? selection.end : text.length;
    final next = text.replaceRange(start, end, value);
    _body.value = TextEditingValue(
      text: next,
      selection: TextSelection.collapsed(offset: start + value.length),
    );
  }

  Future<void> _insertLink() async {
    final controller = TextEditingController();
    final url = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('إضافة رابط'),
        content: TextField(
          controller: controller,
          autofocus: true,
          textDirection: TextDirection.ltr,
          textAlign: TextAlign.left,
          decoration: const InputDecoration(hintText: 'https://'),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('إلغاء')),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, controller.text.trim()),
            child: const Text('إدراج'),
          ),
        ],
      ),
    );
    controller.dispose();
    if (url != null && url.isNotEmpty) {
      _insertIntoBody(url);
    }
  }

  Future<void> _insertEmoji() async {
    const emojis = ['😊', '👍', '🙏', '✨', '🎉', '💼', '✅', '📌', '❤️', '🤝'];
    final emoji = await showModalBottomSheet<String>(
      context: context,
      showDragHandle: true,
      builder: (ctx) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 20),
          child: Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final item in emojis)
                InkWell(
                  onTap: () => Navigator.pop(ctx, item),
                  borderRadius: BorderRadius.circular(12),
                  child: Padding(
                    padding: const EdgeInsets.all(10),
                    child: Text(item, style: const TextStyle(fontSize: 26)),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
    if (emoji != null) {
      _insertIntoBody(emoji);
    }
  }

  void _showTextOnlyHint() {
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('مسار الإرسال الحالي يدعم النص فقط.')),
    );
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
        _sent = true;
        await ref.read(emailRepositoryProvider).deleteComposeDraft();
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
      _sent = true;
      await ref.read(emailRepositoryProvider).deleteComposeDraft();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('تم إرسال الرسالة')));
        context.pop();
      }
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.userMessage)));
    } catch (_) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('تعذر الإرسال')));
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final mint = HasimTokens.pageBg(context);
    final title = widget.replyTo == null ? 'رسالة جديدة' : 'رد';
    final chips = _recipientChips();
    final sendLabel = _sending ? 'جارٍ الإرسال...' : (_recipients.isCampaign ? 'جدولة حملة' : 'إرسال');

    return Scaffold(
      backgroundColor: mint,
      resizeToAvoidBottomInset: true,
      appBar: AppBar(
        automaticallyImplyLeading: false,
        backgroundColor: mint,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        centerTitle: false,
        titleSpacing: 8,
        leading: IconButton(
          tooltip: 'رجوع',
          onPressed: () => context.pop(),
          icon: const Icon(Icons.arrow_forward_rounded),
        ),
        title: Text(
          title,
          style: Theme.of(context).textTheme.titleLarge?.copyWith(
                fontWeight: FontWeight.w800,
                color: HasimTokens.inkColor(context),
              ),
        ),
        actions: [
          Padding(
            padding: const EdgeInsetsDirectional.only(end: 16),
            child: Center(
              child: Text(
                'حاسم',
                style: Theme.of(context).textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w800,
                      color: AppTheme.brand,
                    ),
              ),
            ),
          ),
        ],
      ),
      body: Column(
        children: [
          if (_loadingAccounts) const LinearProgressIndicator(minHeight: 2),
          Expanded(
            child: ListView(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
              keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
              children: [
                const ComposeSectionLabel('حساب الإرسال'),
                ComposeAccountCard(
                  loading: _loadingAccounts,
                  accountsEmpty: !_loadingAccounts && _accounts.isEmpty,
                  email: _selectedAccount?.email,
                  onTap: _pickAccount,
                ),
                const SizedBox(height: 16),
                const ComposeSectionLabel('إلى'),
                ComposeRecipientCard(chips: chips, onTap: _pickRecipients),
                Align(
                  alignment: AlignmentDirectional.centerStart,
                  child: TextButton.icon(
                    onPressed: () => setState(() => _showCcBcc = !_showCcBcc),
                    icon: Icon(
                      _showCcBcc ? Icons.expand_less_rounded : Icons.expand_more_rounded,
                      size: 18,
                      color: AppTheme.brand,
                    ),
                    label: Text(
                      _showCcBcc ? 'إخفاء CC/BCC' : 'إظهار CC/BCC',
                      style: const TextStyle(color: AppTheme.brand, fontWeight: FontWeight.w700),
                    ),
                  ),
                ),
                AnimatedSize(
                  duration: const Duration(milliseconds: 220),
                  curve: Curves.easeOutCubic,
                  alignment: Alignment.topCenter,
                  child: _showCcBcc
                      ? Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            const ComposeSectionLabel('CC'),
                            ComposeTextField(
                              controller: _cc,
                              hintText: 'اختياري — للإرسال الفردي فقط حالياً',
                              textDirection: TextDirection.ltr,
                              textAlign: TextAlign.left,
                            ),
                            const SizedBox(height: 12),
                            const ComposeSectionLabel('BCC'),
                            ComposeTextField(
                              controller: _bcc,
                              hintText: 'اختياري — للإرسال الفردي فقط حالياً',
                              textDirection: TextDirection.ltr,
                              textAlign: TextAlign.left,
                            ),
                            const SizedBox(height: 16),
                          ],
                        )
                      : const SizedBox(width: double.infinity),
                ),
                const ComposeSectionLabel('الموضوع'),
                ComposeTextField(
                  controller: _subject,
                  fieldKey: const Key('compose-subject'),
                  hintText: 'أدخل موضوع الرسالة...',
                ),
                const SizedBox(height: 16),
                const ComposeSectionLabel('النص'),
                ComposeTextField(
                  controller: _body,
                  fieldKey: const Key('compose-body'),
                  hintText: 'اكتب رسالتك هنا...',
                  minLines: 8,
                  maxLines: 16,
                ),
                ComposeToolbar(
                  onAttachment: _showTextOnlyHint,
                  onImage: _showTextOnlyHint,
                  onLink: _insertLink,
                  onEmoji: _insertEmoji,
                ),
              ],
            ),
          ),
          SafeArea(
            top: false,
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
              child: HasimPrimaryButton(
                label: sendLabel,
                icon: Icons.send_rounded,
                expanded: true,
                enabled: _canSend,
                busy: _sending,
                onPressed: _send,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
