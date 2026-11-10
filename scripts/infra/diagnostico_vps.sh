#!/usr/bin/env bash
# scripts/infra/diagnostico_vps.sh
#
# Diagnóstico NÃO DESTRUTIVO da VPS HostGator.
# Executa exclusivamente comandos de consulta: não instala, não altera
# configuração, não reinicia serviço, não mexe em firewall.
# Não imprime senhas nem conteúdo de arquivos de configuração com segredos.
#
# Uso:  bash diagnostico_vps.sh > diagnostico.txt 2>&1
# Depois envie o diagnostico.txt (revise antes: contém IPs e nomes de host).

set -u
export LC_ALL=C

sec() { printf '\n===== %s =====\n' "$1"; }
has() { command -v "$1" >/dev/null 2>&1; }
run() { # executa se o comando existir; nunca interrompe o script
  if has "$1"; then "$@" 2>&1 | head -n "${MAXLINES:-40}"; else echo "(comando $1 indisponível)"; fi
}

sec "1. Identificação"
echo "data_utc: $(date -u +%FT%TZ)"
echo "hostname: $(hostname 2>/dev/null)"
echo "usuario:  $(id -un) (uid=$(id -u))"
run uptime

sec "2. Sistema operacional"
[ -r /etc/os-release ] && grep -E '^(NAME|VERSION|ID|ID_LIKE)=' /etc/os-release
uname -srm
[ -r /etc/redhat-release ] && cat /etc/redhat-release

sec "3. CPU, memória e disco"
echo "nucleos: $(nproc 2>/dev/null)"
grep -m1 'model name' /proc/cpuinfo 2>/dev/null
run free -m
run df -hT -x tmpfs -x devtmpfs
echo "inodes:"; run df -i /

sec "4. Virtualização"
run systemd-detect-virt
[ -e /proc/user_beancounters ] && echo "OpenVZ/Virtuozzo detectado (limites de contêiner)"

sec "5. Painel de controle"
if [ -x /usr/local/cpanel/cpanel ]; then
  echo "cPanel/WHM: SIM  versao: $(cat /usr/local/cpanel/version 2>/dev/null)"
else echo "cPanel/WHM: nao detectado"; fi
[ -d /usr/local/psa ] && echo "Plesk: SIM $(cat /usr/local/psa/version 2>/dev/null)" || echo "Plesk: nao detectado"
[ -d /usr/local/directadmin ] && echo "DirectAdmin: SIM" || echo "DirectAdmin: nao detectado"
[ -d /usr/local/CyberCP ] && echo "CyberPanel: SIM" || true

sec "6. Servidor HTTP"
for b in nginx httpd apache2 litespeed lshttpd; do has "$b" && echo "$b: $(command -v $b)"; done
has nginx && nginx -v 2>&1
has httpd && httpd -v 2>&1 | head -2
has apache2 && apache2 -v 2>&1 | head -2

sec "7. PHP"
has php && php -v 2>&1 | head -1 || echo "php (cli) nao encontrado no PATH"
ls -d /opt/cpanel/ea-php*/ 2>/dev/null | tr '\n' ' '; echo
ls -d /opt/alt/php*/ 2>/dev/null | tr '\n' ' '; echo
ls /etc/php/ 2>/dev/null | tr '\n' ' '; echo
if has php; then
  echo "extensoes relevantes:"
  php -m 2>/dev/null | grep -iE '^(bcmath|ctype|curl|dom|fileinfo|gd|intl|mbstring|openssl|pdo_mysql|redis|sodium|tokenizer|xml|zip|opcache|pcntl|posix)$' | tr '\n' ' '; echo
  php -r 'echo "memory_limit=",ini_get("memory_limit")," disable_functions=",(ini_get("disable_functions")?"(definido)":"(vazio)"),PHP_EOL;'
fi
has composer && composer --version 2>/dev/null | head -1 || echo "composer: nao encontrado"

sec "8. Banco de dados"
has mysql && mysql --version
has mariadb && mariadb --version
has mysqld && mysqld --version 2>/dev/null
has redis-server && redis-server --version || echo "redis: nao encontrado"

sec "9. Node (apenas para build; nao e necessario em producao)"
has node && node -v || echo "node: nao encontrado"

sec "10. Contas com shell de login (somente nomes)"
awk -F: '$7 !~ /(nologin|false|sync|shutdown|halt)$/ {print $1" uid="$3" shell="$7}' /etc/passwd 2>/dev/null

sec "11. SSH (configuracao efetiva, sem chaves)"
if has sshd && [ "$(id -u)" = "0" ]; then
  sshd -T 2>/dev/null | grep -E '^(port|permitrootlogin|passwordauthentication|pubkeyauthentication|allowusers|allowgroups|maxauthtries) '
else
  grep -hE '^\s*(Port|PermitRootLogin|PasswordAuthentication|PubkeyAuthentication|AllowUsers)\b' /etc/ssh/sshd_config /etc/ssh/sshd_config.d/*.conf 2>/dev/null
fi

sec "12. Firewall e protecao contra forca bruta"
for s in firewalld ufw csf lfd fail2ban nftables iptables cphulkd imunify360; do
  if has systemctl; then printf '%-12s %s\n' "$s" "$(systemctl is-active "$s" 2>/dev/null || true)"; fi
done
has csf && csf -v 2>/dev/null | head -1
has ufw && [ "$(id -u)" = "0" ] && ufw status 2>/dev/null | head -20
has firewall-cmd && firewall-cmd --list-all 2>/dev/null | head -20

sec "13. Portas em escuta (servicos publicados)"
if has ss; then ss -tulnH 2>/dev/null | awk '{print $1, $5, $7}' | sort -u | head -60
elif has netstat; then netstat -tuln 2>/dev/null | head -60; fi

sec "14. Servicos ativos"
has systemctl && systemctl list-units --type=service --state=running --no-pager --no-legend 2>/dev/null | awk '{print $1}' | head -80

sec "15. Certificados TLS"
ls /etc/letsencrypt/live/ 2>/dev/null || echo "sem /etc/letsencrypt/live (ou sem permissao)"
has certbot && certbot --version 2>&1
[ -d /var/cpanel/ssl ] && echo "cPanel AutoSSL: diretorio presente"

sec "16. Processos persistentes e agendamento"
has supervisord && supervisord -v || echo "supervisor: nao encontrado"
has systemctl && echo "systemd: $(systemctl --version | head -1)"
has crontab && echo "cron: disponivel" || echo "cron: nao encontrado"

sec "17. Backup e monitoramento existentes"
for b in restic borg rclone duplicity jetbackup jetbackup5 mysqldump mariadb-dump mariabackup xtrabackup gpg age; do
  has "$b" && echo "$b: presente"; done
[ -d /usr/local/jetapps ] && echo "JetBackup: instalado"
for m in node_exporter netdata zabbix_agentd monit; do has "$m" && echo "$m: presente"; done

sec "18. Horario e atualizacoes"
has timedatectl && timedatectl 2>/dev/null | grep -E 'Time zone|synchronized|NTP'
if has apt-get; then echo "pendentes (apt): $(apt list --upgradable 2>/dev/null | grep -c upgradable)"
  [ -f /etc/apt/apt.conf.d/20auto-upgrades ] && cat /etc/apt/apt.conf.d/20auto-upgrades
elif has dnf; then systemctl is-enabled dnf-automatic.timer 2>/dev/null
elif has yum; then systemctl is-enabled yum-cron 2>/dev/null; fi
[ -f /var/run/reboot-required ] && echo "REINICIO PENDENTE"

sec "19. Seguranca do kernel"
has getenforce && echo "SELinux: $(getenforce)"
has aa-status && [ "$(id -u)" = "0" ] && aa-status 2>/dev/null | head -2

sec "FIM — nada foi alterado no servidor"
echo "Itens que o script NAO consegue determinar (ver painel do cliente HostGator):"
echo " - plano/modelo contratado da VPS"
echo " - localizacao geografica do datacenter"
echo " - politica de backup e de atualizacoes do provedor"
