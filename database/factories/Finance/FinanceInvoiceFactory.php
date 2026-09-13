<?php

namespace Database\Factories\Finance;

use App\Models\Finance\FinanceInvoice;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinanceInvoice>
 */
class FinanceInvoiceFactory extends Factory
{
    protected $model = FinanceInvoice::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'invoice_number' => 'INV-'.fake()->unique()->numerify('######'),
            'type' => 'sales',
            'status' => 'unpaid',
            'invoice_status' => 'issued',
            'payment_status' => 'unpaid',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'currency' => 'SAR',
            'subtotal' => 100,
            'discount' => 0,
            'taxable_amount' => 100,
            'tax_amount' => 15,
            'total' => 115,
            'amount_paid' => 0,
            'amount_due' => 115,
            'customer_name' => fake()->name(),
            'tax_profile_type' => 'standard',
            'tax_rate' => 15,
            'tax_document_subtype' => 'standard',
            'zatca_requirement' => 'not_required',
            'issued_at' => now(),
        ];
    }
}
