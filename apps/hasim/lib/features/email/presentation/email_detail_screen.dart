import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/skeleton_list.dart';
import 'package:hasim/features/email/data/email_models.dart';
import 'package:hasim/features/email/data/email_repository.dart';

class EmailDetailScreen extends ConsumerStatefulWidget {
  const EmailDetailScreen({
    super.key,
    required this.messageId,
    this.initialMessage,
  });

  final int messageId;
  final EmailMessageModel? initialMessage;

  @override
  ConsumerState<EmailDetailScreen> createState() => _EmailDetailScreenState();
}

class _EmailDetailScreenState extends ConsumerState<EmailDetailScreen> {
  EmailMessageModel? _message;
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _message = widget.initialMessage;
    _loading = _message?.body == null;
    _load();
  }

  Future<void> _load() async {
    try {
      final msg = await ref.read(emailRepositoryProvider).show(widget.messageId);
      if (!mounted) return;
      setState(() {
        _message = msg;
        _loading = false;
      });
    } catch (_) {
      if (!mounted) return;
      if (_message != null) {
        setState(() => _loading = false);
        return;
      }
      setState(() {
        _error = 'تعذر تحميل محتوى الرسالة.';
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final msg = _message;

    return Scaffold(
      backgroundColor: HasimTokens.pageBg(context),
      appBar: AppBar(
        title: Text(
          msg?.displaySubject ?? 'تفاصيل الرسالة',
          style: const TextStyle(fontWeight: FontWeight.bold),
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
        ),
        backgroundColor: HasimTokens.cardBg(context),
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        actions: [
          if (msg != null)
            IconButton(
              icon: const Icon(Icons.reply),
              tooltip: 'رد',
              onPressed: () {
                context.push(
                  '/emails/compose',
                  extra: {
                    'to': msg.sender,
                    'subject': msg.displaySubject.startsWith('Re:')
                        ? msg.displaySubject
                        : 'Re: ${msg.displaySubject}',
                    'replyToId': msg.id,
                  },
                );
              },
            ),
        ],
      ),
      body: _loading && msg?.body == null
          ? const SkeletonList()
          : _error != null && msg == null
              ? Center(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(_error!),
                      const SizedBox(height: 12),
                      FilledButton(
                        onPressed: _load,
                        child: const Text('إعادة المحاولة'),
                      ),
                    ],
                  ),
                )
              : msg == null
                  ? const Center(child: Text('الرسالة غير متوفرة'))
                  : ListView(
                      padding: const EdgeInsets.all(16),
                      children: [
                        // Header Card
                        Card(
                          elevation: 0,
                          color: HasimTokens.cardBg(context),
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(16),
                            side: BorderSide(color: HasimTokens.lineColor(context)),
                          ),
                          child: Padding(
                            padding: const EdgeInsets.all(16),
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  msg.displaySubject,
                                  style: const TextStyle(
                                    fontSize: 18,
                                    fontWeight: FontWeight.bold,
                                  ),
                                ),
                                const SizedBox(height: 12),
                                Row(
                                  children: [
                                    CircleAvatar(
                                      backgroundColor: HasimTokens.primary.withValues(alpha: 0.15),
                                      child: Text(
                                        msg.sender.isNotEmpty ? msg.sender[0].toUpperCase() : 'E',
                                        style: const TextStyle(
                                          fontWeight: FontWeight.bold,
                                          color: HasimTokens.primary,
                                        ),
                                      ),
                                    ),
                                    const SizedBox(width: 12),
                                    Expanded(
                                      child: Column(
                                        crossAxisAlignment: CrossAxisAlignment.start,
                                        children: [
                                          Text(
                                            'من: ${msg.sender}',
                                            style: const TextStyle(fontWeight: FontWeight.bold),
                                          ),
                                          Text(
                                            'إلى: ${msg.recipient}',
                                            style: TextStyle(fontSize: 12, color: Colors.grey.shade600),
                                          ),
                                        ],
                                      ),
                                    ),
                                    if (msg.createdAt != null)
                                      Text(
                                        msg.createdAt!.split('T').first,
                                        style: const TextStyle(fontSize: 12, color: Colors.grey),
                                      ),
                                  ],
                                ),
                              ],
                            ),
                          ),
                        ),
                        const SizedBox(height: 16),

                        // Body Card
                        Card(
                          elevation: 0,
                          color: HasimTokens.cardBg(context),
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(16),
                            side: BorderSide(color: HasimTokens.lineColor(context)),
                          ),
                          child: Padding(
                            padding: const EdgeInsets.all(16),
                            child: SelectableText(
                              msg.body ?? msg.preview ?? '',
                              style: const TextStyle(fontSize: 15, height: 1.6),
                            ),
                          ),
                        ),

                        // Attachments
                        if (msg.attachments.isNotEmpty) ...[
                          const SizedBox(height: 16),
                          Card(
                            elevation: 0,
                            color: HasimTokens.cardBg(context),
                            shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(16),
                              side: BorderSide(color: HasimTokens.lineColor(context)),
                            ),
                            child: Padding(
                              padding: const EdgeInsets.all(16),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Row(
                                    children: [
                                      const Icon(Icons.attach_file, color: HasimTokens.primary),
                                      const SizedBox(width: 8),
                                      Text(
                                        'المرفقات (${msg.attachments.length})',
                                        style: const TextStyle(fontWeight: FontWeight.bold),
                                      ),
                                    ],
                                  ),
                                  const SizedBox(height: 8),
                                  ...msg.attachments.map((att) => ListTile(
                                    contentPadding: EdgeInsets.zero,
                                    leading: const Icon(Icons.insert_drive_file_outlined),
                                    title: Text(att.fileType),
                                    subtitle: att.fileSize != null
                                        ? Text('${(att.fileSize! / 1024).round()} KB')
                                        : null,
                                  )),
                                ],
                              ),
                            ),
                          ),
                        ],
                      ],
                    ),
    );
  }
}
