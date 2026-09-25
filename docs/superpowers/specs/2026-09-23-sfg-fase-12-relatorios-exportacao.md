# SFG — Fase 12: Relatórios e Exportação (documentação técnica)

Documento técnico da Fase 12 (plano oficial, §14: "Relatórios + CSV/Excel, mesma lógica de cálculo"; não fazer: PDF).
O plano oficial **não foi alterado**. Este arquivo registra o contrato implementado e as decisões formais da fase.

## 1. Regra central

**Dashboard, Relatórios (tela/API) e Exportações (CSV/XLSX) não podem ter cálculos financeiros divergentes** (plano §16 e §17).
Existe uma **fonte única**: `App\Services\IndicadoresFinanceirosService`. O `DashboardService` virou uma fachada sem SQL próprio e o
`RelatorioService` só faz joins de exibição, filtros opcionais, ordenação e paginação **sobre os mesmos builders**. Nenhum SQL financeiro
existe fora desse service.

| Indicador | Predicado (fonte única) |
|---|---|
| Entradas do mês | `data_competencia` no mês, só originais em vigor (`status = confirmada`, sem `entrada_estornada_id`) |
| Despesas pagas do mês | `data_pagamento` no mês, só originais em vigor (`status = paga`, sem `despesa_estornada_id`) — **nunca** a competência |
| Despesas pendentes | `status = pendente` com `data_competencia` no mês (canceladas nunca entram) |
| Transferências | `data_transferencia` no mês, originais em vigor; **não** são receita nem despesa |
| Ajustes | `data_ajuste` no mês |
| Saldos | sempre `SaldoService`: mês corrente = `saldosAtuais()`; mês anterior = `saldosAte()` no último dia do mês |
| Situação do período | `PeriodoFinanceiroService::estaFechado()` |

"Originais em vigor" equivale ao líquido "originais − estornos" do `SaldoService`, porque toda linha de estorno copia as datas da original
(as duas caem no mesmo mês e se anulam). Isso também mantém correto o recorte do Auxiliar quando o estorno foi criado por outra pessoa.
A limitação da Fase 9 (o `saldo_inicial` de uma conta criada depois de um mês passado ainda entra no saldo desse mês) foi **mantida**.

## 2. Perfis e permissões (`RelatorioPolicy`)

| Perfil | Ver relatórios | Exportar | Observação |
|---|---|---|---|
| Pastor / Administrador / Tesoureiro | os 5 | CSV e XLSX | veem tudo |
| Auxiliar financeiro | Resumo, Entradas, Despesas, Movimentação | **nunca** (403) | só o que ele mesmo criou; sem saldos; "Saldos por conta" → 403 |
| Secretário | nenhum (403) | nunca (403) | |

Nenhuma exceção pontual libera este módulo. O backend é a autoridade: parâmetros como `visao`, `escopo` ou `perfil` enviados pelo
cliente são ignorados. Que lançamentos o Auxiliar enxerga reaproveita `viewAll` de `EntradaPolicy`/`DespesaPolicy`; transferências e
ajustes só entram na Movimentação para quem já os enxerga pelas Policies existentes (o Auxiliar nunca).

## 3. Catálogo de relatórios

Filtro principal de todos: `ano_mes=AAAA-MM` (omitido = mês corrente em `America/Sao_Paulo`, decidido pelo backend; mês futuro,
formato inválido, mês 00/13 → **422**). Convenção de filtros = a das APIs existentes (parâmetros planos), não `filtro[...]`.
Filtro que não se aplica ao relatório é ignorado.

| Código | Nome | Filtros extras | Ordenação | Paginado |
|---|---|---|---|---|
| `resumo` | Resumo financeiro | — | — | não |
| `entradas` | Entradas | `conta_id`, `categoria_id` | `data_competencia`, `valor`, `created_at`, `id` | sim |
| `despesas` | Despesas | `conta_id`, `categoria_id`, `status` | `data_competencia`, `data_pagamento`, `valor`, `created_at`, `id` | sim |
| `movimentacoes` | Movimentação financeira | `conta_id` | `data`, `referencia_id` | sim |
| `saldos` | Saldos por conta | `conta_id` | `tipo`, `nome`, `saldo`, `id` | sim |

- **Resumo**: linhas Indicador/Valor (período, totais, quantidade de pendentes; no perfil completo: saldo total, referência do saldo,
  situação do período e saldo de cada conta). `meta.indicadores` tem exatamente o formato do Dashboard.
- **Entradas**: ID, data de competência, categoria, conta, tipo (tipo da conta: Banco/Caixa), descrição, valor, status, criado por,
  data de criação. Só originais em vigor (estornadas e linhas de estorno não aparecem).
- **Despesas**: ID, competência, pagamento, categoria, conta, fornecedor, descrição, valor, status, criado por, criação. Lista pagas
  (pelo pagamento), pendentes e canceladas (pela competência) e estornadas (pelo pagamento); a linha de estorno nunca aparece.
  Totais: pagas e pendentes (quantidade e valor); canceladas e estornadas ficam fora dos totais.
- **Movimentação**: data, tipo, conta, descrição, entrada, saída, valor líquido, referência (ID), usuário. Entradas em vigor, despesas
  pagas (pela data de pagamento), transferências (**uma linha por lado**: "Transferência enviada" na origem e "recebida" no destino) e
  ajustes ("Ajuste (crédito/débito)"). Pares original+estorno ficam de fora (efeito líquido zero). Totais próprios por tipo; a
  variação líquida é exatamente a variação de saldo do `SaldoService` no mês (verificada em teste).
- **Saldos por conta**: ID, conta, tipo, situação (ativa/inativa), saldo; total. Contas inativas entram, removidas não.

## 4. Endpoints

| Rota | Uso |
|---|---|
| `GET /api/v1/relatorios` | catálogo do que **este usuário** pode ver, se pode exportar, formatos, status de despesa e opções de filtro (contas/categorias — sem saldo) |
| `GET /api/v1/relatorios/{relatorio}` | consulta (paginada quando aplicável: `page`, `por_pagina`, padrão 20, máx. 100) |
| `GET /api/v1/relatorios/{relatorio}/exportar/{formato}` | exportação `csv` ou `xlsx` de **todo** o conjunto filtrado |

Só `GET`. `{relatorio}` e `{formato}` são restritos por regex (desconhecido, inclusive `pdf`/`xls` → 404). Resposta JSON: `{ data, meta }`
com `meta = { relatorio, titulo, ano_mes, escopo, colunas, totais, filtros, paginacao, indicadores }`. Erros: `{ message, code, errors }`.
A exportação é **síncrona** (sem Jobs).

## 5. Exportação

Fluxo obrigatório: autorização → validação dos filtros → consulta de todas as linhas e totais (limite) → montagem do arquivo →
**auditoria** → entrega. O arquivo tem o mesmo conteúdo da consulta (mesmo `RelatorioResultado`) e traz os **totais** da tela após uma
linha em branco. O arquivo começa direto pelo conteúdo (sem cabeçalho institucional; não existe tabela `configuracoes`).

- Nome: `sfg-relatorio-{tipo}-{ano_mes}.{csv|xlsx}`. Cabeçalhos: `Content-Disposition: attachment`, `Cache-Control: no-store, private`,
  `X-Content-Type-Options: nosniff`. `Content-Disposition` é exposto no CORS (o front lê o nome do arquivo).
- **Limite**: `config/relatorios.php` → `limite_exportacao = 10000` linhas; acima disso → **422 `EXPORTACAO_MUITO_GRANDE`** (sem auditoria,
  pois nada foi entregue).

### CSV
UTF-8 **com BOM**, separador `;`, quebra **CRLF**, primeira linha = cabeçalhos, dinheiro com **2 casas e vírgula decimal** sem separador de milhar
(`-1234,50`), datas `DD/MM/AAAA`, data e hora `DD/MM/AAAA HH:mm:ss` (America/Sao_Paulo), campos com `;`, aspas ou quebra de linha escapados
(RFC 4180; campos com espaço também vêm entre aspas).

### XLSX (`phpoffice/phpspreadsheet`)
Cabeçalho em negrito, painel congelado, dinheiro como célula **numérica** com formato `#,##0.00` (soma no Excel), datas reais
(`dd/mm/yyyy`, `dd/mm/yyyy hh:mm:ss`), inteiros numéricos, colunas auto-ajustadas, aba com o nome do relatório.

## 6. Auditoria

Toda exportação gera **um** registro em `audit_logs`: módulo `exportacoes`, ação `exported` (rótulos "Exportações" / "Exportação" no
catálogo da tela de auditoria). `dados_novos`: `relatorio`, `formato`, `ano_mes`, `filtros` (sem `ordenar`), `linhas` (linhas de dados),
`totais` (mapa chave→valor), `arquivo`, `resultado = gerado`. Usuário, perfil e IP/agente vêm do registro padrão. **Nenhum texto de
lançamento nem nome de pessoa** é gravado.

**Falha da auditoria ⇒ o arquivo NÃO é entregue**: resposta 500 `EXPORTACAO_NAO_AUDITADA` no formato padrão, sem stack/SQL/detalhe;
o erro técnico vai para o log. Consultar (tela/API/catálogo), negar (403), validar (422) e falhar por tamanho não geram auditoria.

## 7. Segurança

- **Formula Injection**: `TextoSeguro::neutralizar()` prefixa `'` em todo **texto** que comece com `=`, `+`, `-`, `@`, TAB ou CR — em CSV e
  XLSX (categoria, conta, descrição, fornecedor, nome do usuário, rótulos etc.). O XLSX grava texto sempre com
  `setCellValueExplicit(..., TYPE_STRING)` (o `setCellValue` trataria `=...` como fórmula). **Valores monetários e números (inclusive
  negativos) nunca passam por essa função.** A consulta JSON devolve o texto original (a neutralização vale só para o arquivo).
- Exportação negada no backend para Auxiliar e Secretário (403) **antes** de qualquer consulta; autorização vem antes da validação.
- Somente leitura: consultar e exportar não alteram lançamentos, saldos nem períodos (só a exportação cria a linha de auditoria).

## 8. Frontend

`features/reports/` (`ReportsPage.jsx`, `reportsService.js`, `uiHints.js`) e item de menu "Relatórios". Seletor de relatório e de mês
(`max` = mês corrente; o backend valida de verdade), filtros conforme o relatório, ordenação, **Consultar** (aplica os filtros), botões
**Exportar CSV/Excel** (só se `meta.permissoes.exportar`; desabilitados enquanto houver filtro alterado e ainda não consultado, para o
arquivo ser sempre igual à tela), cartões de totais, tabela com paginação, loading, estado vazio, erro tratado com "Tentar novamente".
Auxiliar: sem botões de exportação, aviso de escopo e sem saldos. O que a tela mostra vem da API (`/relatorios`), nunca do perfil.
Download: função **separada** `apiDownload`/`callDownload` (o `apiRequest` continua só JSON).

## 9. Decisões e interpretações desta fase

1. **Coluna "Tipo" do relatório de Entradas** = tipo da conta (Banco/Caixa); a entrada não tem outro atributo de tipo.
2. **Estornos**: originais estornadas e linhas de estorno ficam fora das listas de Entradas/Movimentação; nas Despesas a original
   estornada aparece com status "Estornada" (fora dos totais).
3. **Transferência na Movimentação**: uma linha por lado (enviada/recebida), com totais próprios e sem entrar em receita/despesa.
4. **Despesas listadas no mês**: pagas/estornadas pelo `data_pagamento`; pendentes/canceladas pela `data_competencia`.
5. **Totais nos arquivos**: bloco após uma linha em branco (o plano exige "totais idênticos à tela"); a auditoria conta só linhas de dados.
6. **Decimal do CSV**: vírgula (compatível com Excel pt-BR + separador `;`).
7. **Limite de exportação**: 10.000 linhas (constante em `config/relatorios.php`).
8. **Catálogo `GET /relatorios`**: rota adicional (permissões/opções vêm do backend, não hardcoded no front).
9. **Ambiente**: `php.ini` do XAMPP teve `extension=zip` e `extension=gd` habilitadas (a versão 5.10 do phpspreadsheet exige as duas; as DLLs já
   existiam). Backup do original guardado; a única diferença são os dois `;` removidos.

## 10. Testes

`tests/Feature/Relatorios/` (permissões, filtros, entradas/despesas, movimentação/saldos/resumo, **equivalência com o Dashboard**, formatos
CSV/XLSX, segurança da exportação, auditoria/limite/somente leitura). Ajustes em testes existentes, por expectativas objetivamente
incompatíveis com a regra aprovada: `CorsTest` (agora expõe também `Content-Disposition`), `ConsultaDeAuditoriaTest` (módulo `exportacoes`
passou a existir no catálogo) e `CoberturaDeAuditoriaTest` (ganhou o item de exportações). Resultados finais: ver o relatório da fase.

Também o roteiro E2E da Fase 10 (fora do projeto, no scratchpad) tinha o número de ações do catálogo fixo (16); com a ação `exported` são 17.
Resultado final da regressão: suíte completa 738 testes / 7337 asserções; E2E Fase 12 97/97; regressão E2E Dashboard 47/47, Auditoria 56/56,
Períodos 36/36, Transferências 112/112; mutação 24/24; oxlint sem avisos; `vite build` sem avisos.
