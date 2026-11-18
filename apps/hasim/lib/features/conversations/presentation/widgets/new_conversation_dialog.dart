import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';

class NewConversationDialog extends ConsumerStatefulWidget {
  const NewConversationDialog({super.key});

  static Future<ConversationModel?> show(BuildContext context) {
    return showDialog<ConversationModel>(
      context: context,
      barrierDismissible: false,
      builder: (_) => const NewConversationDialog(),
    );
  }

  @override
  ConsumerState<NewConversationDialog> createState() =>
      _NewConversationDialogState();
}

class _NewConversationDialogState extends ConsumerState<NewConversationDialog> {
  final _formKey = GlobalKey<FormState>();
  final _nameController = TextEditingController();
  final _phoneController = TextEditingController();
  final _messageController = TextEditingController();

  String _channel = 'whatsapp';
  bool _aiEnabled = true;
  bool _submitting = false;
  String? _error;

  @override
  void dispose() {
    _nameController.dispose();
    _phoneController.dispose();
    _messageController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() {
      _submitting = true;
      _error = null;
    });

    try {
      final repo = ref.read(conversationRepositoryProvider);
      final workspaceId = ref.read(prefsStoreProvider).workspaceId;

      final conversation = await repo.createConversation(
        customerName: _nameController.text.trim(),
        customerPhone: _phoneController.text.trim(),
        channel: _channel,
        aiEnabled: _aiEnabled,
        initialMessage: _messageController.text.trim(),
        workspaceId: workspaceId,
      );

      if (!mounted) return;
      Navigator.of(context).pop(conversation);
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.toString().replaceAll('Exception: ', '');
        _submitting = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: Row(
        children: [
          Container(
            padding: const EdgeInsets.all(8),
            decoration: BoxDecoration(
              color: HasimTokens.mintFill(context),
              borderRadius: BorderRadius.circular(10),
            ),
            child: const Icon(
              Icons.chat_bubble_outline_rounded,
              color: HasimTokens.chatGreen,
            ),
          ),
          const SizedBox(width: 12),
          const Text(
            'محادثة جديدة',
            style: TextStyle(fontWeight: FontWeight.bold),
          ),
        ],
      ),
      content: SizedBox(
        width: 440,
        child: SingleChildScrollView(
          child: Form(
            key: _formKey,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (_error != null)
                  Container(
                    padding: const EdgeInsets.all(10),
                    margin: const EdgeInsets.only(bottom: 12),
                    decoration: BoxDecoration(
                      color: Colors.red.shade50,
                      borderRadius: BorderRadius.circular(8),
                      border: Border.all(color: Colors.red.shade200),
                    ),
                    child: Text(
                      _error!,
                      style: TextStyle(
                        color: Colors.red.shade800,
                        fontSize: 13,
                      ),
                    ),
                  ),
                TextFormField(
                  controller: _nameController,
                  validator: (v) => (v == null || v.trim().isEmpty)
                      ? 'يرجى إدخال اسم العميل'
                      : null,
                  decoration: InputDecoration(
                    labelText: 'اسم العميل *',
                    hintText: 'مثال: محمد الشمري',
                    prefixIcon: const Icon(Icons.person_outline, size: 20),
                    filled: true,
                    fillColor: HasimTokens.cardBg(context),
                    border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(10),
                    ),
                  ),
                ),
                const SizedBox(height: 12),
                TextFormField(
                  controller: _phoneController,
                  keyboardType: TextInputType.phone,
                  decoration: InputDecoration(
                    labelText: 'رقم الهاتف',
                    hintText: '966501234567',
                    prefixIcon: const Icon(Icons.phone_outlined, size: 20),
                    filled: true,
                    fillColor: HasimTokens.cardBg(context),
                    border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(10),
                    ),
                  ),
                ),
                const SizedBox(height: 14),
                const Text(
                  'قناة المحادثة',
                  style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600),
                ),
                const SizedBox(height: 6),
                Wrap(
                  spacing: 8,
                  runSpacing: 6,
                  children: [
                    _channelChip('whatsapp', 'واتساب', Icons.chat_rounded),
                    _channelChip(
                      'instagram',
                      'انستغرام',
                      Icons.camera_alt_outlined,
                    ),
                    _channelChip(
                      'facebook_messenger',
                      'ماسنجر',
                      Icons.messenger_outline,
                    ),
                    _channelChip(
                      'manual',
                      'يدوي / هاتف',
                      Icons.phone_in_talk_outlined,
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                TextFormField(
                  controller: _messageController,
                  maxLines: 2,
                  decoration: InputDecoration(
                    labelText: 'الرسالة الأولى (اختياري)',
                    hintText: 'اكتب رسالة الترحيب أو البداية...',
                    prefixIcon: const Icon(Icons.send_outlined, size: 20),
                    filled: true,
                    fillColor: HasimTokens.cardBg(context),
                    border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(10),
                    ),
                  ),
                ),
                const SizedBox(height: 10),
                SwitchListTile(
                  contentPadding: EdgeInsets.zero,
                  value: _aiEnabled,
                  onChanged: (val) => setState(() => _aiEnabled = val),
                  activeThumbColor: HasimTokens.chatGreen,
                  title: const Text(
                    'تفعيل رد الذكاء الاصطناعي',
                    style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600),
                  ),
                  subtitle: const Text(
                    'السماح للمساعد الآلي بالإجابة على استفسارات العميل',
                    style: TextStyle(fontSize: 11),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
      actions: [
        TextButton(
          onPressed: _submitting ? null : () => Navigator.of(context).pop(),
          child: const Text('إلغاء'),
        ),
        FilledButton(
          key: const Key('confirm-start-conversation-btn'),
          onPressed: _submitting ? null : _submit,
          style: FilledButton.styleFrom(
            backgroundColor: HasimTokens.chatGreen,
            foregroundColor: Colors.white,
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(10),
            ),
          ),
          child: _submitting
              ? const SizedBox(
                  width: 18,
                  height: 18,
                  child: CircularProgressIndicator(
                    strokeWidth: 2,
                    color: Colors.white,
                  ),
                )
              : const Text(
                  'بدء المحادثة',
                  style: TextStyle(fontWeight: FontWeight.bold),
                ),
        ),
      ],
    );
  }

  Widget _channelChip(String id, String label, IconData icon) {
    final selected = _channel == id;
    return ChoiceChip(
      selected: selected,
      label: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(
            icon,
            size: 16,
            color: selected ? Colors.white : HasimTokens.mutedColor(context),
          ),
          const SizedBox(width: 6),
          Text(label),
        ],
      ),
      selectedColor: HasimTokens.chatGreen,
      labelStyle: TextStyle(
        color: selected ? Colors.white : HasimTokens.inkColor(context),
        fontWeight: selected ? FontWeight.bold : FontWeight.normal,
        fontSize: 12,
      ),
      onSelected: (_) => setState(() => _channel = id),
    );
  }
}
