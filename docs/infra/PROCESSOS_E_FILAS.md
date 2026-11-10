# Processos persistentes, filas e agendamento

## Estado

**Procedimento projetado. Nenhum worker, unit do systemd, programa do
Supervisor ou cron foi instalado ou testado em servidor.** A VPS ainda não foi
diagnosticada (SO e painel desconhecidos).

| Já existe no código | É plano |
|---|---|
| Fila com driver `database` por padrão (`QUEUE_CONNECTION=database`; tabelas `jobs` e `failed_jobs` no banco de controle) | Worker como serviço (systemd, Supervisor ou cron) |
| `App\Tenancy\Queue\TenantJob`: payload cifrado (`ShouldBeEncrypted`), `tries=3`, `timeout=120`, `backoff=[10,60,300]` | Filas por prioridade |
| `InTenantContext`: revalida instituição ativa e vínculo do ator a cada execução; se negar, descarta o job e audita `job.context` / `denied` | Isolamento de workers por instituição |
| `AppServiceProvider`: o contexto institucional é destruído antes e depois de **qualquer** job | Alertas de fila (ver `MONITORAMENTO.md`) |
| Uma única fila (`default`) | Tarefas agendadas — hoje `routes/console.php` não agenda nada |

Comandos abaixo são **exemplos — confirmar após o diagnóstico**. Sem terminal
local: usar o Terminal do WHM/cPanel (B), o console web do Portal do Cliente
HostGator ou cliente SSH em outro dispositivo.

## Comando do worker

```bash
php artisan queue:work --tries=3 --timeout=120 --max-time=3600
```

- `--tries` e `--timeout` repetem os valores de `TenantJob`; valem para jobs que não os definam.
- `--max-time=3600` encerra o processo a cada hora (contém vazamento de memória); quem o reinicia é o supervisor do processo.
- O worker roda como o **usuário web** (`soc-web` em A; conta cPanel em B). Nunca como root.
- O worker precisa da mesma `APP_KEY` da aplicação web: sem ela não decifra os jobs.

### Ajuste necessário antes de produção: `retry_after`

`config/queue.php` usa `DB_QUEUE_RETRY_AFTER` com padrão **90 s**, menor que o
`timeout` de **120 s** dos jobs. Com isso, um job que demore mais de 90 s pode
ser entregue a outro worker enquanto ainda executa. Definir no `shared/.env`:

```dotenv
DB_QUEUE_RETRY_AFTER=180   # sempre maior que o maior timeout de job
```

## Cenário A — systemd

`/etc/systemd/system/sociometria-worker.service`:

```ini
# EXEMPLO — confirmar após o diagnóstico
[Unit]
Description=Sociometria - worker de fila
After=network.target mariadb.service mysql.service

[Service]
User=soc-web
Group=soc-web
WorkingDirectory=/var/www/sociometria/current
ExecStart=/usr/bin/php artisan queue:work --tries=3 --timeout=120 --max-time=3600 --sleep=3
Restart=always
RestartSec=5
# Dá tempo de o job em curso terminar (timeout de 120 s) antes de forçar a parada
KillSignal=SIGTERM
TimeoutStopSec=150
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now sociometria-worker
systemctl status sociometria-worker
journalctl -u sociometria-worker -n 50
```

Se o diagnóstico mostrar Supervisor já em uso, o equivalente é um bloco
`[program:sociometria-worker]` com `user=soc-web`, `autorestart=true`,
`stopwaitsecs=150` e o mesmo comando. Não manter os dois ao mesmo tempo.

## Cenário B — cPanel

O cPanel não traz Supervisor. Opções, em ordem de preferência:

1. **Cron da conta cPanel** (não exige root; funciona pelo navegador em cPanel › *Cron Jobs*):

   ```cron
   # EXEMPLO — confirmar caminho do PHP e do usuário após o diagnóstico
   * * * * * cd /home/USUARIO/sociometria/current && flock -n /home/USUARIO/sociometria/shared/worker.lock /opt/cpanel/ea-php83/root/usr/bin/php artisan queue:work --stop-when-empty --tries=3 --timeout=120 --max-time=3600 >/dev/null 2>&1
   ```

   O `flock` impede dois workers simultâneos; `--stop-when-empty` encerra o
   processo quando a fila esvazia. Atraso máximo para iniciar um job: 1 minuto.
2. **Unit do systemd criada pelo root** (WHM › Terminal), igual à do cenário A, com `User=USUARIO` e o PHP do EasyApache. Só se o cron não atender.

Não instalar Supervisor por repositório de terceiros sem autorização.

## Agendador (scheduler)

```cron
# A: crontab do usuário soc-web (sudo crontab -u soc-web -e) · B: cPanel › Cron Jobs
* * * * * cd /var/www/sociometria/current && php artisan schedule:run >/dev/null 2>&1
```

Hoje nenhuma tarefa está agendada no código; a entrada é inofensiva e fica
pronta para as rotinas previstas (limpeza de jobs falhos antigos, verificação da
cadeia de auditoria, backups). Em B, usar o caminho completo do PHP.

## Workers no deploy

```bash
# Depois de trocar o symlink `current` (ver DEPLOY_ROLLBACK.md)
php artisan queue:restart
```

- `queue:restart` grava um sinal no cache (`CACHE_STORE=database`); cada worker termina o job atual e sai. O systemd/cron sobe o novo processo já na release nova.
- Não usar `kill -9`: o job interrompido só volta após `retry_after` e conta como tentativa.
- A release anterior só pode ser apagada depois que todos os workers reiniciaram (o processo antigo ainda lê arquivos dela).
- Jobs enfileirados por uma versão e executados por outra: ao mudar o construtor de um job, manter compatibilidade por um deploy ou esvaziar a fila antes.

## Operação

```bash
php artisan queue:failed             # lista jobs que esgotaram as tentativas
php artisan queue:retry ID           # reprocessa um job específico
php artisan queue:forget ID          # descarta após análise
```

- Payloads são cifrados; `queue:failed` mostra classe e exceção, não dados de alunos. Não colar o conteúdo de `failed_jobs.exception` em chamados sem revisar.
- Job descartado por `InTenantContext` (instituição suspensa/inexistente ou vínculo revogado) **não** vai para `failed_jobs`: aparece em `audit_events` como `job.context` / `denied`.
- Reprocessar em massa (`queue:retry all`) só depois de entender a causa.

## Projeto (não implementado)

| Item | Situação hoje | Proposta |
|---|---|---|
| Filas por prioridade | Uma fila `default` | `high` (convites, autenticação), `default`, `low` (relatórios, exportações): `queue:work --queue=high,default,low` |
| Isolamento por instituição | Todos os tenants na mesma fila; o isolamento de **dados** já é garantido por job (contexto validado e limpo) | Limite de jobs simultâneos por instituição, para um tenant não monopolizar o worker |
| Mais de um worker | Um processo | Dimensionar pela CPU/memória do diagnóstico; cada worker abre conexões no banco (`TENANT_DB_MAX_USER_CONNECTIONS=10` por tenant) |
| Redis como backend | Não instalado; `config/database.php` só traz a configuração padrão | Avaliar se o volume justificar; se instalado, somente em `127.0.0.1` com senha |

## Verificação

- [ ] Worker ativo após reiniciar a VPS, rodando como usuário não root.
- [ ] `DB_QUEUE_RETRY_AFTER` maior que 120.
- [ ] `queue:restart` reinicia o worker em menos de 150 s sem perder job.
- [ ] Job de teste de tenant executa; com a instituição suspensa é descartado e auditado.
- [ ] Nenhum processo `queue:work` duplicado (`pgrep -af queue:work`).
