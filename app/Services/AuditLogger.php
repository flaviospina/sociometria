<?php

namespace App\Services;

use App\Models\Control\AuditEvent;
use App\Models\Control\Institution;
use App\Models\User;
use App\Support\Correlation;
use Illuminate\Support\Facades\DB;

/**
 * Trilha de auditoria no banco de controle, encadeada por hash
 * (cada evento inclui o hash do anterior). Alterar ou remover um evento
 * quebra a cadeia e é detectado por verifyChain().
 *
 * `metadata` aceita apenas dados técnicos: nunca nomes de alunos,
 * respostas, senhas ou tokens.
 */
class AuditLogger
{
    public function record(
        string $action,
        string $outcome,
        ?User $actor = null,
        ?Institution $institution = null,
        ?string $resourceType = null,
        ?string $resourceId = null,
        array $metadata = [],
    ): AuditEvent {
        return DB::connection('control')->transaction(function () use ($action, $outcome, $actor, $institution, $resourceType, $resourceId, $metadata) {
            $prev = AuditEvent::query()->orderByDesc('id')->lockForUpdate()->value('hash');

            $fields = [
                'occurred_at' => now()->format('Y-m-d H:i:s.u'),
                'actor_user_id' => $actor?->id,
                'institution_id' => $institution?->id,
                'action' => $action,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'outcome' => $outcome,
                'origin' => self::minimizeIp(Correlation::ip()),
                'correlation_id' => Correlation::id(),
                'metadata' => $metadata === [] ? null : $metadata,
            ];

            $event = (new AuditEvent)->forceFill($fields + ['prev_hash' => $prev, 'hash' => self::hash($prev, $fields)]);
            $event->save();

            return $event;
        });
    }

    /** @return int|null id do primeiro evento inconsistente, ou null se a cadeia estiver íntegra */
    public function verifyChain(): ?int
    {
        $prev = null;
        foreach (DB::connection('control')->table('audit_events')->orderBy('id')->cursor() as $row) {
            $fields = [
                'occurred_at' => $row->occurred_at,
                'actor_user_id' => $row->actor_user_id,
                'institution_id' => $row->institution_id,
                'action' => $row->action,
                'resource_type' => $row->resource_type,
                'resource_id' => $row->resource_id,
                'outcome' => $row->outcome,
                'origin' => $row->origin,
                'correlation_id' => $row->correlation_id,
                'metadata' => $row->metadata === null ? null : json_decode($row->metadata, true),
            ];
            if ($row->prev_hash !== $prev || ! hash_equals($row->hash, self::hash($prev, $fields))) {
                return (int) $row->id;
            }
            $prev = $row->hash;
        }

        return null;
    }

    private static function hash(?string $prev, array $fields): string
    {
        if (is_array($fields['metadata'])) {
            ksort($fields['metadata']);
        }

        return hash('sha256', ($prev ?? '').'|'.json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Origem técnica minimizada: IPv4 /24, IPv6 /48. */
    public static function minimizeIp(?string $ip): ?string
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = inet_pton($ip);
        $keep = strlen($packed) === 4 ? 3 : 6;

        return inet_ntop(substr($packed, 0, $keep).str_repeat("\0", strlen($packed) - $keep));
    }
}
