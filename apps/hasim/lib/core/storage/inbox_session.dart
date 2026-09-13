/// Generation + workspace guard so stale inbox requests cannot commit.
class InboxSession {
  int _generation = 0;
  int? _workspaceId;

  int get generation => _generation;
  int? get workspaceId => _workspaceId;

  void beginWorkspace(int workspaceId) {
    _generation++;
    _workspaceId = workspaceId;
  }

  void clear() {
    _generation++;
    _workspaceId = null;
  }

  bool isCurrent({required int generation, int? workspaceId}) {
    if (generation != _generation) return false;
    if (_workspaceId == null) return true;
    return workspaceId == _workspaceId;
  }
}

class InboxScope {
  const InboxScope({required this.generation, this.workspaceId});

  final int generation;
  final int? workspaceId;
}
