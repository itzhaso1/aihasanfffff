import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/features/catalog/data/chat_catalog_models.dart';
import 'package:hasim/features/catalog/presentation/catalog_categories_screen.dart';
import 'package:hasim/features/catalog/presentation/catalog_category_form_screen.dart';
import 'package:hasim/features/catalog/presentation/catalog_form_screen.dart';
import 'package:hasim/features/catalog/presentation/catalog_list_screen.dart';
import 'package:hasim/features/catalog/presentation/chat_catalog_settings_screen.dart';
import 'package:hasim/features/auth/presentation/forgot_password_screen.dart';
import 'package:hasim/features/auth/presentation/login_screen.dart';
import 'package:hasim/features/auth/presentation/register_screen.dart';
import 'package:hasim/features/auth/presentation/reset_password_screen.dart';
import 'package:hasim/features/auth/presentation/splash_screen.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/contacts/presentation/contact_detail_screen.dart';
import 'package:hasim/features/contacts/presentation/contact_form_screen.dart';
import 'package:hasim/features/contacts/presentation/contact_groups_screen.dart';
import 'package:hasim/features/contacts/presentation/contacts_list_screen.dart';
import 'package:hasim/features/conversations/presentation/chat_entry_screen.dart';
import 'package:hasim/features/conversations/presentation/chat_themes_screen.dart';
import 'package:hasim/features/conversations/presentation/conversations_screen.dart';
import 'package:hasim/features/conversations/presentation/inbox_folder_page.dart';
import 'package:hasim/features/customers/presentation/customer_profile_screen.dart';
import 'package:hasim/features/email/presentation/campaign_status_screen.dart';
import 'package:hasim/features/email/presentation/email_compose_screen.dart';
import 'package:hasim/features/email/presentation/email_detail_screen.dart';
import 'package:hasim/features/email/presentation/email_list_screen.dart';
import 'package:hasim/features/home/presentation/home_screen.dart';
import 'package:hasim/features/more/presentation/security_sessions_screen.dart';
import 'package:hasim/features/notifications/presentation/notifications_screen.dart';
import 'package:hasim/features/search/presentation/global_search_screen.dart';
import 'package:hasim/features/settings/presentation/channels_screen.dart';
import 'package:hasim/features/settings/presentation/company_worker_engagement_capabilities_screen.dart';
import 'package:hasim/features/settings/data/company_worker_discovery_repository.dart';
import 'package:hasim/features/settings/presentation/notification_preferences_screen.dart';
import 'package:hasim/features/settings/presentation/plans_screen.dart';
import 'package:hasim/features/settings/presentation/profile_screen.dart';
import 'package:hasim/features/settings/presentation/ai_settings_screen.dart';
import 'package:hasim/features/settings/presentation/ai_test_screen.dart';
import 'package:hasim/features/settings/presentation/company_worker_profile_screen.dart';
import 'package:hasim/features/privacy/presentation/privacy_lock_settings.dart';
import 'package:hasim/features/privacy/providers/chat_lock_controller.dart';
import 'package:hasim/features/settings/presentation/settings_screen.dart';
import 'package:hasim/features/stories/presentation/create_story_screen.dart';
import 'package:hasim/features/stories/presentation/story_viewer_screen.dart';
import 'package:hasim/features/stories/presentation/updates_screen.dart';
import 'package:hasim/features/stories/providers/stories_controller.dart';
import 'package:hasim/features/workspace/presentation/workspace_picker_screen.dart';
import 'package:hasim/features/worker/presentation/worker_account_screen.dart';
import 'package:hasim/features/worker/presentation/worker_profile_screen.dart';
import 'package:hasim/features/worker/presentation/worker_chat_screen.dart';
import 'package:hasim/features/worker/presentation/worker_disputes_screen.dart';
import 'package:hasim/features/worker/presentation/worker_engagement_detail_screen.dart';
import 'package:hasim/features/worker/presentation/worker_engagement_list_screen.dart';
import 'package:hasim/features/worker/presentation/worker_payouts_screen.dart';
import 'package:hasim/features/worker/presentation/worker_sales_reference_screen.dart';
import 'package:hasim/router/app_shell.dart';
import 'package:hasim/router/auth_redirect.dart';
import 'package:hasim/router/worker_nav.dart';

final appRouterProvider = Provider<GoRouter>((ref) {
  // Keep one router for the app lifetime. Rebuilding it on auth changes restarts
  // `/splash`; auth updates refresh redirects through [_AuthListenable].
  final rootKey = GlobalKey<NavigatorState>();

  return GoRouter(
    navigatorKey: rootKey,
    initialLocation: '/splash',
    refreshListenable: _AuthListenable(ref),
    redirect: (context, state) {
      final auth = ref.read(authControllerProvider);
      return resolveHasimRedirect(
        bootstrapping: auth.bootstrapping,
        isAuthenticated: auth.isAuthenticated,
        isIndependentWorker: auth.isIndependentWorker,
        hasWorkspace: auth.workspace != null,
        isOwner: auth.workspace?.isOwner == true,
        location: state.matchedLocation,
      );
    },
    routes: [
      ShellRoute(
        pageBuilder: (context, state, child) =>
            NoTransitionPage(child: AppRootShell(child: child)),
        routes: [
          GoRoute(
            path: '/splash',
            builder: (context, state) => const SplashScreen(),
          ),
          GoRoute(
            path: '/login',
            builder: (context, state) => const LoginScreen(),
          ),
          GoRoute(
            path: '/register',
            builder: (context, state) => const RegisterScreen(),
          ),
          GoRoute(
            path: '/forgot-password',
            builder: (context, state) => const ForgotPasswordScreen(),
          ),
          GoRoute(
            path: '/reset-password',
            builder: (context, state) => ResetPasswordScreen(
              initialEmail: state.extra is String
                  ? state.extra as String
                  : null,
            ),
          ),
          GoRoute(
            path: '/workspaces',
            builder: (context, state) => const WorkspacePickerScreen(),
          ),
          GoRoute(
            path: workerChatLocation,
            builder: (context, state) => const WorkerChatScreen(),
          ),
          GoRoute(
            path: workerOffersLocation,
            pageBuilder: (context, state) =>
                const NoTransitionPage(child: WorkerOffersScreen()),
          ),
          GoRoute(
            path: workerEngagementsLocation,
            pageBuilder: (context, state) =>
                const NoTransitionPage(child: WorkerEngagementsScreen()),
            routes: [
              GoRoute(
                path: ':engagementId',
                pageBuilder: (context, state) {
                  final id = int.tryParse(
                    state.pathParameters['engagementId'] ?? '',
                  );
                  return NoTransitionPage(
                    child: WorkerEngagementDetailScreen(engagementId: id),
                  );
                },
              ),
            ],
          ),
          GoRoute(
            path: workerPayoutsLocation,
            pageBuilder: (context, state) =>
                const NoTransitionPage(child: WorkerPayoutsScreen()),
          ),
          GoRoute(
            path: workerDisputesLocation,
            pageBuilder: (context, state) =>
                const NoTransitionPage(child: WorkerDisputesScreen()),
            routes: [
              GoRoute(
                path: ':disputeId',
                pageBuilder: (context, state) {
                  final id = int.tryParse(
                    state.pathParameters['disputeId'] ?? '',
                  );
                  return NoTransitionPage(
                    child: WorkerDisputeDetailScreen(disputeId: id),
                  );
                },
              ),
            ],
          ),
          GoRoute(
            path: workerAccountLocation,
            pageBuilder: (context, state) => NoTransitionPage(
              child: state.uri.queryParameters['surface'] == 'profile'
                  ? const WorkerProfileScreen()
                  : const WorkerAccountScreen(),
            ),
          ),
          GoRoute(
            path: workerSalesReferenceLocation,
            pageBuilder: (context, state) =>
                const NoTransitionPage(child: WorkerSalesReferenceScreen()),
          ),
          GoRoute(
            path: '/notifications',
            builder: (context, state) => const NotificationsScreen(),
          ),
          GoRoute(
            path: '/profile',
            builder: (context, state) => const ProfileScreen(),
          ),
          GoRoute(
            path: '/plans',
            builder: (context, state) => const PlansScreen(),
          ),
          GoRoute(
            path: '/channels',
            builder: (context, state) => const ChannelsScreen(),
          ),
          GoRoute(
            path: '/notification-preferences',
            builder: (context, state) => const NotificationPreferencesScreen(),
          ),
          GoRoute(
            path: '/ai-settings',
            builder: (context, state) => const AiSettingsScreen(),
          ),
          GoRoute(
            path: '/ai-test',
            builder: (context, state) => AiTestScreen(
              employeeId: state.extra is int ? state.extra as int : null,
            ),
          ),
          GoRoute(
            path: '/company-worker-profile',
            builder: (context, state) {
              final extra = state.extra;
              return CompanyWorkerProfileScreen(
                worker: extra is CompanyWorkerDiscoveryWorker ? extra : null,
                workerProfileId: extra is int ? extra : null,
              );
            },
          ),
          GoRoute(
            path: '$companyWorkerEngagementCapabilitiesLocation/:engagementId',
            pageBuilder: (context, state) {
              final id = int.tryParse(
                state.pathParameters['engagementId'] ?? '',
              );
              return NoTransitionPage(
                child: CompanyWorkerEngagementCapabilitiesScreen(
                  engagementId: id ?? 0,
                  engagement: state.extra is CompanyWorkerOpenEngagement
                      ? state.extra as CompanyWorkerOpenEngagement
                      : null,
                ),
              );
            },
          ),
          GoRoute(path: '/more', redirect: (_, _) => '/settings'),
          GoRoute(
            path: '/settings',
            builder: (context, state) =>
                const SettingsScreen(withNavigation: false),
          ),
          GoRoute(
            path: '/privacy-lock',
            builder: (context, state) => const PrivacyLockScreen(),
          ),
          GoRoute(
            path: '/archive',
            builder: (context, state) =>
                const InboxFolderPage(folder: 'archived'),
          ),
          GoRoute(
            path: '/locked',
            onExit: (context, state) {
              ProviderScope.containerOf(
                context,
              ).read(chatLockControllerProvider.notifier).lockChats();
              return true;
            },
            builder: (context, state) =>
                const InboxFolderPage(folder: 'locked'),
          ),
          GoRoute(
            path: '/chat-themes',
            builder: (context, state) => ChatThemesScreen(
              conversationId: int.tryParse(
                state.uri.queryParameters['conversation'] ?? '',
              ),
            ),
          ),
          GoRoute(
            path: '/more/security',
            builder: (context, state) => const SecuritySessionsScreen(),
          ),
          GoRoute(
            path: '/activity',
            builder: (context, state) => const HomeScreen(),
          ),
          GoRoute(
            path: '/search',
            builder: (context, state) => const GlobalSearchScreen(),
          ),
          GoRoute(
            path: '/customers/:id',
            builder: (context, state) => CustomerProfileScreen(
              customerId: int.parse(state.pathParameters['id']!),
              initialCustomer: state.extra is CustomerModel
                  ? state.extra as CustomerModel
                  : null,
            ),
          ),
          GoRoute(
            path: '/conversations/:id',
            pageBuilder: (context, state) => NoTransitionPage(
              child: ChatEntryScreen(
                conversationId: int.parse(state.pathParameters['id']!),
                initialConversation: state.extra is ConversationModel
                    ? state.extra as ConversationModel
                    : null,
              ),
            ),
          ),
          GoRoute(
            path: '/email/compose',
            builder: (context, state) => const EmailComposeScreen(),
          ),
          GoRoute(
            path: '/email/campaigns/:id',
            builder: (context, state) => CampaignStatusScreen(
              campaignId: int.parse(state.pathParameters['id']!),
            ),
          ),
          GoRoute(
            path: '/email/:id',
            builder: (context, state) =>
                EmailDetailScreen(id: int.parse(state.pathParameters['id']!)),
          ),
          GoRoute(
            path: '/stories/create',
            builder: (context, state) => const CreateStoryScreen(),
          ),
          GoRoute(
            path: '/stories/view',
            builder: (context, state) {
              final extra = state.extra;
              if (extra is StoryViewerArgs) {
                return StoryViewerScreen(args: extra);
              }
              if (extra is Map) {
                final buckets =
                    (extra['buckets'] as List?)
                        ?.whereType<StoryBucket>()
                        .toList() ??
                    const <StoryBucket>[];
                final bucketIndex =
                    (extra['bucketIndex'] as num?)?.toInt() ?? 0;
                final storyIndex = (extra['storyIndex'] as num?)?.toInt() ?? 0;
                if (buckets.isEmpty) {
                  return const Scaffold(
                    body: Center(child: Text('لا توجد قصص للعرض')),
                  );
                }
                return StoryViewerScreen(
                  args: StoryViewerArgs(
                    buckets: buckets,
                    bucketIndex: bucketIndex,
                    storyIndex: storyIndex,
                  ),
                );
              }
              return const Scaffold(
                body: Center(child: Text('لا توجد قصص للعرض')),
              );
            },
          ),
          GoRoute(
            path: '/catalog',
            builder: (context, state) => CatalogListScreen(
              mode: state.uri.queryParameters['mode'] ?? 'catalog',
            ),
          ),
          GoRoute(
            path: '/catalog/form',
            builder: (context, state) {
              final item = state.extra is ChatCatalogItemModel
                  ? state.extra as ChatCatalogItemModel
                  : null;
              return CatalogFormScreen(item: item);
            },
          ),
          GoRoute(
            path: '/chat-catalog-settings',
            builder: (context, state) => const ChatCatalogSettingsScreen(),
          ),
          GoRoute(
            path: '/catalog/categories',
            builder: (context, state) => const CatalogCategoriesScreen(),
          ),
          GoRoute(
            path: '/catalog/categories/form',
            builder: (context, state) => CatalogCategoryFormScreen(
              category: state.extra is ChatCatalogCategoryModel
                  ? state.extra as ChatCatalogCategoryModel
                  : null,
            ),
          ),
          GoRoute(
            path: '/contacts',
            builder: (context, state) => const ContactsListScreen(),
          ),
          GoRoute(
            path: '/contacts/form',
            builder: (context, state) => ContactFormScreen(
              contact: state.extra is EmailContactModel
                  ? state.extra as EmailContactModel
                  : null,
            ),
          ),
          GoRoute(
            path: '/contacts/:id',
            builder: (context, state) =>
                ContactDetailScreen(id: int.parse(state.pathParameters['id']!)),
          ),
          GoRoute(
            path: '/contact-groups',
            builder: (context, state) => const ContactGroupsScreen(),
          ),
          StatefulShellRoute.indexedStack(
            pageBuilder: (context, state, navigationShell) => NoTransitionPage(
              child: AppShell(navigationShell: navigationShell),
            ),
            branches: [
              StatefulShellBranch(
                routes: [
                  GoRoute(
                    path: '/conversations',
                    pageBuilder: (context, state) => NoTransitionPage(
                      child: ConversationsScreen(
                        initialChannel: state.uri.queryParameters['channel'],
                      ),
                    ),
                  ),
                ],
              ),
              StatefulShellBranch(
                routes: [
                  GoRoute(
                    path: '/email',
                    builder: (context, state) => const EmailListScreen(),
                  ),
                ],
              ),
              StatefulShellBranch(
                routes: [
                  GoRoute(
                    path: '/updates',
                    builder: (context, state) => const UpdatesScreen(),
                  ),
                ],
              ),
            ],
          ),
        ],
      ),
    ],
  );
});

class _AuthListenable extends ChangeNotifier {
  _AuthListenable(this.ref) {
    ref.listen(authControllerProvider, (previous, next) => notifyListeners());
  }
  final Ref ref;
}
