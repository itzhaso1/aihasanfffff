import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/storage/inbox_scope.dart';
import 'package:hasim/features/privacy/chat_lock_view.dart';
import 'package:hasim/features/privacy/data/biometric_authenticator.dart';
import 'package:hasim/features/privacy/data/chat_lock_store.dart';
import 'package:hasim/features/privacy/pin_codec.dart';
import 'package:hasim/features/privacy/providers/chat_lock_controller.dart';

void main() {
  test('pin hash does not keep the digits', () {
    final salt = PinCodec.salt();
    final hash = PinCodec.hash('1234', salt);
    expect(hash.contains('1234'), isFalse);
    expect(PinCodec.matches(pin: '1234', salt: salt, hash: hash), isTrue);
    expect(PinCodec.matches(pin: '0000', salt: salt, hash: hash), isFalse);
    expect(PinCodec.isValid('1234'), isTrue);
    expect(PinCodec.isValid('12ab'), isFalse);
  });

  test('locked chats stay out of the inbox until that section is open', () {
    final items = [
      const ConversationModel(id: 1, channel: 'whatsapp', status: 'open', externalId: 'ظاهر'),
      const ConversationModel(id: 2, channel: 'whatsapp', status: 'open', externalId: 'سري'),
    ];

    expect(
      applyChatLockView(items: items, lockedIds: {2}, filter: 'all').map((item) => item.id),
      [1],
    );
    expect(
      applyChatLockView(items: items, lockedIds: {2}, filter: 'locked').map((item) => item.externalId),
      ['سري'],
    );
  });

  test('lock controller stores a hash and the conversation id for the workspace', () async {
    final store = MemoryChatLockStore();
    final container = ProviderContainer(
      overrides: [
        chatLockStoreProvider.overrideWithValue(store),
        biometricAuthenticatorProvider.overrideWithValue(FakeBiometricAuthenticator()),
        activeWorkspaceIdProvider.overrideWithValue(4),
      ],
    );
    addTearDown(container.dispose);

    final notifier = container.read(chatLockControllerProvider.notifier);
    await notifier.load();
    expect(await notifier.createPin('1234'), isNull);
    expect(notifier.verifyPin('1234'), isTrue);
    expect(notifier.verifyPin('9999'), isFalse);
    expect(store.record.pinHash, isNot(contains('1234')));

    await notifier.setConversationLocked(9, locked: true);
    expect(container.read(lockedConversationIdsProvider), {9});
    expect(container.read(chatLockControllerProvider).lockedIds, {9});

    await notifier.setAppLock(true);
    notifier.lockSessions();
    expect(container.read(chatLockControllerProvider).needsAppLock, isTrue);
    expect(notifier.verifyPin('1234'), isTrue);
    notifier.markAppUnlocked();
    expect(container.read(chatLockControllerProvider).needsAppLock, isFalse);
  });
}
