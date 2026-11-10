# Monitoramento

## Estado

**Plano de monitoramento. Nenhuma ferramenta de monitoramento ou alerta está
instalada, e nenhum script de monitoramento existe no repositório (previsto
para as Etapas 9 e 10).** A VPS ainda não foi diagnosticada; o diagnóstico dirá
o que já vem no servidor (por exemplo, recursos do WHM no cenário B).

| Já existe no código | É plano |
|---|---|
| `GET /healthz`: `{"status":"ok"}` (200) ou `{"status":"unavailable"}` (503); testa só o banco de controle e não revela detalhes | Verificação externa periódica e alertas |
| `X-Correlation-Id` em toda resposta, repetido nos logs e em `audit_events.correlation_id` | Coleta de métricas do servidor |
| `audit_events` no banco de controle, com cadeia de hash; IP de origem minimizado (/24 ou /48) | Consultas agendadas e limiares |
| Tabelas `jobs` e `failed_jobs` (fila `database`) | Painel/relatório periódico |
| Log diário da aplicação em `storage/logs/laravel-AAAA-MM-DD.log` (14 dias por padrão) | Rotação e retenção dos logs do servidor |

Comandos e consultas são **exemplos — confirmar após o diagnóstico**. Sem
terminal local: Terminal do WHM/cPanel (B), console web do Portal do Cliente
HostGator ou cliente SSH em outro dispositivo; alertas devem chegar por e-mail,
sem depender de alguém conectado ao servidor.

## Sinais e origem

| Sinal | De onde vem | Já existe? |
|---|---|---|
| Disponibilidade | Verificação externa de `https://DOMINIO/healthz` a cada 1–5 min | Endpoint **sim**; verificador externo **não** (serviço de terceiro: depende de autorização) |
| CPU | `uptime`, `vmstat`; B: WHM › *Server Status* | Depende de ferramenta/rotina |
| Memória | `free -m`; registros de OOM em `journalctl -k` | Depende de ferramenta/rotina |
| Disco | `df -h`, `df -i` (inodes) | Depende de ferramenta/rotina |
| Erros HTTP (5xx, 4xx anormais) | Log de acesso do Nginx (A) ou do Apache/domlogs (B) | Log existirá com o servidor web; contagem **não** |
| Latência | Tempo de resposta do verificador externo; `$request_time` no log do Nginx (A) | **Não** |
| Banco (no ar, consultas lentas) | `/healthz` (controle); `mariadb-admin status`; slow query log | Parcial: `/healthz` **sim**; resto **não** |
| Conexões de banco | `SHOW GLOBAL STATUS LIKE 'Threads_connected'`; `Max_used_connections`; limite por tenant (`TENANT_DB_MAX_USER_CONNECTIONS`) | Limite por tenant **sim**; medição **não** |
| Tamanho das filas | Tabela `jobs` no banco de controle | Dado **sim**; alerta **não** |
| Jobs com falha | Tabela `failed_jobs`; `php artisan queue:failed` | Dado **sim**; alerta **não** |
| Backups | Código de saída e data do último backup; `restic snapshots` | **Não** (não há backup ainda) |
| Validade de certificados | `openssl s_client` contra o domínio; A: `certbot certificates`; B: WHM › *Manage AutoSSL* | **Não** |
| Erros de autenticação | `audit_events`: `auth.login` / `failure`, `auth.login` / `denied` (limite de tentativas), `auth.mfa` / `failure`; no servidor: `auth.log`/`secure`, fail2ban ou cPHulk | Eventos **sim**; alerta **não** |
| Negações de autorização | `audit_events`: `admin.access` / `denied`, `tenant.resolve` / `denied` | Eventos **sim**; alerta **não** |
| Falhas de isolamento | `audit_events`: `tenant.resolve` / `denied`, `job.context` / `denied`, `institution.provisioned` / `failure` (inclui a verificação de isolamento no provisionamento), `tenant.migrated` / `failure` | Eventos **sim**; alerta **não** |
| Integridade da auditoria | `AuditLogger::verifyChain()` | Método **sim**; comando artisan e rotina **não** |
| Atualizações e reinício pendentes | `apt list --upgradable` / `dnf check-update`; `/var/run/reboot-required` | Depende de rotina |

`/healthz` não cobre fila, bancos de tenant, disco nem certificado: um "ok" não
significa que tudo está bem.

## Consultas de exemplo

Executar com uma conta de leitura no banco de controle (não a do runtime nem root).

```sql
-- EXEMPLO — confirmar após o diagnóstico
-- Fila: tamanho e idade do job mais antigo (segundos)
SELECT COUNT(*) AS pendentes, UNIX_TIMESTAMP() - MIN(available_at) AS idade_s FROM jobs;

-- Jobs com falha nas últimas 24 h
SELECT COUNT(*) FROM failed_jobs WHERE failed_at >= NOW() - INTERVAL 1 DAY;

-- Falhas de autenticação na última hora
SELECT action, outcome, COUNT(*) AS n FROM audit_events
WHERE action IN ('auth.login','auth.mfa') AND outcome IN ('failure','denied')
  AND occurred_at >= NOW() - INTERVAL 1 HOUR
GROUP BY action, outcome;

-- Negações de autorização e sinais de isolamento nas últimas 24 h
SELECT action, outcome, institution_id, COUNT(*) AS n FROM audit_events
WHERE ((action IN ('admin.access','tenant.resolve','job.context') AND outcome = 'denied')
    OR (action IN ('institution.provisioned','tenant.migrated') AND outcome = 'failure'))
  AND occurred_at >= NOW() - INTERVAL 1 DAY
GROUP BY action, outcome, institution_id;
```

```bash
# EXEMPLO — confirmar após o diagnóstico
curl -fsS -o /dev/null -w '%{http_code} %{time_total}s\n' https://DOMINIO/healthz
echo | openssl s_client -servername DOMINIO -connect DOMINIO:443 2>/dev/null | openssl x509 -noout -enddate
df -h / && df -i /
systemctl is-active sociometria-worker        # A
```

## Limiares iniciais (a calibrar no piloto)

| Sinal | Aviso | Crítico |
|---|---|---|
| `/healthz` | 1 falha | 3 falhas seguidas ou 503 |
| Latência de `/healthz` | > 1 s | > 3 s por 5 min |
| Disco (espaço ou inodes) | > 75 % | > 90 % |
| Memória | swap em uso contínuo | processo morto por OOM |
| Carga de CPU | > nº de núcleos por 15 min | > 2× núcleos por 15 min |
| Respostas 5xx | > 1 % em 5 min | > 5 % em 5 min |
| Conexões de banco | > 70 % de `max_connections` | > 90 % |
| Fila | job mais antigo > 5 min | > 30 min ou worker parado |
| `failed_jobs` | qualquer novo | > 10 em 1 h |
| Backup | último sucesso > 5 h | > 24 h ou falha de execução |
| Certificado | < 21 dias | < 7 dias |
| `auth.login` failure/denied | pico fora do padrão | crescimento sustentado (possível ataque) |
| `job.context` denied, `institution.provisioned` failure | — | **qualquer ocorrência: investigar no mesmo dia** |
| `verifyChain()` | — | qualquer evento inconsistente |

`tenant.resolve` / `denied` também ocorre em uso normal (por exemplo, vínculo
revogado ou instituição suspensa): avaliar o campo `metadata.reason` e a
repetição pelo mesmo ator, não a ocorrência isolada.

## Como implementar (decidir após o diagnóstico)

1. **Mínimo viável (A e B):** script agendado por cron que roda as verificações acima e envia e-mail apenas quando há aviso/crítico, mais um verificador externo de `/healthz` (um monitor interno não avisa quando a própria VPS cai). O serviço externo e o envio de e-mail dependem de autorização do responsável.
2. **Cenário B:** aproveitar o que o painel já oferece (alertas do WHM em *Contact Manager*, notificações do AutoSSL, cPHulk) antes de instalar algo.
3. **Cenário A, se o piloto justificar:** agente de métricas dos repositórios oficiais da distribuição. Não expor portas de métricas publicamente; nada de repositórios de terceiros desconhecidos.
4. Rotinas do monitoramento rodam com usuário próprio, sem root e com conta de banco somente leitura.

## Logs: o que pode e o que não pode aparecer

| Log | Local | Cuidado |
|---|---|---|
| Aplicação | `shared/storage/logs/laravel-*.log` | `APP_DEBUG=false` e `LOG_LEVEL=warning` em produção; leitura só pelo usuário web e administradores |
| Auditoria | `audit_events` (banco de controle) | `metadata` aceita apenas dados técnicos |
| Web | Nginx/Apache | URLs usam `public_id` opaco; rotação e retenção definidas |
| Sistema | `auth.log`/`secure`, `journalctl` | Acesso restrito a administradores |

Regras:

- Logs técnicos **nunca** contêm nomes de alunos, respostas de questionários, senhas, tokens, códigos MFA ou conteúdo de `.env`.
- Investigar pelo `X-Correlation-Id` informado pelo usuário, não por nome de pessoa.
- Alertas e chamados citam `public_id` e `correlation_id`, nunca dados pessoais.
- Não copiar `failed_jobs.exception` nem trechos de log para canais externos sem revisar.
- Stack trace com dado pessoal é defeito: corrigir a origem, não apenas apagar a linha.

## Rotina operacional

- [ ] Diário: e-mails de alerta; `failed_jobs`; resultado do backup.
- [ ] Semanal: disco, atualizações pendentes, eventos `denied`/`failure` da semana.
- [ ] Mensal: validade do certificado, revisão de contas com acesso ao servidor, `verifyChain()`.
- [ ] Trimestral: teste de restauração (`BACKUP_RESTAURACAO.md`) e revisão dos limiares.
- [ ] Após cada deploy: 30 minutos de acompanhamento (`DEPLOY_ROLLBACK.md`, etapa 14).
