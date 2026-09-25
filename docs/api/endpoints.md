# API do SFG — endpoints

Prefixo `/api/v1`. Convenções (envelope, erros, paginação, idempotência, limites) em [`README.md`](README.md).
Perfis: **P** Pastor · **A** Administrador · **T** Tesoureiro · **X** Auxiliar financeiro · **S** Secretário.
A coluna "Quem" mostra quem **passa pela autorização** (o resto recebe `403`); regras de estado (período fechado, saldo, status)
respondem `409`. Todas as rotas exigem sessão, exceto `GET /health` e `POST /auth/login`. Esta lista foi conferida com
`php artisan route:list` e com a suíte `MatrizDeAutorizacaoTest`.

## Saúde e autenticação

| Método e rota | Quem | Descrição |
|---|---|---|
| `GET /health` | público | `{"status":"ok"}` (health-check; isento de HTTPS obrigatório) |
| `POST /auth/login` | público | `email`, `password` (≤ 255). `200 {data: usuário}` · `422` credenciais/inativo · `429` limite · `403 ORIGEM_NAO_PERMITIDA` |
| `POST /auth/logout` | logado | encerra a sessão (auditado) |
| `GET /auth/me` | logado | usuário atual: `id, name, email, ativo, perfil{id,slug,nome_exibicao}, ultimo_login_em, created_at` |

## Usuários e permissões

| Método e rota | Quem | Descrição |
|---|---|---|
| `GET /usuarios` | P A S | lista paginada (`por_pagina`); `permissoes_excecao` só para o Pastor |
| `POST /usuarios` | P A | `name` (≤150), `email` (≤150, único), `password` (regras de senha, ≤255), `perfil_id`. A quem pode atribuir qual perfil: `GET /perfis`. Sem perfil válido: `403` para quem nem o perfil de menor privilégio pode criar |
| `PUT /usuarios/{id}` | P A | `name`, `email`, `perfil_id`, `ativo` (nunca a senha). Administrador não edita Pastor/Administrador sem a exceção `usuarios.gerenciar_privilegiado`. `409 ULTIMO_PASTOR_ATIVO` se deixaria o sistema sem Pastor ativo |
| `DELETE /usuarios/{id}` | P A | desativa (não apaga); mesma regra do último Pastor |
| `GET /perfis` | P A S | perfis que o usuário logado pode atribuir |
| `GET /permissoes-excecao` | P | catálogo das exceções pontuais |
| `POST /usuarios/{id}/permissoes-excecao` | P | `permissao` (uma das chaves do catálogo) — auditado |
| `DELETE /usuarios/{id}/permissoes-excecao/{permissao}` | P | revoga — auditado |

## Categorias e contas

| Método e rota | Quem | Descrição |
|---|---|---|
| `GET /categorias` | P A T X S | filtros `tipo` (`entrada`/`despesa`), `ativa`, `por_pagina` |
| `POST /categorias` | P A | `nome` (≤100, único por tipo), `tipo` |
| `PUT /categorias/{id}` | P A | `nome`, `ativa` (o tipo é imutável) |
| `DELETE /categorias/{id}` | P A | só sem uso (`409 CATEGORIA_EM_USO`) |
| `GET /contas` | P A T X | filtros `tipo` (`banco`/`caixa`), `ativa`, `por_pagina`; cada conta traz `saldo_inicial` e `saldo_atual` (calculado pelo backend) |
| `POST /contas` | P A | `nome` (≤100), `tipo`, `saldo_inicial` (imutável depois; caixa não pode ser negativo) |
| `PUT /contas/{id}` | P A | `nome`, `ativa` (tipo e saldo inicial são proibidos → `422`) |
| `DELETE /contas/{id}` | P | só sem movimentação (`409 CONTA_EM_USO`); exclusão lógica |

## Entradas (imutáveis; correção só por estorno)

| Método e rota | Quem | Descrição |
|---|---|---|
| `GET /entradas` | P A T X | filtros `data_de`, `data_ate`, `categoria_id`, `conta_id`, `status`, `estorno`, `ordenar` (`data_competencia`, `valor`, `created_at`, `id`), `por_pagina`. Auxiliar: só as que criou |
| `POST /entradas` | P T X (A com `entradas.operar`) | `categoria_id`, `conta_id`, `valor`, `data_competencia`, `descricao?` (≤255), `contribuinte_nome?` (≤150). `Idempotency-Key`. `409` conta/categoria inativa, período fechado |
| `POST /entradas/{id}/estornar` | P T (A com exceção) | `justificativa` (3–500), `confirmar_saldo_negativo?`. `409 ENTRADA_JA_ESTORNADA` |

Não existem `PUT`/`DELETE`/`GET {id}` de entradas.

## Despesas (`pendente → paga → estornada` ou `pendente → cancelada`)

| Método e rota | Quem | Descrição |
|---|---|---|
| `GET /despesas` | P A T X | filtros de entradas + `status` (`pendente`, `paga`, `estornada`, `cancelada`); `ordenar` inclui `data_pagamento`. Auxiliar: só as que criou. Cada linha traz flags `editavel`, `pagavel`, `cancelavel`, `estornavel`, `excluivel` |
| `POST /despesas` | P T X (A com `despesas.operar`) | `categoria_id`, `valor`, `data_competencia`, `descricao` (≤255), `fornecedor_nome?`. Nasce sempre **pendente**. `Idempotency-Key` |
| `PUT /despesas/{id}` | P T (A com `despesas.operar`) | só pendente; campos protegidos (`status`, `conta_id`, `data_pagamento`, autoria…) → `422` |
| `DELETE /despesas/{id}` | P | exclusão física só de pendente (janela de 48 h para o criador; o Pastor pode como exceção auditada) |
| `POST /despesas/{id}/pagar` | P T (A com `despesas.operar`) | `conta_id`, `data_pagamento`, `confirmar_saldo_negativo?` |
| `POST /despesas/{id}/cancelar` | P T (A com `despesas.operar`) | `justificativa` |
| `POST /despesas/{id}/estornar` | P (T com `despesas.estornar_paga`; A com `despesas.operar` **e** `despesas.estornar_paga`) | `justificativa`; só despesa paga |

## Transferências e ajustes de saldo

| Método e rota | Quem | Descrição |
|---|---|---|
| `GET /transferencias` | P A T | filtros `data_de`, `data_ate`, `conta_id`, `conta_origem_id`, `conta_destino_id`, `status`, `estorno`, `ordenar` (`data_transferencia`, `valor`, `created_at`, `id`) |
| `POST /transferencias` | P T (A com `transferencias.operar`) | `conta_origem_id`, `conta_destino_id` (≠ origem), `valor`, `data_transferencia`, `descricao?`, `confirmar_saldo_negativo?`. Atômica, com trava nas duas contas. `Idempotency-Key` |
| `POST /transferencias/{id}/estornar` | P T (A com `transferencias.operar` + `transferencias.estornar`) | `justificativa`, `confirmar_saldo_negativo?` |
| `GET /ajustes` | P A T | filtros `data_de`, `data_ate`, `conta_id`, `sentido`, `ordenar` (`data_ajuste`, `valor`, `created_at`, `id`) |
| `POST /ajustes` | P T (A com `ajustes.operar`) | `conta_id`, `valor`, `sentido` (`credito`/`debito`), `data_ajuste`, `justificativa` (3–500), `confirmar_saldo_negativo?`. `Idempotency-Key` |

## Fechamento mensal

| Método e rota | Quem | Descrição |
|---|---|---|
| `GET /periodos-financeiros` | P A T | períodos com linha (mês sem linha = aberto); `por_pagina`, `page` |
| `POST /periodos-financeiros/{AAAA-MM}/fechar` | P T | bloqueia toda mutação do mês (`409 PERIODO_FECHADO`) |
| `POST /periodos-financeiros/{AAAA-MM}/reabrir` | P | `justificativa` (3–500) |

## Dashboard, relatórios e exportação

| Método e rota | Quem | Descrição |
|---|---|---|
| `GET /dashboard?ano_mes=AAAA-MM` | P A T X | indicadores do mês (padrão: mês corrente); Auxiliar recebe visão parcial e só o que criou. Mês futuro/inválido → `422`. Limite 60/min |
| `GET /relatorios` | P A T X | catálogo do que o usuário pode ver/exportar, formatos e opções de filtro |
| `GET /relatorios/{relatorio}` | P A T X (`saldos`: só P A T) | `relatorio` ∈ `resumo`, `entradas`, `despesas`, `movimentacoes`, `saldos`; filtros `ano_mes`, `conta_id`, `categoria_id`, `status`, `ordenar`, `por_pagina`, `page`. Limite 60/min |
| `GET /relatorios/{relatorio}/exportar/{formato}` | P A T | `formato` ∈ `csv`, `xlsx`; exporta **todo** o conjunto filtrado (máx. 10.000 linhas → `422 EXPORTACAO_MUITO_GRANDE`). Sempre auditada; falha na auditoria → `500` sem arquivo. Limite 10/min |

Detalhes de cálculo, colunas e formato dos arquivos: `docs/superpowers/specs/2026-09-23-sfg-fase-12-relatorios-exportacao.md`.

## Auditoria (somente leitura)

| Método e rota | Quem | Descrição |
|---|---|---|
| `GET /auditoria` | P A | filtros `modulo`, `acao`, `user_id`, `sem_usuario`, `registro_id`, `data_de`, `data_ate`, `ordenar` (`created_at`, `modulo`, `acao`, `id`), `por_pagina`, `page`. Campos sensíveis (`senha`, `token`, …) aparecem como `[oculto]`. Limite 60/min |
| `GET /auditoria/catalogo` | P A | módulos e ações existentes |

Não existe nenhuma rota de escrita em `audit_logs` (nem `GET {id}`).
