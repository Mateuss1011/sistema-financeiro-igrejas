# Banco de dados, privilégios, backup e restauração

> O plano oficial (§13, §17, §19) define: usuário de produção **sem `UPDATE`/`DELETE` em `audit_logs`**, **migrations testadas em banco
> limpo**, e **backup definido antes do deploy** (bloqueador de *deploy*, não de desenvolvimento), com a estratégia dependendo da
> hospedagem, que ainda não foi escolhida. Por isso a parte de *backup* abaixo traz o **procedimento validado localmente** e **recomendações
> marcadas como tal** — a política final (frequência, retenção, destino) é uma decisão de infraestrutura pendente.

## 1. Requisitos do banco

MariaDB 10.4+ (testado em 10.4.32) ou MySQL equivalente, `utf8mb4` / `utf8mb4_unicode_ci`, engine InnoDB (transações, chaves estrangeiras e
travas `FOR UPDATE` são usadas em todas as operações que afetam saldo). O schema usa 18 restrições `CHECK` e 22 chaves estrangeiras — todas
criadas pelas migrations.

## 2. Ordem de execução numa instalação nova

1. Administrador do banco cria o banco vazio:
   `CREATE DATABASE sfg CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
2. Preencha uma **cópia** de `docs/deploy/mariadb-privilegios.sql` (fora do repositório) e rode a **Parte 1** — cria os dois usuários.
3. Rode as migrations com o **usuário migrador** (DDL) — nunca com o usuário da aplicação:
   `DB_USERNAME=<migrador> DB_PASSWORD=<senha> php artisan migrate --force --seed --no-interaction`
4. Rode a **Parte 2** do script — concede à aplicação apenas o necessário.
5. Configure o `.env` do backend com o usuário **da aplicação** (`DB_USERNAME`/`DB_PASSWORD`).
6. Crie o primeiro Pastor: `php artisan sfg:criar-pastor` (ver [`operacao-e-troubleshooting.md`](operacao-e-troubleshooting.md)).

## 3. Privilégios: o que a aplicação pode e não pode

| Usuário | Pode | **Não** pode |
|---|---|---|
| **Aplicação** (`DB_USERNAME` do `.env`) | `SELECT`, `INSERT`, `UPDATE`, `DELETE` nas tabelas de negócio, sessão, cache e filas; em **`audit_logs`** apenas **`SELECT` e `INSERT`** | `UPDATE`/`DELETE`/`TRUNCATE`/`DROP`/`ALTER` em `audit_logs`; qualquer DDL (`CREATE`/`ALTER`/`DROP` de tabelas); ler a tabela `migrations`; enxergar outros bancos; `GRANT`; `FILE`, `SUPER` e afins |
| **Migrador** (só no deploy) | todos os privilégios **apenas no banco `sfg`** | acesso a outros bancos; `GRANT OPTION`. Não vai para o `.env` da aplicação |

O script é `docs/deploy/mariadb-privilegios.sql` (marcadores `__SFG_*__` a substituir). **Por que `GRANT` por tabela:** o MariaDB/MySQL não
permite revogar uma tabela específica de um privilégio concedido no banco inteiro; a única forma de negar `UPDATE`/`DELETE` só em
`audit_logs` é conceder as demais tabelas uma a uma. Consequência: **toda migration futura que criar tabela precisa do `GRANT`
correspondente no script** — o teste `PrivilegiosDoUsuarioDaAplicacaoTest` falha se existir tabela sem `GRANT`.

**Como aplicar com segurança (não destrutivo):** os comandos só criam usuários e concedem privilégios; não tocam em dados nem em
estrutura, e podem ser repetidos (`CREATE USER IF NOT EXISTS`). Para o banco **já em uso** (depois do primeiro deploy), rode apenas a Parte 2
e teste com uma sessão do usuário da aplicação (`UPDATE audit_logs SET acao='x'` deve falhar com *ERROR 1142 … command denied*).
Para trocar a senha de um usuário: `ALTER USER '<usuário>'@'<host>' IDENTIFIED BY '<nova>'` e atualize o `.env`.

*Verificado localmente* (banco temporário, criado e removido pelo teste): com o usuário restrito, `UPDATE`, `DELETE`, `TRUNCATE`, `DROP TABLE`,
`ALTER TABLE` e `CREATE TABLE` são negados (erro 1142); a API inteira funciona com ele (cadastros, lançamentos, pagamento, transferência,
ajuste, fechamento e reabertura, consultas, exportação auditada, exclusão física de despesa pendente, exceções de permissão); o migrador
cria e apaga tabelas; nenhum outro banco é visível para a aplicação.

## 4. Migrations em banco limpo

`php artisan migrate --force --seed` cria as 19 tabelas, os 5 perfis fixos e as categorias padrão; `migrate:reset` (todos os `down()`)
seguido de nova subida também funciona; rodar `migrate` de novo não altera nada. *Verificado* pelo teste `MigracaoEmBancoLimpoTest` (banco
temporário; nunca `sfg` nem `sfg_testing`). **Nunca** rode `migrate:fresh`, `migrate:reset` ou `db:wipe` em produção.

## 5. Backup

### 5.1 O que entra no backup

- **O banco de dados inteiro** (todas as tabelas: dados financeiros, usuários, `audit_logs`, `migrations`), com estrutura, rotinas e gatilhos.
- O arquivo `backend/.env` (contém segredos: `APP_KEY`, senha do banco) — **guardado separadamente, criptografado, em cofre de segredos**,
  nunca junto do dump nem em repositório. Sem a `APP_KEY` as sessões antigas deixam de valer (o sistema volta a funcionar após novo login).

### 5.2 O que NÃO entra

- `vendor/`, `node_modules/`, `frontend/dist/` e `storage/framework/*` (recriáveis a partir do código e do `composer`/`npm`).
- `storage/logs/` (log técnico; se quiser reter, faça por política de logs à parte).
- Tabelas `cache` e `cache_locks` podem ser excluídas do dump (descartáveis); `sessions` também (só derruba logins ao restaurar).
- O dump **não** deve ser guardado no servidor web nem em pasta pública. Não há arquivos enviados por usuários (não existe upload).

### 5.3 Comando de backup (validado localmente)

```bash
mysqldump -h <host> -u <usuário_de_backup> -p --single-transaction --routines --triggers --events \
          --default-character-set=utf8mb4 --hex-blob sfg > sfg-AAAAMMDD-HHMM.sql
# comprimir e criptografar antes de enviar para fora do servidor (ferramenta a escolher pela infraestrutura)
```

`--single-transaction` gera um retrato consistente sem bloquear o sistema (InnoDB). O usuário de backup só precisa de `SELECT`,
`SHOW VIEW`, `TRIGGER`, `EVENT` e `LOCK TABLES` no banco `sfg` (não use o usuário da aplicação nem o migrador).

### 5.4 Restauração

```bash
mysql -u <admin> -p -e "CREATE DATABASE sfg_restaurado CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u <admin> -p --default-character-set=utf8mb4 sfg_restaurado < sfg-AAAAMMDD-HHMM.sql
```

Restaure **sempre para um banco novo** primeiro e confira antes de apontar o sistema para ele. Restaurar por cima do banco em produção só
com o sistema em manutenção (`php artisan down`) e depois de um backup do estado atual. Depois de restaurar num banco novo, rode o script de
privilégios (Parte 2) para o usuário da aplicação enxergar as tabelas.

### 5.5 Teste de restauração (validado localmente; repita em produção antes de confiar no backup)

1. Crie um banco temporário, restaure o dump nele.
2. Compare tabela a tabela: `CHECKSUM TABLE <origem>.<tabela>` × `CHECKSUM TABLE <restaurado>.<tabela>` (devem ser iguais).
3. Confira `information_schema`: mesmo número de chaves estrangeiras (22) e restrições `CHECK` (18).
4. Apague o banco temporário e o arquivo de dump.

*Verificado localmente* (MariaDB 10.4.32, `mysqldump` do XAMPP, bancos `sfg_fase14_*_tmp` já removidos): dump de um schema migrado com dados de
teste (usuário, conta, entrada, auditoria com acentos) restaurado em outro banco — **as 19 tabelas com `CHECKSUM` idêntico**, 22 chaves
estrangeiras e 18 `CHECK` presentes, texto UTF-8 preservado. Nenhum dado real (`sfg`) foi usado.

### 5.6 Política recomendada — **decisão pendente da infraestrutura**

O plano não fixa frequência nem retenção (depende da hospedagem). Ponto de partida sugerido, para ser confirmado: backup **diário** do
banco; retenção de **30 dias** diários + **12 mensais**; cópia **fora** do servidor de produção; **teste de restauração mensal**;
alerta se um backup falhar ou ficar vazio. A auditoria tem retenção indefinida (plano §6): nunca apague `audit_logs` para “economizar”.

### 5.7 Antes de cada deploy

Faça um backup completo, confira que o arquivo existe e não está vazio, e só então execute migrations. Guarde-o até o pós-deploy ser
aprovado. (Fechamento mensal já bloqueia edição retroativa; o backup é a rede de segurança contra falha de migration.)
