/// Worker destinations inside the existing Hasim router.
abstract final class WorkerNav {
  static const chat = '/worker/chat';
  static const offers = '/worker/offers';
  static const engagements = '/worker/engagements';
  static const account = '/worker/account';
  static const salesReference = '/worker/sales-reference';
  static const payouts = '/worker/payouts';
  static const disputes = '/worker/disputes';

  static const chatIndex = 0;
  static const offersIndex = 1;
  static const engagementsIndex = 2;
  static const accountIndex = 3;

  static final _detail = RegExp(r'^/worker/engagements/\d+$');

  static bool isWorkerLocation(String location) {
    return location == chat ||
        location == offers ||
        location == engagements ||
        location == account ||
        location == salesReference ||
        location == payouts ||
        location == disputes ||
        location.startsWith('$disputes/') ||
        _detail.hasMatch(location);
  }

  /// Canonical Hasim Inbox routes. Workers may open these without leaving
  /// the shared conversation screens.
  static bool isCanonicalInboxLocation(String location) {
    return location == '/conversations' ||
        location.startsWith('/conversations/') ||
        location == '/archive' ||
        location == '/locked' ||
        location == '/chat-themes';
  }

  /// Personal SettingsScreen destinations. These are not company admin routes.
  static bool isPersonalSettingsLocation(String location) {
    return location == '/settings' ||
        location == '/privacy-lock' ||
        location == '/chat-themes' ||
        location == '/more/security';
  }

  static String locationForIndex(int index) {
    return switch (index) {
      offersIndex => offers,
      engagementsIndex => engagements,
      accountIndex => account,
      _ => '/conversations',
    };
  }
}

const workerChatLocation = WorkerNav.chat;
const workerOffersLocation = WorkerNav.offers;
const workerEngagementsLocation = WorkerNav.engagements;
const workerAccountLocation = WorkerNav.account;
const workerSalesReferenceLocation = WorkerNav.salesReference;
const workerPayoutsLocation = WorkerNav.payouts;
const workerDisputesLocation = WorkerNav.disputes;
