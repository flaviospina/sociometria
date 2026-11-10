# Servidor web: Nginx (cenário A) ou cPanel (cenário B)

## Estado

**Configuração projetada. Nada foi instalado, configurado ou testado em
servidor.** O sistema operacional e a presença de cPanel/WHM são desconhecidos
até a execução de `scripts/infra/diagnostico_vps.sh`.

| Já existe no código | É plano (este documento) |
|---|---|
| `public/index.php` e `public/.htaccess` padrão do Laravel | Layout de diretórios, usuários e permissões |
| Cabeçalhos de segurança e `Cache-Control: no-store, private` em toda resposta da aplicação (`app/Http/Middleware/SecurityHeaders.php`); HSTS quando a requisição é HTTPS | Server block Nginx / domínio no cPanel |
| `X-Correlation-Id` em toda resposta (`AssignCorrelationId`) | TLS (Certbot ou AutoSSL) |
| `TRUSTED_PROXIES` (padrão `127.0.0.1`), `GET /healthz` | Pool PHP-FPM dedicado |

Requisitos confirmados no repositório: PHP `^8.3` (`composer.json`); extensões
usadas no CI: `pdo_mysql`, `mbstring`, `intl`, `sodium`. Node só é necessário
para o build, não em produção.

Todos os comandos e caminhos são **exemplos — confirmar após o diagnóstico**.
Sem terminal local: executar pelo Terminal do WHM/cPanel (B), pelo console web
do Portal do Cliente HostGator ou por cliente SSH em outro dispositivo.

## Layout de diretórios

```text
Cenário A: /var/www/sociometria          Cenário B: /home/USUARIO/sociometria
├── releases/
│   └── 2026-11-20_1430_abc1234/   # uma pasta por versão publicada (imutável)
├── shared/
│   ├── .env                        # único por servidor; nunca dentro de uma release
│   └── storage/                    # logs, cache de framework, arquivos
└── current -> releases/2026-11-20_1430_abc1234   # symlink trocado no deploy
```

Dentro de cada release, `.env` e `storage` são symlinks para `shared/`. No
cenário B a aplicação fica **fora** de `public_html`; só `public/` é servido.

## Permissões

Princípio: o processo web lê o código, mas **não** consegue alterá-lo.

| Caminho | Cenário A (dono:grupo, modo) | Cenário B (conta cPanel única) |
|---|---|---|
| `releases/*` (código, `vendor/`) | `deploy:soc-web`, diretórios `750`, arquivos `640` | Diretórios `550`, arquivos `440` após o deploy |
| `shared/storage`, `bootstrap/cache` | `soc-web:soc-web`, `770`/`660` | `750`/`640` |
| `shared/.env` | `deploy:soc-web`, `640` | `640` (ou `600`; o grupo é o próprio usuário) |
| `public/` | leitura para o usuário do Nginx (via grupo) | Leitura pelo Apache conforme o handler do painel |

- **Nunca `chmod 777`.** Se algo "só funciona com 777", o dono/grupo está errado.
- Limitação do cenário B: PHP roda como a própria conta cPanel, que também é dona do código. Tirar o bit de escrita reduz o risco, mas não é separação real de usuários. Registrar como risco aceito ou motivo para preferir o cenário A.
- `artisan` é executado como o usuário web (`sudo -u soc-web php artisan ...` em A), nunca como root.

```bash
# EXEMPLO (A) — confirmar após o diagnóstico
sudo install -d -o deploy -g soc-web -m 750 /var/www/sociometria/{releases,shared}
sudo install -d -o soc-web -g soc-web -m 770 /var/www/sociometria/shared/storage
sudo usermod -aG soc-web www-data      # Debian/Ubuntu; em Alma/Rocky o usuário é "nginx"
sudo install -o deploy -g soc-web -m 640 /dev/null /var/www/sociometria/shared/.env
```

## Cenário A — Nginx + PHP-FPM

Pool dedicado (Debian/Ubuntu: `/etc/php/8.3/fpm/pool.d/sociometria.conf`;
Alma/Rocky: `/etc/php-fpm.d/sociometria.conf`):

```ini
; EXEMPLO — confirmar após o diagnóstico
[sociometria]
user = soc-web
group = soc-web
listen = /run/php/sociometria.sock        ; Alma/Rocky: /run/php-fpm/sociometria.sock
listen.owner = www-data                   ; Alma/Rocky: nginx
listen.group = www-data
listen.mode = 0660
pm = dynamic
pm.max_children = 10                      ; dimensionar pela memória vista no diagnóstico
pm.max_requests = 500
php_admin_value[open_basedir] = /var/www/sociometria/:/tmp/
; expose_php = Off e display_errors = Off ficam no php.ini do FPM
```

Server block (`/etc/nginx/sites-available/sociometria` em Debian/Ubuntu;
`/etc/nginx/conf.d/sociometria.conf` em Alma/Rocky):

```nginx
# EXEMPLO — confirmar após o diagnóstico. DOMINIO = domínio real.
server {
    listen 80; listen [::]:80;
    server_name DOMINIO;
    location /.well-known/acme-challenge/ { root /var/www/letsencrypt; }
    location / { return 301 https://$host$request_uri; }
}

server {
    listen 443 ssl http2; listen [::]:443 ssl http2;
    server_name DOMINIO;

    ssl_certificate     /etc/letsencrypt/live/DOMINIO/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/DOMINIO/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    root /var/www/sociometria/current/public;
    index index.php;
    autoindex off; server_tokens off;
    client_max_body_size 10m;             # ajustar ao maior upload que a aplicação vier a aceitar

    # Arquivos ocultos (.env, .git, .htaccess) nunca são servidos
    location ~ /\.(?!well-known/) { deny all; }

    location / { try_files $uri $uri/ /index.php?$query_string; }

    # Somente o front controller executa PHP
    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT   $realpath_root;
        fastcgi_pass unix:/run/php/sociometria.sock;
        fastcgi_hide_header X-Powered-By;
    }
    location ~ \.php$ { return 404; }

    # Estáticos com hash gerados pelo Vite; a aplicação continua enviando no-store nas respostas PHP
    location /build/ { add_header X-Content-Type-Options nosniff always; expires 30d; }

    access_log /var/log/nginx/sociometria.access.log;
}
```

Notas:

- `$realpath_root` faz o PHP-FPM enxergar a release real, e não o symlink: após trocar `current`, recarregar o PHP-FPM (`systemctl reload php8.3-fpm` / `php-fpm`) para o OPcache não servir a versão anterior.
- Não repetir no Nginx os cabeçalhos que a aplicação já envia (CSP, `X-Frame-Options`, HSTS): cabeçalho duplicado dificulta o diagnóstico.
- Sem proxy/CDN na frente, manter `TRUSTED_PROXIES=127.0.0.1`. Se entrar um proxy, listar **somente** os IPs dele; nunca `*`.
- O log de acesso guarda URL e IP. As URLs usam identificadores opacos (`public_id`), sem nomes; mesmo assim, aplicar rotação e retenção (`logrotate`).

TLS com Certbot (pacote oficial da distribuição; em Alma/Rocky vem do EPEL — confirmar antes de habilitar):

```bash
# EXEMPLO — confirmar após o diagnóstico; o DNS de DOMINIO já deve apontar para a VPS
sudo certbot certonly --webroot -w /var/www/letsencrypt -d DOMINIO
sudo nginx -t && sudo systemctl reload nginx
sudo certbot renew --dry-run        # valida a renovação automática
```

## Cenário B — cPanel/WHM

Preservar Apache/EasyApache. Não instalar Nginx por cima.

1. **Document root.** O domínio principal de uma conta cPanel aponta para `public_html` e isso não muda pelo painel. Opções, da mais simples para a mais invasiva:
   - criar a aplicação como **subdomínio ou domínio adicional** com document root `/home/USUARIO/sociometria/current/public` (cPanel › *Domínios*); ou
   - substituir `public_html` por um symlink para `sociometria/current/public` (mesmo dono, compatível com `SymLinksIfOwnerMatch`); guardar o `public_html` original antes.
   - Não editar à mão os arquivos em `/var/cpanel/userdata`: o painel os reescreve.
2. **Reescrita.** Usar o `public/.htaccess` que já está no repositório; conferir no WHM que `mod_rewrite` está ativo e `AllowOverride` permite o arquivo.
3. **PHP.** *MultiPHP Manager*: selecionar `ea-php83` (ou superior) **para o domínio**, com PHP-FPM do painel se disponível. *MultiPHP INI Editor*: `expose_php=Off`, `display_errors=Off`, `upload_max_filesize`/`post_max_size` coerentes com o limite de upload. Se o EasyApache não oferecer PHP ≥ 8.3, parar e decidir.
4. **CLI.** O `php` do PATH pode ser de outra versão. Em cron e deploy usar o caminho completo, por exemplo `/opt/cpanel/ea-php83/root/usr/bin/php`.
5. **TLS.** *AutoSSL* para o domínio; depois ativar "Forçar HTTPS" em *Domínios*. O HSTS já é enviado pela aplicação.
6. **Listagem e dotfiles.** `Options -Indexes` já está coberto pelo `.htaccess` do Laravel; como a aplicação fica fora do document root, `.env` não é alcançável pela web.
7. **Proxy.** Se o diagnóstico mostrar Nginx do próprio painel na frente do Apache, ajustar `TRUSTED_PROXIES` para o endereço local dele; caso contrário, manter o padrão.

## Variáveis de produção (`shared/.env`)

Partir de `.env.example` e alterar no mínimo:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://DOMINIO
SESSION_SECURE_COOKIE=true
LOG_LEVEL=warning
PLATFORM_HOSTS=DOMINIO
# APP_KEY: gerada UMA vez, guardada fora do servidor. Ver DEPLOY_ROLLBACK.md e BACKUP_RESTAURACAO.md.
```

## Verificação (após a primeira publicação)

- [ ] `https://DOMINIO/healthz` devolve `{"status":"ok"}`; `http://` redireciona para `https://`.
- [ ] `https://DOMINIO/.env` e `https://DOMINIO/.git/config` devolvem 403/404.
- [ ] Resposta traz `X-Correlation-Id`, `Strict-Transport-Security` e `Cache-Control: no-store, private`.
- [ ] Como usuário web, tentar criar um arquivo em `current/app/` falha (A).
- [ ] Teste externo de TLS sem protocolos abaixo de 1.2.
