# Checklist final de produção — SFG (fim da Fase 14)

**Nenhum deploy foi feito.** Cada item abaixo tem um status honesto:

- **OK** — verificado de fato neste ambiente de desenvolvimento (a evidência está na coluna ao lado).
- **PENDENTE** — trabalho ou decisão ainda não feitos.
- **DEPENDE DE INFRAESTRUTURA** — só pode ser feito/verificado no ambiente de produção (hospedagem, domínio, TLS…), que ainda não existe.
- **NÃO APLICÁVEL** — o sistema não tem essa parte.

Ambiente de verificação: Windows/XAMPP, PHP 8.2.12, MariaDB 10.4.32, Node 20.19.6. Ver os guias: [`producao.md`](producao.md),
[`banco-e-backup.md`](banco-e-backup.md), [`operacao-e-troubleshooting.md`](operacao-e-troubleshooting.md).

## 1. Código

| Item | Status | Evidência / observação |
|---|---|---|
| Backend: suíte completa | **OK** | 846 testes, 0 falhas (`php artisan test`) |
| Concorrência (transferências, períodos, entradas, despesas, usuários) | **OK** | testes com processos PHP paralelos, dentro da suíte |
| Frontend: lint | **OK** | `npx oxlint` sem avisos |
| Frontend: build | **OK** | `npx vite build` sem avisos |
| Frontend: testes automatizados de componentes | **PENDENTE** | não existem (plano §12 os prevê); validado por lint, build e E2E manual — `docs/divergencias-do-plano.md` #7 |
| E2E no Chrome (login, perfis, menus, dashboard, relatórios/exportação, auditoria, transferências, períodos, segurança) | **OK** | 6 roteiros executados e verdes (segurança 43/43, Dashboard 47/47, Auditoria 56/56, Períodos 36/36, Transferências 112/112, Relatórios 97/97); os roteiros **não estão no repositório** |
| Migrations em banco limpo (`migrate --seed`, `migrate:reset`, nova subida) | **OK** | `MigracaoEmBancoLimpoTest`; 19 tabelas |
| Nenhuma migration alterada ou criada nas Fases 13–14 | **OK** | 14 arquivos em `database/migrations`, inalterados |
| Instalação de produção sem dependências de desenvolvimento | **OK** | `composer install --no-dev --optimize-autoloader` numa cópia sem `vendor`/`tests`/`.env`: sobe, migra, cacheia |
| `config:cache`, `route:cache`, `event:cache` | **OK** | funcionam; nenhum `env()` fora de `config/` (teste automático) |
| Vulnerabilidades em dependências | **OK** | `composer audit`: nenhum alerta; `npm audit --omit=dev`: 0 |
| Segredos no código ou no bundle | **OK** | varredura automática (backend, `.env.example`, frontend, `dist/`) sem achados |
| Divergências plano × implementado | **PENDENTE** | 9 itens em `docs/divergencias-do-plano.md` aguardando decisão (destaque: Tesoureiro estorna transferência; módulo Configurações inexistente) |

## 2. Segurança

| Item | Status | Evidência / observação |
|---|---|---|
| Autorização por perfil em **todas** as rotas | **OK** | `MatrizDeAutorizacaoTest` (5 perfis × 44 rotas + anônimo) |
| Rate limiting (login, exportações, consultas pesadas) | **OK** | testes + E2E; limites em `docs/api/README.md` |
| Cabeçalhos de segurança em toda resposta (inclui 401/404/429/500) | **OK** | testes + verificado por HTTP real |
| HTTPS obrigatório em produção (308/426, HSTS) | **OK** (código) | verificado com servidor local em `APP_ENV=production`; **TLS/certificado: DEPENDE DE INFRAESTRUTURA** |
| Proxy reverso: `TRUSTED_PROXIES` | **OK** (mecanismo) / **DEPENDE DE INFRAESTRUTURA** (valor) | lista vem da config (funciona com `config:cache`); IP real do proxy só a hospedagem informa; vazio = nenhum confiável |
| CORS restrito (origens, métodos, cabeçalhos) | **OK** (código) / **DEPENDE** (origem real) | `CORS_ALLOWED_ORIGINS=https://app.<domínio>` a definir |
| Cookies (`HttpOnly`, `Secure`, `SameSite=Lax`, domínio-pai) | **OK** (config) / **DEPENDE** (domínio) | `SESSION_DOMAIN=.<domínio>` a definir; sem ele o CSRF falha entre subdomínios |
| Sanctum (sessão por cookie, CSRF) | **OK** (código) / **DEPENDE** (`SANCTUM_STATEFUL_DOMAINS`) | CSRF real verificado no E2E (419 sem token) |
| `APP_DEBUG=false` / sem stack trace na resposta | **OK** | 500 sempre genérico (mesmo com debug ligado em produção); `.env.example` já vem com `false` |
| Segredos fora do Git; `.env` ignorado; `.env.example` sem segredos | **OK** | teste automático; `.env` real: **DEPENDE** (preencher no servidor) |
| `audit_logs`: sem rota de escrita, guarda no model | **OK** | testes |
| `audit_logs`: usuário de banco **sem** `UPDATE`/`DELETE` | **OK** (script validado) / **DEPENDE** (aplicar em produção) | `mariadb-privilegios.sql` provado em banco temporário (`UPDATE`/`DELETE`/`TRUNCATE`/`DROP`/`ALTER` negados; API inteira funciona) |
| Senha: hash bcrypt (custo 12), nunca em resposta/log/auditoria | **OK** | testes |
| Recuperação/troca de senha | **PENDENTE** | não existe endpoint nem tela; procedimento de emergência em `operacao-e-troubleshooting.md` §2 |
| Autenticação de dois fatores | **NÃO APLICÁVEL** | fora do MVP (plano §3) |

## 3. Banco de dados

| Item | Status | Evidência / observação |
|---|---|---|
| Migrations/seeders em banco limpo | **OK** | ver Código |
| Integridade: 22 chaves estrangeiras, 18 `CHECK`, índices únicos | **OK** | `AuditoriaEIntegridadeDeBancoTest`, `MigracaoEmBancoLimpoTest`; estados inválidos barrados até por SQL direto |
| Dois usuários de banco (aplicação restrita × migrador) | **OK** (script) / **DEPENDE** (criar no servidor real) | `docs/deploy/mariadb-privilegios.sql` (senhas geradas pela infraestrutura, nunca versionadas) |
| Backup: procedimento (`mysqldump` consistente) | **OK** | validado localmente |
| Restauração + teste de restauração | **OK** (procedimento) | dump restaurado em banco novo: 19 tabelas com `CHECKSUM` idêntico, 22 FKs e 18 CHECKs presentes |
| Política de backup (frequência, retenção, destino, alerta) | **DEPENDE DE INFRAESTRUTURA** | plano §17: bloqueador de deploy até a hospedagem ser escolhida; sugestão em `banco-e-backup.md` §5.6 |
| Teste de restauração em produção | **DEPENDE DE INFRAESTRUTURA** | repetir com o primeiro backup real |

## 4. Infraestrutura

| Item | Status | Observação |
|---|---|---|
| Hospedagem escolhida | **DEPENDE DE INFRAESTRUTURA** | plano §19 pendência 2 |
| Domínio e DNS (`app.` e `api.`) | **DEPENDE DE INFRAESTRUTURA** | domínios reais ainda não definidos (plano §19 pendência 1); **nenhum DNS foi configurado** |
| Certificado TLS (HTTPS) + redirecionamento 80→443 | **DEPENDE DE INFRAESTRUTURA** | |
| PHP 8.2+ com extensões (`pdo_mysql`, `mbstring`, `openssl`, `xml*`, `tokenizer`, `fileinfo`, `ctype`, `json`, `gd`, `zip`) | **DEPENDE DE INFRAESTRUTURA** | lista conferida com `composer check-platform-reqs` |
| MariaDB/MySQL 10.4+ (InnoDB, `utf8mb4`) | **DEPENDE DE INFRAESTRUTURA** | |
| Servidor web: raiz da API = `backend/public/`; frontend = `frontend/dist/` | **DEPENDE DE INFRAESTRUTURA** | exemplo `nginx` em `producao.md` (não executado) |
| Node.js | **NÃO APLICÁVEL** em produção | só para compilar o frontend (pode ser na máquina de build) |
| Worker de fila / agendador (`schedule:run`) | **NÃO APLICÁVEL** | a aplicação não tem jobs nem tarefas agendadas |
| Armazenamento de arquivos (`storage:link`) | **NÃO APLICÁVEL** | não há upload |
| Monitoramento e alertas (disponibilidade, disco, erros 5xx, falha de backup) | **DEPENDE DE INFRAESTRUTURA** | não definido pelo plano; `GET /api/v1/health` está disponível para o *health-check* |

## 5. Operação

| Item | Status | Evidência / observação |
|---|---|---|
| Primeiro Pastor (`php artisan sfg:criar-pastor`) | **OK** (testes) / **PENDENTE** (rodar no servidor) | 7 testes + 5 mutações; execução no terminal real do servidor fica para o pós-deploy |
| Nunca zero Pastor ativo | **OK** | regra + travas de banco, testada com concorrência real |
| Logs técnicos com rotação (`LOG_STACK=daily`, 14 dias) | **OK** (config) | separados da auditoria de negócio |
| Auditoria imutável e consultável (tela Auditoria) | **OK** | |
| Procedimento de recuperação (backup → restauração) | **OK** (documentado e validado localmente) | |
| Manutenção programada (`artisan down/up`) | **OK** (documentado) | |
| Solução de problemas comuns | **OK** (documentado) | `operacao-e-troubleshooting.md` §6 |

## 6. Checklist de deploy (na hora, em ordem)

1. [ ] Aprovação explícita do responsável para publicar. Definir hospedagem, domínios e política de backup.
2. [ ] Criar o banco vazio; rodar a Parte 1 de `mariadb-privilegios.sql` (usuários) com senhas fortes geradas.
3. [ ] Servidor com PHP 8.2 + extensões; código do backend; `composer install --no-dev --optimize-autoloader`.
4. [ ] `.env` a partir de `.env.example` com os valores reais (`producao.md` §2); `php artisan key:generate --force`.
5. [ ] **Backup completo** do estado atual (na primeira instalação não há o que salvar).
6. [ ] `php artisan migrate --force --seed` com o usuário **migrador**; depois a Parte 2 do script (privilégios).
7. [ ] `php artisan sfg:criar-pastor` (terminal interativo).
8. [ ] `php artisan config:cache && php artisan route:cache && php artisan event:cache`; permissões de escrita em `storage/` e `bootstrap/cache/`.
9. [ ] Servidor web da API com raiz em `backend/public/`, TLS válido; `TRUSTED_PROXIES` **somente** se houver proxy que termine o TLS.
10. [ ] Frontend: `VITE_API_URL=https://api.<domínio>/api/v1 npm run build`; publicar `frontend/dist/` em `app.<domínio>`.

## 7. Checklist pós-deploy

1. [ ] `GET https://api.<domínio>/api/v1/health` → `200`; `http://…` (sem TLS) → redireciona (`308`) e `POST` por HTTP → `426`.
2. [ ] Cabeçalhos: `Strict-Transport-Security`, `X-Content-Type-Options`, `Cache-Control: no-store` presentes; erro 404 devolve JSON genérico.
3. [ ] Login pelo frontend com o Pastor criado (sem `419`/`403 ORIGEM_NAO_PERMITIDA`); logout encerra a sessão.
4. [ ] Cookie de sessão: `Secure`, `HttpOnly`, `SameSite=Lax`, domínio `.<domínio>` (ferramentas do navegador).
5. [ ] Origem estranha (`Origin: https://outro.exemplo`) **não** é liberada no CORS.
6. [ ] Como usuário da aplicação no banco: `UPDATE audit_logs …` falha com `ERROR 1142`.
7. [ ] Criar um usuário de cada perfil e conferir menus e acessos; tentar acesso indevido pela API (esperado `403`).
8. [ ] Lançar uma entrada e uma despesa de teste, pagar, transferir, fechar e reabrir um período; conferir a tela **Auditoria**; estornar/limpar os testes conforme regra da igreja (lançamentos não são apagados: estorne).
9. [ ] Exportar CSV e XLSX (abrem no Excel; totais iguais aos da tela; uma linha `exportacoes` na auditoria).
10. [ ] Rodar o **backup** e fazer o **teste de restauração** num banco novo; agendar a rotina.
11. [ ] Conferir `storage/logs` (sem erros 500 inesperados) e o monitoramento.
12. [ ] Guardar `.env` e senhas de banco no cofre da equipe; registrar quem tem acesso ao servidor.
