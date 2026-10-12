import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/features/conversations/providers/conversations_controller.dart';
import 'package:hasim/features/privacy/presentation/pin_prompt.dart';
import 'package:hasim/features/privacy/providers/chat_lock_controller.dart';

void leaveSpecialInbox(WidgetRef ref) {
  final filter = ref.read(conversationsControllerProvider).filter;
  if (filter == 'locked') {
    ref.read(chatLockControllerProvider.notifier).lockChats();
  }
  if (filter == 'archived' || filter == 'locked') {
    ref.read(conversationsControllerProvider.notifier).setFilter('all');
  }
}

void openArchivePage(BuildContext context, WidgetRef ref) {
  ref.read(chatLockControllerProvider.notifier).lockChats();
  context.go('/archive');
}

void openLockedPage(BuildContext context) {
  context.go('/locked');
}

Future<void> openLockedChats(
  BuildContext context,
  WidgetRef ref,
) async {
  final notifier = ref.read(chatLockControllerProvider.notifier);
  await notifier.load();
  if (!context.mounted) return;
  var state = ref.read(chatLockControllerProvider);
  if (!state.pinSet) {
    final pin = await showCreatePinDialog(context);
    if (pin == null || !context.mounted) return;
    final error = await notifier.createPin(pin);
    if (!context.mounted) return;
    if (error != null) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error)));
      return;
    }
    state = ref.read(chatLockControllerProvider);
  }
  if (!state.chatsUnlocked) {
    final ok = await showVerifyPinDialog(
      context,
      title: 'الدردشات المقفلة',
      subtitle: 'أدخل رمز PIN لعرض الدردشات المحمية',
      allowBiometric: state.lockedChatsBiometricEnabled && state.biometricAvailable,
      verify: notifier.verifyPin,
      biometric: notifier.authenticateBiometric,
    );
    if (!ok || !context.mounted) return;
    notifier.markChatsUnlocked();
  }
}

Future<void> lockConversation(BuildContext context, WidgetRef ref, int id) async {
  final notifier = ref.read(chatLockControllerProvider.notifier);
  await notifier.load();
  if (!context.mounted) return;
  if (!ref.read(chatLockControllerProvider).pinSet) {
    final pin = await showCreatePinDialog(context);
    if (pin == null || !context.mounted) return;
    final error = await notifier.createPin(pin);
    if (!context.mounted) return;
    if (error != null) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error)));
      return;
    }
  }
  await notifier.setConversationLocked(id, locked: true);
  if (!context.mounted) return;
  ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('نُقلت الدردشة إلى الدردشات المقفلة')));
}
