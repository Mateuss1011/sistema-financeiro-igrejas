# API do SFG — convenções gerais

Base: `https://api.<domínio>/api/v1` (local: `http://localhost:8000/api/v1`). Toda resposta de `/api/*` é JSON, mesmo sem o
cabeçalho `Accept`. A lista de endpoints está em [`endpoints.md`](endpoints.md).

## Autenticação (sessão por cookie — Laravel Sanctum, SPA)

Não há token de API nem `Authorization: Bearer`. O navegador guarda um cookie de sessão `HttpOnly`.

1. `GET {origem-da-api}/sanctum/csrf-cookie` (fora de `/api/v1`) — define o cookie `XSRF-TOKEN`.
2. `POST /auth/login` com `{ "email": "...", "password": "..." }`, enviando `credentials: 'include'`, o cabeçalho
   `X-XSRF-TOKEN` (valor do cookie) e a requisição vindo de uma origem listada em `SANCTUM_STATEFUL_DOMAINS`
   (o navegador envia `Origin` sozinho). Resposta `200`: `{ "data": <usuário> }`.
3. Nas demais chamadas: `credentials: 'include'`; em métodos que **não** são GET, repita `X-XSRF-TOKEN`. Token expirado → `419`
   (o frontend renova o cookie e repete).
4. `GET /auth/me` devolve o usuário logado; `POST /auth/logout` encerra a sessão.

Comportamentos de segurança da autenticação:

- Login de origem **sem** sessão (sem `Origin` do frontend) → `403 ORIGEM_NAO_PERMITIDA`.
- Credencial inválida e usuário inexistente dão a **mesma** resposta (`422`, "Credenciais inválidas."); usuário inativo → `422`.
- **Limite de tentativas:** 5 falhas por minuto para o par e-mail + IP; a 6ª tentativa (mesmo com a senha certa) → `429 MUITAS_TENTATIVAS`
  sem tentar autenticar. Além disso, teto de 30 tentativas/min por IP.
- Um usuário **desativado** perde a sessão já aberta: a próxima requisição recebe `401 USUARIO_INATIVO`.
- Sessão expirada/ausente → `401` (`{"message":"Unauthenticated."}`).

## Formato das respostas

Sucesso: `{ "data": ... }`. Listagens paginadas acrescentam `links` e `meta` (paginador do Laravel; `meta.permissoes` traz o que o
usuário pode fazer na tela). Erro: sempre

```json
{ "message": "texto para o usuário", "code": "CODIGO_ESTAVEL", "errors": {} }
```

(`422` de validação traz `message` e `errors: { campo: ["..."] }`; o framework ainda pode omitir `code` nesse caso.)
Nenhuma resposta contém senha, token, stack trace, caminho de arquivo ou SQL; o detalhe técnico vai só para o log do servidor.

| Status | Significado |
|---|---|
| 200 / 201 | sucesso / criado (`Idempotent-Replayed: true` quando é reenvio idempotente) |
| 401 | não autenticado ou sessão encerrada (`USUARIO_INATIVO`) |
| 403 | perfil sem permissão (a autorização é sempre feita no backend, antes da validação) |
| 404 | recurso/rota inexistente (`NAO_ENCONTRADO`); ids de rota só numéricos (1–18 dígitos) |
| 405 | método não permitido (`METODO_NAO_PERMITIDO`) — entradas, transferências e ajustes são imutáveis: só existe estorno |
| 409 | regra de negócio (ver códigos abaixo) |
| 419 | token CSRF ausente/expirado (`CSRF_INVALIDO`) |
| 422 | validação (inclui `EXPORTACAO_MUITO_GRANDE`, `ENTRADA_INVALIDA`) |
| 426 | (produção) método com corpo por HTTP sem TLS (`HTTPS_OBRIGATORIO`); leituras por HTTP recebem `308` para HTTPS |
| 429 | limite de requisições/tentativas; cabeçalho `Retry-After` em segundos (`MUITAS_REQUISICOES` / `MUITAS_TENTATIVAS`) |
| 500 | erro interno genérico (`ERRO_INTERNO`); exportação sem auditoria → `EXPORTACAO_NAO_AUDITADA` (nenhum arquivo entregue) |

## Convenções

- **Dinheiro:** string decimal com 2 casas (`"1234.50"`), nunca número JSON. Enviar com no máximo 2 casas e `> 0`.
- **Datas:** `AAAA-MM-DD`; meses `AAAA-MM`. Datas de lançamento não podem ser futuras (fuso `America/Sao_Paulo`).
  Carimbos (`created_at` etc.) em ISO 8601 UTC.
- **Paginação:** `por_pagina` (1–100, padrão 20) e `page`. Valores fora da faixa → `422`.
- **Filtros:** parâmetros planos (`?conta_id=1&data_de=2026-01-01`). Parâmetros desconhecidos são ignorados; arrays/objetos onde se espera
  escalar → `422`. `perfil`, `visao`, `escopo` e afins enviados pelo cliente **nunca** mudam o que o backend decide.
- **Ordenação:** `?ordenar=-campo,campo2` (prefixo `-` = decrescente), só com os campos listados em cada endpoint; o desempate por `id` é automático.
- **Idempotência:** `Idempotency-Key` (1–64 caracteres ASCII visíveis, sem espaço) em `POST` de entradas, despesas, transferências e ajustes.
  Mesma chave e mesmo corpo → devolve o resultado original com `Idempotent-Replayed: true` (não duplica); mesma chave com corpo diferente →
  `409 IDEMPOTENCY_KEY_REUTILIZADA`.
- **Byte nulo (`\0`)** em qualquer campo → `422 ENTRADA_INVALIDA`.
- **Cabeçalhos de resposta:** `Cache-Control: no-store, private`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
  `Referrer-Policy: no-referrer`, CSP restritiva; `Strict-Transport-Security` em produção por HTTPS.
- **CORS:** só a(s) origem(ns) de `CORS_ALLOWED_ORIGINS`; métodos `GET, POST, PUT, DELETE, OPTIONS`; cabeçalhos `Accept, Content-Type,
  X-XSRF-TOKEN, Idempotency-Key`; expostos `Idempotent-Replayed` e `Content-Disposition`. (`PATCH` existe no roteador do Laravel, mas o CORS o bloqueia: use `PUT`.)

## Limites de taxa (rate limiting)

| Operação | Limite | Chave |
|---|---|---|
| Login — falhas | 5/min | e-mail + IP |
| Login — tentativas | 30/min | IP |
| Exportações (`.../exportar/{formato}`) | 10/min | usuário |
| Consultas pesadas: `dashboard`, `relatorios/{relatorio}`, `auditoria` | 60/min (cota compartilhada) | usuário |

Demais operações não têm throttle (são protegidas por idempotência, travas de banco e regras de negócio).

## Códigos de negócio (`409`)

`SALDO_INSUFICIENTE` (caixa físico nunca fica negativo) · `SALDO_NEGATIVO_REQUER_CONFIRMACAO` (conta bancária: reenviar com
`confirmar_saldo_negativo: true`) · `CONTA_INATIVA` · `CATEGORIA_INATIVA` · `CONTA_EM_USO` · `CATEGORIA_EM_USO` · `PERIODO_FECHADO` ·
`PERIODO_JA_FECHADO` · `PERIODO_JA_ABERTO` · `ENTRADA_JA_ESTORNADA` · `DESPESA_JA_ESTORNADA` · `DESPESA_NAO_PENDENTE` · `DESPESA_NAO_PAGA` ·
`JANELA_EXCLUSAO_EXPIRADA` · `TRANSFERENCIA_JA_ESTORNADA` · `ESTORNO_NAO_ESTORNAVEL` · `TRANSFERENCIA_NAO_ESTORNAVEL` ·
`IDEMPOTENCY_KEY_REUTILIZADA` · `ULTIMO_PASTOR_ATIVO`.

## Perfis (letras usadas em `endpoints.md`)

**P** Pastor · **A** Administrador · **T** Tesoureiro · **X** Auxiliar financeiro · **S** Secretário. Exceções pontuais concedidas pelo
Pastor (`entradas.operar`, `despesas.operar`, `despesas.estornar_paga`, `transferencias.operar`, `transferencias.estornar`, `ajustes.operar`,
`usuarios.gerenciar_privilegiado`) ampliam o que o Administrador/Tesoureiro pode fazer.
