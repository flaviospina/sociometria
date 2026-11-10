# Sociometria Escolar — plataforma SaaS

Plataforma multi-institucional de sociometria escolar, com um banco de dados
independente por instituição. **Em desenvolvimento**: hoje existe a fundação de
segurança e multi-tenancy (backend, endpoints JSON); ainda não há interface,
questionários, sociogramas nem relatórios. Estado detalhado em
[`docs/PLANO_IMPLEMENTACAO.md`](docs/PLANO_IMPLEMENTACAO.md).

## Ambiente local

Requisitos: PHP 8.3+, Composer 2, MySQL 8 ou MariaDB 10.6+.

```bash
composer install
bash scripts/dev/setup_local_db.sh     # cria bancos e contas LOCAIS (senhas públicas de desenvolvimento)
cp .env.example .env                    # preencher DB_PASSWORD e TENANT_PROVISIONER_PASSWORD com as senhas locais
php artisan key:generate
php artisan migrate
php artisan test
```

Os testes criam e removem bancos reais com o prefixo `soc_tt_`. Use somente
dados fictícios neste ambiente.

## Documentação

- Arquitetura: [`docs/saas/ARQUITETURA_MULTI_TENANT.md`](docs/saas/ARQUITETURA_MULTI_TENANT.md)
- Instalação na HostGator: [`docs/infra/INSTALACAO_HOSTGATOR.md`](docs/infra/INSTALACAO_HOSTGATOR.md)
- Diagnóstico da VPS: [`docs/infra/DIAGNOSTICO_VPS_HOSTGATOR.md`](docs/infra/DIAGNOSTICO_VPS_HOSTGATOR.md)
- Segurança: [`docs/security/`](docs/security/) · Privacidade: [`docs/privacy/`](docs/privacy/)
