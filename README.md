# SFG — Sistema Financeiro para Igrejas

Sistema web de gestão financeira para **uma única igreja**: entradas (dízimos, ofertas, doações), despesas, várias contas
bancárias e caixas físicos, transferências, ajustes de saldo, fechamento mensal, dashboard, relatórios com exportação
CSV/Excel e trilha de auditoria completa. Não é multi-tenant.

- **Backend:** Laravel 12 · PHP 8.2 · MariaDB/MySQL · Laravel Sanctum (sessão por cookie).
- **Frontend:** React 19 · Vite · JavaScript · Bootstrap 5 (puro) · `fetch` nativo.
- Arquitetura desacoplada: SPA (`app.…`) consumindo a API REST (`api.…`, prefixo `/api/v1`) em subdomínios separados.

> Plano oficial de referência: [`docs/superpowers/specs/2026-09-17-sfg-plano-oficial-design.md`](docs/superpowers/specs/2026-09-17-sfg-plano-oficial-design.md).
> Documentos técnicos por fase estão na mesma pasta.

## Estado do projeto

As fases 1 a 14 do plano estão concluídas. O que existe, o que foi validado e o que falta para colocar em produção estão em
[`docs/deploy/checklist-de-producao.md`](docs/deploy/checklist-de-producao.md). **Nenhum deploy foi feito.**
As diferenças conhecidas entre o plano e o que foi construído estão em [`docs/divergencias-do-plano.md`](docs/divergencias-do-plano.md).

## Estrutura

```
backend/    API Laravel (app/, config/, database/, routes/, tests/)
frontend/   SPA React (src/features/<módulo>, src/shared, src/app)
docs/
  superpowers/specs/   plano oficial e documentos técnicos das fases
  api/                 documentação da API (convenções e endpoints)
  deploy/              produção, banco/backup, checklist, operação, privilégios SQL
```

## Requisitos

| Componente | Versão testada | Observação |
|---|---|---|
| PHP | 8.2 | extensões: `pdo_mysql`, `mbstring`, `openssl`, `xml`, `simplexml`, `xmlreader`, `xmlwriter`, `libxml`, `tokenizer`, `fileinfo`, `ctype`, `json`, `session`, **`gd`** e **`zip`** (exportação XLSX) |
| Composer | 2.x | |
| MariaDB | 10.4 (MySQL equivalente) | precisa de `CHECK` constraints e colunas JSON |
| Node.js / npm | 20.x / 10.x | **só para compilar** o frontend; produção serve arquivos estáticos |

## Instalação local (do zero)

1. **Banco de dados** (MariaDB rodando). Crie os bancos:
   ```sql
   CREATE DATABASE sfg CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE DATABASE sfg_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; -- usado só pelos testes
   ```
2. **Backend**
   ```bash
   cd backend
   composer install
   cp .env.example .env          # depois edite o .env (bloco "DESENVOLVIMENTO LOCAL" no fim do modelo)
   php artisan key:generate
   php artisan migrate --seed    # cria as tabelas, os 5 perfis fixos e as categorias padrão
   php artisan sfg:criar-pastor  # cria o PRIMEIRO Pastor (pede nome, e-mail e senha de forma oculta)
   php artisan serve             # http://localhost:8000
   ```
   Valores do `.env` para desenvolvimento: `APP_ENV=local`, `APP_DEBUG=true`, `APP_URL=http://localhost:8000`,
   `DB_USERNAME`/`DB_PASSWORD` do seu MariaDB, `SESSION_DOMAIN=localhost`, `SESSION_SECURE_COOKIE=false`,
   `SESSION_ENCRYPT=false`, `SANCTUM_STATEFUL_DOMAINS=localhost:5173`, `CORS_ALLOWED_ORIGINS=http://localhost:5173`.
3. **Frontend**
   ```bash
   cd frontend
   npm install
   cp .env.example .env          # VITE_API_URL=http://localhost:8000/api/v1
   npm run dev                   # http://localhost:5173
   ```
4. Abra `http://localhost:5173` e entre com o Pastor criado no passo 2.

> **Primeiro Pastor:** o sistema não traz usuário nem senha padrão (nada de credencial no código). O comando
> `php artisan sfg:criar-pastor` só funciona com a tabela `users` vazia, nunca aceita a senha por argumento e audita a criação.
> Depois disso, os demais usuários são criados pelo próprio sistema (tela **Usuários**).

## Perfis

Pastor · Administrador · Tesoureiro · Auxiliar financeiro · Secretário. A matriz de permissões (com as exceções pontuais
concedidas pelo Pastor) está no plano oficial, seção 4, e é imposta **no backend** por Policies; o frontend só esconde menus.

## Testes, lint e build

```bash
# Backend (usa o banco sfg_testing, root sem senha — ver backend/phpunit.xml; ajuste lá se o seu MariaDB for diferente)
cd backend && php artisan test

# Frontend
cd frontend && npm run lint && npm run build
```

- A suíte de backend cobre autorização por perfil, regras financeiras, concorrência real (processos paralelos), idempotência,
  auditoria, relatórios/exportação, segurança (rate limit, XSS, SQL injection, mass assignment, IDOR, CORS, headers) e migrações
  em banco limpo. Alguns testes criam bancos/usuários **temporários** no MariaDB local (nomes `sfg_fase13_*`/`sfg_fase14_*`) e os removem.
- Os testes E2E no Chrome foram executados manualmente durante as fases; os roteiros **não fazem parte do repositório**.

## Documentação

| Assunto | Arquivo |
|---|---|
| Convenções e endpoints da API | [`docs/api/README.md`](docs/api/README.md), [`docs/api/endpoints.md`](docs/api/endpoints.md) |
| Configuração de produção (HTTPS, CORS, Sanctum, proxies, cookies) | [`docs/deploy/producao.md`](docs/deploy/producao.md) |
| Banco, privilégios, backup e restauração | [`docs/deploy/banco-e-backup.md`](docs/deploy/banco-e-backup.md) |
| Operação, logs, auditoria e solução de problemas | [`docs/deploy/operacao-e-troubleshooting.md`](docs/deploy/operacao-e-troubleshooting.md) |
| Checklist de deploy e pós-deploy | [`docs/deploy/checklist-de-producao.md`](docs/deploy/checklist-de-producao.md) |
| Segurança (Fase 13) | [`docs/superpowers/specs/2026-09-24-sfg-fase-13-testes-e-seguranca.md`](docs/superpowers/specs/2026-09-24-sfg-fase-13-testes-e-seguranca.md) |
| Relatórios e exportação (Fase 12) | [`docs/superpowers/specs/2026-09-23-sfg-fase-12-relatorios-exportacao.md`](docs/superpowers/specs/2026-09-23-sfg-fase-12-relatorios-exportacao.md) |

## Fora do escopo do MVP

Cadastro de membros, dízimos nominais, anexos, exportação PDF, gráficos, 2FA, folha de pagamento, patrimônio, integração
bancária, notificações, app mobile, multi-tenant e aprovação em múltiplas etapas (plano oficial, seção 3).
