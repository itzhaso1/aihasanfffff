import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/features/email/data/email_models.dart';
import 'package:hasim/features/email/data/email_repository.dart';

class EmailFolderState {
  const EmailFolderState({
    this.messages = const [],
    this.accounts = const [],
    this.currentFolder = 'inbox',
    this.loading = false,
    this.error,
  });

  final List<EmailMessageModel> messages;
  final List<EmailAccountModel> accounts;
  final String currentFolder;
  final bool loading;
  final String? error;

  EmailFolderState copyWith({
    List<EmailMessageModel>? messages,
    List<EmailAccountModel>? accounts,
    String? currentFolder,
    bool? loading,
    String? error,
  }) {
    return EmailFolderState(
      messages: messages ?? this.messages,
      accounts: accounts ?? this.accounts,
      currentFolder: currentFolder ?? this.currentFolder,
      loading: loading ?? this.loading,
      error: error,
    );
  }
}

final emailFolderControllerProvider =
    StateNotifierProvider.autoDispose<EmailFolderController, EmailFolderState>((
      ref,
    ) {
      return EmailFolderController(ref.watch(emailRepositoryProvider));
    });

class EmailFolderController extends StateNotifier<EmailFolderState> {
  EmailFolderController(this._repo) : super(const EmailFolderState()) {
    init();
  }

  final EmailRepository _repo;

  Future<void> init() async {
    state = state.copyWith(loading: true, error: null);
    try {
      final accounts = await _repo.accounts();
      final messages = await _repo.listFolder(state.currentFolder);
      state = state.copyWith(
        accounts: accounts,
        messages: messages,
        loading: false,
      );
    } catch (_) {
      state = state.copyWith(
        loading: false,
        error: 'تعذر تحميل رسائل البريد. تأكد من تفعيل خدمة البريد لمساحة العمل.',
      );
    }
  }

  Future<void> switchFolder(String folder) async {
    if (state.currentFolder == folder) return;
    state = state.copyWith(currentFolder: folder, loading: true, error: null);
    try {
      final messages = await _repo.listFolder(folder);
      state = state.copyWith(messages: messages, loading: false);
    } catch (_) {
      state = state.copyWith(
        loading: false,
        error: 'تعذر تحميل مجلد البريد.',
      );
    }
  }

  Future<void> refresh() async {
    try {
      final messages = await _repo.listFolder(state.currentFolder);
      state = state.copyWith(messages: messages);
    } catch (_) {}
  }

  Future<void> toggleStar(int id) async {
    final current = state.messages.firstWhere((m) => m.id == id);
    final nextStarred = !current.isStarred;
    state = state.copyWith(
      messages: state.messages.map((m) {
        if (m.id == id) return m.copyWith(isStarred: nextStarred);
        return m;
      }).toList(),
    );
    try {
      await _repo.star(id);
    } catch (_) {
      // rollback on failure
      state = state.copyWith(
        messages: state.messages.map((m) {
          if (m.id == id) return m.copyWith(isStarred: !nextStarred);
          return m;
        }).toList(),
      );
    }
  }

  Future<void> markRead(int id) async {
    try {
      await _repo.markRead(id);
      state = state.copyWith(
        messages: state.messages.map((m) {
          if (m.id == id) return m.copyWith(isRead: true);
          return m;
        }).toList(),
      );
    } catch (_) {}
  }
}
