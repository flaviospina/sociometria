<?php

namespace App\Tenancy;

use App\Tenancy\Exceptions\NoTenantContextException;
use Illuminate\Encryption\Encrypter;

/**
 * Criptografia de campos com a chave de dados (DEK) da instituição ativa.
 *
 * Modelo "envelope": cada instituição tem uma DEK aleatória de 256 bits,
 * guardada no banco de controle cifrada pela APP_KEY. Um texto cifrado de
 * uma instituição não pode ser decifrado no contexto de outra.
 * Algoritmo: AES-256-GCM (implementação do Laravel/OpenSSL).
 */
class TenantEncrypter
{
    public const CIPHER = 'aes-256-gcm';

    private ?Encrypter $encrypter = null;

    public static function generateKey(): string
    {
        return base64_encode(Encrypter::generateKey(self::CIPHER));
    }

    public function useKey(string $base64Key): void
    {
        $this->encrypter = new Encrypter(base64_decode($base64Key, true), self::CIPHER);
    }

    public function forgetKey(): void
    {
        $this->encrypter = null;
    }

    public function encrypt(string $value): string
    {
        return $this->current()->encryptString($value);
    }

    public function decrypt(string $payload): string
    {
        return $this->current()->decryptString($payload);
    }

    private function current(): Encrypter
    {
        return $this->encrypter ?? throw new NoTenantContextException('Nenhuma chave institucional carregada.');
    }
}
