# Diagnóstico da VPS HostGator

## Estado

| Item | Situação |
|---|---|
| Acesso SSH à VPS nesta fase | **Não disponível** — nenhum comando foi executado no servidor |
| Script de diagnóstico somente leitura | Pronto: `scripts/infra/diagnostico_vps.sh` |
| Resultado do diagnóstico | **Pendente** — tabela da seção 3 ainda vazia |
| Escolha do cenário de instalação (A sem painel / B com cPanel) | **Pendente** — depende do resultado |

Nada foi instalado, alterado ou consultado na VPS. Este documento contém o
procedimento e o checklist; os resultados serão preenchidos quando o relatório
do script for enviado.

## 1. Como executar o diagnóstico (não altera nada)

O script só usa comandos de consulta: não instala pacotes, não edita arquivos,
não reinicia serviços e não toca em firewall ou SSH. Não imprime senhas nem
chaves. O relatório contém nomes de host, IPs, contas e portas — revise antes
de compartilhar.

**Se a VPS tem WHM/cPanel** (não exige terminal no seu computador):

1. Entre no WHM (`https://IP-DA-VPS:2087`) como root.
2. No menu, abra **Terminal** (em *Server Configuration*).
3. Envie o arquivo `scripts/infra/diagnostico_vps.sh` pelo **Gerenciador de
   Arquivos** do cPanel, ou cole o conteúdo em um arquivo novo:
   `nano /root/diagnostico_vps.sh`
4. Execute: `bash /root/diagnostico_vps.sh > /root/diagnostico.txt 2>&1`
5. Baixe `/root/diagnostico.txt` e envie o conteúdo.

**Se a VPS não tem painel:** use o console web do Portal do Cliente HostGator
(acesso de emergência/VNC da VPS) ou um cliente SSH em outro dispositivo, e
execute os mesmos passos 3 a 5.

Executar como root dá o relatório completo (configuração efetiva do SSH e
regras de firewall). Como usuário comum o script funciona, com menos detalhes.

## 2. Itens que o script não descobre

Consultar no Portal do Cliente HostGator e anotar na seção 3:

- Plano/modelo contratado da VPS e se é gerenciada ou não.
- Localização do datacenter (relevante para a análise de LGPD da instituição).
- Se há backup do provedor contratado, com que frequência e retenção
  (**não presumir que existe**).
- Política de atualização do provedor (quem aplica correções do SO e do painel).
- Se existe console de emergência (necessário antes de qualquer mudança de
  SSH ou firewall).

## 3. Resultado (a preencher)

| # | Item | Resultado | Origem |
|---|---|---|---|
| 1 | Modelo e plano da VPS | pendente | Portal do Cliente |
| 2 | CPU / RAM / disco | pendente | script §3 |
| 3 | Sistema operacional e versão | pendente | script §2 |
| 4 | Painel (cPanel/WHM, Plesk, outro) | pendente | script §5 |
| 5 | Servidor HTTP | pendente | script §6 |
| 6 | Versões de PHP disponíveis | pendente | script §7 |
| 7 | MySQL ou MariaDB e versão | pendente | script §8 |
| 8 | Acesso root e contas SSH | pendente | script §10–11 |
| 9 | Firewall ativo | pendente | script §12 |
| 10 | Serviços publicados (portas) | pendente | script §13 |
| 11 | Certificados TLS | pendente | script §15 |
| 12 | Recursos de backup | pendente | script §17 + Portal |
| 13 | Processos persistentes (systemd/Supervisor) | pendente | script §16 |
| 14 | Monitoramento existente | pendente | script §17 |
| 15 | Localização geográfica | pendente | Portal do Cliente |
| 16 | Política de atualizações do provedor | pendente | Portal do Cliente |

## 4. Critérios de decisão após o diagnóstico

| Achado | Consequência |
|---|---|
| cPanel/WHM presente | **Cenário B**: manter a pilha do painel (Apache/EasyApache, MultiPHP, AutoSSL). Não instalar Nginx nem Docker por cima |
| Sem painel | **Cenário A**: Nginx + PHP-FPM + systemd, instalados dos repositórios oficiais da distribuição |
| PHP 8.3+ indisponível | **Bloqueante**: o Laravel 13 exige PHP ≥ 8.3. No cPanel, habilitar `ea-php83`/`ea-php84` pelo EasyApache; sem painel, verificar a versão empacotada pela distribuição |
| MySQL < 8.0 e MariaDB < 10.6 | Atualizar antes da instalação (planejar com backup); a aplicação foi testada em MariaDB 10.11 |
| Conta de banco sem privilégio `CREATE USER` (só via painel) | O provisionamento automático de instituições exige uma conta provisionadora com `CREATE USER` e `GRANT` sobre o prefixo dos tenants. Em VPS com root isso é possível; se o painel impedir, reavaliar |
| OpenVZ/Virtuozzo com limites de contêiner | Rever dimensionamento de workers e conexões |
| RAM < 4 GB | Aceitável para piloto pequeno; sem Redis, filas e cache em banco (configuração atual) |
| Sem console de emergência | Não alterar SSH/firewall até existir um caminho de recuperação testado |
| 3306 ou 6379 escutando em interface pública | Corrigir no hardening antes de qualquer dado real |

## 5. Compatibilidade verificada no ambiente de desenvolvimento

Estes valores são do contêiner de desenvolvimento, **não da VPS**:

| Componente | Versão usada nos testes |
|---|---|
| PHP | 8.3.6 |
| Laravel | 13.40.0 (exige PHP ≥ 8.3) |
| MariaDB | 10.11.14 |
| Extensões PHP usadas | pdo_mysql, mbstring, openssl, sodium, intl, ctype, tokenizer, xml, fileinfo |
