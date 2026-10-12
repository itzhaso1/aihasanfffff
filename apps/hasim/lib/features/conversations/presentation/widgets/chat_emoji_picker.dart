import 'package:flutter/material.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
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
    this.docked = false,
    this.onClose,
  });

  static const double panelHeight = 352;
  static const double dockedHeight = 300;
  static const double panelMaxWidth = 420;

  final ValueChanged<String> onEmojiSelected;
  final bool docked;
  final VoidCallback? onClose;

  @override
  State<ChatEmojiPickerPanel> createState() => _ChatEmojiPickerPanelState();
}

class _ChatEmojiPickerPanelState extends State<ChatEmojiPickerPanel> {
  int _categoryIndex = 0;
  _PickerSurface _surface = _PickerSurface.emoji;
  final _search = TextEditingController();
  bool _searching = false;

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

  Widget _buildDocked(BuildContext context) {
    final muted = HasimTokens.mutedColor(context);
    final ink = HasimTokens.inkColor(context);
    return Material(
      color: HasimTokens.cardBg(context),
      child: Directionality(
        textDirection: TextDirection.rtl,
        child: Column(
          children: [
            SizedBox(
              height: 46,
              child: Row(
                children: [
                  IconButton(
                    onPressed: widget.onClose,
                    icon: Icon(Icons.close, color: ink, size: 22),
                  ),
                  _DockTab(
                    selected: _surface == _PickerSurface.sticker,
                    onTap: () => setState(() => _surface = _PickerSurface.sticker),
                    child: Icon(Icons.sticky_note_2_outlined, size: 22, color: _surface == _PickerSurface.sticker ? ink : muted),
                  ),
                  _DockTab(
                    selected: _surface == _PickerSurface.gif,
                    onTap: () => setState(() => _surface = _PickerSurface.gif),
                    child: Text(
                      'GIF',
                      style: TextStyle(
                        fontWeight: FontWeight.w800,
                        fontSize: 15,
                        color: _surface == _PickerSurface.gif ? ink : muted,
                      ),
                    ),
                  ),
                  _DockTab(
                    selected: _surface == _PickerSurface.emoji,
                    onTap: () => setState(() {
                      _surface = _PickerSurface.emoji;
                      _searching = false;
                    }),
                    child: Icon(Icons.emoji_emotions_outlined, size: 22, color: _surface == _PickerSurface.emoji ? ink : muted),
                  ),
                  const Spacer(),
                  IconButton(
                    onPressed: () => setState(() => _searching = !_searching),
                    icon: Icon(Icons.search, color: _searching ? ink : muted, size: 22),
                  ),
                ],
              ),
            ),
            if (_searching)
              Padding(
                padding: const EdgeInsets.fromLTRB(12, 0, 12, 6),
                child: TextField(
                  controller: _search,
                  autofocus: true,
                  onChanged: (_) => setState(() {}),
                  decoration: InputDecoration(
                    hintText: 'ابحث عن رمز تعبيري',
                    isDense: true,
                    prefixIcon: const Icon(Icons.search, size: 20),
                    border: OutlineInputBorder(borderRadius: BorderRadius.circular(20)),
                  ),
                ),
              )
            else if (_surface == _PickerSurface.emoji)
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 4),
                child: Align(
                  alignment: Alignment.centerRight,
                  child: Text(
                    _categories[_categoryIndex].titleAr,
                    style: TextStyle(color: muted, fontSize: 13, fontWeight: FontWeight.w600),
                  ),
                ),
              ),
            Expanded(child: _buildBody(context, muted)),
            SizedBox(
              height: 48,
              child: ListView.builder(
                scrollDirection: Axis.horizontal,
                itemCount: _categories.length,
                itemBuilder: (context, index) {
                  final cat = _categories[index];
                  final selected = _surface == _PickerSurface.emoji && _categoryIndex == index;
                  return InkWell(
                    onTap: () => setState(() {
                      _surface = _PickerSurface.emoji;
                      _categoryIndex = index;
                      _searching = false;
                      _search.clear();
                    }),
                    child: SizedBox(
                      width: 46,
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.end,
                        children: [
                          Icon(cat.icon, size: 22, color: selected ? ink : muted),
                          const SizedBox(height: 6),
                          Container(
                            height: 3,
                            width: 26,
                            decoration: BoxDecoration(
                              color: selected ? ink : Colors.transparent,
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
            SizedBox(height: MediaQuery.paddingOf(context).bottom),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (widget.docked) return _buildDocked(context);
    final line = HasimTokens.lineColor(context);
    final muted = HasimTokens.mutedColor(context);

    return Material(
      color: HasimTokens.cardBg(context),
      elevation: 8,
      shadowColor: Colors.black.withValues(alpha: 0.18),
      borderRadius: BorderRadius.circular(14),
      clipBehavior: Clip.antiAlias,
      child: SizedBox.expand(
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

    return LayoutBuilder(
      builder: (context, constraints) {
        return GridView.builder(
          padding: const EdgeInsets.symmetric(horizontal: 8),
          gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
            crossAxisCount: HasimLayout.emojiGridCount(constraints.maxWidth),
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
                child: Text(
                  emoji,
                  style: const TextStyle(fontSize: 26, height: 1.1),
                ),
              ),
            );
          },
        );
      },
    );
  }
}

class _DockTab extends StatelessWidget {
  const _DockTab({required this.selected, required this.onTap, required this.child});

  final bool selected;
  final VoidCallback onTap;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: selected ? const Color(0xFFE7F6F3) : Colors.transparent,
      borderRadius: BorderRadius.circular(10),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(10),
        child: SizedBox(width: 48, height: 36, child: Center(child: child)),
      ),
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
