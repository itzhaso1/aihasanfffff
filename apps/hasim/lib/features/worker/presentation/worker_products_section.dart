import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:hasim/core/config/app_config.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/features/worker/data/worker_product.dart';
import 'package:hasim/features/worker/presentation/worker_account_widgets.dart';
import 'package:hasim/features/worker/providers/worker_products_controller.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

class WorkerProductsSection extends ConsumerWidget {
  const WorkerProductsSection({super.key, required this.engagementId});

  final int engagementId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(workerProductsProvider(engagementId));

    return WorkerSectionCard(
      key: const Key('worker-products-section'),
      title: 'المنتجات',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            'المنتجات المحددة لهذا العمل',
            style: Theme.of(
              context,
            ).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w800),
          ),
          const SizedBox(height: 4),
          Text(
            'الأسعار المتاحة لك ضمن هذا الارتباط.',
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
              color: HasimTokens.mutedColor(context),
              height: 1.45,
            ),
          ),
          const SizedBox(height: 12),
          if (state.loading && state.items.isEmpty)
            const _WorkerProductsSkeleton()
          else if (state.permissionDenied)
            const _WorkerProductsMessage(
              icon: Icons.lock_outline_rounded,
              message: 'لا تملك صلاحية عرض المنتجات',
            )
          else if (state.error != null && state.items.isEmpty)
            _WorkerProductsError(
              message: state.error!,
              onRetry: () => ref
                  .read(workerProductsProvider(engagementId).notifier)
                  .load(),
            )
          else if (state.items.isEmpty)
            const _WorkerProductsMessage(
              icon: Icons.inventory_2_outlined,
              message: 'لا توجد منتجات محددة لهذا العمل حاليًا',
            )
          else
            for (final product in state.items) ...[
              _WorkerProductCard(product: product),
              if (product != state.items.last) const SizedBox(height: 10),
            ],
        ],
      ),
    );
  }
}

class _WorkerProductCard extends StatelessWidget {
  const _WorkerProductCard({required this.product});

  final WorkerProduct product;

  @override
  Widget build(BuildContext context) {
    final imageUrl = _resolveImageUrl(product.imageUrl);
    return DecoratedBox(
      decoration: BoxDecoration(
        color: HasimTokens.pageBg(context),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: HasimTokens.lineColor(context)),
      ),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _WorkerProductImage(name: product.name, url: imageUrl),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    product.name,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.titleSmall?.copyWith(
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                  if (product.categoryName != null) ...[
                    const SizedBox(height: 3),
                    Text(
                      product.categoryName!,
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: HasimTokens.mutedColor(context),
                      ),
                    ),
                  ],
                  if (product.description != null) ...[
                    const SizedBox(height: 6),
                    Text(
                      product.description!,
                      maxLines: 3,
                      overflow: TextOverflow.ellipsis,
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: HasimTokens.mutedColor(context),
                        height: 1.4,
                      ),
                    ),
                  ],
                  const SizedBox(height: 10),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      _WorkerPricePill(
                        label: 'السعر الأساسي',
                        value: _price(product.price, product.currency),
                        highlighted: true,
                      ),
                      _WorkerPricePill(
                        label: 'الحد الأدنى لهذا العمل',
                        value: _price(
                          product.minimumSellingPrice,
                          product.currency,
                        ),
                      ),
                      _WorkerPricePill(
                        label: 'الحد الأعلى لهذا العمل',
                        value: _price(
                          product.maximumSellingPrice,
                          product.currency,
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _WorkerProductImage extends StatelessWidget {
  const _WorkerProductImage({required this.name, required this.url});

  final String name;
  final String? url;

  @override
  Widget build(BuildContext context) {
    final fallback = DecoratedBox(
      decoration: BoxDecoration(
        color: HasimTokens.mintFill(context),
        borderRadius: BorderRadius.circular(14),
      ),
      child: Icon(Icons.inventory_2_outlined, color: HasimTokens.primary),
    );
    if (url == null) {
      return SizedBox(width: 64, height: 64, child: fallback);
    }
    return ClipRRect(
      borderRadius: BorderRadius.circular(14),
      child: CachedNetworkImage(
        imageUrl: url!,
        width: 64,
        height: 64,
        fit: BoxFit.cover,
        errorWidget: (_, _, _) => fallback,
      ),
    );
  }
}

class _WorkerPricePill extends StatelessWidget {
  const _WorkerPricePill({
    required this.label,
    required this.value,
    this.highlighted = false,
  });

  final String label;
  final String value;
  final bool highlighted;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: highlighted
            ? HasimTokens.mintFill(context)
            : HasimTokens.cardBg(context),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: HasimTokens.lineColor(context)),
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 7),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              label,
              style: Theme.of(context).textTheme.labelSmall?.copyWith(
                color: HasimTokens.mutedColor(context),
              ),
            ),
            const SizedBox(height: 2),
            Text(
              value,
              textDirection: TextDirection.ltr,
              style: Theme.of(context).textTheme.labelMedium?.copyWith(
                fontWeight: FontWeight.w800,
                color: highlighted
                    ? HasimTokens.accentDark
                    : HasimTokens.inkColor(context),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _WorkerProductsMessage extends StatelessWidget {
  const _WorkerProductsMessage({required this.icon, required this.message});

  final IconData icon;
  final String message;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 18),
      child: Column(
        children: [
          Icon(icon, size: 32, color: HasimTokens.mutedColor(context)),
          const SizedBox(height: 8),
          Text(message, textAlign: TextAlign.center),
        ],
      ),
    );
  }
}

class _WorkerProductsError extends StatelessWidget {
  const _WorkerProductsError({required this.message, required this.onRetry});

  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          message,
          style: TextStyle(color: Theme.of(context).colorScheme.error),
        ),
        TextButton(onPressed: onRetry, child: const Text('إعادة المحاولة')),
      ],
    );
  }
}

class _WorkerProductsSkeleton extends StatelessWidget {
  const _WorkerProductsSkeleton();

  @override
  Widget build(BuildContext context) {
    return Container(
      height: 105,
      decoration: BoxDecoration(
        color: HasimTokens.lineColor(context),
        borderRadius: BorderRadius.circular(16),
      ),
    );
  }
}

String _price(num? value, String? currency) {
  if (value == null) return 'غير محدد';
  return '${value.toStringAsFixed(2)} ${currency ?? ''}'.trim();
}

String? _resolveImageUrl(String? value) {
  final raw = value?.trim() ?? '';
  if (raw.isEmpty) return null;
  if (raw.startsWith('http://') || raw.startsWith('https://')) return raw;
  final path = raw.startsWith('/') ? raw : '/$raw';
  return '${AppConfig.hostBase}$path';
}
