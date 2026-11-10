<?php

namespace Tests\Feature;

use App\Models\Control\AuditEvent;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class AuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_hash_chain_detects_tampering_and_deletion(): void
    {
        $audit = app(AuditLogger::class);
        foreach (range(1, 5) as $i) {
            $audit->record('test.event', 'success', null, null, 'thing', (string) $i, ['n' => $i, 'a' => 'b']);
        }
        $this->assertNull($audit->verifyChain());

        $ids = AuditEvent::query()->orderBy('id')->pluck('id');
        DB::connection('control')->table('audit_events')->where('id', $ids[2])->update(['outcome' => 'denied']);
        $this->assertSame($ids[2], $audit->verifyChain());

        DB::connection('control')->table('audit_events')->where('id', $ids[2])->update(['outcome' => 'success']);
        $this->assertNull($audit->verifyChain());

        DB::connection('control')->table('audit_events')->where('id', $ids[1])->delete();
        $this->assertSame($ids[2], $audit->verifyChain());
    }

    public function test_audit_events_are_immutable_through_the_application(): void
    {
        $event = app(AuditLogger::class)->record('test.event', 'success');

        try {
            $event->forceFill(['outcome' => 'denied'])->save();
            $this->fail('Atualização deveria ser bloqueada.');
        } catch (LogicException) {
        }
        $this->expectException(LogicException::class);
        $event->delete();
    }

    public function test_origin_address_is_minimized(): void
    {
        $this->assertSame('203.0.113.0', AuditLogger::minimizeIp('203.0.113.77'));
        $this->assertSame('2001:db8:abcd::', AuditLogger::minimizeIp('2001:db8:abcd:12:34:56:78:9a'));
        $this->assertNull(AuditLogger::minimizeIp('não é ip'));
    }
}
