# Planos e quotas

Como limites comerciais são armazenados e o que é efetivamente aplicado hoje. Resumo franco: **apenas `max_schools` e `max_active_students` são verificados**. Os demais campos existem na tabela e não têm efeito.

## Estado

### Implementado e testado

| Item | Código | Evidência |
|---|---|---|
| Tabela `plans` e vínculo `institutions.plan_id` | `database/migrations/2026_11_10_000001_create_control_plane_tables.php`, `app/Models/Control/Plan.php` | — |
| Limite de escolas ativas | `QuotaService::assertCanCreateSchool()` em `SchoolController::store()` | `ResolverAndQuotaTest::test_plan_limits_are_enforced_by_backend_and_cannot_be_changed_by_request` |
| Limite de alunos ativos | `QuotaService::assertCanCreateStudent()` em `StudentController::store()` | mesmo teste |
| Parâmetros da requisição não alteram plano nem limite | validação aceita só campos do recurso; `Plan` e `Institution` usam `$guarded = ['*']` | mesmo teste (envia `max_schools`, `plan`, `plan_id` e confere que nada muda) |
| Suspensão/reativação manual de instituição | `Admin\InstitutionController::suspend()/reactivate()` | `HttpIsolationTest::test_suspending_one_institution_does_not_block_the_others` |

Implementado **sem teste automatizado**:

- `Institution::limit()` com precedência `limit_overrides` > plano > sem limite;
- limitador de requisições `throttle:tenant` (`AppServiceProvider`);
- teto de conexões de banco por tenant (`MAX_USER_CONNECTIONS`, aplicado em `TenantDatabaseAdmin::createRuntimeUser()`).

### Projetado, ainda não implementado

| Item | Etapa |
|---|---|
| Verificação de `max_staff` (convites/vínculos) | 4 |
| Verificação de `max_applications_per_year` | 5 |
| Verificação de `storage_mb` (não há uploads) | 8 |
| Uso de `features` (ex.: `longitudinal_history`) para liberar funcionalidades | 6–8 |
| Avaliação de `subscription_status` (o campo existe, é exibido no painel, mas nenhum código decide acesso com base nele) | 10 |
| Módulo de pagamento/cobrança | fora das etapas 1–10 atuais; sem data |
| CRUD de planos, troca de plano e edição de `limit_overrides` por API (hoje só por acesso direto ao banco de controle) | 4 |
| Painel de consumo por instituição | 7 |
| Suspensão automática por inadimplência | 10 |

## Estrutura de dados

Tabela `plans` (banco de controle):

| Coluna | Tipo | Aplicado? |
|---|---|---|
| `code` (único), `name` | texto | identificação |
| `max_schools` | inteiro, nulo = sem limite | **sim** |
| `max_active_students` | inteiro, nulo = sem limite | **sim** |
| `max_staff` | inteiro | não |
| `max_applications_per_year` | inteiro | não |
| `storage_mb` | inteiro | não |
| `features` | JSON | não |

Em `institutions`: `plan_id` (opcional), `limit_overrides` (JSON, opcional), `subscription_status` (`trial` por padrão; valores previstos `trial|active|past_due|canceled`; não avaliado).

Não há seeder de planos: a tabela começa vazia. Instituição sem plano e sem override **não tem limite algum**.

## Como o limite é calculado

`Institution::limit($key)`:

1. se `limit_overrides[$key]` existir, usa esse valor;
2. senão, usa `plans.<key>` do plano da instituição;
3. senão, `null` (sem limite).

`QuotaService::assertBelow()` conta os registros `status = 'active'` no banco do tenant (`schools` ou `students`) e, se `contagem >= limite`, lança HTTP **402** com a mensagem "Limite do plano contratado atingido.".

A instituição usada no cálculo vem de `TenantContext` (carregada do banco de controle pelo `TenantResolver` a cada requisição, com `institution.plan`). Mudança de plano ou override no banco de controle vale na requisição seguinte.

## Onde a verificação acontece

| Operação | Verificação |
|---|---|
| `POST /app/schools` | `max_schools` |
| `POST /app/classes/{class}/students` | `max_active_students` |
| Qualquer outra | nenhuma quota |

Criações feitas fora desses dois endpoints (seeders, `DemoScenarioBuilder`, futuros importadores ou jobs) **não passam por `QuotaService`** a menos que o chamem explicitamente.

## Isolamento de consumo

| Mecanismo | Valor padrão | Configuração |
|---|---|---|
| Requisições por usuário em `/app/*` | 120/min | `RATE_LIMIT_USER_PER_MINUTE` |
| Requisições agregadas por instituição em `/app/*` | 1500/min | `RATE_LIMIT_TENANT_PER_MINUTE` |
| Conexões simultâneas de banco por tenant | 10 | `TENANT_DB_MAX_USER_CONNECTIONS` |

Ressalva sobre o teto por instituição: a chave do limitador usa a instituição **gravada na sessão** (`POST /context`). Para usuários com um único vínculo, que nunca selecionam contexto, a chave cai para `u<user_id>`, ou seja, o teto vira por usuário e não agrega a instituição. Esses limites não variam por plano.

## Suspensão

`POST /admin/institutions/{publicId}/suspend` muda o estado para `suspended` (só a partir de `active`) e registra `institution.suspended`. Efeitos: o `TenantResolver` nega novas requisições, `InTenantContext` descarta jobs e convites pendentes deixam de ser aceitos. Dados não são apagados; a conta de banco do tenant não é bloqueada. `reactivate` reverte. A decisão é sempre manual.

## Limites conhecidos

- **Condição de corrida**: contagem e inserção não são atômicas; duas criações simultâneas podem ultrapassar o limite em uma unidade.
- Reduzir um limite abaixo do uso atual não remove nem bloqueia registros existentes; apenas impede novas criações.
- Não há endpoint para inativar escola ou aluno, portanto hoje a contagem de "ativos" só cresce.
- O código 402 é devolvido ao usuário institucional sem indicar qual limite foi atingido.
