# Hardening da VPS (HostGator Brasil)

## Estado

**Procedimento projetado. Nada aqui foi executado.** A VPS ainda não foi
diagnosticada: não há acesso SSH nesta fase, o sistema operacional é
desconhecido e não se sabe se existe cPanel/WHM.

| O que existe hoje | O que é plano |
|---|---|
| `scripts/infra/diagnostico_vps.sh` (somente leitura; ainda não executado no servidor) | Todo o checklist abaixo |
| Aplicação sem uso de root do banco (`config/database.php`) | Usuários de sistema, SSH, firewall, fail2ban/cPHulk, NTP, atualizações |

O resultado do diagnóstico decide o cenário:

- **Cenário A** — VPS sem painel: Nginx + PHP-FPM + MySQL 8/MariaDB + systemd + Certbot + firewall + fail2ban.
- **Cenário B** — VPS com cPanel/WHM: preservar a pilha do painel (Apache/EasyApache, MultiPHP, AutoSSL, CSF/cPHulk se presentes). Não instalar Nginx nem Docker por cima.

Todos os comandos são **exemplos — confirmar após o diagnóstico**. A base é o
conjunto de boas práticas CIS aplicáveis a um servidor web único; não é uma
auditoria CIS completa.

## Regra zero: recuperação antes de qualquer mudança de SSH ou firewall

O responsável trabalha em um computador que **não permite abrir terminal
local**. O acesso será por: Terminal do WHM/cPanel no navegador (B), console
web/VNC do Portal do Cliente HostGator, ou cliente SSH em outro dispositivo.

Antes de tocar em SSH ou firewall, **todos** os itens precisam estar marcados:

- [ ] Confirmado no Portal do Cliente HostGator se o plano oferece console de emergência (web/VNC) **e testado o login por ele**. Se não houver, o único resgate é o suporte: registrar isso e redobrar a cautela.
- [ ] Duas sessões abertas ao mesmo tempo: a de trabalho e uma segunda, que só é fechada depois de validar a mudança em uma terceira conexão nova.
- [ ] Reversão automática agendada antes de aplicar a regra (exemplos abaixo).
- [ ] Cópia do arquivo original (`cp -a arquivo arquivo.orig-AAAAMMDD`).
- [ ] Senha de root/administrativa guardada no cofre de senhas (o console de emergência pede senha, não chave).

```bash
# EXEMPLOS — confirmar após o diagnóstico
# Reversão automática de firewall (cancelar com `systemctl stop` do timer após validar)
sudo systemd-run --on-active=10min --unit=fw-rollback ufw disable            # A, Debian/Ubuntu
sudo systemd-run --on-active=10min --unit=fw-rollback firewall-cmd --reload  # A, Alma/Rocky: regras sem --permanent somem no reload
# B (CSF): manter TESTING = "1" em /etc/csf/csf.conf até validar — o CSF limpa as regras sozinho a cada 5 min

# SSH: sempre validar a sintaxe e usar reload (não derruba sessões abertas)
sudo sshd -t && sudo systemctl reload ssh     # Debian/Ubuntu (serviço "ssh")
sudo sshd -t && sudo systemctl reload sshd    # Alma/Rocky/cPanel (serviço "sshd")
```

## Ordem obrigatória para SSH

1. Criar a conta administrativa alternativa com sudo e instalar a chave pública nela.
2. Abrir **nova** conexão com a chave e executar `sudo -v`. Só então seguir.
3. Desativar login por senha (`PasswordAuthentication no`). Validar em nova conexão.
4. Só depois desativar root remoto (`PermitRootLogin no`). Validar em nova conexão.

No cenário B, fazer pelo WHM (*Manage root's SSH Keys*, *SSH Password
Authorization Tweak*) em vez de editar arquivos à mão, e lembrar que o Terminal
do WHM continua funcionando pela porta 2087 mesmo que o SSH falhe.

## Checklist

### 1. Sistema e atualizações

| # | Item | Exemplo (confirmar após o diagnóstico) | Risco de bloqueio / como reverter |
|---|---|---|---|
| 1.1 | Rodar o diagnóstico e arquivar o resultado | `bash diagnostico_vps.sh > diagnostico.txt 2>&1` | Nenhum (somente leitura) |
| 1.2 | Aplicar atualizações pendentes | A Deb: `apt update && apt upgrade` · A Alma: `dnf upgrade` · B: WHM › *Update Server Software* | Baixo. Kernel novo exige reinício: agendar janela e ter o console de emergência testado |
| 1.3 | Atualizações de segurança automáticas | A Deb: `unattended-upgrades` · A Alma: `dnf-automatic` (somente `security`) · B: política de atualização do próprio cPanel | Baixo. Reverter desativando o timer/serviço |
| 1.4 | Fuso e sincronização de hora (NTP) | `timedatectl set-timezone America/Sao_Paulo`; `timedatectl set-ntp true` (ou `chrony`) | Nenhum. Hora errada invalida TOTP/MFA e a ordem dos eventos de auditoria |
| 1.5 | Remover/desativar serviços não usados | `systemctl list-units --type=service --state=running`; `systemctl disable --now SERVICO` | Médio. Em B, **não** desativar serviços do painel (cpsrvd, exim, named etc.) sem análise. Reverter com `enable --now` |
| 1.6 | Sem repositórios de terceiros desconhecidos | Só repositórios oficiais da distribuição (e do cPanel em B). Se o PHP ≥ 8.3 não existir neles, **parar** e decidir com o responsável | — |

### 2. Usuários e privilégios

| # | Item | Exemplo | Risco de bloqueio / como reverter |
|---|---|---|---|
| 2.1 | Conta administrativa nominal com sudo | A Deb: `adduser NOME && usermod -aG sudo NOME` · A Alma: `useradd -m NOME && usermod -aG wheel NOME` | Nenhum ao criar. Validar `sudo -v` antes do item 3.3 |
| 2.2 | Usuário de deploy (dono do código, sem sudo geral) | A: `useradd -m -s /bin/bash deploy` · B: a própria conta cPanel | Nenhum |
| 2.3 | Usuário web/worker (PHP-FPM e filas), sem shell | A: `useradd -r -s /usr/sbin/nologin soc-web` · B: PHP roda como a conta cPanel (limitação do painel) | Nenhum |
| 2.4 | Nada da aplicação roda como root | Composer, `artisan`, workers e cron sob `deploy`/`soc-web` | — |
| 2.5 | Sudo mínimo para o deploy | Arquivo em `/etc/sudoers.d/` liberando só `systemctl restart sociometria-worker` e `reload` do PHP-FPM; validar com `visudo -c` | Médio: sudoers inválido quebra o sudo. Sempre `visudo -cf ARQUIVO` antes; reverter pelo console de emergência |
| 2.6 | Logs de autenticação e de sudo preservados | Deb: `/var/log/auth.log` · Alma/cPanel: `/var/log/secure`; `journalctl _COMM=sudo` | Nenhum |

### 3. SSH

| # | Item | Exemplo | Risco de bloqueio / como reverter |
|---|---|---|---|
| 3.1 | Chave por pessoa (ed25519), com passphrase | Gerada no dispositivo que fará o acesso; só a pública vai ao servidor | Nenhum |
| 3.2 | Restringir quem entra | `AllowUsers NOME deploy` em `/etc/ssh/sshd_config.d/10-sociometria.conf` | **Alto**: nome errado bloqueia todos. Manter segunda sessão; reverter apagando o arquivo e `reload` |
| 3.3 | Desativar senha | `PasswordAuthentication no`, `KbdInteractiveAuthentication no` | **Alto**. Só após validar a chave em nova conexão. Reverter pelo console de emergência |
| 3.4 | Desativar root remoto | `PermitRootLogin no` | **Alto**. Só após 2.1 validado. Em B, avaliar `prohibit-password`: algumas rotinas do painel/suporte usam root por chave |
| 3.5 | Limites | `MaxAuthTries 3`, `LoginGraceTime 30`, `X11Forwarding no` | Baixo |
| 3.6 | Porta | Manter a 22 no piloto. Trocar a porta não é controle de segurança e aumenta o risco de bloqueio | — |

### 4. Firewall

Política: negar entrada por padrão; liberar apenas o necessário.

| Porta | Cenário A | Cenário B |
|---|---|---|
| 22 (SSH) | Aberta; restringir por IP se houver IP fixo | Idem |
| 80, 443 | Abertas | Abertas |
| 2083 (cPanel), 2087 (WHM) | — | **Não fechar sem análise.** Restringir por IP (CSF `csf.allow` / WHM *Host Access Control*); são o caminho do Terminal web |
| 2082, 2086 (painel sem TLS) | — | Forçar redirecionamento para TLS no WHM *Tweak Settings* |
| 3306 (MySQL/MariaDB) | **Nunca pública**: `bind-address = 127.0.0.1` ou só socket | **Nunca pública**; desativar "Remote MySQL" se não houver uso |
| 6379 (Redis, se vier a existir) | **Nunca pública**: `bind 127.0.0.1` + senha | Idem |
| E-mail (25, 465, 587, 993...) | Fechadas (não há servidor de e-mail) | Analisar: o painel pode depender delas |

| # | Item | Exemplo | Risco de bloqueio / como reverter |
|---|---|---|---|
| 4.1 | Agendar a reversão automática | Ver "Regra zero" | — |
| 4.2 | Liberar SSH **antes** de ativar | A Deb: `ufw allow 22/tcp; ufw allow 80,443/tcp; ufw enable` · A Alma: `firewall-cmd --add-service={ssh,http,https}` (sem `--permanent` até validar) | **Alto**. Reversão automática + console de emergência |
| 4.3 | Validar em conexão nova e só então persistir | A Alma: `firewall-cmd --runtime-to-permanent`; cancelar o timer `fw-rollback` | — |
| 4.4 | B: usar o firewall já presente | Se houver CSF, configurar por ele (WHM › *ConfigServer Security & Firewall*). **Não** instalar ufw/firewalld em paralelo | **Alto**: dois firewalls se anulam ou bloqueiam o painel |
| 4.5 | Conferir de fora | Varredura de portas a partir de outra rede; 3306/6379 devem aparecer fechadas | Nenhum |

### 5. Força bruta

| # | Item | Exemplo | Risco de bloqueio / como reverter |
|---|---|---|---|
| 5.1 | A: fail2ban com jail `sshd` | Deb: `apt install fail2ban` · Alma: pacote vem do EPEL — confirmar com o responsável antes de habilitar o repositório | Médio: pode banir o próprio IP. Cadastrar `ignoreip`; desbanir com `fail2ban-client set sshd unbanip IP` |
| 5.2 | B: cPHulk (e LFD, se houver CSF) | WHM › *cPHulk Brute Force Protection*; incluir o IP do responsável na lista de permitidos **antes** de ativar | Médio: bloqueio do WHM. Liberar pelo console: `whmapi1 flush_cphulk_login_history_for_ips ip=IP` |
| 5.3 | Aplicação | Já existe no código: `throttle` nas rotas de login e MFA obrigatório por padrão (`SECURITY_MFA_REQUIRED`) | — |

### 6. Banco de dados

- [ ] `mysql_secure_installation`/`mariadb-secure-installation` (A): remover usuários anônimos e o banco `test`.
- [ ] root do banco apenas por socket local, usado por operador humano. **Nunca** no `.env`.
- [ ] Contas da aplicação conforme o código: runtime do controle, provisionador (`CREATE USER` + bancos com o prefixo de tenant) e uma conta por tenant só com `SELECT, INSERT, UPDATE, DELETE` (criada pelo provisionamento). Em produção, o runtime do controle deve ter privilégio apenas no banco de controle.
- [ ] Senhas longas e aleatórias, geradas no servidor, guardadas no cofre.
- [ ] B: decidir como o provisionador convive com o cPanel (bancos criados por SQL direto não aparecem no painel nem nos backups do painel). Ver `BACKUP_RESTAURACAO.md`.

## Verificação final

- [ ] Nova conexão SSH por chave funciona; por senha e como root é recusada.
- [ ] Console de emergência continua funcionando.
- [ ] Reiniciar a VPS em janela combinada e conferir que firewall, banco, web e NTP voltam sozinhos.
- [ ] Rodar o diagnóstico de novo e guardar junto do primeiro (antes/depois).
- [ ] Registrar data, quem executou e quais itens ficaram pendentes.
