import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';

class CatalogDigitalScreen extends ConsumerWidget {
  const CatalogDigitalScreen({super.key, this.embedded = false});

  final bool embedded;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final body = Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Text(
          'المخزون الرقمي وتسليم الملفات عبر الشات ملغيان. أدر المنتجات كمرجع لمعلومات الذكاء الاصطناعي.',
          key: const Key('digital-inventory-retired'),
          textAlign: TextAlign.center,
          style: TextStyle(
            color: HasimTokens.inkColor(context),
            height: 1.5,
          ),
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
