#!/usr/bin/env bash
# scripts/dev/setup_local_db.sh
#
# Prepara o MySQL/MariaDB LOCAL (desenvolvimento e testes) com as três
# identidades de banco usadas pela aplicação. NUNCA executar em produção:
# as senhas abaixo são fixas, públicas e servem somente para dados fictícios.
#
#   soc_control      -> runtime do banco de controle (somente sociometria_control*)
#   soc_provisioner  -> cria bancos/usuários de tenants e executa migrations
#                       institucionais; NÃO enxerga o banco de controle
#   (tenants)        -> criados pelo provisionamento, um usuário por instituição
#
# Uso: bash scripts/dev/setup_local_db.sh   (requer acesso root local ao banco)

set -euo pipefail

CLIENT="$(command -v mariadb || command -v mysql)"
HOST_PATTERN="${DB_USER_HOST:-localhost}"

"$CLIENT" -uroot <<SQL
CREATE DATABASE IF NOT EXISTS sociometria_control      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS sociometria_control_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'soc_control'@'${HOST_PATTERN}' IDENTIFIED BY 'dev-only-control';
GRANT ALL PRIVILEGES ON \`sociometria\_control\`.*       TO 'soc_control'@'${HOST_PATTERN}';
GRANT ALL PRIVILEGES ON \`sociometria\_control\_test\`.* TO 'soc_control'@'${HOST_PATTERN}';

CREATE USER IF NOT EXISTS 'soc_provisioner'@'${HOST_PATTERN}' IDENTIFIED BY 'dev-only-provisioner';
GRANT CREATE USER ON *.* TO 'soc_provisioner'@'${HOST_PATTERN}';
-- soc_t_  = tenants de desenvolvimento; soc_tt_ = tenants criados pelos testes
GRANT ALL PRIVILEGES ON \`soc\_t\_%\`.*  TO 'soc_provisioner'@'${HOST_PATTERN}' WITH GRANT OPTION;
GRANT ALL PRIVILEGES ON \`soc\_tt\_%\`.* TO 'soc_provisioner'@'${HOST_PATTERN}' WITH GRANT OPTION;
FLUSH PRIVILEGES;
SQL

echo "Bancos e usuários locais prontos (host dos usuários: ${HOST_PATTERN})."
