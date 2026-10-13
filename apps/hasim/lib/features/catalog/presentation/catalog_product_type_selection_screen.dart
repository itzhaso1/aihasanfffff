import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';

class CatalogProductTypeSelectionScreen extends StatelessWidget {
  const CatalogProductTypeSelectionScreen({
    super.key,
    this.embedded = false,
    this.onSelected,
  });

  final bool embedded;
  final ValueChanged<bool>? onSelected;

  void _openForm(BuildContext context) {
    final callback = onSelected;
    if (callback != null) {
      callback(false);
      return;
    }
    context.push('/catalog/form');
  }

  @override
  Widget build(BuildContext context) {
    final body = Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              'أضف منتجاً كمرجع لمعلومات الذكاء الاصطناعي.',
              textAlign: TextAlign.center,
              style: TextStyle(
                color: HasimTokens.inkColor(context),
                height: 1.5,
              ),
            ),
            const SizedBox(height: 16),
            FilledButton(
              key: const Key('catalog-product-type-normal'),
              onPressed: () => _openForm(context),
              child: const Text('إضافة منتج'),
            ),
          ],
        ),
      ),
    );

    if (embedded) {
      return body;
    }

    return Scaffold(
      appBar: AppBar(title: const Text('المنتجات')),
      body: body,
    );
  }
}
