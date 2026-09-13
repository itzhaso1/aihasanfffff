import 'dart:io';

import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/widgets/async_body.dart';
import 'package:hasim/core/widgets/skeleton_list.dart';
import 'package:hasim/features/conversations/providers/conversations_controller.dart';
import 'package:image_picker/image_picker.dart';
import 'package:intl/intl.dart' hide TextDirection;

class ChatScreen extends ConsumerStatefulWidget {
  const ChatScreen({super.key, required this.conversationId});
  final int conversationId;

  @override
  ConsumerState<ChatScreen> createState() => _ChatScreenState();
}

class _ChatScreenState extends ConsumerState<ChatScreen> {
  final _controller = TextEditingController();
  final _scroll = ScrollController();

  @override
  void initState() {
    super.initState();
    _scroll.addListener(_onScroll);
  }

  void _onScroll() {
    if (!_scroll.hasClients) return;
    if (_scroll.position.pixels <= 48) {
      ref.read(chatControllerProvider(widget.conversationId).notifier).loadOlder();
    }
  }

  @override
  void dispose() {
    _scroll.removeListener(_onScroll);
    _controller.dispose();
    _scroll.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    final text = _controller.text;
    _controller.clear();
    await ref.read(chatControllerProvider(widget.conversationId).notifier).send(text);
    await Future<void>.delayed(const Duration(milliseconds: 50));
    if (_scroll.hasClients) {
      _scroll.animateTo(
        _scroll.position.maxScrollExtent,
        duration: const Duration(milliseconds: 250),
        curve: Curves.easeOut,
      );
    }
  }

  Future<void> _pickImage() async {
    final picker = ImagePicker();
    final file = await picker.pickImage(source: ImageSource.gallery, imageQuality: 85);
    if (file == null) return;
    await ref.read(chatControllerProvider(widget.conversationId).notifier).sendThenAttach(File(file.path));
  }

  Future<void> _suggest() async {
    final text = await ref.read(chatControllerProvider(widget.conversationId).notifier).suggestReply();
    if (!mounted || text == null) return;
    _controller.text = text;
    ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('تم إدراج اقتراح الرد.')));
  }

  Future<void> _summarize() async {
    final text = await ref.read(chatControllerProvider(widget.conversationId).notifier).summarize();
    if (!mounted || text == null) return;
    await showDialog<void>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('ملخص المحادثة'),
        content: SingleChildScrollView(child: Text(text, style: const TextStyle(height: 1.45))),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: const Text('إغلاق')),
        ],
      ),
    );
  }

  Future<void> _quickAddContact() async {
    final customer = ref.read(chatControllerProvider(widget.conversationId)).conversation?.customer;
    final email = customer?.email?.trim();
    if (email == null || email.isEmpty) return;
    try {
      final existing = await ref.read(contactRepositoryProvider).findByEmail(email);
      if (!mounted) return;
      if (existing.isNotEmpty) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: const Text('جهة الاتصال موجودة مسبقاً'),
            action: SnackBarAction(
              label: 'عرض',
              onPressed: () => context.push('/contacts/${existing.first.id}'),
            ),
          ),
        );
        return;
      }
      final created = await ref.read(contactRepositoryProvider).create(
            name: customer?.name ?? email,
            email: email,
            phone: customer?.phone,
          );
      if (!mounted) return;
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
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('تعذر الإضافة')));
      }
    }
  }

  void _openImage(String url) {
    showDialog<void>(
      context: context,
      builder: (context) => Dialog(
        insetPadding: const EdgeInsets.all(16),
        child: InteractiveViewer(
          child: CachedNetworkImage(imageUrl: url, fit: BoxFit.contain),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(chatControllerProvider(widget.conversationId));
    final title = state.conversation?.title ?? 'محادثة';
    final customerId = state.conversation?.customer?.id;

    return Scaffold(
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(title, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
            if (state.conversation != null)
              Text(state.conversation!.channelLabel, style: TextStyle(fontSize: 12, color: Colors.grey.shade600)),
          ],
        ),
        actions: [
          if (customerId != null)
            IconButton(
              tooltip: 'ملف العميل',
              onPressed: () => context.push('/customers/$customerId'),
              icon: const Icon(Icons.person_outline),
            ),
          PopupMenuButton<String>(
            onSelected: (v) {
              if (v == 'suggest') _suggest();
              if (v == 'summarize') _summarize();
              if (v == 'add_contact') _quickAddContact();
            },
            itemBuilder: (_) => [
              const PopupMenuItem(value: 'suggest', child: Text('اقتراح رد')),
              const PopupMenuItem(value: 'summarize', child: Text('تلخيص')),
              if (state.conversation?.customer?.email != null &&
                  state.conversation!.customer!.email!.trim().isNotEmpty)
                const PopupMenuItem(value: 'add_contact', child: Text('إضافة لجهات الاتصال')),
            ],
          ),
        ],
      ),
      body: Column(
        children: [
          if (state.loadingOlder) const LinearProgressIndicator(minHeight: 2),
          if (state.aiBusy) const LinearProgressIndicator(minHeight: 2),
          Expanded(
            child: state.loading && state.messages.isEmpty
                ? const SkeletonList()
                : AsyncBody(
                    loading: false,
                    error: state.error != null && state.messages.isEmpty ? state.error : null,
                    isEmpty: !state.loading && state.messages.isEmpty,
                    emptyTitle: 'ابدأ المحادثة',
                    emptySubtitle: 'لا توجد رسائل بعد.',
                    onRetry: () => ref.read(chatControllerProvider(widget.conversationId).notifier).refresh(),
                    child: ListView.builder(
                      controller: _scroll,
                      padding: const EdgeInsets.all(12),
                      itemCount: state.messages.length,
                      itemBuilder: (context, index) {
                        final m = state.messages[index];
                        return _Bubble(
                          message: m,
                          onRetry: m.localFailed
                              ? () => ref.read(chatControllerProvider(widget.conversationId).notifier).retryFailed(m)
                              : null,
                          onImageTap: _openImage,
                        );
                      },
                    ),
                  ),
          ),
          SafeArea(
            top: false,
            child: Padding(
              padding: const EdgeInsets.fromLTRB(8, 4, 8, 8),
              child: Row(
                children: [
                  IconButton(onPressed: state.sending ? null : _pickImage, icon: const Icon(Icons.attach_file)),
                  Expanded(
                    child: TextField(
                      controller: _controller,
                      minLines: 1,
                      maxLines: 4,
                      decoration: const InputDecoration(hintText: 'اكتب رسالة...'),
                      onSubmitted: (_) => _send(),
                    ),
                  ),
                  const SizedBox(width: 6),
                  IconButton.filled(
                    onPressed: state.sending ? null : _send,
                    icon: const Icon(Icons.send),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _Bubble extends StatelessWidget {
  const _Bubble({required this.message, this.onRetry, this.onImageTap});
  final MessageModel message;
  final VoidCallback? onRetry;
  final void Function(String url)? onImageTap;

  @override
  Widget build(BuildContext context) {
    final mine = message.isOutbound;
    final bg = message.localFailed
        ? Colors.red.shade50
        : (mine ? Theme.of(context).colorScheme.primary : Theme.of(context).cardTheme.color ?? Colors.white);
    final fg = mine && !message.localFailed ? Colors.white : const Color(0xFF0F172A);

    return Align(
      alignment: mine ? Alignment.centerRight : Alignment.centerLeft,
      child: InkWell(
        onTap: onRetry,
        borderRadius: BorderRadius.circular(16),
        child: Container(
          margin: const EdgeInsets.symmetric(vertical: 4),
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          constraints: BoxConstraints(maxWidth: MediaQuery.of(context).size.width * 0.78),
          decoration: BoxDecoration(
            color: bg,
            borderRadius: BorderRadius.circular(16),
            border: mine && !message.localFailed ? null : Border.all(color: Colors.grey.shade200),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (message.content.isNotEmpty && message.content != '📎')
                Text(message.content, style: TextStyle(color: fg, height: 1.35)),
              for (final a in message.attachments)
                if (a.kind == 'image' && a.downloadUrl != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 8),
                    child: GestureDetector(
                      onTap: () => onImageTap?.call(a.downloadUrl!),
                      child: ClipRRect(
                        borderRadius: BorderRadius.circular(12),
                        child: CachedNetworkImage(imageUrl: a.downloadUrl!, height: 160, fit: BoxFit.cover),
                      ),
                    ),
                  ),
              const SizedBox(height: 4),
              Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    message.createdAt == null ? '' : DateFormat('HH:mm').format(message.createdAt!.toLocal()),
                    style: TextStyle(color: fg.withValues(alpha: 0.75), fontSize: 11),
                  ),
                  if (message.localPending) ...[
                    const SizedBox(width: 6),
                    SizedBox(width: 12, height: 12, child: CircularProgressIndicator(strokeWidth: 1.5, color: fg)),
                  ],
                  if (message.localFailed) ...[
                    const SizedBox(width: 6),
                    Icon(Icons.refresh, size: 14, color: Colors.red.shade400),
                    const SizedBox(width: 4),
                    Text('إعادة المحاولة', style: TextStyle(fontSize: 11, color: Colors.red.shade400)),
                  ],
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
