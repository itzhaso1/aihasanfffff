import 'package:hasim/l10n/app_localizations.dart';

extension CatalogInventoryL10n on AppLocalizations {
  String inventoryPolicyLabel(String code) {
    return switch (code) {
      'not_tracked' => catalogInventoryPolicyNotTracked,
      'finite_stock' || 'backorder' => catalogInventoryPolicyFiniteStock,
      'unlimited' => catalogInventoryPolicyUnlimited,
      'on_request' => catalogInventoryPolicyOnRequest,
      'digital_pool' => catalogInventoryPolicyDigitalPool,
      'file_asset' => catalogInventoryPolicyFileAsset,
      'service_capacity' => catalogInventoryPolicyServiceCapacity,
      _ => code,
    };
  }

  String? inventoryPolicyDescription(String code) {
    return switch (code) {
      'not_tracked' => catalogInventoryPolicyNotTrackedHint,
      'finite_stock' => catalogInventoryPolicyFiniteStockHint,
      'unlimited' => catalogInventoryPolicyUnlimitedHint,
      'on_request' => catalogInventoryPolicyOnRequestHint,
      'digital_pool' => catalogInventoryPolicyDigitalPoolHint,
      'file_asset' => catalogInventoryPolicyFileAssetHint,
      _ => null,
    };
  }
}
