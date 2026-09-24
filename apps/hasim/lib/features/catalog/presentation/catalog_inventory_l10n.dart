import 'package:hasim/l10n/app_localizations.dart';

extension CatalogInventoryL10n on AppLocalizations {
  String inventoryPolicyLabel(String code) {
    return switch (code) {
      'not_tracked' => catalogInventoryPolicyNotTracked,
      'finite_stock' => catalogInventoryPolicyFiniteStock,
      'unlimited' => catalogInventoryPolicyUnlimited,
      'backorder' => catalogInventoryPolicyBackorder,
      'on_request' => catalogInventoryPolicyOnRequest,
      'digital_pool' => catalogInventoryPolicyDigitalPool,
      'file_asset' => catalogInventoryPolicyFileAsset,
      'service_capacity' => catalogInventoryPolicyServiceCapacity,
      _ => code,
    };
  }
}
