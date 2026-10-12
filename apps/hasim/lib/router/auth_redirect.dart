import 'package:hasim/router/worker_nav.dart';

/// Decides the next location from the existing session.
/// Company users keep the workspace flow. Independent workers stay on the worker shell.
String? resolveHasimRedirect({
  required bool bootstrapping,
  required bool isAuthenticated,
  required bool isIndependentWorker,
  required bool hasWorkspace,
  required String location,
  bool isOwner = false,
}) {
  const publicAuth = {
    '/login',
    '/register',
    '/forgot-password',
    '/reset-password',
    '/splash',
  };

  if (location == '/splash') {
    // SplashScreen leaves as soon as auth bootstrap finishes (short brand hold).
    return null;
  }
  if (bootstrapping) return '/splash';
  if (location == '/home') {
    return '/conversations';
  }
  if (!isAuthenticated) {
    return publicAuth.contains(location) ? null : '/login';
  }
  // Private inbox folders are owner-only. Employees stay on the main inbox.
  if (!isIndependentWorker &&
      (location == '/archive' || location == '/locked')) {
    if (hasWorkspace && isOwner) {
      return null;
    }
    return hasWorkspace ? '/conversations' : '/workspaces';
  }
  if (isIndependentWorker) {
    if (location == '/login' ||
        location == '/register' ||
        location == '/forgot-password' ||
        location == '/reset-password') {
      return '/conversations';
    }
    if (location == workerChatLocation) {
      return '/conversations';
    }
    if (location == '/workspaces') {
      return '/conversations';
    }
    if (location == '/email' || location.startsWith('/email/')) {
      return location == '/email/compose' ? '/email' : null;
    }
    if (location == '/updates' || location.startsWith('/updates/')) {
      return location == '/updates/create' ? '/updates' : null;
    }
    if (location == '/stories/create') {
      return '/updates';
    }
    if (WorkerNav.isWorkerLocation(location) ||
        WorkerNav.isCanonicalInboxLocation(location) ||
        WorkerNav.isPersonalSettingsLocation(location)) {
      return null;
    }
    return '/conversations';
  }
  if (WorkerNav.isWorkerLocation(location)) {
    return hasWorkspace ? '/conversations' : '/workspaces';
  }
  if (location == '/login' ||
      location == '/register' ||
      location == '/forgot-password' ||
      location == '/reset-password') {
    return hasWorkspace ? '/conversations' : '/workspaces';
  }
  if (!hasWorkspace && location != '/workspaces') {
    return '/workspaces';
  }
  return null;
}
