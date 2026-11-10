#!/usr/bin/env bash
# scripts/infra/diagnostico_compartilhado.sh
#
# Diagnóstico NÃO DESTRUTIVO para hospedagem COMPARTILHADA com cPanel
# (ex.: HostGator plano Turbo). Roda como o usuário da conta, sem root.
# Só executa consultas: não cria banco, não instala nada, não altera
# configuração. Não imprime senhas.
#
# Uso (no Terminal do cPanel):
#   bash ~/diagnostico_compartilhado.sh > ~/diagnostico.txt 2>&1
#
# O relatório contém o nome da conta cPanel, domínios e nomes de bancos
# existentes. Revise antes de compartilhar.

set -u
export LC_ALL=C

sec() { printf '\n===== %s =====\n' "$1"; }
has() { command -v "$1" >/dev/null 2>&1; }
api() { # chamada somente leitura à UAPI do cPanel
  if has uapi; then uapi --output=json "$@" 2>&1 | head -c 6000; echo; else echo "(uapi indisponivel)"; fi
}

sec "1. Conta e ambiente"
echo "data_utc: $(date -u +%FT%TZ)"
echo "usuario:  $(id -un)"
echo "home:     $HOME"
echo "shell:    ${SHELL:-?}"
echo "hostname: $(hostname 2>/dev/null)"
uname -srm
[ -r /etc/os-release ] && grep -E '^(NAME|VERSION)=' /etc/os-release
[ -d /var/cagefs ] || [ -e /usr/sbin/cagefsctl ] && echo "CageFS/CloudLinux: provavel" || true
[ -r /usr/local/cpanel/version ] && echo "cPanel: $(cat /usr/local/cpanel/version)"

sec "2. Limites da conta (processos, memoria, arquivos)"
echo "max processos:        $(ulimit -u 2>/dev/null)"
echo "memoria virtual (kB): $(ulimit -v 2>/dev/null)"
echo "arquivos abertos:     $(ulimit -n 2>/dev/null)"
echo "nucleos visiveis:     $(nproc 2>/dev/null)"
has free && free -m 2>/dev/null | head -2

sec "3. Espaco em disco e inodes da conta"
has quota && quota -s 2>/dev/null | tail -4
echo "uso do home: $(du -sh "$HOME" 2>/dev/null | cut -f1)"
api StatsBar get_stats display='diskusage|inodesusage|sqldatabases|subdomains|addondomains|bandwidthusage'

sec "4. PHP na linha de comando"
if has php; then
  php -v 2>&1 | head -1
  echo "binario: $(command -v php)"
  php -r 'echo "memory_limit=",ini_get("memory_limit")," max_execution_time=",ini_get("max_execution_time"),PHP_EOL;'
  php -r 'echo "disable_functions=",(ini_get("disable_functions") ?: "(vazio)"),PHP_EOL;'
  echo "extensoes necessarias presentes:"
  for e in pdo_mysql mbstring openssl sodium intl ctype tokenizer xml dom fileinfo zip curl bcmath gd pcntl posix; do
    php -r "echo extension_loaded('$e') ? '  ok      ' : '  FALTA   ';" 2>/dev/null; echo "$e"
  done
else
  echo "php nao encontrado no PATH"
fi

sec "5. Versoes de PHP instaladas no servidor"
ls -d /opt/cpanel/ea-php*/root/usr/bin/php 2>/dev/null
ls -d /opt/alt/php*/usr/bin/php 2>/dev/null
for p in /opt/cpanel/ea-php8[3-9]/root/usr/bin/php /opt/alt/php8[3-9]/usr/bin/php; do
  [ -x "$p" ] && echo "$p -> $("$p" -v 2>/dev/null | head -1)"
done
echo "--- versoes oferecidas pelo painel:"
api LangPHP php_get_installed_versions
echo "--- versao por dominio:"
api LangPHP php_get_vhost_versions

sec "6. Banco de dados"
has mysql && mysql --version
has mariadb && mariadb --version
echo "--- servidor:"
api Mysql get_server_information
echo "--- restricoes de nomes (prefixo e tamanho maximo):"
api Mysql get_restrictions
echo "--- bancos existentes na conta:"
api Mysql list_databases
echo "--- usuarios de banco existentes:"
api Mysql list_users

sec "7. Ferramentas"
for t in composer git node npm curl wget unzip zip tar crontab gpg openssl rsync; do
  if has "$t"; then printf '  ok      %s (%s)\n' "$t" "$(command -v "$t")"; else printf '  FALTA   %s\n' "$t"; fi
done
has composer && composer --version 2>/dev/null | head -1
has git && git --version

sec "8. Agendamento (cron)"
if has crontab; then
  echo "linhas de cron existentes: $(crontab -l 2>/dev/null | grep -vcE '^\s*(#|$)')"
else
  echo "crontab indisponivel no terminal (verificar 'Tarefas Cron' no cPanel)"
fi

sec "9. Dominios e raizes de documento"
api DomainInfo list_domains
ls -ld "$HOME/public_html" 2>/dev/null

sec "10. Saida de rede (necessaria para Composer e e-mail)"
if has curl; then
  for u in https://repo.packagist.org/packages.json https://api.github.com; do
    printf '  %s -> HTTP %s\n' "$u" "$(curl -s -o /dev/null -m 10 -w '%{http_code}' "$u" 2>/dev/null)"
  done
fi

sec "11. Processos persistentes"
echo "processos da conta agora: $(ps -u "$(id -un)" 2>/dev/null | wc -l)"
has supervisord && echo "supervisor: presente" || echo "supervisor: ausente (esperado em compartilhado)"
has screen && echo "screen: presente" || true
has tmux && echo "tmux: presente" || true

sec "FIM - nada foi alterado na conta"
echo "Confirmar no Portal do Cliente HostGator (o script nao descobre):"
echo " - limite de bancos MySQL do plano e de tarefas cron (intervalo minimo)"
echo " - se ha backup do provedor, frequencia e retencao"
echo " - localizacao do datacenter"
