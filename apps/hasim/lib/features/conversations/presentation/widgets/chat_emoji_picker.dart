import 'package:flutter/material.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';

enum _PickerSurface { emoji, gif, sticker }

class _EmojiCategory {
  const _EmojiCategory({
    required this.id,
    required this.icon,
    required this.titleAr,
    required this.emojis,
  });

  final String id;
  final IconData icon;
  final String titleAr;
  final List<String> emojis;
}

const _categories = <_EmojiCategory>[
  _EmojiCategory(
    id: 'smileys',
    icon: Icons.emoji_emotions_outlined,
    titleAr: 'وجوه ضاحكة وأشخاص',
    emojis: [
      '😀', '😃', '😄', '😁', '😆', '😅', '🤣', '😂', '🙂', '🙃',
      '😉', '😊', '😇', '🥰', '😍', '🤩', '😘', '😗', '☺️', '😚',
      '😙', '🥲', '😋', '😛', '😜', '🤪', '😝', '🤑', '🤗', '🤭',
      '🤫', '🤔', '🤐', '🤨', '😐', '😑', '😶', '😏', '😒', '🙄',
      '😬', '🤥', '😌', '😔', '😪', '🤤', '😴', '😷', '🤒', '🤕',
      '🤢', '🤮', '🤧', '🥵', '🥶', '🥴', '😵', '🤯', '🤠', '🥳',
      '🥸', '😎', '🤓', '🧐', '😕', '😟', '🙁', '☹️', '😮', '😯',
      '😲', '😳', '🥺', '😦', '😧', '😨', '😰', '😥', '😢', '😭',
      '😱', '😖', '😣', '😞', '😓', '😩', '😫', '🥱', '😤', '😡',
      '😠', '🤬', '👋', '🤚', '🖐️', '✋', '🖖', '👌', '🤌', '🤏',
      '✌️', '🤞', '🤟', '🤘', '🤙', '👈', '👉', '👆', '👇', '☝️',
      '👍', '👎', '✊', '👊', '🤛', '🤜', '👏', '🙌', '👐', '🤲',
      '🙏', '💪', '🦾', '🦿', '🦵', '🦶', '👂', '🦻', '👃', '🧠',
    ],
  ),
  _EmojiCategory(
    id: 'nature',
    icon: Icons.local_florist_outlined,
    titleAr: 'حيوانات وطبيعة',
    emojis: [
      '🐶', '🐱', '🐭', '🐹', '🐰', '🦊', '🐻', '🐼', '🐨', '🐯',
      '🦁', '🐮', '🐷', '🐸', '🐵', '🐔', '🐧', '🐦', '🐤', '🦄',
      '🐝', '🦋', '🐌', '🐞', '🐢', '🐍', '🦎', '🐙', '🦑', '🐠',
      '🐬', '🐳', '🌸', '💐', '🌹', '🌺', '🌻', '🌼', '🌷', '🌱',
      '🌲', '🌳', '🌴', '🍀', '🍁', '🍂', '🌍', '🌙', '⭐', '🌈',
    ],
  ),
  _EmojiCategory(
    id: 'food',
    icon: Icons.local_cafe_outlined,
    titleAr: 'طعام ومشروبات',
    emojis: [
      '🍎', '🍐', '🍊', '🍋', '🍌', '🍉', '🍇', '🍓', '🫐', '🍒',
      '🍑', '🥭', '🍍', '🥥', '🥝', '🍅', '🥑', '🌽', '🌶️', '🥒',
      '🥕', '🧄', '🧅', '🥔', '🍞', '🥐', '🥖', '🧀', '🥚', '🍳',
      '🥞', '🧇', '🥓', '🍔', '🍟', '🍕', '🌭', '🥪', '🌮', '🌯',
      '🥗', '🍝', '🍜', '🍲', '🍛', '🍣', '🍱', '🥟', '🍤', '🍩',
      '🍪', '🎂', '🍰', '🧁', '🍫', '🍬', '🍭', '☕', '🍵', '🧃',
    ],
  ),
  _EmojiCategory(
    id: 'activities',
    icon: Icons.sports_basketball_outlined,
    titleAr: 'أنشطة',
    emojis: [
      '⚽', '🏀', '🏈', '⚾', '🎾', '🏐', '🏉', '🎱', '🏓', '🏸',
      '🥅', '🏒', '🏑', '🥍', '🏏', '⛳', '🏹', '🎣', '🥊', '🥋',
      '🎽', '🛹', '🛼', '🚴', '🏊', '🤸', '⛷️', '🏂', '🧗', '🤺',
      '🏆', '🥇', '🥈', '🥉', '🏅', '🎖️', '🎯', '🎮', '🎲', '🧩',
    ],
  ),
  _EmojiCategory(
    id: 'travel',
    icon: Icons.directions_car_outlined,
    titleAr: 'سفر وأماكن',
    emojis: [
      '🚗', '🚕', '🚙', '🚌', '🚎', '🏎️', '🚓', '🚑', '🚒', '🚐',
      '🛻', '🚚', '🚛', '🚜', '🏍️', '🛵', '🚲', '🛴', '✈️', '🛫',
      '🛬', '🚀', '🛸', '🚁', '⛵', '🚤', '🛥️', '🚢', '⚓', '🗼',
      '🗽', '🏰', '🏯', '🕌', '⛪', '🛕', '🗿', '🏠', '🏡', '🏢',
    ],
  ),
  _EmojiCategory(
    id: 'objects',
    icon: Icons.lightbulb_outline_rounded,
    titleAr: 'أشياء',
    emojis: [
      '⌚', '📱', '💻', '⌨️', '🖥️', '🖨️', '🖱️', '💽', '💾', '💿',
      '📷', '📸', '📹', '🎥', '📞', '☎️', '📺', '📻', '🎙️', '⏰',
      '⏳', '💡', '🔦', '🕯️', '🧯', '💰', '💳', '💎', '🔧', '🔨',
      '⚙️', '🔑', '🗝️', '🔒', '🔓', '📎', '📌', '✂️', '📝', '✏️',
      '📁', '📂', '📅', '📊', '📈', '📉', '🗂️', '🗃️', '🗄️', '🗑️',
    ],
  ),
  _EmojiCategory(
    id: 'symbols',
    icon: Icons.music_note_outlined,
    titleAr: 'رموز',
    emojis: [
      '❤️', '🧡', '💛', '💚', '💙', '💜', '🖤', '🤍', '🤎', '💔',
      '❣️', '💕', '💞', '💓', '💗', '💖', '💘', '💝', '💟', '☮️',
      '✝️', '☪️', '🕉️', '☸️', '✡️', '🔯', '🕎', '☯️', '☦️', '🛐',
      '⛎', '♈', '♉', '♊', '♋', '♌', '♍', '♎', '♏', '♐',
      '♑', '♒', '♓', '🆔', '⚛️', '🉑', '☢️', '☣️', '📴', '📳',
      '🎵', '🎶', '➕', '➖', '➗', '✖️', '♾️', '‼️', '⁉️', '❓',
    ],
  ),
  _EmojiCategory(
    id: 'flags',
    icon: Icons.flag_outlined,
    titleAr: 'أعلام',
    emojis: [
      '🏁', '🚩', '🎌', '🏴', '🏳️', '🏳️‍🌈', '🇦🇪', '🇸🇦', '🇪🇬', '🇯🇴',
      '🇱🇧', '🇸🇾', '🇮🇶', '🇰🇼', '🇶🇦', '🇧🇭', '🇴🇲', '🇾🇪', '🇲🇦', '🇩🇿',
      '🇹🇳', '🇱🇾', '🇸🇩', '🇵🇸', '🇹🇷', '🇺🇸', '🇬🇧', '🇫🇷', '🇩🇪', '🇮🇹',
    ],
  ),
];

/// لوحة رموز تعبيرية عائمة (ليست modal) — فوق شريط الكتابة.
class ChatEmojiPickerPanel extends StatefulWidget {
  const ChatEmojiPickerPanel({
    super.key,
    required this.onEmojiSelected,
  });

  static const double panelHeight = 352;
  static const double panelMaxWidth = 420;

  final ValueChanged<String> onEmojiSelected;

  @override
  State<ChatEmojiPickerPanel> createState() => _ChatEmojiPickerPanelState();
}

class _ChatEmojiPickerPanelState extends State<ChatEmojiPickerPanel> {
  int _categoryIndex = 0;
  _PickerSurface _surface = _PickerSurface.emoji;
  final _search = TextEditingController();

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  List<String> get _visibleEmojis {
    final query = _search.text.trim();
    if (query.isEmpty) return _categories[_categoryIndex].emojis;
    final all = <String>[];
    for (final cat in _categories) {
      all.addAll(cat.emojis);
    }
    if (query.runes.length <= 2) {
      return all.where((e) => e.contains(query)).toList();
    }
    return all;
  }

  @override
  Widget build(BuildContext context) {
    final line = HasimTokens.lineColor(context);
    final muted = HasimTokens.mutedColor(context);

    return Material(
      color: Colors.white,
      elevation: 8,
      shadowColor: Colors.black.withValues(alpha: 0.18),
      borderRadius: BorderRadius.circular(14),
      clipBehavior: Clip.antiAlias,
      child: SizedBox(
        height: ChatEmojiPickerPanel.panelHeight,
        child: Directionality(
          textDirection: TextDirection.rtl,
          child: Column(
            children: [
                  SizedBox(
                    height: 44,
                    child: ListView.separated(
                      scrollDirection: Axis.horizontal,
                      padding: const EdgeInsets.symmetric(horizontal: 6),
                      itemCount: _categories.length,
                      separatorBuilder: (_, _) => const SizedBox(width: 2),
                      itemBuilder: (context, index) {
                        final cat = _categories[index];
                        final selected = _surface == _PickerSurface.emoji && _categoryIndex == index;
                        return InkWell(
                          onTap: () {
                            setState(() {
                              _surface = _PickerSurface.emoji;
                              _categoryIndex = index;
                            });
                          },
                          child: SizedBox(
                            width: 44,
                            child: Column(
                              mainAxisAlignment: MainAxisAlignment.end,
                              children: [
                                Icon(
                                  cat.icon,
                                  size: 22,
                                  color: selected ? HasimTokens.inkColor(context) : muted,
                                ),
                                const SizedBox(height: 6),
                                Container(
                                  height: 3,
                                  width: 28,
                                  decoration: BoxDecoration(
                                    color: selected ? HasimTokens.inkColor(context) : Colors.transparent,
                                    borderRadius: BorderRadius.circular(2),
                                  ),
                                ),
                              ],
                            ),
                          ),
                        );
                      },
                    ),
                  ),
                  Divider(height: 1, color: line),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(12, 10, 12, 6),
                    child: TextField(
                      controller: _search,
                      onChanged: (_) => setState(() {}),
                      decoration: InputDecoration(
                        hintText: 'ابحث عن رمز تعبيري',
                        hintStyle: TextStyle(color: muted, fontSize: 14),
                        prefixIcon: Icon(Icons.search_rounded, color: muted, size: 22),
                        isDense: true,
                        contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                        border: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(24),
                          borderSide: BorderSide(color: line),
                        ),
                        enabledBorder: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(24),
                          borderSide: BorderSide(color: line),
                        ),
                        focusedBorder: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(24),
                          borderSide: BorderSide(color: HasimTokens.chatGreen.withValues(alpha: 0.6)),
                        ),
                        filled: true,
                        fillColor: Colors.white,
                      ),
                    ),
                  ),
                  if (_surface == _PickerSurface.emoji && _search.text.trim().isEmpty)
                    Padding(
                      padding: const EdgeInsets.fromLTRB(16, 0, 16, 6),
                      child: Align(
                        alignment: Alignment.centerRight,
                        child: Text(
                          _categories[_categoryIndex].titleAr,
                          style: TextStyle(color: muted, fontSize: 13, fontWeight: FontWeight.w600),
                        ),
                      ),
                    ),
                  Expanded(child: _buildBody(context, muted)),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(16, 6, 16, 12),
                    child: _BottomModeBar(
                      surface: _surface,
                      onPick: (s) => setState(() => _surface = s),
                    ),
                  ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildBody(BuildContext context, Color muted) {
    if (_surface != _PickerSurface.emoji) {
      final label = _surface == _PickerSurface.gif ? 'GIF' : 'الملصقات';
      return Center(
        child: Text(
          '$label — قريباً',
          style: TextStyle(color: muted, fontWeight: FontWeight.w600, fontSize: 15),
        ),
      );
    }

    final emojis = _visibleEmojis;
    if (emojis.isEmpty) {
      return Center(
        child: Text(
          'لا توجد نتائج',
          style: TextStyle(color: muted, fontWeight: FontWeight.w500),
        ),
      );
    }

    return GridView.builder(
      padding: const EdgeInsets.symmetric(horizontal: 8),
      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 8,
        mainAxisSpacing: 2,
        crossAxisSpacing: 2,
      ),
      itemCount: emojis.length,
      itemBuilder: (context, index) {
        final emoji = emojis[index];
        return InkWell(
          onTap: () => widget.onEmojiSelected(emoji),
          borderRadius: BorderRadius.circular(8),
          child: Center(
            child: Text(emoji, style: const TextStyle(fontSize: 26, height: 1.1)),
          ),
        );
      },
    );
  }
}

class _BottomModeBar extends StatelessWidget {
  const _BottomModeBar({required this.surface, required this.onPick});

  final _PickerSurface surface;
  final ValueChanged<_PickerSurface> onPick;

  @override
  Widget build(BuildContext context) {
    final line = HasimTokens.lineColor(context);
    return DecoratedBox(
      decoration: BoxDecoration(
        border: Border.all(color: line),
        borderRadius: BorderRadius.circular(28),
      ),
      child: Row(
        children: [
          _modeChip(
            context,
            label: null,
            icon: Icons.emoji_emotions_outlined,
            selected: surface == _PickerSurface.emoji,
            onTap: () => onPick(_PickerSurface.emoji),
          ),
          _divider(line),
          _modeChip(
            context,
            label: 'GIF',
            icon: null,
            selected: surface == _PickerSurface.gif,
            onTap: () => onPick(_PickerSurface.gif),
          ),
          _divider(line),
          _modeChip(
            context,
            label: null,
            icon: Icons.sticky_note_2_outlined,
            selected: surface == _PickerSurface.sticker,
            onTap: () => onPick(_PickerSurface.sticker),
          ),
        ],
      ),
    );
  }

  Widget _divider(Color line) => Container(width: 1, height: 36, color: line);

  Widget _modeChip(
    BuildContext context, {
    required String? label,
    required IconData? icon,
    required bool selected,
    required VoidCallback onTap,
  }) {
    final fg = selected ? HasimTokens.inkColor(context) : HasimTokens.mutedColor(context);
    return Expanded(
      child: Material(
        color: selected ? HasimTokens.chatGreen.withValues(alpha: 0.12) : Colors.transparent,
        child: InkWell(
          onTap: onTap,
          child: SizedBox(
            height: 40,
            child: Center(
              child: label != null
                  ? Text(label, style: TextStyle(fontWeight: FontWeight.w800, fontSize: 14, color: fg))
                  : Icon(icon, size: 22, color: fg),
            ),
          ),
        ),
      ),
    );
  }
}
