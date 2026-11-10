# Gestão de segredos

Quais segredos existem, onde ficam, o que protege cada um e onde a proteção acaba. **Não há KMS nem gerenciador externo de segredos**: a raiz de confiança é a `APP_KEY` no arquivo `.env` da própria VPS.

## Estado

### Implementado e testado

| Item | Código | Evidência |
|---|---|---|
| Senha de banco do tenant e DEK cifradas em repouso e fora de JSON/arrays | `Institution` (casts `encrypted`, `$hidden`) | `ProvisioningTest::test_credentials_and_data_key_are_encrypted_at_rest_and_hidden` |
| DEK aleatória de 256 bits por instituição; campos de aluno em AES-256-GCM | `TenantEncrypter`, `TenantEncrypted` | `ContextSwitchingTest::test_student_identifiers_are_encrypted_at_rest_with_per_institution_key`, `ResolverAndQuotaTest::test_student_created_over_http_is_stored_encrypted_and_never_in_audit_log` |
| DEK e credencial do tenant só em memória durante o contexto | `TenantContext::leave()`, `TenantEncrypter::forgetKey()` | `ContextSwitchingTest::test_tenant_connection_is_unusable_after_leaving_context`, `test_cache_is_namespaced_per_institution` (exceção sem contexto) |
| Segredo TOTP cifrado, oculto e não atribuível em massa; senha com hash | `User` (casts `encrypted`/`hashed`, `$hidden`, `$fillable`) | `LoginTest::test_privileged_attributes_are_not_mass_assignable_and_secrets_are_hidden` |
| Token de convite: só o SHA-256 é persistido; uso único | `InvitationService` | `ResolverAndQuotaTest::test_onboarding_through_admin_api_and_invitation` |
| Payload de jobs cifrado | `TenantJob` (`ShouldBeEncrypted`) | `TenantJobTest::test_job_payload_is_encrypted` |
| Auditoria sem segredos | `AuditLogger` | `LoginTest::test_authentication_events_are_audited_without_secrets` |
| Senha de tenant gerada aleatoriamente (40 caracteres), nunca informada por pessoa | `InstitutionProvisioner::provision()` | `ProvisioningTest::test_provisioning_creates_isolated_database_with_opaque_identifiers` |

Configurado, **sem teste**: `.env` fora do Git (`.gitignore`); `.env.example` sem valores secretos; `SESSION_ENCRYPT=true` no exemplo (o padrão de `config/session.php` é `false` se a variável faltar).

### Projetado, ainda não implementado

| Item | Etapa |
|---|---|
| KMS ou gerenciador externo de segredos (APP_KEY e DEKs fora da VPS) | 9–10 |
| Rotação automatizada de APP_KEY, DEKs, senhas de tenant e do provisionador | 9 |
| Separação do provisionador em processo/usuário de sistema distinto do web | 9–10 |
| Privilégios mínimos documentados para a conta de controle em produção | 10 |
| Procedimento de resposta a vazamento de segredo | 9 |
| Backup/custódia da APP_KEY e teste de restauração | 9 |
| Varredura de segredos no repositório/CI | 9 |

## Inventário

| Segredo | Onde fica | Proteção em repouso | Quem usa |
|---|---|---|---|
| `APP_KEY` | `.env` | **nenhuma além das permissões do arquivo** | Laravel: casts `encrypted`, sessão, jobs cifrados |
| `DB_PASSWORD` (conta de controle) | `.env` | permissões do arquivo | conexão `control` |
| `TENANT_PROVISIONER_PASSWORD` | `.env` | permissões do arquivo | conexões `provisioner` e `tenant_migrator` |
| Senha de banco de cada tenant | `institutions.db_password` | cifrada com `APP_KEY` (AES-256-CBC + HMAC, padrão Laravel) | `TenantConnectionManager` |
| DEK de cada instituição | `institutions.data_key` | cifrada com `APP_KEY` | `TenantEncrypter` (AES-256-GCM) |
| Segredo TOTP | `users.mfa_secret` | cifrado com `APP_KEY` | `MfaService` |
| Senha de usuário | `users.password` | hash (bcrypt, padrão do framework) | login |
| Token de convite | e-mail do convidado | no banco só `token_hash` (SHA-256) | `InvitationService::accept()` |
| Sessão | tabela `sessions` (banco de controle) | cifrada se `SESSION_ENCRYPT=true` | framework |
| Payload de `TenantJob` | tabela `jobs` | cifrado com `APP_KEY` | worker |

Hierarquia efetiva:

```
APP_KEY (.env, mesma VPS)
 ├── institutions.db_password  → acesso ao banco de cada tenant
 ├── institutions.data_key (DEK) → nome, nascimento e matrícula dos alunos
 ├── users.mfa_secret
 └── sessões e payloads de fila
```

## Limites e riscos residuais

1. **A APP_KEY decifra tudo.** Ela fica na mesma VPS que a aplicação e o banco. Quem compromete o servidor e lê o `.env` (ou a memória do processo) decifra as senhas de banco de todos os tenants, todas as DEKs e, com elas, os dados identificáveis de todos os alunos. O modelo "envelope" atual separa instituições entre si, mas **não** protege contra a tomada do servidor nem contra um backup que contenha `.env` e banco de controle juntos.
2. **Credencial do provisionador no `.env` do processo web.** É a credencial mais poderosa em uso: `CREATE USER` global e `ALL ... WITH GRANT OPTION` sobre todos os bancos de tenant, o que inclui ler os bancos diretamente (os campos cifrados continuam exigindo a DEK). O privilégio `CREATE USER` em MySQL/MariaDB também permite alterar ou remover outras contas. Qualquer execução de código no processo web herda esse poder.
3. **Conta de controle com `ALL` no ambiente local.** `scripts/dev/setup_local_db.sh` concede `ALL PRIVILEGES` nos bancos de controle. Os privilégios de produção não estão definidos; replicar o script em produção seria excessivo (e ele traz senhas fixas).
4. **`audit_events` sem proteção no banco.** `UPDATE` e `DELETE` não estão revogados para a conta de controle. A imutabilidade é da aplicação (`AuditEvent`) e a cadeia de hash apenas detecta alteração; quem tem a credencial de controle pode recalcular a cadeia. O hash não usa segredo (não é HMAC).
5. **Sem rotação.** Nenhuma chave ou senha tem rotação automatizada nem procedimento testado. Trocar a `APP_KEY` sem recifrar `db_password`, `data_key` e `mfa_secret` torna todos os tenants inacessíveis. O Laravel aceita chaves antigas para decifrar (`APP_PREVIOUS_KEYS` em `config/app.php`), mas não há rotina de recifragem; trocar uma DEK exigiria recifrar todos os alunos da instituição, o que também não existe.
6. **Perda da APP_KEY = perda dos dados cifrados.** Não há custódia nem cópia de segurança definida para a chave.
7. **Token de convite no corpo do e-mail.** Com `MAIL_MAILER=log` (padrão do `.env.example`), o link completo com o token é gravado no log da aplicação. Aceitável só em desenvolvimento.
8. **Segredo TOTP devolvido em texto** na resposta de `POST /mfa/enroll` (necessário para o cadastro); a resposta sai com `Cache-Control: no-store`. Não há códigos de recuperação.
9. **Senhas `dev-only-*`** aparecem em `scripts/dev/setup_local_db.sh` e `phpunit.xml`. São públicas, exclusivas de desenvolvimento/teste e não podem existir em servidor com dados reais.
10. **Credenciais em memória.** Durante uma requisição, a senha do tenant fica na configuração do Laravel e a DEK no `TenantEncrypter`; `APP_DEBUG=true` ou um dump de erro poderia expô-las. `.env.example` traz `APP_DEBUG=true`, valor inadequado fora de desenvolvimento.

## Práticas que o código já impõe

- Segredos de instituição só são gravados por `forceFill` em serviços (`$guarded = ['*']`).
- `db_name`, `db_username`, `db_password` e `data_key` nunca são serializados; a API administrativa expõe só metadados.
- Erros de provisionamento registram apenas a classe da exceção na auditoria, não a mensagem.
- IP de origem é truncado (IPv4 /24, IPv6 /48) antes de ir para a auditoria (`AuditTest::test_origin_address_is_minimized`).

## Regras operacionais (manuais, até a Etapa 9)

- `.env` de produção com dono e leitura restritos ao usuário do PHP; nunca em repositório, backup não cifrado ou canal de mensagens.
- `APP_KEY`, senha de controle e senha do provisionador diferentes por ambiente e geradas aleatoriamente.
- `APP_DEBUG=false`, `SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE=true` e `MAIL_MAILER` real em homologação/produção.
- Guardar cópia da `APP_KEY` fora da VPS, separada dos backups de banco.
- Qualquer suspeita de leitura do `.env` deve ser tratada como comprometimento de todos os tenants.

## Evolução recomendada (não implementada)

1. Mover a `APP_KEY` (ou, melhor, a cifragem das DEKs) para um KMS/gerenciador externo de segredos, de modo que o servidor de aplicação peça a decifragem em vez de possuir a chave.
2. Tirar a credencial do provisionador do processo web: comando/worker dedicado, com usuário de sistema e `.env` próprios.
3. Conta de controle com privilégios mínimos e sem `UPDATE`/`DELETE` em `audit_events`; âncora externa periódica do último hash.
4. Rotinas testadas de rotação (APP_KEY, DEK, senhas de tenant) e de restauração.
