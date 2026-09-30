# SFG — Sistema Financeiro para Igrejas

![PHP](https://img.shields.io/badge/PHP-8.2-777BB4?logo=php&logoColor=white)
![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel&logoColor=white)
![React](https://img.shields.io/badge/React-19-61DAFB?logo=react&logoColor=black)
![Bootstrap](https://img.shields.io/badge/Bootstrap-5-7952B3?logo=bootstrap&logoColor=white)
![MariaDB](https://img.shields.io/badge/MariaDB-10.4-003545?logo=mariadb&logoColor=white)
![Licença](https://img.shields.io/badge/licen%C3%A7a-MIT-green)

Sistema web de gestão financeira para **uma única igreja**: entradas (dízimos, ofertas, doações), despesas, várias contas
bancárias e caixas físicos, transferências, ajustes de saldo, fechamento mensal, dashboard, relatórios com exportação
CSV/Excel e trilha de auditoria completa. Não é multi-tenant.

- **Backend:** Laravel 12 · PHP 8.2 · MariaDB/MySQL · Laravel Sanctum (sessão por cookie).
- **Frontend:** React 19 · Vite · JavaScript · Bootstrap 5 (puro) · `fetch` nativo.
- Arquitetura desacoplada: SPA (`app.…`) consumindo a API REST (`api.…`, prefixo `/api/v1`) em subdomínios separados.

> Plano oficial de referência: [`docs/superpowers/specs/2026-09-17-sfg-plano-oficial-design.md`](docs/superpowers/specs/2026-09-17-sfg-plano-oficial-design.md).
> Documentos técnicos por fase estão na mesma pasta.

## Sumário

- [Objetivo](#objetivo)
- [Principais funcionalidades](#principais-funcionalidades)
- [Perfis e o que cada um faz](#perfis-e-o-que-cada-um-faz-resumo)
- [Relatórios e exportação](#relatórios-e-exportação)
- [Segurança](#segurança)
- [Auditoria](#auditoria)
- [Estado do projeto](#estado-do-projeto)
- [Estrutura](#estrutura)
- [Requisitos](#requisitos)
- [Instalação local](#instalação-local-do-zero)
- [Testes, lint e build](#testes-lint-e-build)
- [Demonstração](#demonstração)
- [Documentação](#documentação)
- [Fora do escopo do MVP](#fora-do-escopo-do-mvp)
- [Licença](#licença)
- [Autor](#autor)

## Objetivo

Projeto de portfólio, construído com padrões de qualidade suficientes para um futuro uso real por uma igreja (plano oficial, seção 1):
regras financeiras rigorosas, autorização no backend, auditoria completa e testes automatizados. **Não há instalação em produção nem
clientes**: o repositório é o código-fonte, a documentação e um ambiente de demonstração local.

## Principais funcionalidades

- **Autenticação** por sessão de cookie (Laravel Sanctum) e **cinco perfis** com permissões impostas por Policies no backend.
- **Usuários** e exceções pontuais de permissão (concedidas só pelo Pastor); o sistema nunca fica sem Pastor ativo.
- **Categorias** e **contas/caixas** (o saldo é sempre calculado a partir dos lançamentos, nunca digitado).
- **Entradas** (imutáveis: correção só por estorno), **despesas** (pendente → paga → estornada, ou cancelada), **transferências** entre
  contas e **ajustes de saldo** justificados — com idempotência e travas de banco contra concorrência.
- **Fechamento mensal** (fechar/reabrir com justificativa), que bloqueia mutações no período.
- **Dashboard** com indicadores do mês (sem gráficos) e **relatórios** com **exportação CSV/XLSX**.
- **Auditoria** somente leitura de tudo que importa.

## Perfis e o que cada um faz (resumo)

| Perfil | Em resumo |
|---|---|
| Pastor | acesso completo, gerencia usuários e exceções, reabre períodos |
| Administrador | consulta e gestão administrativa; **não opera lançamentos** sem exceção do Pastor |
| Tesoureiro | rotina financeira completa; sem Auditoria e sem gestão de usuários |
| Auxiliar financeiro | vê **somente o que criou**; Dashboard parcial; **não exporta**; sem saldos, transferências e ajustes |
| Secretário | sem acesso aos módulos financeiros (só Categorias e Usuários, em leitura) |

A matriz completa de permissões (com as exceções pontuais concedidas pelo Pastor) está no plano oficial, seção 4, e é imposta
**no backend** por Policies; o frontend só esconde menus.

## Relatórios e exportação

Cinco relatórios (Resumo, Entradas, Despesas, Movimentação financeira e Saldos por conta), filtráveis por mês (padrão: mês corrente), com
os **mesmos números do Dashboard** (uma única fonte de cálculo). Exportação **CSV** (UTF-8 com BOM, separador `;`) e **XLSX**, síncrona,
com limite de 10.000 linhas, com proteção contra injeção de fórmulas e **sempre auditada**. O Auxiliar e o Secretário não exportam.

## Segurança

Autorização por Policy em toda rota; CSRF e cookie `HttpOnly`; limite de tentativas no login e nas rotas pesadas; CORS restrito;
cabeçalhos de segurança; HTTPS obrigatório e cookie `Secure` em produção; nenhuma resposta expõe stack trace, SQL, caminhos ou segredos;
logs técnicos separados da auditoria de negócio; senhas com hash bcrypt; nenhum segredo no repositório (`.env` fora do Git). A
auditoria é imutável na aplicação e, em produção, também no banco (usuário de banco sem `UPDATE`/`DELETE` em `audit_logs`, com script em
`docs/deploy/`). Detalhes em [`docs/superpowers/specs/2026-09-24-sfg-fase-13-testes-e-seguranca.md`](docs/superpowers/specs/2026-09-24-sfg-fase-13-testes-e-seguranca.md).

## Auditoria

Toda ação relevante gera um registro imutável (quem, o quê, quando, de onde), consultável na tela **Auditoria** por Pastor e
Administrador. Não existe rota de escrita em `audit_logs`; exportações também são auditadas; consultas não geram log.

## Estado do projeto

As fases do plano (0 a 14) estão concluídas. O que existe, o que foi validado e o que falta para colocar em produção estão em
[`docs/deploy/checklist-de-producao.md`](docs/deploy/checklist-de-producao.md). **Nenhum deploy foi feito: o sistema não está hospedado
em lugar nenhum e não há URL pública.** As diferenças conhecidas entre o plano e o que foi construído estão em
[`docs/divergencias-do-plano.md`](docs/divergencias-do-plano.md).

### Limitações conhecidas

- Não há recuperação nem troca de senha pelo sistema (procedimento de emergência em `docs/deploy/operacao-e-troubleshooting.md`).
- Não existe o módulo **Configurações** previsto no plano.
- O Tesoureiro estorna transferências sem exceção (divergência do plano, documentada).
- Não há testes automatizados de frontend; os testes E2E no Chrome foram manuais e seus roteiros não estão no repositório.
- Deploy, hospedagem, domínio, TLS e política de backup dependem de infraestrutura ainda não definida.

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

## Demonstração

Para apresentar o sistema há um ambiente de demonstração **reproduzível**, com 5 usuários fictícios (um por perfil) e uma massa financeira
pequena e coerente (contas, entradas, despesas em vários estados, estornos, transferência, ajuste, mês anterior fechado). Tudo é criado
pelos mesmos Services da API e o acesso continua sendo o login real, regido pelas Policies existentes.

```bash
cd backend
php artisan sfg:demo          # cria (ou completa) o ambiente; pede a senha de demonstração de forma oculta
php artisan sfg:demo:reset    # remove SOMENTE os dados de demonstração
```

Usuários (e-mails fictícios): `demo.pastor@sfg.demo`, `demo.administrador@sfg.demo`, `demo.tesoureiro@sfg.demo`,
`demo.auxiliar@sfg.demo` e `demo.secretario@sfg.demo`. **Senha: definida pelo operador ao executar `php artisan sfg:demo`** — ela não
existe em nenhum arquivo do projeto. Os comandos recusam rodar com `APP_ENV=production`. Detalhes, dados criados e roteiro por perfil em
[`docs/demo/README.md`](docs/demo/README.md).

## Documentação

| Assunto | Arquivo |
|---|---|
| Convenções e endpoints da API | [`docs/api/README.md`](docs/api/README.md), [`docs/api/endpoints.md`](docs/api/endpoints.md) |
| Configuração de produção (HTTPS, CORS, Sanctum, proxies, cookies) | [`docs/deploy/producao.md`](docs/deploy/producao.md) |
| Banco, privilégios, backup e restauração | [`docs/deploy/banco-e-backup.md`](docs/deploy/banco-e-backup.md) |
| Operação, logs, auditoria e solução de problemas | [`docs/deploy/operacao-e-troubleshooting.md`](docs/deploy/operacao-e-troubleshooting.md) |
| Checklist de deploy e pós-deploy | [`docs/deploy/checklist-de-producao.md`](docs/deploy/checklist-de-producao.md) |
| Ambiente de demonstração | [`docs/demo/README.md`](docs/demo/README.md) |
| Segurança (Fase 13) | [`docs/superpowers/specs/2026-09-24-sfg-fase-13-testes-e-seguranca.md`](docs/superpowers/specs/2026-09-24-sfg-fase-13-testes-e-seguranca.md) |
| Relatórios e exportação (Fase 12) | [`docs/superpowers/specs/2026-09-23-sfg-fase-12-relatorios-exportacao.md`](docs/superpowers/specs/2026-09-23-sfg-fase-12-relatorios-exportacao.md) |

## Fora do escopo do MVP

Cadastro de membros, dízimos nominais, anexos, exportação PDF, gráficos, 2FA, folha de pagamento, patrimônio, integração
bancária, notificações, app mobile, multi-tenant e aprovação em múltiplas etapas (plano oficial, seção 3).

## Licença

Distribuído sob a licença MIT. Veja o arquivo [LICENSE](LICENSE) para mais detalhes.

## Autor

**Mateus Silva Santos**

- GitHub: [@Mateuss1011](https://github.com/Mateuss1011)
- LinkedIn: [Mateus Silva Santos](https://www.linkedin.com/in/mateus-silva-santos-678082283)
