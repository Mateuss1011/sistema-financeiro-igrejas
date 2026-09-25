# SFG — Fase 13: Testes completos e segurança

Validação técnica e hardening do MVP. Nenhuma regra de negócio das Fases 2–12 foi alterada; nenhuma migration, nenhum pacote.

## 1. Vulnerabilidades e defeitos encontrados (todos reproduzidos antes de corrigir)

| # | Achado | Causa | Correção |
|---|---|---|---|
| 1 | Login sem limite de tentativas (força bruta / password spraying) | Nenhum rate limiting no projeto | 5 falhas/min por e-mail+IP (só falhas contam; zera no sucesso; 429 sem tentar autenticar nem auditar) + teto de 30/min por IP |
| 2 | Usuário desativado mantinha a sessão aberta | `ativo` só era conferido no login | Middleware `GarantirUsuarioAtivo` em todas as rotas autenticadas: 401 `USUARIO_INATIVO` e sessão invalidada |
| 3 | `POST /usuarios` de Tesoureiro/Auxiliar/Secretário devolvia **422** (validação) em vez de 403, revelando regras e e-mails já cadastrados | `authorize()` liberava quando `perfil_id` faltava/era inválido | Sem perfil válido vale o de menor privilégio: quem nem esse pode criar recebe 403 |
| 4 | `ordenar[]=x` (array) → **500** (TypeError) em auditoria, relatórios, entradas, despesas, transferências e ajustes | closure de validação rodava mesmo após `string` falhar | `bail` |
| 5 | `perfil_id[]=1` em usuários → **500** | `Perfil::find(array)` | só escalar |
| 6 | Byte nulo (`\0`) em qualquer campo `date_format` → **500** (ValueError) | comportamento do PHP/Laravel | middleware `RejeitarBytesNulos` (422 padrão) |
| 7 | `GET /usuarios?por_pagina=-1` → **500** (SQL) e valor gigante devolvia todos | parâmetro sem validação | `integer|min:1|max:100` |
| 8 | `PUT /usuarios/8abc` resolvia o usuário 8; ids gigantes em rotas de despesa → **500** | MariaDB compara `id='8abc'` como 8; `int` do PHP estoura | rotas com `whereNumber` / `[0-9]{1,18}` |
| 9 | 404/405/419/429 citavam classes internas (`No query results for model [App\Models\User] 5`) e rotas | mensagens do framework | handler padroniza `{message, code, errors}` genérico |
| 10 | 500 sem formato padrão; com `APP_DEBUG=true` vazava trace | handler padrão | resposta genérica `ERRO_INTERNO` (também em produção com debug ligado por engano); detalhe só no log técnico |
| 11 | Sem cabeçalhos de segurança, sem HTTPS obrigatório, cookie de sessão sem `Secure` por padrão em produção | inexistentes | `CabecalhosDeSeguranca` (global + respostas de exceção), `ExigirHttpsEmProducao`, HSTS, `URL::forceHttps`, `session.secure` = produção |
| 12 | CORS com métodos/cabeçalhos `*` e origem fixa no código | config | listas explícitas; origens por `CORS_ALLOWED_ORIGINS` (padrão localhost:5173) |
| 13 | Login de origem sem sessão (sem Origin do SPA) com credencial certa → **500** | `$request->session()` inexistente | 403 `ORIGEM_NAO_PERMITIDA`, antes de olhar credenciais |
| 14 | **Corrida real**: dois Pastores se desativando/rebaixando ao mesmo tempo → sistema **sem Pastor ativo** (ou deadlock 500) | regra do último Pastor sem trava; agravado porque a subconsulta em `perfis` fixava o snapshot antes da espera do lock | `FOR UPDATE` nos Pastores ativos (ordem de id) + releitura sob trava (leitura atual, não `refresh()`) |
| 15 | Cadastros simultâneos do mesmo e-mail → QueryException (500) | índice único sem tratamento | vira `ValidationException` (422) |
| 16 | Senha de cadastro sem `max` (senha > 255 nunca conseguiria logar) | regra | `max:255` igual ao login |
| 17 | `AuditLog` alterável/excluível por Eloquent | sem guarda | `updating`/`deleting` lançam `LogicException` |
| 18 | `.env.example` era o modelo padrão do Laravel (sqlite, debug ligado) | — | modelo seguro para produção, sem segredos |

## 2. Decisões

- **Rate limiting** (`AppServiceProvider::definirLimitesDeRequisicao`): login (falhas 5/min por e-mail+IP; 30/min por IP), exportações 10/min por usuário, consultas agregadas (dashboard, relatórios, auditoria) 60/min por usuário com cota compartilhada. Escritas comuns **sem** throttle (idempotência, locks e regras já as protegem). Bloqueio de login não é auditado (evita inflar `audit_logs`).
- **Cabeçalhos**: `no-store` em toda a API (dados financeiros por usuário), CSP `default-src 'none'`, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy: no-referrer`.
- **HTTPS em produção**: GET/HEAD → 308 para `https://{host de APP_URL}`; demais métodos → 426 (o corpo já teria trafegado em claro). `/up` e `/api/v1/health` isentos. Atrás de proxy: `TRUSTED_PROXIES`.
- **Auditoria**: proteção no banco (usuário do MariaDB sem UPDATE/DELETE em `audit_logs`) fica para a Fase 14 (deploy) — não foi criada migration/trigger.
- **XSS**: a API devolve texto em JSON (nunca HTML); o React escapa o que renderiza (verificado no E2E com cargas reais); nada de `dangerouslySetInnerHTML`/`innerHTML`/`localStorage` no frontend.

## 3. Ajustes em testes existentes (incompatibilidades objetivas, não mascaram falha)

- `FiltrosDeRelatoriosTest::test_mes_futuro_...`: dispara 20 exportações em sequência; agora limpa a cota por minuto entre elas.
- Roteiro E2E da Fase 10 (fora do projeto): número de ações do catálogo 16 → 17 (Fase 12) — já registrado no relatório da Fase 12.

## 4. Testes novos (`tests/Feature/Seguranca/`)

`MatrizDeAutorizacaoTest`, `AutenticacaoESessaoTest`, `RateLimitingTest`, `ValidacaoHostilTest`, `MassAssignmentTest`, `IdorEEscopoTest`, `InjecaoSqlEXssTest`, `ErrosSemVazamentoTest`, `CabecalhosCorsEHttpsTest`, `ConfiguracaoDeSegurancaTest`, `AuditoriaEIntegridadeDeBancoTest`, `ConcorrenciaDeUsuariosTest`, `MigracaoEmBancoLimpoTest`. Os resultados finais constam no relatório da fase.

## 5. Limitações conhecidas

- IDs sequenciais + `DELETE /despesas/{id}` responde 404 (inexistente) × 403 (existe e sem permissão): permite sondar existência de ids por perfis sem acesso.
- `AuditLog::query()->update()/delete()` (query builder) não dispara eventos do model; a garantia forte depende de permissões do banco (Fase 14).
- Mensagens de validação padrão continuam em inglês (`APP_LOCALE=en`); o frontend as traduz.
- O primeiro Pastor precisa ser criado no deploy (nenhum seeder cria usuário/senha).

## 6. Resultado final

- Suíte completa: **830 testes / 87.798 asserções, 0 falhas** (baseline 738 / 7.337; +92 testes novos, 13 arquivos em `tests/Feature/Seguranca/`).
- Mutação: **29/29** detectadas (S12 sobreviveu na 1ª rodada — lacuna real do teste do escopo do Auxiliar no mês atual —, o teste foi reforçado e a mutação passou a ser detectada); arquivos restaurados idênticos.
- E2E (Chrome): segurança 43/43; regressão Dashboard 47/47, Auditoria 56/56, Períodos 36/36, Transferências 112/112, Relatórios 97/97.
- oxlint sem avisos; `vite build` sem avisos. Sem migrations, sem pacotes novos.
- Roteiros E2E antigos ajustados por incompatibilidade objetiva: Relatórios limpa a cota de exportação entre exportações; Auditoria aceita PATCH sem resposta (bloqueado pelo CORS restrito).
