<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ProvisionsTenants;

/** Testes que provisionam bancos institucionais reais (MySQL/MariaDB). */
abstract class TenantTestCase extends TestCase
{
    use ProvisionsTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Trava de segurança: estes testes criam e removem bancos.
        $this->assertSame('soc_tt_', config('tenancy.database_prefix'));
        $this->assertSame('sociometria_control_test', config('database.connections.control.database'));
    }

    protected function tearDown(): void
    {
        $this->dropTestTenants();
        parent::tearDown();
    }
}
