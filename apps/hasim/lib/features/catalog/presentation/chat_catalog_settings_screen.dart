import 'package:flutter/material.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/mobile/hasim_mobile_scaffold.dart';
import 'package:hasim/features/catalog/presentation/catalog_categories_screen.dart';
import 'package:hasim/features/catalog/presentation/catalog_form_screen.dart';
import 'package:hasim/features/catalog/presentation/catalog_list_screen.dart';

class ChatCatalogSettingsScreen extends StatefulWidget {
  const ChatCatalogSettingsScreen({
    super.key,
    this.embedded = false,
    this.hideHeader = false,
  });

  final bool embedded;
  final bool hideHeader;

  @override
  State<ChatCatalogSettingsScreen> createState() =>
      _ChatCatalogSettingsScreenState();
}

class _ChatCatalogSettingsScreenState extends State<ChatCatalogSettingsScreen> {
  int _index = 0;
  bool _mobileMenuVisible = true;

  static const _items = <_CatalogEntry>[
    _CatalogEntry(
      Icons.add_box_outlined,
      'إضافة منتج',
      'مرجع لمعلومات الذكاء الاصطناعي',
    ),
    _CatalogEntry(Icons.folder_outlined, 'إضافة تصنيف', 'تصنيفات المنتجات'),
    _CatalogEntry(Icons.inventory_2_outlined, 'المنتجات', 'المنتجات المحفوظة'),
  ];

  void _select(int index) {
    final compact = HasimLayout.isCompact(context);
    if (compact && !widget.embedded) {
      setState(() {
        _index = index;
        _mobileMenuVisible = false;
      });
      return;
    }
    if (_index == index) {
      return;
    }
    setState(() {
      _index = index;
    });
  }

  void _handleMobileBack() {
    if (!_mobileMenuVisible) {
      setState(() {
        _mobileMenuVisible = true;
      });
      return;
    }
    Navigator.of(context).maybePop();
  }

  Widget _detail() {
    return switch (_index) {
      0 => CatalogFormScreen(
        key: const ValueKey('catalog-product-form'),
        embedded: true,
      ),
      1 => const CatalogCategoriesScreen(
        key: ValueKey('catalog-categories'),
        embedded: true,
      ),
      2 => const CatalogListScreen(
        key: ValueKey('catalog-products'),
        embedded: true,
      ),
      _ => const CatalogListScreen(
        key: ValueKey('catalog-products'),
        embedded: true,
      ),
    };
  }

  Widget _menu({required bool compact}) {
    final links = [
      for (var i = 0; i < _items.length; i++)
        _CatalogLink(
          icon: _items[i].icon,
          title: _items[i].title,
          subtitle: compact ? null : _items[i].subtitle,
          selected: _index == i,
          compact: compact,
          onTap: () => _select(i),
        ),
    ];
    if (compact) {
      final tabWidth = ((MediaQuery.sizeOf(context).width - 42) / 2)
          .clamp(132.0, 220.0)
          .toDouble();
      return SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.fromLTRB(12, 8, 12, 10),
        child: Row(
          children: [
            for (final link in links) ...[
              SizedBox(width: tabWidth, child: link),
              const SizedBox(width: 8),
            ],
          ],
        ),
      );
    }
    return ListView(
      padding: const EdgeInsets.fromLTRB(8, 12, 8, 24),
      children: links,
    );
  }

  @override
  Widget build(BuildContext context) {
    final line = HasimTokens.lineColor(context);
    final pageBg = HasimTokens.pageBg(context);
    final compact = HasimLayout.isCompact(context);
    final header = DecoratedBox(
      decoration: BoxDecoration(
        border: Border(
          bottom: BorderSide(color: HasimTokens.lineColor(context)),
        ),
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 8, 16, 12),
        child: Row(
          children: [
            if (!widget.embedded)
              TextButton.icon(
                onPressed: () => Navigator.of(context).maybePop(),
                icon: const Icon(Icons.arrow_back_rounded, size: 18),
                label: const Text('العودة'),
              ),
            const Spacer(),
            Flexible(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(
                    'إعدادات المنتجات',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.headlineMedium?.copyWith(
                      color: HasimTokens.inkColor(context),
                    ),
                  ),
                  Text(
                    'إدارة المنتجات والتصنيفات كمرجع للذكاء الاصطناعي',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: HasimTokens.mutedColor(context),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(width: 10),
            Container(
              width: 42,
              height: 42,
              alignment: Alignment.center,
              decoration: BoxDecoration(
                color: HasimTokens.mutedColor(context).withValues(alpha: 0.08),
                borderRadius: BorderRadius.circular(10),
              ),
              child: Icon(
                Icons.layers_outlined,
                color: HasimTokens.mutedColor(context),
              ),
            ),
          ],
        ),
      ),
    );

    final page = LayoutBuilder(
      builder: (context, constraints) {
        final split = constraints.maxWidth >= 860;
        final detail = ColoredBox(
          color: compact ? Colors.transparent : pageBg,
          child: _detail(),
        );
        final body = split
            ? Row(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  SizedBox(
                    width: constraints.maxWidth >= 1100 ? 240 : 200,
                    child: DecoratedBox(
                      decoration: BoxDecoration(
                        color: pageBg,
                        border: Border(left: BorderSide(color: line)),
                      ),
                      child: _menu(compact: false),
                    ),
                  ),
                  Expanded(child: detail),
                ],
              )
            : Column(
                children: [
                  if (_mobileMenuVisible)
                    DecoratedBox(
                      decoration: BoxDecoration(
                        border: Border(bottom: BorderSide(color: line)),
                      ),
                      child: _menu(compact: true),
                    ),
                  Expanded(child: detail),
                ],
              );
        final blobSize = constraints.maxWidth < 380 ? 240.0 : 300.0;
        final content = compact
            ? Stack(
                clipBehavior: Clip.hardEdge,
                children: [
                  Positioned(
                    top: -110,
                    left: -130,
                    child: _mobileBackdropBlob(
                      color: const Color(0xFFEAF8F8),
                      size: blobSize,
                    ),
                  ),
                  Positioned(
                    right: -150,
                    bottom: -150,
                    child: _mobileBackdropBlob(
                      color: const Color(0xFFE9F9F7),
                      size: blobSize + 20,
                    ),
                  ),
                  Positioned.fill(child: body),
                ],
              )
            : body;
        return Column(
          children: [
            if (!widget.hideHeader && !compact) header,
            Expanded(child: content),
          ],
        );
      },
    );

    if (!widget.embedded && compact) {
      return HasimMobileScaffold(
        title: 'إعدادات المنتجات',
        centerTitle: true,
        leading: Padding(
          padding: const EdgeInsets.all(8),
          child: IconButton(
            onPressed: _handleMobileBack,
            style: IconButton.styleFrom(
              backgroundColor: const Color(0xFFF1F7F7),
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(14),
              ),
            ),
            icon: const Icon(Icons.arrow_forward_rounded),
          ),
        ),
        body: page,
      );
    }

    return Scaffold(
      backgroundColor: widget.embedded ? Colors.transparent : pageBg,
      body: page,
    );
  }

  Widget _mobileBackdropBlob({required Color color, required double size}) {
    return IgnorePointer(
      child: Container(
        width: size,
        height: size,
        decoration: BoxDecoration(color: color, shape: BoxShape.circle),
      ),
    );
  }
}

class _CatalogEntry {
  const _CatalogEntry(this.icon, this.title, this.subtitle);

  final IconData icon;
  final String title;
  final String? subtitle;
}

class _CatalogLink extends StatelessWidget {
  const _CatalogLink({
    required this.icon,
    required this.title,
    required this.selected,
    required this.onTap,
    this.subtitle,
    this.compact = false,
  });

  final IconData icon;
  final String title;
  final String? subtitle;
  final bool selected;
  final bool compact;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    if (compact) {
      final narrow = MediaQuery.sizeOf(context).width < 380;
      return Material(
        color: selected
            ? HasimTokens.mintFill(context)
            : HasimTokens.cardBg(context),
        borderRadius: BorderRadius.circular(narrow ? 18 : 22),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(narrow ? 18 : 22),
          child: Ink(
            height: narrow ? 54 : 60,
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(narrow ? 18 : 22),
              border: Border.all(color: HasimTokens.lineColor(context)),
              boxShadow: HasimTokens.softShadow(context),
            ),
            child: Padding(
              padding: EdgeInsets.symmetric(horizontal: narrow ? 12 : 16),
              child: Row(
                textDirection: TextDirection.rtl,
                children: [
                  Expanded(
                    child: FittedBox(
                      fit: BoxFit.scaleDown,
                      alignment: AlignmentDirectional.centerEnd,
                      child: Text(
                        title,
                        maxLines: 1,
                        style: Theme.of(context).textTheme.titleMedium
                            ?.copyWith(
                              fontWeight: FontWeight.w800,
                              fontSize: narrow ? 13 : 14,
                              color: selected
                                  ? HasimTokens.accentDark
                                  : HasimTokens.inkColor(context),
                            ),
                      ),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Container(
                    width: narrow ? 32 : 36,
                    height: narrow ? 32 : 36,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      color: HasimTokens.mutedColor(
                        context,
                      ).withValues(alpha: 0.08),
                      borderRadius: BorderRadius.circular(narrow ? 10 : 12),
                    ),
                    child: Icon(
                      icon,
                      size: 20,
                      color: HasimTokens.mutedColor(context),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      );
    }

    return Material(
      color: selected ? HasimTokens.mintFill(context) : Colors.transparent,
      borderRadius: BorderRadius.circular(10),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(10),
        child: Padding(
          padding: compact
              ? const EdgeInsets.fromLTRB(10, 8, 10, 8)
              : const EdgeInsets.fromLTRB(10, 12, 10, 12),
          child: Row(
            mainAxisSize: compact ? MainAxisSize.min : MainAxisSize.max,
            children: [
              if (compact)
                ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 160),
                  child: Text(
                    title,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.titleSmall?.copyWith(
                      fontWeight: FontWeight.w600,
                      color: HasimTokens.inkColor(context),
                    ),
                  ),
                )
              else
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        title,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: Theme.of(context).textTheme.bodyLarge?.copyWith(
                          color: HasimTokens.inkColor(context),
                        ),
                      ),
                      if (subtitle != null) ...[
                        const SizedBox(height: 3),
                        Text(
                          subtitle!,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: Theme.of(context).textTheme.bodySmall
                              ?.copyWith(
                                color: HasimTokens.mutedColor(context),
                              ),
                        ),
                      ],
                    ],
                  ),
                ),
              const SizedBox(width: 8),
              Container(
                width: compact ? 28 : 36,
                height: compact ? 28 : 36,
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  color: HasimTokens.mutedColor(
                    context,
                  ).withValues(alpha: 0.08),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Icon(
                  icon,
                  size: compact ? 16 : 18,
                  color: HasimTokens.mutedColor(context),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
