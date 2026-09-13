<?php

namespace Tests\Unit\Finance;

use App\Support\Finance\InvoicePresentation;
use PHPUnit\Framework\TestCase;

class InvoicePresentationTest extends TestCase
{
    public function test_maps_operator_lifecycle_without_changing_stored_statuses(): void
    {
        $this->assertSame('draft', InvoicePresentation::fromStatuses('draft', 'unpaid'));
        $this->assertSame('sent', InvoicePresentation::fromStatuses('issued', 'unpaid'));
        $this->assertSame('partial', InvoicePresentation::fromStatuses('issued', 'partial'));
        $this->assertSame('paid', InvoicePresentation::fromStatuses('issued', 'paid'));
        $this->assertSame('overdue', InvoicePresentation::fromStatuses('issued', 'overdue'));
        $this->assertSame('cancelled', InvoicePresentation::fromStatuses('cancelled', 'unpaid'));
        $this->assertSame('مرسلة', InvoicePresentation::label('sent'));
    }
}
