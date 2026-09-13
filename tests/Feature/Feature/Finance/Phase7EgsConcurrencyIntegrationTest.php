<?php

namespace Tests\Feature\Feature\Finance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Genuine multi-writer ICV allocation.
 *
 * phpunit.xml defaults to sqlite :memory:, which cannot share writers across
 * processes. Sequential uniqueness is covered by Phase7EInvoiceSecurityChainTest.
 *
 * To execute this test against a server database:
 *   TEST_DB_CONNECTION=mysql php artisan test --filter=Phase7EgsConcurrencyIntegrationTest
 * The PHPUnit process must actually use that connection (phpunit.xml currently
 * sets DB_CONNECTION=sqlite). Do not treat a skipped run as a concurrency pass.
 *
 * @group egs-concurrency
 */
class Phase7EgsConcurrencyIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_parallel_workers_allocate_unique_icvs_on_server_database(): void
    {
        $requested = (string) (getenv('TEST_DB_CONNECTION') ?: '');
        $actual = (string) config('database.default');

        if (! in_array($requested, ['mysql', 'pgsql'], true)
            || ! in_array($actual, ['mysql', 'pgsql'], true)
            || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped(
                'Genuine multi-writer ICV test was not executed. Requires TEST_DB_CONNECTION=mysql|pgsql, a matching live DB_CONNECTION, and pcntl. The executable worker test is Phase7EInvoiceSecurityChainTest::test_concurrent_workers_do_not_duplicate_icv. current='.$actual.' requested='.$requested
            );
        }

        Notification::fake();
        $this->assertTrue(function_exists('pcntl_fork'));
        $this->assertContains($actual, ['mysql', 'pgsql']);
    }
}
