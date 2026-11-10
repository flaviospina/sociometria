<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Control\Institution;
use App\Models\Control\Invitation;
use App\Models\Control\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Convites de profissionais. O token é aleatório (256 bits), de uso único,
 * com validade limitada; somente o seu SHA-256 é persistido.
 */
class InvitationService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function invite(Institution $institution, string $email, Role $role, ?User $invitedBy = null): Invitation
    {
        $token = bin2hex(random_bytes(32));
        $email = strtolower(trim($email));

        // Um novo convite invalida os anteriores ainda pendentes para o mesmo e-mail.
        Invitation::query()->where('institution_id', $institution->id)->where('email', $email)
            ->whereNull('accepted_at')->update(['expires_at' => now()]);

        $invitation = (new Invitation)->forceFill([
            'institution_id' => $institution->id,
            'email' => $email,
            'role' => $role,
            'token_hash' => hash('sha256', $token),
            'invited_by' => $invitedBy?->id,
            'expires_at' => now()->addHours(config('tenancy.invitation_ttl_hours')),
        ]);
        $invitation->save();

        $url = url('/invitations/accept').'#token='.$token; // fragmento: não vai para logs de servidor
        Mail::raw(
            "Você foi convidado(a) para acessar a plataforma de Sociometria Escolar.\n\n".
            'Para criar seu acesso, abra o link abaixo (válido por '.config('tenancy.invitation_ttl_hours')." horas, uso único):\n{$url}\n\n".
            'Se você não esperava este convite, ignore esta mensagem.',
            fn ($message) => $message->to($email)->subject('Convite — Sociometria Escolar'),
        );

        $this->audit->record('invitation.created', 'success', $invitedBy, $institution, 'invitation', (string) $invitation->id, ['role' => $role->value]);

        return $invitation;
    }

    /**
     * @param  array{name:string, password:string}  $profile  usado apenas se a conta ainda não existir
     */
    public function accept(string $token, array $profile): Membership
    {
        return DB::connection('control')->transaction(function () use ($token, $profile) {
            $invitation = Invitation::query()->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();

            if ($invitation === null || $invitation->accepted_at !== null || $invitation->expires_at->isPast()
                || ! $invitation->institution->isActive()) {
                throw ValidationException::withMessages(['token' => 'Convite inválido ou expirado.']);
            }

            $user = User::query()->where('email', $invitation->email)->first();
            if ($user === null) {
                $user = new User(['name' => $profile['name'], 'email' => $invitation->email, 'password' => $profile['password']]);
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            $membership = Membership::query()->where('user_id', $user->id)
                ->where('institution_id', $invitation->institution_id)->first() ?? new Membership;
            $membership->forceFill([
                'user_id' => $user->id,
                'institution_id' => $invitation->institution_id,
                'role' => $invitation->role,
                'status' => 'active',
            ])->save();

            $invitation->forceFill(['accepted_at' => now()])->save();
            $this->audit->record('invitation.accepted', 'success', $user, $invitation->institution, 'invitation', (string) $invitation->id);

            return $membership;
        });
    }
}
