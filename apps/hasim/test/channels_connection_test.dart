import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/settings/data/channel_connection.dart';
import 'package:hasim/features/settings/data/channel_connection_runtime.dart';
import 'package:hasim/features/settings/data/plan_channel_repository.dart';
import 'package:hasim/features/settings/presentation/channels_screen.dart';
import 'package:hasim/features/settings/providers/channels_controller.dart';
import 'package:hasim/l10n/app_localizations.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'support/isolated_api_client.dart';

class _MemorySecureStore extends SecureStore {
  @override
  Future<void> saveToken(String token) async {}
  @override
  Future<String?> readToken() async => 'token';
  @override
  Future<void> clearToken() async {}
}

class _SeededAuth extends AuthController {
  _SeededAuth(super.ref, this.seed);
  final AuthState seed;

  @override
  Future<void> bootstrap() async {
    state = seed;
  }

  void switchTo(WorkspaceModel workspace) {
    state = state.copyWith(workspace: workspace);
  }
}

class _FakeChannels extends ChannelRepository {
  _FakeChannels(super.api);

  List<ChannelModel> items = [
    const ChannelModel(
      key: 'whatsapp',
      name: 'WhatsApp',
      canConnectInApp: true,
      canManage: true,
      connectionMode: 'embedded_signup',
    ),
    const ChannelModel(
      key: 'facebook_messenger',
      name: 'Facebook Messenger',
      canConnectInApp: true,
      canManage: true,
      connectionMode: 'oauth',
    ),
    const ChannelModel(
      key: 'instagram',
      name: 'Instagram',
      canConnectInApp: true,
      canManage: true,
      connectionMode: 'oauth',
    ),
    const ChannelModel(
      key: 'telegram',
      name: 'Telegram',
      canConnectInApp: true,
      canManage: true,
      connectionMode: 'bot_token',
    ),
    const ChannelModel(
      key: 'tiktok',
      name: 'TikTok',
      canConnectInApp: true,
      canManage: true,
      connectionMode: 'oauth',
    ),
  ];

  final starts = <String>[];
  String? lastBotToken;
  Object? startError;
  ChannelConnectStart? startResult;
  int disconnects = 0;
  List<FacebookPageChoice> pages = const [];
  String? selectedPage;

  @override
  Future<List<ChannelModel>> list() async => items;

  @override
  Future<ChannelConnectStart> startConnect(
    String channel, {
    String? botToken,
  }) async {
    starts.add(channel);
    lastBotToken = botToken;
    if (startError != null) {
      throw startError!;
    }
    if (channel == 'telegram') {
      items = [
        for (final item in items)
          if (item.key == 'telegram')
            ChannelModel(
              key: item.key,
              name: item.name,
              connected: true,
              status: 'connected',
              canConnectInApp: true,
              canDisconnect: true,
              connectionMode: 'bot_token',
              displayName: '@hasim_bot',
            )
          else
            item,
      ];
      return ChannelConnectStart(
        channel: channel,
        mode: 'bot_token',
        connected: true,
        displayName: '@hasim_bot',
      );
    }
    return startResult ??
        ChannelConnectStart(
          channel: channel,
          mode: channel == 'whatsapp' ? 'embedded_signup' : 'oauth',
          authorizationUrl: 'https://example.test/oauth/$channel',
          callbackScheme: 'hasim',
        );
  }

  @override
  Future<void> disconnect(String channel) async {
    disconnects++;
    items = [
      for (final item in items)
        if (item.key == channel)
          ChannelModel(
            key: item.key,
            name: item.name,
            connected: false,
            status: 'disconnected',
            canConnectInApp: true,
            connectionMode: item.connectionMode,
          )
        else
          item,
    ];
  }

  @override
  Future<List<FacebookPageChoice>> facebookPages(String attempt) async => pages;

  @override
  Future<ChannelConnectStart> selectFacebookPage(
    String attempt,
    String pageId,
  ) async {
    selectedPage = pageId;
    items = [
      for (final item in items)
        if (item.key == 'facebook_messenger')
          const ChannelModel(
            key: 'facebook_messenger',
            name: 'Facebook Messenger',
            connected: true,
            status: 'connected',
            canConnectInApp: true,
            canDisconnect: true,
            connectionMode: 'oauth',
            displayName: 'Page Two',
          )
        else
          item,
    ];
    return const ChannelConnectStart(
      channel: 'facebook_messenger',
      mode: 'oauth',
      connected: true,
      displayName: 'Page Two',
    );
  }
}

class _Launcher implements ChannelBrowserLauncher {
  final opened = <Uri>[];
  bool ok = true;

  @override
  Future<bool> open(Uri url) async {
    opened.add(url);
    return ok;
  }
}

class _Links implements ChannelCallbackLinkSource {
  final controller = StreamController<Uri>.broadcast();

  @override
  Stream<Uri> get incoming => controller.stream;

  @override
  Future<Uri?> initial() async => null;
}

const _workspaceA = WorkspaceModel(id: 1, name: 'شركة أ', isOwner: true);
const _workspaceB = WorkspaceModel(id: 2, name: 'شركة ب', isOwner: true);

const _authA = AuthState(
  bootstrapping: false,
  token: 'token',
  user: UserModel(id: 8, name: 'حسن'),
  workspace: _workspaceA,
  workspaces: [_workspaceA, _workspaceB],
);

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  GoogleFonts.config.allowRuntimeFetching = false;

  late _FakeChannels channels;
  late _Launcher launcher;
  late _Links links;
  late _SeededAuth auth;
  late SharedPreferences prefs;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    prefs = await SharedPreferences.getInstance();
    channels = _FakeChannels(
      isolatedTestApiClient(
        secureStore: _MemorySecureStore(),
        prefsStore: PrefsStore(prefs),
      ),
    );
    launcher = _Launcher();
    links = _Links();
  });

  Widget app() {
    return ProviderScope(
      overrides: [
        sharedPrefsProvider.overrideWithValue(prefs),
        secureStoreProvider.overrideWithValue(_MemorySecureStore()),
        apiClientProvider.overrideWithValue(
          isolatedTestApiClient(
            secureStore: _MemorySecureStore(),
            prefsStore: PrefsStore(prefs),
          ),
        ),
        channelRepositoryProvider.overrideWithValue(channels),
        channelBrowserLauncherProvider.overrideWithValue(launcher),
        channelCallbackLinkSourceProvider.overrideWithValue(links),
        authControllerProvider.overrideWith((ref) {
          auth = _SeededAuth(ref, _authA);
          return auth;
        }),
      ],
      child: MaterialApp(
        locale: const Locale('ar'),
        supportedLocales: AppLocalizations.supportedLocales,
        localizationsDelegates: AppLocalizations.localizationsDelegates,
        home: const Scaffold(body: ChannelsScreen(embedded: true)),
      ),
    );
  }

  Future<void> settleList(WidgetTester tester) async {
    await tester.pumpWidget(app());
    await tester.pump();
    await tester.pump();
  }

  testWidgets(
    'lists channels and starts WhatsApp Meta Telegram TikTok connections',
    (tester) async {
      await settleList(tester);

      expect(find.text('WhatsApp'), findsOneWidget);
      expect(find.text('Facebook Messenger'), findsOneWidget);
      expect(find.text('Instagram'), findsOneWidget);
      expect(find.text('Telegram'), findsOneWidget);
      expect(find.text('TikTok'), findsOneWidget);
      expect(find.text('ربط'), findsWidgets);

      await tester.tap(find.byKey(const ValueKey('channel-connect-whatsapp')));
      await tester.pump();
      expect(channels.starts, contains('whatsapp'));
      expect(launcher.opened.single.toString(), contains('/oauth/whatsapp'));
    },
  );

  testWidgets(
    'starts Facebook oauth in the system browser instead of fetching Facebook',
    (tester) async {
      channels.startResult = const ChannelConnectStart(
        channel: 'facebook_messenger',
        mode: 'oauth',
        authorizationUrl: 'https://www.facebook.com/v20.0/dialog/oauth',
        callbackScheme: 'hasim',
      );
      await settleList(tester);
      await tester.ensureVisible(
        find.byKey(const ValueKey('channel-connect-facebook_messenger')),
      );
      await tester.tap(
        find.byKey(const ValueKey('channel-connect-facebook_messenger')),
      );
      await tester.pump();
      expect(channels.starts, ['facebook_messenger']);
      expect(
        launcher.opened.single.toString(),
        'https://www.facebook.com/v20.0/dialog/oauth',
      );
      expect(find.text('تعذر تحديث هذا الجزء. حاول مرة أخرى.'), findsNothing);
    },
  );

  testWidgets('starts Instagram and TikTok oauth in the system browser', (
    tester,
  ) async {
    await settleList(tester);
    await tester.ensureVisible(
      find.byKey(const ValueKey('channel-connect-instagram')),
    );
    await tester.tap(find.byKey(const ValueKey('channel-connect-instagram')));
    await tester.pump();
    expect(channels.starts, contains('instagram'));

    await tester.ensureVisible(
      find.byKey(const ValueKey('channel-connect-tiktok')),
    );
    await tester.tap(find.byKey(const ValueKey('channel-connect-tiktok')));
    await tester.pump();
    expect(channels.starts, contains('tiktok'));
    expect(launcher.opened.length, 2);
  });

  testWidgets('shows loading then connected after callback success', (
    tester,
  ) async {
    await settleList(tester);
    await tester.tap(find.byKey(const ValueKey('channel-connect-whatsapp')));
    await tester.pump();
    expect(find.byType(CircularProgressIndicator), findsOneWidget);

    channels.items = [
      const ChannelModel(
        key: 'whatsapp',
        name: 'WhatsApp',
        connected: true,
        status: 'connected',
        canConnectInApp: true,
        canDisconnect: true,
        connectionMode: 'embedded_signup',
        displayName: 'Hasim WABA',
      ),
      ...channels.items.skip(1),
    ];
    links.controller.add(
      Uri.parse(
        'hasim://channels/oauth?channel=whatsapp&status=success&workspace=1',
      ),
    );
    await tester.pump();
    await tester.pump();
    expect(find.text('متصل'), findsWidgets);
    expect(find.text('إعادة ربط'), findsOneWidget);
    expect(find.text('قطع الاتصال'), findsOneWidget);
  });

  testWidgets('Facebook page selection refreshes and clears the busy state', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(800, 1000);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await settleList(tester);
    channels.pages = const [FacebookPageChoice(id: 'page-1', name: 'Page One')];
    links.controller.add(
      Uri.parse(
        'hasim://channels/oauth?channel=facebook_messenger'
        '&status=select_page&attempt=attempt-1&workspace=1',
      ),
    );
    await tester.pump();
    await tester.pump();

    expect(find.text('اختر صفحة Facebook'), findsOneWidget);
    final container = ProviderScope.containerOf(
      tester.element(find.byType(ChannelsScreen)),
    );
    await container
        .read(channelsControllerProvider.notifier)
        .selectFacebookPage('page-1');
    await tester.pumpAndSettle();

    expect(channels.selectedPage, 'page-1');
    expect(find.text('متصل'), findsOneWidget);
    expect(find.byType(CircularProgressIndicator), findsNothing);
  });

  testWidgets('shows failure and cancel browser flow', (tester) async {
    await settleList(tester);
    launcher.ok = false;
    await tester.tap(find.byKey(const ValueKey('channel-connect-whatsapp')));
    await tester.pump();
    await tester.pump();
    expect(find.text('تعذر فتح صفحة الربط.'), findsOneWidget);
  });

  testWidgets('handles cancelled oauth callback', (tester) async {
    await settleList(tester);
    await tester.tap(find.byKey(const ValueKey('channel-connect-instagram')));
    await tester.pump();
    links.controller.add(
      Uri.parse(
        'hasim://channels/oauth?channel=instagram&status=cancelled&workspace=1',
      ),
    );
    await tester.pump();
    await tester.pump();
    expect(find.text('تم إلغاء الربط.'), findsOneWidget);
  });

  testWidgets('telegram token sheet is shown from connect', (tester) async {
    await settleList(tester);
    await tester.ensureVisible(
      find.byKey(const ValueKey('channel-connect-telegram')),
    );
    await tester.tap(find.byKey(const ValueKey('channel-connect-telegram')));
    await tester.pump();
    await tester.pump();
    expect(find.text('ربط Telegram'), findsOneWidget);
    expect(find.textContaining('BotFather'), findsOneWidget);
  });

  testWidgets('telegram connect posts token without browser', (tester) async {
    await settleList(tester);
    final ctx = tester.element(find.byType(ChannelsScreen));
    await ProviderScope.containerOf(ctx)
        .read(channelsControllerProvider.notifier)
        .connect('telegram', botToken: '123456:secret');
    await tester.pump();
    await tester.pump();
    expect(channels.lastBotToken, '123456:secret');
    expect(launcher.opened, isEmpty);
    expect(find.text('متصل'), findsWidgets);
  });

  testWidgets('disconnect and reconnect refresh status', (tester) async {
    channels.items = [
      const ChannelModel(
        key: 'facebook_messenger',
        name: 'Facebook Messenger',
        connected: true,
        status: 'connected',
        canConnectInApp: true,
        canDisconnect: true,
        connectionMode: 'oauth',
        displayName: 'Hasim Page',
      ),
    ];
    await settleList(tester);
    expect(find.text('قطع الاتصال'), findsOneWidget);
    await tester.tap(find.text('قطع الاتصال'));
    await tester.pump();
    await tester.pump();
    expect(channels.disconnects, 1);
    expect(find.text('غير متصل'), findsOneWidget);

    await tester.tap(find.text('ربط'));
    await tester.pump();
    expect(channels.starts, contains('facebook_messenger'));
  });

  testWidgets('workspace switch reloads only the new workspace channels', (
    tester,
  ) async {
    await settleList(tester);
    channels.items = [
      const ChannelModel(
        key: 'tiktok',
        name: 'TikTok',
        connected: true,
        status: 'connected',
        connectionMode: 'oauth',
        displayName: 'B shop',
      ),
    ];
    final ctx = tester.element(find.byType(ChannelsScreen));
    ProviderScope.containerOf(ctx).read(authControllerProvider.notifier);
    auth.switchTo(_workspaceB);
    await tester.pump();
    await tester.pump();
    expect(find.text('TikTok'), findsOneWidget);
    expect(find.text('WhatsApp'), findsNothing);
  });

  testWidgets('authorization error is shown', (tester) async {
    channels.startError = ApiException(
      'ليست لديك صلاحية إدارة القنوات.',
      statusCode: 403,
    );
    await settleList(tester);
    await tester.tap(find.byKey(const ValueKey('channel-connect-whatsapp')));
    await tester.pump();
    await tester.pump();
    expect(find.textContaining('صلاحية'), findsOneWidget);
  });

  test('repository start rejects secret payloads', () async {
    SharedPreferences.setMockInitialValues({});
    final prefsStore = PrefsStore(await SharedPreferences.getInstance());
    final dio = Dio(BaseOptions(baseUrl: 'http://127.0.0.1/api/mobile/v1/'));
    dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) {
          handler.resolve(
            Response(
              requestOptions: options,
              data: {
                'success': true,
                'data': {
                  'channel': 'instagram',
                  'mode': 'oauth',
                  'access_token': 'should-not-leak',
                },
              },
            ),
          );
        },
      ),
    );
    final repo = ChannelRepository(
      ApiClient(
        secureStore: _MemorySecureStore(),
        prefsStore: prefsStore,
        dio: dio,
      ),
    );
    expect(repo.startConnect('instagram'), throwsA(isA<ApiException>()));
  });
}
