# Divergências conhecidas entre o plano oficial e o que foi construído

Este registro é **informativo**: nenhuma delas foi alterada na Fase 14 (a fase não cria funcionalidade nem muda regra de
negócio). Cada item precisa de uma decisão explícita de quem aprova o projeto — manter como está ou abrir trabalho novo.
O plano oficial (`docs/superpowers/specs/2026-09-17-sfg-plano-oficial-design.md`) permanece intacto.

| # | Plano oficial | Estado real | Origem / observação |
|---|---|---|---|
| 1 | §4, matriz: Tesoureiro em **Transferências**: "Criar (estorno só com exceção)"; §5: "estorno exclusivo do Pastor" | **Tesoureiro estorna transferências sem exceção** (`TransferenciaPolicy::permissoes`: Pastor e Tesoureiro `estornar = true`; Administrador precisa de `transferencias.operar` **e** `transferencias.estornar`) | Decisão de implementação da Fase 8 (documentada no docblock da Policy e coberta por testes). É a única divergência de permissão encontrada ao conferir a matriz do plano com o comportamento real. |
| 2 | §2/§4/§8/§10: módulo **Configurações** (tabela `configuracoes`, tela, permissão "Institucional") | Não existe tabela `configuracoes`, endpoint nem tela | A Fase 12 decidiu não ter cabeçalho institucional nos arquivos exportados; nenhuma fase criou o módulo. |
| 3 | §9: filtros `?filtro[campo]=` | Filtros planos (`?conta_id=1&data_de=2026-01-01`) | Convenção adotada desde as primeiras listagens e mantida na Fase 12. |
| 4 | §7: Events/Listeners para gravar a auditoria | `AuditoriaService::registrar()` é chamado diretamente pelos Services | Cobertura de auditoria testada (`CoberturaDeAuditoriaTest`). |
| 5 | §7/§10: React Hook Form, axios com interceptors, rotas protegidas, `usePermissao`, telas "Acesso negado" e "Página não encontrada" | `fetch` nativo (`shared/apiClient.js`, `shared/apiCall.js`), navegação por estado no `AppShell` (sem roteador), menus/telas decididos pelos dados da API; sem telas dedicadas de acesso negado/404 (a API responde 401/403/404 e a tela mostra a mensagem) | Não há pacote `axios`, `react-router` nem `react-hook-form` no `package.json`. A autorização real está sempre no backend. |
| 6 | §9: "Erro: `{ message, code, errors }`" e "Sucesso: `{ data, meta }`" | Erros seguem o contrato. Sucessos seguem `{ data }` (listagens paginadas trazem também `links` e `meta` do paginador) | Padrão dos API Resources do Laravel. |
| 7 | §12: testes de frontend (componentes, rotas protegidas, estados) | Não há suíte de testes de frontend; a validação foi por lint, build e roteiros E2E manuais no Chrome (fora do repositório) | — |
| 8 | §11: `frontend/src/app/{routes,providers}`, `shared/{hooks,services,context}` | Estrutura mais enxuta: `src/app`, `src/features/<módulo>`, `src/shared/{components,utils}` | Organização por funcionalidade preservada. |
| 9 | §14, Fase 14: "doc da API" em Markdown | Feita à mão em `docs/api/`; sem Scramble/OpenAPI (o plano marca Scramble como opcional) | — |

## Limitações conhecidas (herdadas das fases anteriores)

- **Fase 9:** o `saldo_inicial` de uma conta criada depois de um mês passado ainda entra no saldo histórico desse mês.
- **Fase 13:** IDs sequenciais + `DELETE /despesas/{id}` responde 404 (inexistente) × 403 (existe, sem permissão), o que permite
  sondar a existência de ids por perfis sem acesso; `AuditLog::query()->update()/delete()` (query builder) não dispara eventos do
  model — a garantia forte é o usuário de banco sem `UPDATE`/`DELETE` em `audit_logs` (`docs/deploy/banco-e-backup.md`).
- Mensagens de validação do Laravel continuam em inglês (`APP_LOCALE=en`); o frontend traduz as mais comuns.
- Fuso: `config('app.timezone')` = `UTC` (carimbos gravados em UTC); as regras de negócio por data usam `America/Sao_Paulo` no código
  (`App\Support\AnoMes`, validações "não pode ser futura").
