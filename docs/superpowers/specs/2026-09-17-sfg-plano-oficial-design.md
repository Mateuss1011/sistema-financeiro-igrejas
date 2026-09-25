# SFG — Sistema Financeiro para Igrejas
## Plano Oficial de Planejamento — v1.0 (consolidado)

Documento de referência para todas as fases de implementação. Substitui e consolida os Blocos 1, 2 e 3 discutidos anteriormente. Nenhum código, migration, model, controller, rota, componente, tela, arquivo de configuração, teste ou pacote foi criado neste momento — este é exclusivamente o plano.

---

## 1. Visão geral do produto

Sistema web de gestão financeira para uma única igreja, cobrindo o ciclo completo de entradas (dízimos, ofertas, doações, outras receitas) e saídas (despesas), com múltiplas contas bancárias e caixas físicos, transferências, ajustes de saldo, fechamento mensal, relatórios, exportação e trilha de auditoria completa. Arquitetura desacoplada: SPA React (JavaScript, Vite, Bootstrap puro) consumindo uma API REST Laravel (PHP, Sanctum, MySQL). Projeto inicialmente de portfólio, construído com padrões de qualidade suficientes para uso real futuro por uma igreja. Não é multi-tenant nesta v1 — uma única igreja.

## 2. Escopo do MVP

Autenticação · Usuários e perfis · Permissões e exceções pontuais · Categorias · Contas bancárias e caixas físicos · Entradas · Despesas · Transferências · Ajustes de saldo · Fechamento mensal · Auditoria (infraestrutura desde a Fase 2, tela na Fase 10) · Dashboard básico (sem gráficos) · Relatórios · Exportação CSV/Excel · Configurações institucionais · Testes automatizados · Documentação · Preparação para deploy.

## 3. Itens fora do MVP

Cadastro completo de membros · dízimos nominais · comprovantes/anexos · exportação PDF · gráficos (Chart.js fica reservado para depois) · autenticação 2FA · folha de pagamento · patrimônio · integração bancária automática · notificações · aplicativo mobile · multi-tenant · aprovação financeira em múltiplas etapas.

## 4. Perfis e permissões

**Perfis:** Pastor, Administrador, Tesoureiro, Auxiliar financeiro, Secretário.

**Regras gerais:**
- Pode existir mais de um Pastor ativo simultaneamente; o sistema nunca pode ficar com zero Pastores ativos (bloqueio explícito ao tentar desativar/rebaixar o último).
- Administrador tem leitura financeira por padrão e **não executa operações financeiras críticas** (criar/editar/excluir/estornar entradas, despesas, transferências, ajustes) sem uma exceção pontual concedida pelo Pastor.
- Administrador não pode criar, editar, desativar ou rebaixar outro Administrador ou um Pastor, **salvo exceção explicitamente concedida pelo Pastor** através do mecanismo de exceções pontuais.
- Tesoureiro executa a rotina financeira completa, mas **não pode estornar sozinho uma despesa paga** sem uma permissão específica concedida pelo Pastor — por padrão, estorno de despesa paga é exclusivo do Pastor.
- Auxiliar financeiro consulta apenas os próprios lançamentos e não exporta relatórios.
- Secretário não acessa módulos financeiros na v1 (footprint limitado a usuários em modo leitura e configurações institucionais).
- Toda concessão/revogação de exceção de permissão é exclusiva do Pastor e sempre auditada.
- Nenhuma rota protegida depende apenas de validação no frontend — toda autorização é reforçada por Policy no backend.

**Matriz por módulo:**

| Módulo | Pastor | Administrador | Tesoureiro | Auxiliar financeiro | Secretário |
|---|---|---|---|---|---|
| Dashboard | Completo | Completo | Completo | Parcial (indicadores agregados) | Sem acesso |
| Entradas | CRUD + estorno | Visualizar (exceção p/ operar) | Criar/visualizar/estornar | Criar + ver os próprios | Sem acesso |
| Despesas | CRUD completo + estorno de paga | Visualizar (exceção p/ operar) | Criar/editar (Pendente)/pagar/cancelar; estorno de paga só com exceção | Criar (nasce Pendente) + ver as próprias | Sem acesso |
| Categorias | CRUD | CRUD | Visualizar | Visualizar | Visualizar |
| Contas e caixas | CRUD + ajuste de saldo | Criar/editar cadastro/ativar-inativar (não ajusta saldo) | Visualizar + ajuste de saldo | Visualizar saldos | Sem acesso |
| Transferências | Criar + estornar | Visualizar | Criar (estorno só com exceção) | Sem acesso | Sem acesso |
| Fechamento | Fechar + reabrir | Visualizar | Fechar (reabrir só Pastor) | Sem acesso | Sem acesso |
| Auditoria | Consultar (nunca editar/excluir) | Consultar | Sem acesso | Sem acesso | Sem acesso |
| Usuários | CRUD total, gerencia exceções | Cria/edita Tesoureiro/Auxiliar/Secretário | Sem acesso | Sem acesso | Visualizar (somente leitura) |
| Relatórios/Exportação | Visualizar + exportar | Visualizar + exportar | Visualizar + exportar | Visualizar próprios (não exporta) | Sem acesso |
| Configurações | Tudo | Institucional (não sensível) | Sem acesso | Sem acesso | Institucional (não sensível) |

## 5. Regras financeiras

**Entradas:** valor > 0 em `DECIMAL(14,2)`; categoria e conta obrigatórias e ativas; nunca excluídas fisicamente uma vez confirmadas; correção só por estorno vinculado ao lançamento original (nunca edição destrutiva); estorno duplicado bloqueado; período fechado bloqueia criação e qualquer mutação relacionada.

**Despesas:** ciclo `Pendente → Paga → Estornada` ou `Pendente → Cancelada`; nasce sempre Pendente; marcar como Paga exige conta de origem e data de pagamento; Paga não é editável nem excluída fisicamente, apenas estornada; Cancelada nunca volta a Paga; Paga nunca volta a Pendente; exclusão física só quando **todas** as condições coexistem: status Pendente, criada pelo próprio usuário, ≤48h desde a criação, período aberto, usuário com permissão — o Pastor pode realizar essa exclusão como exceção mesmo fora dessas condições, sempre com auditoria.

**Contas e saldos:** tipos `banco` e `caixa` na mesma tabela; saldo inicial definido na criação, imutável depois; saldo atual sempre calculado pelo backend a partir dos lançamentos, nunca confiado ao payload do frontend nem editável diretamente; conta inativa não recebe lançamentos; conta já movimentada nunca é excluída fisicamente, apenas inativada; caixa físico nunca fica negativo (bloqueio); conta bancária pode ficar negativa somente com alerta e confirmação explícita; todo o cálculo de saldo centralizado em um único Service.

**Transferências:** nunca são receita nem despesa; origem ≠ destino (validado); operação atômica via `DB::transaction()` com `lockForUpdate()` nas duas contas envolvidas; estorno exclusivo do Pastor; estorno duplicado bloqueado; teste de concorrência obrigatório.

**Ajustes de saldo:** justificativa sempre obrigatória; sempre auditados; respeitam o fechamento de período; nunca permitem alteração arbitrária de saldo vinda do frontend.

**Fechamento mensal:** período fechado bloqueia criação/edição/estorno de entradas, criação/edição/pagamento/cancelamento/estorno de despesas, criação/estorno de transferências e criação de ajustes de saldo relacionados àquela competência. Pastor e Tesoureiro podem fechar; somente Pastor reabre; reabertura exige justificativa obrigatória; fechamento e reabertura sempre auditados, registrando usuário, data, justificativa e período afetado.

## 6. Regras de auditoria

Infraestrutura criada já na **Fase 2** (não na Fase 10 — a Fase 10 é apenas a tela de consulta).

**Auditado, no mínimo:** login (sucesso e falha), logout, criação/edição/desativação de usuários, concessão/revogação de permissões, CRUD/inativação de categorias e contas, criação e estorno de entradas, criação/pagamento/cancelamento/exclusão permitida/estorno de despesas, transferências e seus estornos, ajustes de saldo, fechamento e reabertura de período, exportações, alterações de configurações.

**Características obrigatórias:** somente leitura (sem endpoints de UPDATE/DELETE); histórico preservado indefinidamente durante o projeto; registra o usuário responsável quando existente, e permite registrar tentativas de usuário inexistente (ex: login falho); reforço no nível do banco recomendado (usuário de aplicação sem `UPDATE`/`DELETE` em `audit_logs`).

## 7. Arquitetura

**Backend (Laravel):** Controllers finos e versionados (`/api/v1`) → Form Requests (validação + `authorize()` via Policy) → Services (regras multi-tabela: `SaldoService`, `TransferenciaService`, `FechamentoService`) → Models Eloquent enxutos → API Resources para toda resposta JSON. Policies obrigatórias em toda rota protegida. Enums nativos PHP para status fixos. Events/Listeners para desacoplar a gravação de auditoria da lógica de negócio. Jobs reservados para tarefas assíncronas futuras. Sem Repository Pattern (Eloquent já é abstração suficiente — evitar indireção sem ganho real). `DB::transaction()` + `lockForUpdate()` em toda operação que afeta saldo.

**Frontend (React/Vite):** organização por funcionalidade (`features/<módulo>/{components,pages,services,hooks}`), `shared/` para componentes/hooks/utilitários reutilizáveis, `AuthContext` + Sanctum via cookie HttpOnly (sem token em `localStorage`), rotas protegidas que bloqueiam a rota (não só escondem menu), hook `usePermissao` para exibição condicional (sempre reforçado no backend), React Hook Form para formulários, interceptors do axios para 401/403/422.

## 8. Modelo de dados (tabelas)

`perfis` · `users` (soft delete) · `audit_logs` (imutável) · `permissoes_excecao` · `configuracoes` · `categorias` · `contas` (soft delete) · `periodos_financeiros` · `entradas` (self-FK de estorno) · `despesas` (self-FK de estorno) · `transferencias` (self-FK de estorno) · `ajustes_saldo`.

Padrões: dinheiro sempre `DECIMAL(14,2)`; entradas e despesas em tabelas separadas (regras/campos distintos o bastante para justificar); soft delete só em `users`/`contas`; `entradas`/`despesas`/`transferencias` nunca usam soft delete — usam status "estornada"; `criado_por`/`atualizado_por` sempre preenchidos pelo backend; FKs de lançamento sempre `ON DELETE RESTRICT`.

## 9. Contrato da API

Prefixo `/api/v1`. Sucesso: `{ "data": {}, "meta": {} }`. Erro: `{ "message": "...", "code": "...", "errors": {} }`. Paginação, filtros (`?filtro[...]=`) e ordenação (`?ordenar=-campo`) padronizados em todas as listagens. Idempotência via header opcional `Idempotency-Key` mantida para criação de entradas, despesas e transferências (proteção contra duplo-clique/reenvio). Conflito de saldo → `409` com `code: SALDO_INSUFICIENTE`/`CONTA_INATIVA`. Período fechado → `409` com `code: PERIODO_FECHADO`.

Módulos cobertos: Auth, Usuários, Permissões/Exceções, Categorias, Contas, Entradas, Despesas, Transferências, Ajustes, Fechamentos, Auditoria, Dashboard, Relatórios, Exportações — cada um com verbo/rota, permissão exigida e erros possíveis já definidos no Bloco 3 (mantido sem alterações).

## 10. Telas do MVP

Login · Dashboard · Usuários · Categorias · Contas e caixas · Entradas · Despesas · Transferências · Fechamento · Auditoria · Relatórios · Configurações · Acesso negado · Página não encontrada. Padrão comum a todas as listagens: loading, empty state, tratamento de erro via interceptors, responsivo (desktop prioritário, funcional em tablet).

## 11. Estrutura de pastas

```
sfg/
  backend/app/{Enums,Events,Exceptions,Listeners,Models,Policies,Services}/
  backend/app/Http/{Controllers/Api/V1,Requests,Resources,Middleware}/
  backend/database/{factories,migrations,seeders}/
  backend/routes/api.php
  backend/tests/{Feature,Unit}/
  frontend/src/app/{routes,providers}/
  frontend/src/features/<módulo>/{components,pages,services,hooks}/
  frontend/src/shared/{components,layout,hooks,services,utils,context}/
  frontend/tests/
  docs/superpowers/specs/   (este documento)
  docs/api/                 (documentação Markdown manual)
```

## 12. Estratégia de testes

**Backend:** unitários de Services (saldo, transições de status, fechamento); Policy tests por perfil/ação (matriz da seção 4); Feature tests de autenticação, permissões, entradas, despesas, transferências, ajustes, fechamento, auditoria, exportação.

**Frontend:** componentes compartilhados, rotas protegidas, exibição condicional por permissão, estados de erro/carregamento.

**Cenários críticos obrigatórios:** acesso sem permissão (403) · saldo enviado no payload ignorado · movimentação em conta inativa (409) · caixa físico negativo bloqueado · transferência origem=destino (422) · pagar despesa sem conta (422) · mutação em período fechado (409) · reabertura por não-Pastor (403) · exclusão de despesa paga (403/409) · tentativa de UPDATE/DELETE em `audit_logs` (rota inexistente) · desativar o último Pastor (409) · concorrência em transferências simultâneas na mesma conta · estorno duplicado (409) · reenvio de requisição financeira com mesma `Idempotency-Key` (não duplica).

## 13. Segurança

Sanctum · rate limiting no login e na API · hash seguro de senha · Form Requests + Policies em toda rota · proteção CSRF · CORS restrito ao domínio real do frontend · HTTPS em produção · `APP_DEBUG=false` em produção, sem stack trace exposta · nenhuma senha em API Resource · logs técnicos separados da auditoria de negócio · backup definido antes do deploy (estratégia pendente até a decisão de hospedagem) · migrations testadas em banco limpo · `.env` fora do Git, `.env.example` atualizado · usuário de banco de produção sem `UPDATE`/`DELETE` em `audit_logs` · testes de autorização, concorrência e duplicação obrigatórios · validação real sempre no backend.

## 14. Fases de implementação

| Fase | Objetivo | Não fazer |
|---|---|---|
| 0 — Preparação | Fechar decisões pendentes (seção 19) | Instalar/criar qualquer coisa |
| 1 — Estrutura e ambiente | Monorepo rodando (Laravel + Vite), health-check | Criar tabelas de domínio ou login |
| 2 — Banco base e autenticação | Login Sanctum + `perfis`/`users`/`audit_logs`, login já auditado | Permissões granulares |
| 3 — Usuários, perfis e permissões | CRUD de usuários, exceções pontuais, regra do último Pastor | Módulos financeiros |
| 4 — Categorias | CRUD + seed padrão, bloqueio de exclusão em uso | Vincular a entradas/despesas |
| 5 — Contas e caixas | CRUD, saldo só calculado | Transferências/ajustes |
| 6 — Entradas | Criação, estorno, `periodos_financeiros` introduzida | Despesas |
| 7 — Despesas | Ciclo de status completo, janela de exclusão de 48h | Transferências |
| 8 — Transferências e ajustes | Atomicidade, concorrência, ajuste de saldo | Fechamento |
| 9 — Fechamento mensal | Fechar/reabrir bloqueando mutações | Tela de auditoria |
| 10 — Tela de auditoria | Consulta filtrável + checklist de cobertura | Qualquer exclusão/edição de log |
| 11 — Dashboard | Indicadores básicos por perfil | Gráficos |
| 12 — Relatórios e exportação | Relatórios + CSV/Excel, mesma lógica de cálculo | PDF |
| 13 — Testes completos e segurança | Cenários críticos cross-module, checklist de segurança | Funcionalidades novas |
| 14 — Documentação e deploy | README, doc da API, checklist de deploy | Deploy real sem autorização |

*(Cada fase, ao ser iniciada individualmente, deve detalhar tabelas/endpoints/telas/regras/testes/critérios específicos conforme já modelado no Bloco 3 — mantidos sem alteração de conteúdo, apenas resumidos aqui para consolidação.)*

## 15. Ordem das migrations

```
1. perfis            5. configuracoes       9.  entradas
2. users             6. categorias          10. despesas
3. audit_logs        7. contas              11. transferencias
4. permissoes_excecao 8. periodos_financeiros 12. ajustes_saldo
--- pós-MVP ---
13. anexos
```

## 16. Critérios de aceite do MVP

Login funcional e auditado · matriz de permissões 100% coberta por testes de Policy · nunca zero Pastor ativo · entradas nunca excluídas fisicamente, sempre estornáveis · despesas respeitando o ciclo de status e a janela de 48h · saldo sempre derivado dos lançamentos, nunca editável diretamente · transferências atômicas e resistentes a concorrência · fechamento bloqueando 100% das mutações do período · auditoria cobrindo 100% da lista aprovada, sem rota de edição/exclusão · dashboard e relatórios com números idênticos para o mesmo filtro · exportação com totais idênticos à tela e sempre auditada · checklist de segurança concluído · suíte de testes passando · documentação permitindo a um terceiro rodar o projeto do zero.

## 17. Riscos

Complexidade de saldo → centralizado em `SaldoService`. Concorrência → `lockForUpdate()` + testes dedicados. Estornos mal vinculados → FK self-referencing obrigatória. Fechamento virar gargalo → lógico e reversível. Exceções de permissão mal controladas → sempre auditadas, concessão exclusiva do Pastor. Lacunas de auditoria → checklist de cobertura na Fase 10. Exportação divergente do relatório → mesma query/Service para os dois. Backup indefinido → tratado como bloqueador de deploy, não de desenvolvimento. Dados financeiros incorretos → testes de Feature com asserção de saldo final. Dependência de pacotes não aprovados → nenhuma instalação sem aprovação explícita. Escopo crescendo → "o que não fazer" explícito por fase.

## 18. Decisões confirmadas

- Monorepo · livro-caixa simples (não partidas dobradas) · papéis fixos com exceções pontuais · auditoria financeira + administrativa sensível.
- Múltiplos Pastores permitidos; nunca zero Pastor ativo.
- Administrador: leitura financeira por padrão; opera criticamente só com exceção do Pastor; não gerencia Pastor/Administrador salvo exceção do Pastor.
- Tesoureiro: rotina completa, mas estorno de despesa paga exige exceção do Pastor.
- Secretário: sem acesso a módulos financeiros na v1.
- Exclusão física de despesa: só Pendente, próprio criador, ≤48h, período aberto (Pastor pode exceção auditada). Entradas: nunca excluídas fisicamente, sempre por estorno.
- Saldo negativo: bloqueado em caixa físico, permitido com alerta em conta bancária.
- Fechamento: mensal, fecha Pastor/Tesoureiro, reabre só Pastor com justificativa.
- Auditoria: infraestrutura desde a Fase 2, retenção indefinida durante o projeto, imutável (sem update/delete).
- Exportação: CSV/Excel no MVP, PDF depois; Auxiliar financeiro não exporta.
- Bibliotecas: React Hook Form aprovado; Spatie Query Builder condicional (se trouxer benefício real); Chart.js só pós-MVP; Scramble opcional; documentação Markdown manual suficiente por ora.
- Idempotency-Key mantida para entradas, despesas e transferências.
- 2FA fora do MVP.
- Sem Repository Pattern.

## 19. Pendências reais restantes

1. **Topologia de domínio — decidida.** Frontend React e backend Laravel serão hospedados em subdomínios separados (produção planejada: `app.igreja.com` e `api.igreja.com`; desenvolvimento local: `http://localhost:5173` e `http://localhost:8000`). Os domínios de produção acima são exemplos de arquitetura — os domínios reais ainda serão definidos antes da Fase 2, junto com a hospedagem.
2. **Ambiente de hospedagem/deploy** — necessário antes da Fase 14, e do qual depende a estratégia de backup.
3. **Confirmação final da lista de categorias padrão** (seed) — de baixa prioridade, pode ser resolvida na revisão da Fase 4.

Nenhuma decisão restante bloqueia o início da Fase 0/1.

## 20. Próximo passo recomendado

Topologia escolhida: frontend e backend em **subdomínios separados** (`app.*`/`api.*`), o que já orienta a configuração de CORS e dos *stateful domains* do Sanctum na Fase 2 — mas essa fase depende dos domínios reais de produção e da hospedagem, ainda a definir (pendências 1 e 2). O próximo passo é iniciar **somente a Fase 0**, sem avançar automaticamente para as demais — cada fase seguinte só começa após revisão e aprovação da anterior, nunca o sistema inteiro de uma vez.

---

## Regras de execução com o Claude Code (reforço permanente)

Nunca implementar o sistema inteiro de uma vez · nunca avançar automaticamente de fase sem aprovação · nunca instalar pacote sem aprovação explícita · nunca alterar migration já aplicada (criar uma nova) · nunca rodar comando destrutivo · nunca `git push --force` ou `git reset --hard` sem pedido explícito · nunca pular ou desabilitar teste para fazê-lo passar · nunca fazer deploy ou alterar produção sem autorização explícita · um commit por fase concluída · revisar e testar cada fase antes de iniciar a próxima.
