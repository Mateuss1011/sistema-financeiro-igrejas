-- =============================================================================
-- SFG — privilégios do MariaDB/MySQL para produção (plano oficial §6 e §13).
--
-- Objetivo: o usuário que a APLICAÇÃO usa em produção NÃO pode alterar nem excluir registros de `audit_logs`
-- (auditoria imutável também no nível do banco). Dois usuários separados:
--
--   __SFG_MIGRATOR_USER__  usado SÓ no deploy, para `php artisan migrate` (DDL). Nunca vai para o .env da aplicação.
--   __SFG_APP_USER__       usado pela aplicação (DB_USERNAME/DB_PASSWORD do .env): só DML, e em `audit_logs`
--                          apenas SELECT e INSERT.
--
-- COMO USAR (uma vez, por um administrador do banco, ANTES do primeiro deploy):
--   1. Copie este arquivo para FORA do repositório e troque os marcadores abaixo pelos valores reais
--      (senhas fortes e únicas, geradas por você; nunca versione o arquivo preenchido):
--        __SFG_DB__               nome do banco, ex.: sfg
--        __SFG_HOST__             de onde o usuário conecta, ex.: localhost ou o IP do servidor da aplicação (evite '%')
--        __SFG_APP_USER__         ex.: sfg_app
--        __SFG_APP_PASSWORD__
--        __SFG_MIGRATOR_USER__    ex.: sfg_migrator
--        __SFG_MIGRATOR_PASSWORD__
--   2. Crie o banco vazio (CREATE DATABASE `__SFG_DB__` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;).
--   3. Rode a PARTE 1 (usuários) como administrador do banco.
--   4. Rode as migrations com o usuário migrador (veja docs/deploy/banco-e-backup.md, "Ordem de execução").
--   5. Rode a PARTE 2 (privilégios da aplicação) como administrador — as tabelas precisam existir.
--      (Rodar o arquivo inteiro de uma vez só funciona DEPOIS das migrations; numa instalação nova, execute
--      as partes na ordem acima.)
--   6. Toda migration futura que criar tabela exige o GRANT correspondente na Parte 2 (o teste
--      `PrivilegiosDoUsuarioDaAplicacaoTest` falha se uma tabela existir sem privilégio concedido aqui).
--
-- Por que GRANT por tabela e não `ON db.*` + REVOKE: no MariaDB/MySQL não é possível revogar uma tabela específica
-- de um privilégio concedido no nível do banco. A única forma de negar UPDATE/DELETE só em `audit_logs` é conceder as
-- demais tabelas individualmente.
-- =============================================================================


-- ---------------------------------------------------------------- Parte 1 — usuários (antes das migrations)

CREATE USER IF NOT EXISTS '__SFG_MIGRATOR_USER__'@'__SFG_HOST__' IDENTIFIED BY '__SFG_MIGRATOR_PASSWORD__';
CREATE USER IF NOT EXISTS '__SFG_APP_USER__'@'__SFG_HOST__' IDENTIFIED BY '__SFG_APP_PASSWORD__';

-- Migrador: dono do schema (cria/altera/remove tabelas). Sem GRANT OPTION e sem acesso a outros bancos.
GRANT ALL PRIVILEGES ON `__SFG_DB__`.* TO '__SFG_MIGRATOR_USER__'@'__SFG_HOST__';


-- ---------------------------------------------------------------- Parte 2 — usuário da aplicação (depois das migrations)

-- Tabelas de negócio: leitura e escrita completas.
GRANT SELECT, INSERT, UPDATE, DELETE ON `__SFG_DB__`.`users` TO '__SFG_APP_USER__'@'__SFG_HOST__';
GRANT SELECT, INSERT, UPDATE, DELETE ON `__SFG_DB__`.`perfis` TO '__SFG_APP_USER__'@'__SFG_HOST__';
GRANT SELECT, INSERT, UPDATE, DELETE ON `__SFG_DB__`.`permissoes_excecao` TO '__SFG_APP_USER__'@'__SFG_HOST__';
GRANT SELECT, INSERT, UPDATE, DELETE ON `__SFG_DB__`.`categorias` TO '__SFG_APP_USER__'@'__SFG_HOST__';
GRANT SELECT, INSERT, UPDATE, DELETE ON `__SFG_DB__`.`contas` TO '__SFG_APP_USER__'@'__SFG_HOST__';
GRANT SELECT, INSERT, UPDATE, DELETE ON `__SFG_DB__`.`periodos_financeiros` TO '__SFG_APP_USER__'@'__SFG_HOST__';
GRANT SELECT, INSERT, UPDATE, DELETE ON `__SFG_DB__`.`entradas` TO '__SFG_APP_USER__'@'__SFG_HOST__';
GRANT SELECT, INSERT, UPDATE, DELETE ON `__SFG_DB__`.`despesas` TO '__SFG_APP_USER__'@'__SFG_HOST__';
GRANT SELECT, INSERT, UPDATE, DELETE ON `__SFG_DB__`.`transferencias` TO '__SFG_APP_USER__'@'__SFG_HOST__';
GRANT SELECT, INSERT, UPDATE, DELETE ON `__SFG_DB__`.`ajustes_saldo` TO '__SFG_APP_USER__'@'__SFG_HOST__';

-- Infraestrutura do framework (sessão, cache, filas): escrita e limpeza frequentes.
GRANT SELECT, INSERT, UPDATE, DELETE ON `__SFG_DB__`.`sessions` TO '__SFG_APP_USER__'@'__SFG_HOST__';
GRANT SELECT, INSERT, UPDATE, DELETE ON `__SFG_DB__`.`cache` TO '__SFG_APP_USER__'@'__SFG_HOST__';
GRANT SELECT, INSERT, UPDATE, DELETE ON `__SFG_DB__`.`cache_locks` TO '__SFG_APP_USER__'@'__SFG_HOST__';
GRANT SELECT, INSERT, UPDATE, DELETE ON `__SFG_DB__`.`jobs` TO '__SFG_APP_USER__'@'__SFG_HOST__';
GRANT SELECT, INSERT, UPDATE, DELETE ON `__SFG_DB__`.`job_batches` TO '__SFG_APP_USER__'@'__SFG_HOST__';
GRANT SELECT, INSERT, UPDATE, DELETE ON `__SFG_DB__`.`failed_jobs` TO '__SFG_APP_USER__'@'__SFG_HOST__';
GRANT SELECT, INSERT, UPDATE, DELETE ON `__SFG_DB__`.`password_reset_tokens` TO '__SFG_APP_USER__'@'__SFG_HOST__';

-- Auditoria: SOMENTE consultar e acrescentar. Sem UPDATE, sem DELETE (nem TRUNCATE/DROP/ALTER: o usuário não tem DDL).
GRANT SELECT, INSERT ON `__SFG_DB__`.`audit_logs` TO '__SFG_APP_USER__'@'__SFG_HOST__';

-- A tabela `migrations` NÃO é concedida ao usuário da aplicação (só o migrador a usa).

FLUSH PRIVILEGES;
