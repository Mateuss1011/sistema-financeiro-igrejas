# Ambiente de demonstração do SFG

> **Todos os dados são fictícios.** Nomes, e-mails (`@sfg.demo`), contas, valores e fornecedores são inventados; não há pessoa, igreja,
> conta bancária, telefone ou documento real. O ambiente serve para apresentar o sistema (portfólio/produto) e **não é para produção**.

## Objetivo

Montar, com um comando, um ambiente **reproduzível** que prova as regras que o SFG **já tem** — sem nenhuma lógica especial para demo:
os cinco perfis entram pelo **login real**, e quem decide o que cada um vê e faz são as **Policies existentes**. Tudo é criado pelos
mesmos Services da API (regras de saldo, período fechado, estorno, idempotência e **auditoria de verdade**); nada é inserido “por fora”.

Não há tela nem botão de “entrar como…”, nenhum bypass de autenticação, **nenhum endpoint especial de login demo** e nenhum endpoint
público novo: os usuários demo são usuários comuns do sistema, e a demonstração não altera nenhuma Policy nem concede exceção de permissão.

## Como criar, resetar e recriar

Pré-requisito: o sistema instalado, com `php artisan migrate --seed` já executado (perfis e categorias padrão) — veja o [README](../../README.md).

```bash
cd backend
php artisan sfg:demo          # cria (ou completa) o ambiente
php artisan sfg:demo:reset    # remove SOMENTE os dados de demonstração
php artisan sfg:demo:reset && php artisan sfg:demo    # recriar do zero
```

**Senha.** O comando `sfg:demo` pede a senha de demonstração de forma **interativa e oculta** (e a confirmação) e a aplica aos cinco
usuários. Ela **não existe em nenhum arquivo do projeto**, não é impressa, não é registrada em log e é gravada apenas como hash.
Para automação **local/controlada** é possível definir a variável de ambiente `SFG_DEMO_PASSWORD` no processo (sem valor padrão, nunca
por argumento de linha de comando); use uma senha forte que só você conheça e remova a variável do ambiente depois.

**Produção bloqueada.** Com `APP_ENV=production` os dois comandos recusam: *“O ambiente de demonstração não pode ser executado em
produção.”* Não há `--force` nem outra forma de burlar isso (e o próprio serviço também recusa).

**Idempotente.** Rodar `sfg:demo` de novo não duplica nada: os registros são reaproveitados (a senha informada é reaplicada e os
usuários demo são reativados, se necessário). Pode ser rodado em qualquer dia do mês (nenhuma data futura é gerada).

**Reset seguro.** `sfg:demo:reset` **não** usa `migrate:fresh`/`db:wipe`, não apaga tabelas inteiras e só remove o que a demonstração criou,
na ordem das chaves estrangeiras. Se não houver nada: *“Nenhum dado de demonstração encontrado.”* (não é erro). Se um dado que **não**
é de demonstração estiver ligado a um usuário demo, o comando aborta sem apagar nada.

## Usuários (o perfil de cada um é o do sistema real)

| Perfil | Nome | E-mail |
|---|---|---|
| Pastor | Demo Pastor | `demo.pastor@sfg.demo` |
| Administrador | Demo Administrador | `demo.administrador@sfg.demo` |
| Tesoureiro | Demo Tesoureiro | `demo.tesoureiro@sfg.demo` |
| Auxiliar financeiro | Demo Auxiliar Financeiro | `demo.auxiliar@sfg.demo` |
| Secretário | Demo Secretário | `demo.secretario@sfg.demo` |

Nenhum deles recebe exceção de permissão nem privilégio extra. **Senha: definida pelo operador ao executar `php artisan sfg:demo`.**

## O que é criado

| Item | Quantidade | Detalhe |
|---|---|---|
| Usuários | 5 | os da tabela acima (o Pastor é criado primeiro; os demais são cadastrados por ele, com auditoria) |
| Contas | 3 | `DEMO - Conta Bancária Principal` (banco, saldo inicial R$ 5.000,00), `DEMO - Caixa Geral` (caixa, R$ 300,00) e `DEMO - Conta Reserva (inativa)` |
| Categorias | 0 a 8 | reaproveita as categorias padrão do sistema; só cria `DEMO - …` se uma padrão não existir/estiver inativa |
| Entradas | 12 | 11 lançamentos (mês anterior e atual; Pastor, Tesoureiro e Auxiliar) + 1 linha de **estorno** |
| Despesas | 13 | 5 pagas, 5 pendentes, 1 cancelada, 1 estornada (+ 1 linha de estorno) |
| Transferências | 1 | banco → caixa, R$ 800,00 (não é receita nem despesa) |
| Ajustes | 1 | crédito de R$ 12,50 no caixa, com justificativa |
| Períodos | 1 | **mês anterior fechado**; mês atual aberto |
| Auditoria | ~50 registros | gerados pelas operações reais acima (nenhum log inventado) |

Regras de identificação dos dados demo (sem alterar o schema): usuários pelos e-mails `@sfg.demo`; contas/categorias criadas aqui pelo
prefixo `DEMO - `; lançamentos por terem sido criados pelos usuários demo (e por chaves de idempotência `demo-*`).

### Números do ambiente (para conferir na tela)

Mês **atual** (aberto): entradas **R$ 4.616,00** · despesas pagas **R$ 1.665,60** · **4** pendentes (**R$ 305,50**) · saldo total
**R$ 10.663,00** (banco R$ 9.454,75 · caixa R$ 1.208,25 · reserva R$ 0,00).
Mês **anterior** (fechado): entradas **R$ 3.910,50** · despesas pagas **R$ 1.510,40** · **1** pendente (**R$ 89,90**).
Uma entrada de R$ 200,00 (duplicada) e uma despesa de R$ 95,00 (paga por engano) aparecem **estornadas** e não entram nos totais.

## O que dá para demonstrar (por perfil)

Entre em `http://localhost:5173` (ou na URL do frontend) com cada e-mail e a senha que você definiu.

| Perfil | Mostre | Resultado esperado (regras atuais) |
|---|---|---|
| **Pastor** | Dashboard, Entradas, Despesas, Transferências, Relatórios (5), exportar CSV/Excel, Fechamento, **Auditoria**, Usuários | acesso completo; o Dashboard traz saldo por conta e situação do período; a Auditoria lista as operações reais da demonstração |
| **Administrador** | Dashboard, listas, relatórios/exportação, Auditoria, Usuários | consulta e gestão administrativa; **não opera lançamentos** (criar entrada/despesa → bloqueado), como no plano |
| **Tesoureiro** | criar entrada/despesa, pagar, cancelar, transferir, ajustar, fechar período, exportar | rotina financeira completa; **sem** Auditoria e **sem** Usuários |
| **Auxiliar financeiro** | Entradas, Despesas, Dashboard, Relatórios | vê **somente o que ele criou** (3 entradas e 3 despesas, apesar de existirem lançamentos de outros); Dashboard **parcial** (entradas R$ 435,75, 2 pendentes R$ 101,30) **sem saldo nem situação do período**; relatório “Saldos por conta” bloqueado; **não exporta**; Transferências, Ajustes, Fechamento, Auditoria e Usuários bloqueados |
| **Secretário** | tentar abrir Dashboard/Entradas/Despesas/Relatórios | **sem acesso** aos módulos financeiros; só vê Categorias e Usuários (leitura) |

Outras demonstrações possíveis: filtrar Relatórios pelo **mês anterior** (período fechado) e pelo atual; tentar lançar no mês fechado
(bloqueado); ver que o **estorno** mantém o original e a linha de estorno e que os totais continuam coerentes entre Dashboard e Relatórios;
exportar e ver a exportação aparecer na Auditoria; ver a conta inativa não receber lançamentos.

## Limitações

- As datas são **relativas a hoje** (mês atual e anterior); nada é gerado no futuro. Se o mês anterior já estiver fechado por outro motivo
  antes da primeira execução, os lançamentos demo desse mês não são criados (o comando avisa).
- O reset também remove a **trilha de auditoria produzida pelos usuários demo** (senão sobrariam registros órfãos a cada recriação). É seguro
  porque o comando só roda fora de produção; em produção nem existe, e o usuário de banco de produção não tem `DELETE` em `audit_logs`.
- A senha só vale para a demonstração; trocar a senha de um usuário demo pelo sistema não é possível (o sistema não tem tela de troca) —
  para mudar, rode `sfg:demo` de novo com outra senha.
- Não há dados em volume: a massa é pequena de propósito para a apresentação ser rápida.
- Recomenda-se usar um banco de desenvolvimento **limpo**; se houver dados reais, o reset não os toca, mas a demonstração e os dados reais
  compartilhariam as mesmas telas.
