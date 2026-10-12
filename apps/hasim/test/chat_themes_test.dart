import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/features/conversations/data/chat_wallpaper_catalog.dart';
import 'package:hasim/features/conversations/presentation/chat_themes_screen.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_wallpaper.dart';
import 'package:hasim/features/conversations/data/chat_bubble_color_catalog.dart';
import 'package:hasim/features/conversations/providers/chat_bubble_color_controller.dart';
import 'package:hasim/features/conversations/providers/chat_wallpaper_controller.dart';
import 'package:hasim/l10n/app_localizations.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('chat wallpaper defaults and migrates removed or legacy ids', () async {
    SharedPreferences.setMockInitialValues({});
    final store = PrefsStore(await SharedPreferences.getInstance());
    final controller = ChatWallpaperController(store);

    expect(controller.state, ChatWallpaperCatalog.defaultId);
    expect(
      ChatWallpaperCatalog.assetFor(controller.state),
      ChatWallpaperCatalog.defaultAsset,
    );

    for (final removed in ['default', 'doodle', 'mandala', 'w03']) {
      await controller.select(removed);
      expect(controller.state, ChatWallpaperCatalog.defaultId);
    }

    await controller.select('missing');
    expect(controller.state, ChatWallpaperCatalog.defaultId);
    expect(store.chatWallpaper, ChatWallpaperCatalog.defaultId);

    await controller.select('geometric');
    expect(controller.state, 'geometric');

    await controller.select('mr_doodle');
    expect(controller.state, 'mr_doodle');
    await controller.select('mario');
    expect(controller.state, 'mario');
    await controller.select('owl');
    expect(controller.state, 'owl');
    expect(ChatWallpaperCatalog.assetFor('owl'), 'assets/chat_wallpapers/chat_wallpaper_owl.jpg');
    expect(ChatWallpaperCatalog.find('neon_mind')?.tile, isFalse);
    expect(ChatWallpaperCatalog.options, hasLength(17));
  });

  test('chat outgoing bubble color defaults and normalizes unknown ids', () async {
    SharedPreferences.setMockInitialValues({});
    final store = PrefsStore(await SharedPreferences.getInstance());
    final controller = ChatBubbleColorController(store);

    expect(controller.state, ChatBubbleColorCatalog.defaultId);
    await controller.select('lavender');
    expect(controller.state, 'lavender');
    expect(store.chatOutgoingBubbleColor, 'lavender');

    await controller.select('unknown');
    expect(controller.state, ChatBubbleColorCatalog.defaultId);
  });

  testWidgets('themes screen lists all chat wallpapers', (tester) async {
    SharedPreferences.setMockInitialValues({});
    final prefs = await SharedPreferences.getInstance();

    final container = ProviderContainer(
      overrides: [sharedPrefsProvider.overrideWithValue(prefs)],
    );
    addTearDown(container.dispose);

    await tester.pumpWidget(
      UncontrolledProviderScope(
        container: container,
        child: MaterialApp(
          locale: const Locale('ar'),
          supportedLocales: AppLocalizations.supportedLocales,
          localizationsDelegates: AppLocalizations.localizationsDelegates,
          home: const ChatThemesScreen(),
        ),
      ),
    );
    await tester.pump();

    expect(find.text('المظهر والسمات'), findsOneWidget);
    expect(find.text('نمط داكن'), findsOneWidget);
    expect(find.text('دودل ملون'), findsOneWidget);
    expect(find.text('نمط هندسي'), findsOneWidget);
    expect(find.text('دودل أبيض وأسود'), findsOneWidget);
    expect(find.text('نمط ألعاب'), findsOneWidget);
    expect(find.text('لون فقاعاتك'), findsOneWidget);
    for (final option in ChatWallpaperCatalog.options) {
      expect(find.byKey(Key('chat-theme-${option.id}')), findsOneWidget);
    }
    expect(container.read(chatWallpaperControllerProvider), ChatWallpaperCatalog.defaultId);

    await tester.pumpWidget(
      UncontrolledProviderScope(
        container: container,
        child: const MaterialApp(home: Scaffold(body: ChatWallpaper())),
      ),
    );
    await tester.pump();

    final decorated = tester.widget<DecoratedBox>(find.byType(DecoratedBox).first);
    final provider = (decorated.decoration as BoxDecoration).image!.image;
    final image = provider is ResizeImage ? provider.imageProvider as AssetImage : provider as AssetImage;
    expect(image.assetName, ChatWallpaperCatalog.defaultAsset);
  });
}
