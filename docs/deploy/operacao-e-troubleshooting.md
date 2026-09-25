# Operação, logs, auditoria e solução de problemas

## 1. Primeiro Pastor (instalação nova)

O plano oficial **não define** como o primeiro usuário nasce (*PONTO NÃO DEFINIDO PELO PLANO*). Nenhum seeder cria usuário e não há senha padrão.
O mecanismo mínimo adotado na Fase 14 é o comando:

```bash
cd backend
php artisan sfg:criar-pastor          # pede nome, e-mail e a senha (oculta, com confirmação)
```

- Só funciona com a tabela `users` **vazia** (contando usuários removidos); depois disso responde “Já existem usuários cadastrados”.
- Exige terminal interativo e **nunca** aceita a senha por argumento, opção ou variável de ambiente (não fica em histórico de shell).
- Usa as mesmas regras de nome/e-mail/senha do cadastro pela API e registra a auditoria `usuarios / created` (sem a senha).
- Antes dele, as migrations e o seed precisam ter rodado (perfis existentes). Depois, os demais usuários são criados na tela **Usuários**.

O sistema nunca fica sem Pastor ativo por operação normal: desativar/rebaixar o último Pastor responde `409 ULTIMO_PASTOR_ATIVO`, mesmo com
operações simultâneas (travas no banco; testado com processos paralelos).

*Não verificado neste ambiente:* a execução do comando num terminal real (o prompt oculto do Artisan foi exercitado só nos testes automatizados).
Faça-o uma vez, no servidor, como parte do checklist pós-deploy.

## 2. Senha esquecida ou troca de senha — **lacuna conhecida**

A API **não tem** endpoint de troca/redefinição de senha (nem “esqueci minha senha”); `PUT /usuarios/{id}` nunca altera a senha. Isso não
está no plano oficial do MVP e **não foi criado** (seria funcionalidade nova). Enquanto isso, o procedimento de emergência exige acesso ao
servidor:

```bash
php artisan tinker
>>> $u = App\Models\User::where('email', 'pessoa@igreja.org')->firstOrFail();
>>> $u->password = '<nova senha forte>'; $u->save();      # o Laravel aplica o hash sozinho
>>> exit
```

Cuidados: o histórico do tinker (PsySH) grava o que foi digitado — apague a linha do histórico depois; a pessoa continuará com essa senha,
porque também não há tela para ela trocá-la. Registre a operação fora do sistema (ela não gera auditoria de negócio). **Decisão pendente:** criar um comando
`sfg:redefinir-senha` (interativo, auditado) e/ou um fluxo de troca de senha — listado em “Decisões que precisam de aprovação” no relatório da Fase 14.

## 3. Logs técnicos × auditoria de negócio

| | Log técnico | Auditoria de negócio |
|---|---|---|
| Onde | `backend/storage/logs/laravel-AAAA-MM-DD.log` (rotação diária, 14 dias) | tabela `audit_logs` + tela **Auditoria** (Pastor e Administrador) |
| Conteúdo | erros e exceções do servidor (stack trace **só aqui**, nunca na resposta da API) | quem fez o quê, quando, de onde: login/falhas, usuários, permissões, categorias, contas, entradas, despesas, transferências, ajustes, fechamento/reabertura, exportações |
| Retenção | 14 dias (`LOG_DAILY_DAYS`) | indefinida — nunca apagar |
| Alteração | rotativo | **imutável**: sem rota de escrita, guarda no model e (em produção) usuário de banco sem `UPDATE`/`DELETE` |

Tentativas de login **bloqueadas** por limite (429) não geram auditoria (evita inflar a tabela num ataque); as falhas anteriores ao bloqueio sim
(`login_failed`). Consultas (telas, API) não geram auditoria; **exportações sim** (uma linha por arquivo entregue). Erros 5xx: procure
no log técnico pela hora da falha; a resposta ao usuário só traz `ERRO_INTERNO`.

## 4. Rotinas de manutenção

| Rotina | Frequência sugerida | Como |
|---|---|---|
| Backup do banco + teste de restauração | diário / mensal | `banco-e-backup.md` |
| Conferir espaço em disco e tamanho de `storage/logs` | semanal | a rotação diária limita o log |
| Atualizar dependências e aplicar correções de segurança | mensal | `composer audit` e `npm audit --omit=dev` (ambos sem alertas na Fase 14); testes antes de publicar |
| Revisar usuários ativos e exceções de permissão | trimestral | tela **Usuários** (as exceções são concedidas só pelo Pastor e auditadas) |
| Conferir a tela de Auditoria | conforme política da igreja | filtros por módulo/ação/período |

Não há jobs, filas, agendador (`schedule:run`) nem upload de arquivos: nada disso precisa estar configurado.

## 5. Manutenção programada

`php artisan down` antes de atualizar (a API responde 503) e `php artisan up` ao terminar. Ver a sequência em `producao.md`, seção 6.

## 6. Solução de problemas

| Sintoma | Causa provável | O que fazer |
|---|---|---|
| Login responde `419` | cookie `XSRF-TOKEN` não chegou ao JavaScript (cookie de outro domínio) | `SESSION_DOMAIN=.<domínio>` (com ponto), `SESSION_SECURE_COOKIE=true`, frontend e API no mesmo domínio-pai, HTTPS nos dois |
| Login responde `403 ORIGEM_NAO_PERMITIDA` | a `Origin` do navegador não está em `SANCTUM_STATEFUL_DOMAINS` | conferir o host (sem esquema) e limpar o cache de configuração (`php artisan config:cache`) |
| Erro de CORS no navegador | `CORS_ALLOWED_ORIGINS` diferente da origem real (esquema, host **e** porta) | ajustar e refazer `config:cache` |
| Redirecionamento `308` em loop | HTTPS terminado num proxy que a API não considera confiável | `TRUSTED_PROXIES` com o IP real do proxy (`producao.md`, seção 5) |
| `426 HTTPS_OBRIGATORIO` | requisição com corpo chegando por HTTP em produção | usar HTTPS; conferir se o proxy repassa `X-Forwarded-Proto` |
| `429 MUITAS_TENTATIVAS` no login | 5 falhas/min para o e-mail+IP (ou 30/min por IP) | aguardar o `Retry-After`; em emergência, `php artisan cache:clear` zera os contadores (também limpa o cache da aplicação) |
| `429 MUITAS_REQUISICOES` | exportações (10/min) ou consultas pesadas (60/min) | aguardar o `Retry-After` |
| `401 USUARIO_INATIVO` | usuário foi desativado com a sessão aberta | comportamento esperado; reativar na tela Usuários |
| `409 PERIODO_FECHADO` | competência fechada | só o Pastor reabre (com justificativa) |
| Exportação responde `500 EXPORTACAO_NAO_AUDITADA` | falha ao gravar a auditoria (o arquivo **não** é entregue por desenho) | conferir permissões do usuário do banco em `audit_logs` (precisa de `INSERT`) e o log técnico |
| Exportação `422 EXPORTACAO_MUITO_GRANDE` | mais de 10.000 linhas | filtrar por mês/conta/categoria |
| `500` genérico | erro de servidor | log técnico em `storage/logs`; `APP_DEBUG` deve continuar `false` |
| `SQLSTATE 1142 command denied` | falta `GRANT` para uma tabela nova ao usuário da aplicação | acrescentar a tabela na Parte 2 de `mariadb-privilegios.sql` e reaplicar |
| Migration falha por permissão | migrations rodando com o usuário da aplicação | usar o usuário **migrador** |
| Relatórios XLSX falham | falta a extensão `zip` ou `gd` do PHP | habilitar no `php.ini` do servidor |
| Página em branco / API fora | servidor web apontando para a pasta errada ou PHP-FPM parado | raiz do site da API = `backend/public/`; conferir `GET /api/v1/health` |
