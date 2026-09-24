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
import 'package:hasim/features/customers/presentation/customer_profile_screen.dart';
import 'package:hasim/features/email/presentation/campaign_status_screen.dart';
import 'package:hasim/features/email/presentation/email_compose_screen.dart';
import 'package:hasim/features/email/presentation/email_detail_screen.dart';
import 'package:hasim/features/email/presentation/email_list_screen.dart';
import 'package:hasim/features/home/presentation/home_screen.dart';
import 'package:hasim/features/more/presentation/security_sessions_screen.dart';
import 'package:hasim/features/notifications/presentation/notifications_screen.dart';
import 'package:hasim/features/settings/presentation/channels_screen.dart';
import 'package:hasim/features/settings/presentation/notification_preferences_screen.dart';
import 'package:hasim/features/settings/presentation/plans_screen.dart';
import 'package:hasim/features/settings/presentation/profile_screen.dart';
import 'package:hasim/features/settings/presentation/ai_settings_screen.dart';
import 'package:hasim/features/search/presentation/global_search_screen.dart';
import 'package:hasim/features/settings/presentation/settings_screen.dart';
import 'package:hasim/features/stories/presentation/create_story_screen.dart';
import 'package:hasim/features/stories/presentation/story_viewer_screen.dart';
import 'package:hasim/features/stories/presentation/updates_screen.dart';
import 'package:hasim/features/stories/providers/stories_controller.dart';
import 'package:hasim/features/workspace/presentation/workspace_picker_screen.dart';
import 'package:hasim/router/app_shell.dart';

final _rootKey = GlobalKey<NavigatorState>();

final appRouterProvider = Provider<GoRouter>((ref) {
  final auth = ref.watch(authControllerProvider);

  return GoRouter(
    navigatorKey: _rootKey,
    initialLocation: '/splash',
    refreshListenable: _AuthListenable(ref),
    redirect: (context, state) {
      final loc = state.matchedLocation;
      final publicAuth = loc == '/login' ||
          loc == '/forgot-password' ||
          loc == '/reset-password' ||
          loc == '/splash';

      if (loc == '/splash') return null;
      if (auth.bootstrapping) return '/splash';

      // Legacy dashboard route → conversations (messaging-first)
      if (loc == '/home') return '/conversations';

      if (!auth.isAuthenticated) {
        return publicAuth ? null : '/login';
      }
      if (auth.isAuthenticated && (loc == '/login' || loc == '/forgot-password' || loc == '/reset-password')) {
        return auth.workspace == null ? '/workspaces' : '/conversations';
      }
      if (auth.isAuthenticated && auth.workspace == null && loc != '/workspaces') {
        return '/workspaces';
      }
      return null;
    },
    routes: [
      GoRoute(path: '/splash', builder: (context, state) => const SplashScreen()),
      GoRoute(path: '/login', builder: (context, state) => const LoginScreen()),
      GoRoute(path: '/forgot-password', builder: (context, state) => const ForgotPasswordScreen()),
      GoRoute(
        path: '/reset-password',
        builder: (context, state) => ResetPasswordScreen(
          initialEmail: state.extra is String ? state.extra as String : null,
        ),
      ),
      GoRoute(path: '/workspaces', builder: (context, state) => const WorkspacePickerScreen()),
      GoRoute(path: '/notifications', builder: (context, state) => const NotificationsScreen()),
      GoRoute(path: '/profile', builder: (context, state) => const ProfileScreen()),
      GoRoute(path: '/plans', builder: (context, state) => const PlansScreen()),
      GoRoute(path: '/channels', builder: (context, state) => const ChannelsScreen()),
      GoRoute(path: '/notification-preferences', builder: (context, state) => const NotificationPreferencesScreen()),
      GoRoute(path: '/chat-catalog-settings', builder: (context, state) => const ChatCatalogSettingsScreen()),
      GoRoute(path: '/ai-settings', builder: (context, state) => const AiSettingsScreen()),
      GoRoute(path: '/search', builder: (context, state) => const GlobalSearchScreen()),
      GoRoute(path: '/more', redirect: (_, __) => '/settings'),
      GoRoute(path: '/settings', builder: (context, state) => const SettingsScreen()),
      GoRoute(path: '/chat-themes', builder: (context, state) => const ChatThemesScreen()),
      GoRoute(path: '/more/security', builder: (context, state) => const SecuritySessionsScreen()),
      GoRoute(path: '/activity', builder: (context, state) => const HomeScreen()),
      GoRoute(
        path: '/customers/:id',
        builder: (context, state) => CustomerProfileScreen(customerId: int.parse(state.pathParameters['id']!)),
      ),
      GoRoute(
        path: '/conversations/:id',
        builder: (context, state) => ChatEntryScreen(conversationId: int.parse(state.pathParameters['id']!)),
      ),
      GoRoute(path: '/email/compose', builder: (context, state) => const EmailComposeScreen()),
      GoRoute(
        path: '/email/campaigns/:id',
        builder: (context, state) => CampaignStatusScreen(campaignId: int.parse(state.pathParameters['id']!)),
      ),
      GoRoute(
        path: '/email/:id',
        builder: (context, state) => EmailDetailScreen(id: int.parse(state.pathParameters['id']!)),
      ),
      GoRoute(path: '/stories/create', builder: (context, state) => const CreateStoryScreen()),
      GoRoute(
        path: '/stories/view',
        builder: (context, state) {
          final extra = state.extra;
          if (extra is StoryViewerArgs) {
            return StoryViewerScreen(args: extra);
          }
          if (extra is Map) {
            final buckets = (extra['buckets'] as List?)?.whereType<StoryBucket>().toList() ?? const <StoryBucket>[];
            final bucketIndex = (extra['bucketIndex'] as num?)?.toInt() ?? 0;
            final storyIndex = (extra['storyIndex'] as num?)?.toInt() ?? 0;
            if (buckets.isEmpty) {
              return const Scaffold(body: Center(child: Text('لا توجد قصص للعرض')));
            }
            return StoryViewerScreen(
              args: StoryViewerArgs(buckets: buckets, bucketIndex: bucketIndex, storyIndex: storyIndex),
            );
          }
          return const Scaffold(body: Center(child: Text('لا توجد قصص للعرض')));
        },
      ),
      GoRoute(path: '/catalog', builder: (context, state) => const CatalogListScreen()),
      GoRoute(path: '/catalog/categories', builder: (context, state) => const CatalogCategoriesScreen()),
      GoRoute(
        path: '/catalog/categories/form',
        builder: (context, state) => CatalogCategoryFormScreen(
          category: state.extra is ChatCatalogCategoryModel ? state.extra as ChatCatalogCategoryModel : null,
        ),
      ),
      GoRoute(
        path: '/catalog/form',
        builder: (context, state) => CatalogFormScreen(
          item: state.extra is ChatCatalogItemModel ? state.extra as ChatCatalogItemModel : null,
        ),
      ),
      GoRoute(path: '/contacts', builder: (context, state) => const ContactsListScreen()),
      GoRoute(
        path: '/contacts/form',
        builder: (context, state) => ContactFormScreen(
          contact: state.extra is EmailContactModel ? state.extra as EmailContactModel : null,
        ),
      ),
      GoRoute(
        path: '/contacts/:id',
        builder: (context, state) => ContactDetailScreen(id: int.parse(state.pathParameters['id']!)),
      ),
      GoRoute(path: '/contact-groups', builder: (context, state) => const ContactGroupsScreen()),
      StatefulShellRoute.indexedStack(
        builder: (context, state, navigationShell) => AppShell(navigationShell: navigationShell),
        branches: [
          StatefulShellBranch(routes: [GoRoute(path: '/conversations', builder: (context, state) => const ConversationsScreen())]),
          StatefulShellBranch(routes: [GoRoute(path: '/email', builder: (context, state) => const EmailListScreen())]),
          StatefulShellBranch(routes: [GoRoute(path: '/updates', builder: (context, state) => const UpdatesScreen())]),
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
