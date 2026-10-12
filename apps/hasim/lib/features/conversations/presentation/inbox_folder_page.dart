import 'package:flutter/material.dart';
import 'package:hasim/features/conversations/presentation/conversations_screen.dart';

/// صفحة مستقلة للمؤرشفة أو الدردشات المقفلة، مع سكة التنقل.
class InboxFolderPage extends StatelessWidget {
  const InboxFolderPage({super.key, required this.folder});

  /// `archived` أو `locked`.
  final String folder;

  @override
  Widget build(BuildContext context) => ConversationsScreen(folder: folder);
}
