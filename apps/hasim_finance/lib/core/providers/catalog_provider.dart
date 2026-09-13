import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim_finance/core/auth/auth_controller.dart';
import 'package:hasim_finance/core/models/models.dart';

final financeCatalogProvider = FutureProvider<FinanceCatalog>((ref) async {
  ref.watch(authControllerProvider.select((state) => state.workspace?.id));
  final raw = await ref.watch(financeApiProvider).bootstrap();
  return FinanceCatalog.fromBootstrap(raw);
});
