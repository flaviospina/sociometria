<?php

namespace Tests\Feature\Tenancy;

use App\Enums\InstitutionStatus;
use App\Models\Control\AuditEvent;
use App\Models\Control\Membership;
use App\Models\Tenant\School;
use App\Tenancy\Queue\TenantJob;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

class RecordSchoolNamesJob extends TenantJob
{
    public static array $seen = [];

    public function handle(TenantContext $context): void
    {
        self::$seen[] = [$context->institution()->public_id, School::query()->orderBy('name')->pluck('name')->all()];
    }
}

class TenantJobTest extends TenantTestCase
{
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        RecordSchoolNamesJob::$seen = [];
        $this->s = $this->scenario(['Alfa', 'Beta'], schools: 1, classes: 1, students: 1);
    }

    public function test_workers_do_not_mix_institutional_contexts(): void
    {
        $alfa = $this->s['Alfa']['institution'];
        $beta = $this->s['Beta']['institution'];

        foreach ([$alfa, $beta, $beta, $alfa, $beta] as $institution) {
            RecordSchoolNamesJob::dispatch($institution->public_id); // fila sync: executa no mesmo processo, como um worker
            $this->assertFalse(app(TenantContext::class)->active(), 'O contexto deve ser limpo após cada job.');
        }

        $this->assertSame([
            [$alfa->public_id, ['Escola Alfa 1']],
            [$beta->public_id, ['Escola Beta 1']],
            [$beta->public_id, ['Escola Beta 1']],
            [$alfa->public_id, ['Escola Alfa 1']],
            [$beta->public_id, ['Escola Beta 1']],
        ], RecordSchoolNamesJob::$seen);
    }

    public function test_job_for_suspended_or_unknown_institution_is_discarded(): void
    {
        $alfa = $this->s['Alfa']['institution'];
        $alfa->forceFill(['status' => InstitutionStatus::Suspended])->save();

        RecordSchoolNamesJob::dispatch($alfa->public_id);
        RecordSchoolNamesJob::dispatch('01HZZZZZZZZZZZZZZZZZZZZZZZ');

        $this->assertSame([], RecordSchoolNamesJob::$seen);
        $this->assertSame(2, AuditEvent::query()->where('action', 'job.context')->where('outcome', 'denied')->count());
    }

    public function test_job_on_behalf_of_user_requires_still_active_membership(): void
    {
        $alfa = $this->s['Alfa']['institution'];
        $betaAdmin = $this->s['Beta']['admin'];
        $alfaAdmin = $this->s['Alfa']['admin'];

        RecordSchoolNamesJob::dispatch($alfa->public_id, $betaAdmin->id); // ator sem vínculo com a Alfa
        $this->assertSame([], RecordSchoolNamesJob::$seen);

        Membership::query()->where('user_id', $alfaAdmin->id)->update(['status' => 'revoked']);
        RecordSchoolNamesJob::dispatch($alfa->public_id, $alfaAdmin->id); // vínculo revogado depois do enfileiramento
        $this->assertSame([], RecordSchoolNamesJob::$seen);
    }

    public function test_job_payload_is_encrypted(): void
    {
        config(['queue.default' => 'database']);
        $alfa = $this->s['Alfa']['institution'];
        RecordSchoolNamesJob::dispatch($alfa->public_id);

        $payload = DB::connection('control')->table('jobs')->value('payload');
        $this->assertNotNull($payload);
        $this->assertStringNotContainsString($alfa->public_id, $payload);
    }
}
