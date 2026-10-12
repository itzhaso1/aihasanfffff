import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/storage/inbox_scope.dart';
import 'package:hasim/features/privacy/data/biometric_authenticator.dart';
import 'package:hasim/features/privacy/data/chat_lock_store.dart';
import 'package:hasim/features/privacy/pin_codec.dart';

class ChatLockState {
  const ChatLockState({
    this.loaded = false,
    this.pinSet = false,
    this.appLockEnabled = false,
    this.appBiometricEnabled = false,
    this.lockedChatsBiometricEnabled = false,
    this.biometricAvailable = false,
    this.appUnlocked = true,
    this.chatsUnlocked = false,
    this.lockedIds = const {},
  });

  final bool loaded;
  final bool pinSet;
  final bool appLockEnabled;
  final bool appBiometricEnabled;
  final bool lockedChatsBiometricEnabled;
  final bool biometricAvailable;
  final bool appUnlocked;
  final bool chatsUnlocked;
  final Set<int> lockedIds;

  bool get needsAppLock => loaded && pinSet && appLockEnabled && !appUnlocked;

  ChatLockState copyWith({
    bool? loaded,
    bool? pinSet,
    bool? appLockEnabled,
    bool? appBiometricEnabled,
    bool? lockedChatsBiometricEnabled,
    bool? biometricAvailable,
    bool? appUnlocked,
    bool? chatsUnlocked,
    Set<int>? lockedIds,
  }) {
    return ChatLockState(
      loaded: loaded ?? this.loaded,
      pinSet: pinSet ?? this.pinSet,
      appLockEnabled: appLockEnabled ?? this.appLockEnabled,
      appBiometricEnabled: appBiometricEnabled ?? this.appBiometricEnabled,
      lockedChatsBiometricEnabled: lockedChatsBiometricEnabled ?? this.lockedChatsBiometricEnabled,
      biometricAvailable: biometricAvailable ?? this.biometricAvailable,
      appUnlocked: appUnlocked ?? this.appUnlocked,
      chatsUnlocked: chatsUnlocked ?? this.chatsUnlocked,
      lockedIds: lockedIds ?? this.lockedIds,
    );
  }
}

final chatLockStoreProvider = Provider<ChatLockStore>((ref) => SecureChatLockStore());

final biometricAuthenticatorProvider = Provider<BiometricAuthenticator>((ref) => LocalBiometricAuthenticator());

final lockedConversationIdsProvider = StateProvider<Set<int>>((ref) => const {});

final chatLockControllerProvider = StateNotifierProvider<ChatLockController, ChatLockState>((ref) {
  return ChatLockController(ref);
});

class ChatLockController extends StateNotifier<ChatLockState> {
  ChatLockController(this._ref) : super(const ChatLockState()) {
    _workspaceSub = _ref.listen<int?>(activeWorkspaceIdProvider, (previous, next) {
      if (previous == next) return;
      _publishIds();
    });
    _ready = _load();
  }

  final Ref _ref;
  ProviderSubscription<int?>? _workspaceSub;
  ChatLockRecord _record = ChatLockRecord.empty;
  late Future<void> _ready;
  int _epoch = 0;
  bool _authenticating = false;

  Future<void> load() => _ready;

  Set<int> _ids() {
    final workspaceId = _ref.read(activeWorkspaceIdProvider);
    if (workspaceId == null) return const {};
    return _record.lockedIdsByWorkspace[workspaceId] ?? const {};
  }

  void _publishIds() {
    final ids = _ids();
    final current = _ref.read(lockedConversationIdsProvider);
    if (current.length == ids.length && current.containsAll(ids)) {
      state = state.copyWith(lockedIds: ids);
      return;
    }
    _ref.read(lockedConversationIdsProvider.notifier).state = ids;
    state = state.copyWith(lockedIds: ids);
  }

  void _emit({bool? biometricAvailable}) {
    state = state.copyWith(
      loaded: true,
      pinSet: _record.pinSet,
      appLockEnabled: _record.appLockEnabled,
      appBiometricEnabled: _record.appBiometricEnabled,
      lockedChatsBiometricEnabled: _record.lockedChatsBiometricEnabled,
      biometricAvailable: biometricAvailable,
      appUnlocked: _record.appLockEnabled ? state.appUnlocked : true,
    );
    _publishIds();
  }

  Future<void> _load() async {
    final epoch = _epoch;
    final record = await _ref.read(chatLockStoreProvider).read();
    var available = false;
    try {
      available = await _ref.read(biometricAuthenticatorProvider).isAvailable;
    } catch (_) {}
    if (!mounted || epoch != _epoch) return;
    _record = record;
    _emit(biometricAvailable: available);
  }

  Future<void> _save() async {
    _epoch++;
    await _ref.read(chatLockStoreProvider).write(_record);
    if (!mounted) return;
    _emit();
  }

  bool verifyPin(String pin) {
    if (!_record.pinSet) return false;
    return PinCodec.matches(pin: pin, salt: _record.pinSalt!, hash: _record.pinHash!);
  }

  Future<String?> createPin(String pin) async {
    await load();
    if (!PinCodec.isValid(pin)) return 'استخدم 4 أرقام.';
    if (_record.pinSet) return 'رمز PIN موجود مسبقًا.';
    final salt = PinCodec.salt();
    _record = _record.copyWith(pinSalt: salt, pinHash: PinCodec.hash(pin, salt));
    await _save();
    return null;
  }

  Future<String?> changePin({required String current, required String next}) async {
    await load();
    if (!verifyPin(current)) return 'الرمز الحالي غير صحيح.';
    if (!PinCodec.isValid(next)) return 'استخدم 4 أرقام.';
    if (current == next) return 'اختر رمزًا مختلفًا.';
    final salt = PinCodec.salt();
    _record = _record.copyWith(pinSalt: salt, pinHash: PinCodec.hash(next, salt));
    await _save();
    return null;
  }

  Future<void> setAppLock(bool enabled) async {
    await load();
    if (enabled && !_record.pinSet) return;
    state = state.copyWith(appUnlocked: enabled ? state.appUnlocked : true);
    _record = _record.copyWith(
      appLockEnabled: enabled,
      appBiometricEnabled: enabled ? _record.appBiometricEnabled : false,
    );
    await _save();
  }

  Future<String?> setAppBiometric(bool enabled) async {
    await load();
    if (enabled) {
      if (!state.biometricAvailable) return 'البصمة أو Face ID غير متاحة على هذا الجهاز.';
      if (!_record.appLockEnabled) return 'فعّل قفل التطبيق أولًا.';
      final ok = await _ref.read(biometricAuthenticatorProvider).authenticate('أكّد هويتك لتفعيل فتح التطبيق بالبصمة');
      if (!ok) return 'لم يتم تأكيد البصمة.';
    }
    _record = _record.copyWith(appBiometricEnabled: enabled);
    await _save();
    return null;
  }

  Future<String?> setLockedChatsBiometric(bool enabled) async {
    await load();
    if (enabled) {
      if (!_record.pinSet) return 'أنشئ رمز PIN أولًا.';
      if (!state.biometricAvailable) return 'البصمة أو Face ID غير متاحة على هذا الجهاز.';
      final ok = await _ref.read(biometricAuthenticatorProvider).authenticate('أكّد هويتك لحماية الدردشات المقفلة بالبصمة');
      if (!ok) return 'لم يتم تأكيد البصمة.';
    }
    _record = _record.copyWith(lockedChatsBiometricEnabled: enabled);
    await _save();
    return null;
  }

  Future<void> setConversationLocked(int id, {required bool locked}) async {
    await load();
    final workspaceId = _ref.read(activeWorkspaceIdProvider);
    if (workspaceId == null) return;
    final map = {
      for (final entry in _record.lockedIdsByWorkspace.entries) entry.key: {...entry.value},
    };
    final ids = map[workspaceId] ?? <int>{};
    if (locked) {
      ids.add(id);
    } else {
      ids.remove(id);
    }
    map[workspaceId] = ids;
    _record = _record.copyWith(lockedIdsByWorkspace: map);
    await _save();
  }

  Future<bool> authenticateBiometric(String reason) async {
    _authenticating = true;
    try {
      return await _ref.read(biometricAuthenticatorProvider).authenticate(reason);
    } finally {
      _authenticating = false;
    }
  }

  void markAppUnlocked() => state = state.copyWith(appUnlocked: true);

  void markChatsUnlocked() => state = state.copyWith(chatsUnlocked: true);

  void lockChats() => state = state.copyWith(chatsUnlocked: false);

  void lockSessions() {
    if (_authenticating) return;
    state = state.copyWith(
      appUnlocked: state.appLockEnabled ? false : true,
      chatsUnlocked: false,
    );
  }

  @override
  void dispose() {
    _workspaceSub?.close();
    super.dispose();
  }
}
