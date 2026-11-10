# Instalação na VPS HostGator — passo a passo

## Estado

- **Nenhuma instalação foi feita.** A VPS ainda não foi diagnosticada.
- O que está no repositório hoje é a fundação do backend (autenticação, MFA,
  multi-tenancy, cadastro escolar básico) **sem interface web**: publicar agora
  serviria apenas para homologar a infraestrutura, não para uso pelas escolas.
- Os comandos abaixo são o roteiro previsto. Os marcados com ⚠ dependem do
  sistema operacional e do painel e **serão fixados após o diagnóstico**; não
  os execute antes disso.
- Scripts de instalação automatizada (`scripts/infra/install_*.sh`) ainda não
  existem; serão escritos para o cenário real (Etapa 10).

Regras válidas para qualquer passo: nunca usar `chmod 777`; nunca rodar
Composer, workers ou a aplicação como root; nunca usar o root do MySQL na
aplicação; nunca colar senhas em chat, e-mail ou no repositório; toda mudança
de SSH/firewall só depois de testar um caminho de recuperação.

## Passo 1 — Diagnóstico (pode ser feito já)

Siga `docs/infra/DIAGNOSTICO_VPS_HOSTGATOR.md` e envie o `diagnostico.txt`.
Ele define se a instalação segue o **Cenário A** (sem painel) ou **B** (cPanel/WHM).

## Passo 2 — Decisões que dependem de você

| Decisão | Por quê |
|---|---|
| Domínios (ex.: `app.seudominio.com.br`, `admin.seudominio.com.br`) | DNS e certificados |
| Serviço de e-mail transacional (SMTP) | Convites e avisos; envolve contratação |
| Destino externo dos backups cifrados | Não depender só da VPS; envolve contratação |
| Quem terá acesso administrativo ao servidor | Chaves SSH e contas nominais |
| Janela para o hardening | Há risco de bloqueio de acesso se feito sem preparo |

## Passo 3 — Preparar o servidor ⚠

1. Aplicar atualizações de segurança do sistema.
2. Garantir PHP 8.3+ com as extensões: `pdo_mysql mbstring openssl sodium intl
   ctype tokenizer xml fileinfo zip opcache` (e `pcntl posix` para os workers).
   - Cenário B: WHM → *EasyApache 4* → habilitar `ea-php83` (ou superior) e as
     extensões; WHM → *MultiPHP Manager* → atribuir a versão ao domínio.
   - Cenário A: pacotes `php-fpm` e extensões do repositório oficial da distribuição.
3. Instalar o Composer 2 para o usuário de deploy (não root).
4. Criar o usuário de deploy (dono do código) separado do usuário que executa
   o PHP. No cPanel, a conta cPanel do site cumpre o papel do usuário de execução.
5. Hardening conforme `docs/infra/HARDENING_VPS.md`.

## Passo 4 — Banco de dados ⚠

Criar, com o root do banco **apenas nesta etapa**, as contas abaixo. Gere
senhas longas e aleatórias no próprio servidor; não reutilize as de desenvolvimento.

```sql
CREATE DATABASE sociometria_control CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- runtime do banco de controle
CREATE USER 'soc_control'@'localhost' IDENTIFIED BY '<senha gerada>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES
  ON `sociometria\_control`.* TO 'soc_control'@'localhost';

-- provisionador: cria bancos/contas de instituições; NÃO enxerga o banco de controle
CREATE USER 'soc_provisioner'@'localhost' IDENTIFIED BY '<outra senha gerada>';
GRANT CREATE USER ON *.* TO 'soc_provisioner'@'localhost';
GRANT ALL PRIVILEGES ON `soc\_t\_%`.* TO 'soc_provisioner'@'localhost' WITH GRANT OPTION;
```

As contas de cada instituição **não são criadas à mão**: o provisionamento da
aplicação cria um banco `soc_t_<hex aleatório>` e uma conta exclusiva só com
`SELECT/INSERT/UPDATE/DELETE` para cada uma.

Conferir que o banco escuta apenas em `127.0.0.1`/socket (porta 3306 fechada
para a internet).

## Passo 5 — Publicar o código ⚠

Layout (Cenário A; no B adapta-se a `/home/USUARIO/`):

```
/var/www/sociometria/
├── releases/<data-hora>/   código de cada versão (somente leitura para o PHP)
├── shared/.env             configuração e segredos (modo 640)
├── shared/storage/         logs, cache, arquivos privados
└── current -> releases/<versão ativa>
```

1. Enviar a release (pacote gerado pelo CI ou `git clone` com chave de deploy
   somente leitura) para `releases/<data-hora>/`.
2. `composer install --no-dev --optimize-autoloader --no-interaction` como
   usuário de deploy.
3. Ligar `shared/.env` e `shared/storage` à release.
4. Apontar o **document root para `current/public`** — nunca para a raiz do projeto.
   - Cenário B: cPanel → *Domínios* → editar a raiz do documento.
   - Cenário A: `root` do server block do Nginx (`docs/infra/CONFIGURACAO_NGINX_OU_CPANEL.md`).

## Passo 6 — Configurar o `.env` de produção

Copiar `.env.example` para `shared/.env` e ajustar no servidor:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.seudominio.com.br
LOG_LEVEL=warning
DB_DRIVER=mysql            # ou mariadb, conforme o diagnóstico
DB_PASSWORD=<senha do soc_control>
TENANT_PROVISIONER_PASSWORD=<senha do soc_provisioner>
PLATFORM_HOSTS=app.seudominio.com.br,admin.seudominio.com.br
SESSION_SECURE_COOKIE=true
SECURITY_MFA_REQUIRED=true
```

Depois, **uma única vez**:

```bash
php artisan key:generate --force
```

> **A `APP_KEY` é o segredo mais crítico do sistema.** Ela cifra as senhas dos
> bancos das instituições e as chaves que protegem os dados dos alunos.
> Guarde uma cópia fora do servidor, em cofre de senhas. Nunca a regenere em
> produção: perder ou trocar a chave torna todos os bancos institucionais e os
> dados cifrados inacessíveis.

## Passo 7 — Migrations e otimização

```bash
php artisan migrate --force          # banco de controle
php artisan config:cache
php artisan route:cache
```

## Passo 8 — HTTPS ⚠

- Cenário B: WHM/cPanel → *SSL/TLS Status* → executar AutoSSL para os domínios.
- Cenário A: Certbot do repositório oficial, com renovação automática.
- Confirmar o redirecionamento de HTTP para HTTPS.

## Passo 9 — Filas e agendamento ⚠

- Worker: `php artisan queue:work --tries=3 --timeout=120 --max-time=3600`,
  mantido por systemd (A) ou Supervisor/cron (B), com o usuário da aplicação.
- Cron (a cada minuto): `php artisan schedule:run`.
- Detalhes em `docs/infra/PROCESSOS_E_FILAS.md`.

## Passo 10 — Primeiro administrador da plataforma

```bash
php artisan platform:create-admin voce@seudominio.com.br --name="Seu Nome"
```

A senha é pedida no terminal, sem eco (nunca como argumento). No primeiro
login o sistema exige o cadastro do segundo fator (aplicativo autenticador)
antes de liberar o painel. Não crie administradores inserindo linhas no banco.

Limitação atual: ainda não há códigos de recuperação de MFA nem redefinição de
senha; perder o dispositivo exige intervenção manual no servidor.

## Passo 11 — Verificações finais

| Verificação | Esperado |
|---|---|
| `https://app.seudominio.com.br/healthz` | `{"status":"ok"}` |
| `https://app.seudominio.com.br/.env` | 404 |
| `http://...` | redireciona para `https://` |
| Porta 3306 a partir de fora | fechada |
| `php artisan about` | `Environment: production`, `Debug Mode: OFF` |
| Login sem MFA em `/app/schools` | 403 |

## Passo 12 — Antes de qualquer dado real

Backups externos cifrados com restauração testada
(`docs/infra/BACKUP_RESTAURACAO.md`), monitoramento ativo
(`docs/infra/MONITORAMENTO.md`) e validação jurídica pela instituição
(`docs/privacy/LGPD.md`). Sem esses três itens o sistema não deve receber
dados de estudantes.

## Recuperação

- Deploy com problema: reapontar `current` para a release anterior e executar
  `php artisan queue:restart` (`docs/infra/DEPLOY_ROLLBACK.md`).
- Perda de acesso SSH: console de emergência do Portal do Cliente HostGator.
- Perda do `.env`: restaurar do cofre; **não** gerar nova `APP_KEY`.
