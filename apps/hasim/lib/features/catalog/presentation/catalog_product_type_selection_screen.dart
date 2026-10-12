import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';

class CatalogProductTypeSelectionScreen extends StatelessWidget {
  const CatalogProductTypeSelectionScreen({
    super.key,
    this.embedded = false,
    this.onSelected,
  });

  final bool embedded;
  final ValueChanged<bool>? onSelected;

  void _select(BuildContext context, bool digital) {
    final callback = onSelected;
    if (callback != null) {
      callback(digital);
      return;
    }
    context.push('/catalog/form?digital=${digital ? 1 : 0}');
  }

  @override
  Widget build(BuildContext context) {
    final page = HasimTokens.pageBg(context);
    final compact = HasimLayout.isCompact(context);
    final content = SafeArea(
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(16, 20, 16, 24),
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 720),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  'اختر نوع المنتج',
                  textAlign: TextAlign.end,
                  style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                    color: HasimTokens.inkColor(context),
                    fontWeight: FontWeight.w800,
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  'حدد نوع المنتج أولاً لعرض الحقول المناسبة فقط.',
                  textAlign: TextAlign.end,
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    color: HasimTokens.mutedColor(context),
                  ),
                ),
                const SizedBox(height: 20),
                _ProductTypeCard(
                  key: const Key('catalog-product-type-normal'),
                  icon: Icons.shopping_bag_outlined,
                  title: 'منتج عادي',
                  description:
                      'منتج عادي أو مادي يُدار بالمخزون والنسخ عند الحاجة.',
                  onTap: () => _select(context, false),
                ),
                const SizedBox(height: 12),
                _ProductTypeCard(
                  key: const Key('catalog-product-type-digital'),
                  icon: Icons.cloud_outlined,
                  title: 'منتج رقمي',
                  description:
                      'منتج يعتمد على الأكواد أو التراخيص أو ملف رقمي.',
                  onTap: () => _select(context, true),
                ),
              ],
            ),
          ),
        ),
      ),
    );

    return Scaffold(
      backgroundColor: embedded ? Colors.transparent : page,
      appBar: embedded
          ? null
          : AppBar(
              title: const Text('اختر نوع المنتج'),
              backgroundColor: page,
              surfaceTintColor: Colors.transparent,
            ),
      body: compact ? _mobileContent(context) : content,
    );
  }

  Widget _mobileContent(BuildContext context) {
    final narrow = MediaQuery.sizeOf(context).width < 380;
    return SafeArea(
      child: SingleChildScrollView(
        padding: EdgeInsets.fromLTRB(
          narrow ? 8 : 12,
          narrow ? 14 : 20,
          narrow ? 8 : 12,
          narrow ? 16 : 24,
        ),
        child: Material(
          color: HasimTokens.cardBg(context),
          borderRadius: BorderRadius.circular(narrow ? 22 : 28),
          child: Padding(
            padding: EdgeInsets.fromLTRB(
              narrow ? 10 : 16,
              narrow ? 16 : 24,
              narrow ? 10 : 16,
              narrow ? 20 : 28,
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  textDirection: TextDirection.rtl,
                  children: [
                    Container(
                      width: narrow ? 42 : 48,
                      height: narrow ? 42 : 48,
                      alignment: Alignment.center,
                      decoration: BoxDecoration(
                        color: HasimTokens.mintFill(context),
                        borderRadius: BorderRadius.circular(narrow ? 12 : 14),
                      ),
                      child: Icon(
                        Icons.category_outlined,
                        color: HasimTokens.chatGreen,
                        size: narrow ? 22 : 25,
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.end,
                        children: [
                          Text(
                            'اختر نوع المنتج',
                            textAlign: TextAlign.end,
                            style: Theme.of(context).textTheme.headlineSmall
                                ?.copyWith(
                                  color: HasimTokens.inkColor(context),
                                  fontWeight: FontWeight.w800,
                                  fontSize: narrow ? 21 : null,
                                ),
                          ),
                          const SizedBox(height: 6),
                          Text(
                            'حدد نوع المنتج أولاً لعرض الحقول المناسبة فقط.',
                            textAlign: TextAlign.end,
                            style: Theme.of(context).textTheme.bodyMedium
                                ?.copyWith(
                                  color: HasimTokens.mutedColor(context),
                                  height: 1.35,
                                  fontSize: narrow ? 12 : null,
                                ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
                SizedBox(height: narrow ? 16 : 22),
                _ProductTypeCard(
                  key: const Key('catalog-product-type-normal'),
                  icon: Icons.shopping_bag_outlined,
                  title: 'منتج عادي',
                  description:
                      'منتج عادي أو مادي يُدار بالمخزون والنسخ عند الحاجة.',
                  mobile: true,
                  onTap: () => _select(context, false),
                ),
                SizedBox(height: narrow ? 10 : 14),
                _ProductTypeCard(
                  key: const Key('catalog-product-type-digital'),
                  icon: Icons.cloud_outlined,
                  title: 'منتج رقمي',
                  description:
                      'منتج يعتمد على الأكواد أو التراخيص أو ملف رقمي.',
                  mobile: true,
                  onTap: () => _select(context, true),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _ProductTypeCard extends StatelessWidget {
  const _ProductTypeCard({
    super.key,
    required this.icon,
    required this.title,
    required this.description,
    required this.onTap,
    this.mobile = false,
  });

  final IconData icon;
  final String title;
  final String description;
  final VoidCallback onTap;
  final bool mobile;

  @override
  Widget build(BuildContext context) {
    final iconColor = HasimTokens.mutedColor(context);
    if (mobile) {
      final narrow = MediaQuery.sizeOf(context).width < 380;
      return Material(
        color: HasimTokens.cardBg(context),
        borderRadius: BorderRadius.circular(narrow ? 18 : 22),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(narrow ? 18 : 22),
          child: Ink(
            height: narrow ? 88 : 100,
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(narrow ? 18 : 22),
              border: Border.all(color: HasimTokens.lineColor(context)),
              boxShadow: HasimTokens.softShadow(context),
            ),
            child: Padding(
              padding: EdgeInsets.symmetric(
                horizontal: narrow ? 12 : 16,
                vertical: narrow ? 10 : 13,
              ),
              child: Row(
                textDirection: TextDirection.rtl,
                children: [
                  Container(
                    width: narrow ? 46 : 52,
                    height: narrow ? 46 : 52,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      color: HasimTokens.mintFill(context),
                      borderRadius: BorderRadius.circular(narrow ? 14 : 16),
                    ),
                    child: Icon(
                      icon,
                      color: HasimTokens.chatGreen,
                      size: narrow ? 24 : 27,
                    ),
                  ),
                  SizedBox(width: narrow ? 10 : 12),
                  Expanded(
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      crossAxisAlignment: CrossAxisAlignment.end,
                      children: [
                        Text(
                          title,
                          textAlign: TextAlign.end,
                          style: Theme.of(context).textTheme.titleLarge
                              ?.copyWith(
                                color: HasimTokens.inkColor(context),
                                fontWeight: FontWeight.w800,
                                fontSize: narrow ? 17 : 19,
                              ),
                        ),
                        SizedBox(height: narrow ? 3 : 5),
                        Text(
                          description,
                          textAlign: TextAlign.end,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: Theme.of(context).textTheme.bodyMedium
                              ?.copyWith(
                                color: HasimTokens.mutedColor(context),
                                height: 1.3,
                                fontSize: 12,
                              ),
                        ),
                      ],
                    ),
                  ),
                  SizedBox(width: narrow ? 8 : 10),
                  Container(
                    width: narrow ? 34 : 38,
                    height: narrow ? 34 : 38,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      color: HasimTokens.mutedColor(
                        context,
                      ).withValues(alpha: 0.08),
                      shape: BoxShape.circle,
                    ),
                    child: Icon(
                      Icons.chevron_right_rounded,
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
      color: HasimTokens.cardBg(context),
      borderRadius: BorderRadius.circular(14),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(14),
        child: Ink(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(14),
            border: Border.all(color: HasimTokens.lineColor(context)),
          ),
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Row(
              children: [
                Icon(
                  Icons.arrow_back_ios_new_rounded,
                  size: 18,
                  color: iconColor,
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: [
                      Text(
                        title,
                        textAlign: TextAlign.end,
                        style: Theme.of(context).textTheme.titleMedium
                            ?.copyWith(
                              color: HasimTokens.inkColor(context),
                              fontWeight: FontWeight.w800,
                            ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        description,
                        textAlign: TextAlign.end,
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: HasimTokens.mutedColor(context),
                          height: 1.4,
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 14),
                Container(
                  width: 44,
                  height: 44,
                  alignment: Alignment.center,
                  decoration: BoxDecoration(
                    color: iconColor.withValues(alpha: 0.08),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Icon(icon, color: iconColor),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
