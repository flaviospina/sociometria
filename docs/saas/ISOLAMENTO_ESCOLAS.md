# Isolamento entre escolas da mesma instituição

Uma instituição pode ter várias escolas. **Todas compartilham o mesmo banco de tenant.** A separação entre escolas e turmas é **lógica**, feita pela aplicação em `app/Services/SchoolAccess.php`; não há barreira física (banco, credencial ou chave) entre escolas da mesma instituição. O isolamento entre instituições é tratado em `docs/security/ISOLAMENTO_TENANTS.md`.

## Estado

### Implementado e testado

| Item | Código | Evidência (`tests/Feature/Tenancy/HttpIsolationTest.php`) |
|---|---|---|
| Administrador institucional vê todas as escolas da própria instituição (e só dela) | `SchoolAccess::institutionWide()`, `visibleSchools()` | `test_institution_admin_sees_only_own_institution_schools` |
| Diretor com escopo em uma escola não alcança outra escola da mesma instituição | `SchoolAccess::canViewSchool()` | `test_director_cannot_access_other_school_of_same_institution` |
| Professor alcança apenas a turma atribuída | `SchoolAccess::canAccessClass()`, `visibleClasses()` | `test_teacher_reaches_only_assigned_class` |
| Trocar identificadores na URL não expõe recursos fora do escopo (404) | `SchoolController::findVisible()`, `StudentController::findAccessible()` | `test_changing_identifiers_in_url_never_exposes_another_institution` |
| Vínculo revogado perde acesso na requisição seguinte | `TenantResolver` | `test_revoked_membership_loses_access_immediately` |
| Listagem de alunos sempre auditada, sem nomes no log | `StudentController::index()` | `ResolverAndQuotaTest::test_student_created_over_http_is_stored_encrypted_and_never_in_audit_log` |

### Projetado, ainda não implementado

| Item | Etapa |
|---|---|
| Endpoints para gerir `staff_scopes` (hoje só criados por seeder/teste: `database/seeders/DemoScenarioBuilder.php`) | 4 |
| Endpoints para criar/editar turmas, anos letivos e matrículas; editar/inativar escola ou aluno | 4 |
| Convite de profissionais pelo administrador institucional (o serviço existe; só é acionado no provisionamento) | 4 |
| Regras de escopo para questionários, resultados sociométricos e relatórios | 5–8 |
| Policies/Gates do Laravel (a autorização hoje é feita por chamadas explícitas a `SchoolAccess` nos controladores) | 4 |

## Modelo de dados do escopo

- **Papel** (`memberships.role`, banco de controle): `institution_admin`, `director`, `coordinator`, `counselor`, `teacher` (`app/Enums/Role.php`). Um usuário tem no máximo um vínculo por instituição (`unique(user_id, institution_id)`).
- **Escopo** (`staff_scopes`, banco do tenant): `(user_id, school_id, class_group_id)`. `class_group_id = NULL` significa a escola inteira.

| Papel | Alcance | `canManageStructure()` |
|---|---|---|
| `institution_admin` | Todas as escolas, sem precisar de `staff_scopes` | sim |
| `director` | Só o que constar em `staff_scopes` | sim |
| `coordinator` | Só o que constar em `staff_scopes` | sim |
| `counselor` | Só o que constar em `staff_scopes` | não |
| `teacher` | Só o que constar em `staff_scopes` | não |

O papel não define sozinho o alcance: um `director` sem linha em `staff_scopes` não vê nenhuma escola. Sem vínculo no contexto (jobs de serviço), `SchoolAccess` nega tudo (`whereRaw('1 = 0')`).

## Regras por endpoint

| Endpoint | Regra aplicada |
|---|---|
| `GET /app/schools` | `visibleSchools()`: todas (admin) ou escolas com qualquer escopo do usuário |
| `GET /app/schools/{school}` | `canViewSchool()`; senão 404 |
| `POST /app/schools` | Somente `institutionWide()` (403 para os demais) + quota `max_schools` |
| `GET /app/schools/{school}/classes` | `canViewSchool()` e depois `visibleClasses()`: todas as turmas se houver escopo de escola inteira; senão só as turmas atribuídas |
| `GET /app/classes/{class}/students` | `canAccessClass()`; senão 404; gera `students.listed` na auditoria |
| `POST /app/classes/{class}/students` | `canAccessClass()` (404) + `canManageStructure()` (403) + quota `max_active_students` |

Decisões de implementação:

- Recursos são buscados explicitamente por `public_id` (ULID) depois do middleware de tenant; não há route model binding implícito.
- "Não existe" e "fora do escopo" retornam o mesmo 404.
- `id`, `school_id` e `school_year_id` internos ficam em `$hidden` nos modelos; as respostas expõem apenas `public_id`.

## Limites e riscos residuais

1. **Separação lógica, não física.** Professores e diretores de escolas diferentes da mesma instituição usam a mesma conexão e a mesma credencial de banco. Um controlador novo que consulte `School`, `ClassGroup` ou `Student` sem passar por `SchoolAccess` expõe dados de todas as escolas da instituição. Não há global scope que imponha o filtro por escola.
2. **Mesma chave de dados.** A DEK é por instituição; todas as escolas dela usam a mesma chave.
3. **Quem tem escopo de uma única turma enxerga o nome da escola** (`visibleSchools()` inclui escolas com qualquer escopo) e as turmas atribuídas, não as demais.
4. **Ordenação por nome em memória.** `StudentController::index()` carrega os alunos da turma e ordena com `sortBy('name')` depois de decifrar, porque `students.name` é cifrado e não pode ser ordenado nem pesquisado por SQL. Não existe busca de aluno por nome; quando existir, terá o mesmo custo (ou exigirá índice cego, ainda não projetado em código).
5. **Sem paginação** na listagem de alunos de uma turma.
6. **Aluno pertence a uma escola** (`students.school_id`) e é ligado a turmas por `enrollments`; não há verificação de que a turma da matrícula seja da mesma escola além do que o controlador faz ao criar (`school_id` copiado da turma).
7. **`staff_scopes` com `class_group_id` NULL**: a restrição `unique(user_id, school_id, class_group_id)` não impede linhas duplicadas de "escola inteira" (valores NULL não colidem em índices únicos do MySQL/MariaDB). Sem efeito sobre a autorização, mas relevante para a futura tela de gestão.
8. **Cobertura de testes por papel**: os testes exercitam `institution_admin`, `director` e `teacher`. `coordinator` e `counselor` não têm teste próprio.
