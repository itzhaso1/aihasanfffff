<?php

namespace Database\Factories\Commerce;

use App\Enums\Commerce\CommerceSellerStatus;
use App\Enums\Commerce\CommerceSellerType;
use App\Models\Commerce\Seller;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Seller> */
class SellerFactory extends Factory
{
    protected $model = Seller::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'workspace_id' => Workspace::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'display_name' => $name,
            'seller_type' => CommerceSellerType::WorkspaceMerchant,
            'status' => CommerceSellerStatus::Active,
            'metadata' => null,
        ];
    }
}
