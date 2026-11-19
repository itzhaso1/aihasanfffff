import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/features/email/data/email_models.dart';
import 'package:hasim/features/email/data/email_repository.dart';

class EmailComposeScreen extends ConsumerStatefulWidget {
  const EmailComposeScreen({
    super.key,
    this.initialTo,
    this.initialSubject,
    this.replyToId,
  });

  final String? initialTo;
  final String? initialSubject;
  final int? replyToId;

  @override
  ConsumerState<EmailComposeScreen> createState() => _EmailComposeScreenState();
}

class _EmailComposeScreenState extends ConsumerState<EmailComposeScreen> {
  final _formKey = GlobalKey<FormState>();
  late final TextEditingController _toController;
  late final TextEditingController _subjectController;
  late final TextEditingController _bodyController;

  List<EmailAccountModel> _accounts = [];
  EmailAccountModel? _selectedAccount;
  bool _loadingAccounts = true;
  bool _sending = false;

  @override
  void initState() {
    super.initState();
    _toController = TextEditingController(text: widget.initialTo ?? '');
    _subjectController =
        TextEditingController(text: widget.initialSubject ?? '');
    _bodyController = TextEditingController();
    _loadAccounts();
  }

  @override
  void dispose() {
    _toController.dispose();
    _subjectController.dispose();
    _bodyController.dispose();
    super.dispose();
  }

  Future<void> _loadAccounts() async {
    try {
      final accounts = await ref.read(emailRepositoryProvider).accounts();
      if (!mounted) return;
      setState(() {
        _accounts = accounts;
        if (accounts.isNotEmpty) {
          _selectedAccount = accounts.first;
        }
        _loadingAccounts = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() => _loadingAccounts = false);
    }
  }

  Future<void> _send() async {
    if (!_formKey.currentState!.validate()) return;
    if (_selectedAccount == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('يرجى تحديد حساب البريد المرسل منه.'),
          backgroundColor: Colors.red,
        ),
      );
      return;
    }

    setState(() => _sending = true);
    try {
      await ref.read(emailRepositoryProvider).send(
            emailAccountId: _selectedAccount!.id,
            to: _toController.text.trim(),
            subject: _subjectController.text.trim(),
            body: _bodyController.text.trim(),
            replyToMessageId: widget.replyToId,
          );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: const Text('تم إرسال البريد الإلكتروني بنجاح.'),
          backgroundColor: HasimTokens.chatGreen,
        ),
      );
      Navigator.of(context).pop(true);
    } catch (e) {
      if (!mounted) return;
      setState(() => _sending = false);
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('تعذر إرسال البريد. تأكد من صحة البيانات ورصيد الإرسال.'),
          backgroundColor: Colors.red,
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: HasimTokens.pageBg(context),
      appBar: AppBar(
        title: const Text('إنشاء رسالة بريد', style: TextStyle(fontWeight: FontWeight.bold)),
        backgroundColor: HasimTokens.cardBg(context),
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        actions: [
          IconButton(
            icon: _sending
                ? const SizedBox(
                    width: 20,
                    height: 20,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Icon(Icons.send_rounded, color: HasimTokens.primary),
            tooltip: 'إرسال',
            onPressed: _sending ? null : _send,
          ),
        ],
      ),
      body: Form(
        key: _formKey,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            // Sender Account Picker
            Card(
              elevation: 0,
              color: HasimTokens.cardBg(context),
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(14),
                side: BorderSide(color: HasimTokens.lineColor(context)),
              ),
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                child: _loadingAccounts
                    ? const Center(child: Padding(padding: EdgeInsets.all(8), child: CircularProgressIndicator()))
                    : _accounts.isEmpty
                        ? const Text('لا توجد حسابات بريد مهيأة في مساحة العمل.',
                            style: TextStyle(color: Colors.red))
                        : DropdownButtonHideUnderline(
                            child: DropdownButton<EmailAccountModel>(
                              value: _selectedAccount,
                              isExpanded: true,
                              hint: const Text('اختر حساب الإرسال'),
                              items: _accounts.map((acc) {
                                return DropdownMenuItem(
                                  value: acc,
                                  child: Row(
                                    children: [
                                      const Icon(Icons.alternate_email, size: 18, color: HasimTokens.primary),
                                      const SizedBox(width: 8),
                                      Text('${acc.name} (${acc.email})'),
                                    ],
                                  ),
                                );
                              }).toList(),
                              onChanged: (val) {
                                if (val != null) setState(() => _selectedAccount = val);
                              },
                            ),
                          ),
              ),
            ),
            const SizedBox(height: 12),

            // To input
            TextFormField(
              controller: _toController,
              keyboardType: TextInputType.emailAddress,
              decoration: const InputDecoration(
                labelText: 'إلى (المرسل إليه)',
                hintText: 'recipient@example.com',
                border: OutlineInputBorder(),
                prefixIcon: Icon(Icons.person_outline),
              ),
              validator: (v) {
                if (v == null || v.trim().isEmpty) return 'يرجى إدخال عنوان البريد الإلكتروني';
                return null;
              },
            ),
            const SizedBox(height: 12),

            // Subject input
            TextFormField(
              controller: _subjectController,
              decoration: const InputDecoration(
                labelText: 'الموضوع',
                border: OutlineInputBorder(),
                prefixIcon: Icon(Icons.subject),
              ),
            ),
            const SizedBox(height: 12),

            // Body input
            TextFormField(
              controller: _bodyController,
              maxLines: 12,
              decoration: const InputDecoration(
                labelText: 'نص الرسالة',
                alignLabelWithHint: true,
                border: OutlineInputBorder(),
              ),
              validator: (v) {
                if (v == null || v.trim().isEmpty) return 'يرجى كتابة نص الرسالة';
                return null;
              },
            ),
          ],
        ),
      ),
    );
  }
}
