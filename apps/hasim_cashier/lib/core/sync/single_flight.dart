/// One in-flight task at a time. Extra callers share the same [Future]
/// instead of starting a parallel job.
class SingleFlight<T> {
  Future<T>? _inFlight;

  bool get isInFlight => _inFlight != null;

  Future<T> run(Future<T> Function() action) {
    final existing = _inFlight;
    if (existing != null) return existing;
    late final Future<T> future;
    future = Future<T>.sync(action).whenComplete(() {
      if (identical(_inFlight, future)) {
        _inFlight = null;
      }
    });
    _inFlight = future;
    return future;
  }
}
