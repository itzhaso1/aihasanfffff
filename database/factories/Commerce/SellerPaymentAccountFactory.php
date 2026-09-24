<?php

namespace Database\Factories\Commerce;

use App\Enums\Commerce\CommerceSellerPaymentAccountStatus;
use App\Models\Commerce\Seller;
use App\Models\Commerce\SellerPaymentAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SellerPaymentAccount> */
class SellerPaymentAccountFactory extends Factory
{
    protected $model = SellerPaymentAccount::class;

    public function definition(): array
    {
        return [
            'workspace_id' => 0,
            'commerce_seller_id' => Seller::factory(),
            'provider' => 'hyperpay',
            'provider_reference' => 'hp_ref_'.fake()->uuid(),
            'status' => CommerceSellerPaymentAccountStatus::Pending,
            'metadata' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (SellerPaymentAccount $account): void {
            if ((int) $account->workspace_id <= 0 && $account->commerce_seller_id) {
                $seller = Seller::withoutGlobalScopes()->find($account->commerce_seller_id);
                if ($seller) {
                    $account->workspace_id = $seller->workspace_id;
                }
            }
        });
    }
}
