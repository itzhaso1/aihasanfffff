import 'package:flutter/painting.dart';

/// خلفيات شاشة المحادثة المضمّنة مع التطبيق.
class ChatWallpaperOption {
  const ChatWallpaperOption({
    required this.id,
    required this.asset,
    required this.label,
    this.tile = false,
  });

  final String id;
  final String asset;

  /// عنوان عربي في شاشة السمات.
  final String label;

  /// `true` = نمط متكرر. `false` = صورة كاملة تملأ الشاشة.
  final bool tile;
}

class ChatWallpaperCatalog {
  static const defaultId = 'pokemon';

  static const defaultAsset = 'assets/chat_wallpapers/chat_wallpaper_pokemon.jpg';

  static const options = <ChatWallpaperOption>[
    ChatWallpaperOption(
      id: defaultId,
      asset: defaultAsset,
      label: 'نمط داكن',
      tile: true,
    ),
    ChatWallpaperOption(
      id: 'color_doodle',
      asset: 'assets/chat_wallpapers/chat_wallpaper_color_doodle.jpg',
      label: 'دودل ملون',
      tile: true,
    ),
    ChatWallpaperOption(
      id: 'geometric',
      asset: 'assets/chat_wallpapers/chat_wallpaper_geometric.jpg',
      label: 'نمط هندسي',
      tile: true,
    ),
    ChatWallpaperOption(
      id: 'mr_doodle',
      asset: 'assets/chat_wallpapers/chat_wallpaper_mr_doodle.jpg',
      label: 'دودل أبيض وأسود',
      tile: true,
    ),
    ChatWallpaperOption(
      id: 'mario',
      asset: 'assets/chat_wallpapers/chat_wallpaper_mario.jpg',
      label: 'نمط ألعاب',
      tile: true,
    ),
    ChatWallpaperOption(
      id: 'social_green',
      asset: 'assets/chat_wallpapers/chat_wallpaper_social_green.jpg',
      label: 'تواصل أخضر',
      tile: true,
    ),
    ChatWallpaperOption(
      id: 'neon_lines',
      asset: 'assets/chat_wallpapers/chat_wallpaper_neon_lines.jpg',
      label: 'خطوط نيون',
      tile: true,
    ),
    ChatWallpaperOption(
      id: 'lifestyle',
      asset: 'assets/chat_wallpapers/chat_wallpaper_lifestyle.jpg',
      label: 'يوميات',
      tile: true,
    ),
    ChatWallpaperOption(
      id: 'dark_icons',
      asset: 'assets/chat_wallpapers/chat_wallpaper_dark_icons.jpg',
      label: 'رموز داكنة',
      tile: true,
    ),
    ChatWallpaperOption(
      id: 'neon_mind',
      asset: 'assets/chat_wallpapers/chat_wallpaper_neon_mind.jpg',
      label: 'عقل نيون',
    ),
    ChatWallpaperOption(
      id: 'light_doodle',
      asset: 'assets/chat_wallpapers/chat_wallpaper_light_doodle.jpg',
      label: 'دودل فاتح',
      tile: true,
    ),
    ChatWallpaperOption(
      id: 'owl',
      asset: 'assets/chat_wallpapers/chat_wallpaper_owl.jpg',
      label: 'بومة ملونة',
    ),
    ChatWallpaperOption(
      id: 'space_doodle',
      asset: 'assets/chat_wallpapers/chat_wallpaper_space_doodle.jpg',
      label: 'فضاء',
      tile: true,
    ),
    ChatWallpaperOption(
      id: 'stickers',
      asset: 'assets/chat_wallpapers/chat_wallpaper_stickers.jpg',
      label: 'ملصقات',
      tile: true,
    ),
    ChatWallpaperOption(
      id: 'night_doodle',
      asset: 'assets/chat_wallpapers/chat_wallpaper_night_doodle.jpg',
      label: 'دودل ليلي',
      tile: true,
    ),
    ChatWallpaperOption(
      id: 'memphis',
      asset: 'assets/chat_wallpapers/chat_wallpaper_memphis.jpg',
      label: 'ممفيس',
      tile: true,
    ),
    ChatWallpaperOption(
      id: 'game_doodle',
      asset: 'assets/chat_wallpapers/chat_wallpaper_game_doodle.jpg',
      label: 'ألعاب داكنة',
      tile: true,
    ),
  ];

  static const _legacyIds = {
    'w01',
    'w02',
    'w03',
    'w04',
    'w05',
    'w06',
    'w07',
    'w08',
    'w09',
    'w10',
    'w11',
    'w12',
    'w13',
    'w14',
    'default',
    'doodle',
    'mandala',
  };

  static ChatWallpaperOption? find(String? id) {
    final resolved = normalize(id);
    for (final option in options) {
      if (option.id == resolved) return option;
    }
    return null;
  }

  static String normalize(String? id) {
    if (id == null || id.isEmpty) return defaultId;
    if (_legacyIds.contains(id)) return defaultId;
    for (final option in options) {
      if (option.id == id) return id;
    }
    return defaultId;
  }

  static String assetFor(String? id) {
    return find(id)?.asset ?? defaultAsset;
  }

  /// طريقة عرض الخلفية في المحادثة والمعاينة.
  static DecorationImage decorationImage(
    String? id, {
    double patternScale = 1.35,
    double opacity = 1,
  }) {
    final option = find(id) ?? options.first;
    if (option.tile) {
      return DecorationImage(
        image: ResizeImage(
          AssetImage(option.asset),
          width: 512,
          policy: ResizeImagePolicy.fit,
        ),
        repeat: ImageRepeat.repeat,
        fit: BoxFit.none,
        scale: patternScale,
        filterQuality: FilterQuality.none,
        opacity: opacity,
        onError: (_, _) {},
      );
    }
    return DecorationImage(
      image: ResizeImage(
        AssetImage(option.asset),
        width: 1080,
        policy: ResizeImagePolicy.fit,
      ),
      fit: BoxFit.cover,
      alignment: Alignment.center,
      filterQuality: FilterQuality.low,
      opacity: opacity,
      onError: (_, _) {},
    );
  }
}
