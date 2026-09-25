# Configuração de produção

> **Nada aqui foi executado em produção.** Não existe ambiente de produção autorizado. Tudo o que pôde ser verificado localmente foi
> (marcado como *verificado*); o que depende da infraestrutura final está marcado como *depende de infraestrutura* e nunca recebe valores
> inventados. Ver também [`checklist-de-producao.md`](checklist-de-producao.md).

## 1. Topologia (plano oficial, §19)

Frontend e backend em **subdomínios separados** do mesmo domínio: `app.<domínio>` (arquivos estáticos do React) e `api.<domínio>`
(Laravel). Os domínios reais ainda serão definidos; abaixo usa-se `exemplo.org` só como ilustração.

- `app.exemplo.org` → publica a pasta `frontend/dist/` (só HTML/JS/CSS; a aplicação não tem roteador, então não precisa de *fallback* de rota).
- `api.exemplo.org` → servidor web apontando para `backend/public/` (PHP-FPM). É a única origem que fala com o banco.

Por serem subdomínios do mesmo domínio, o navegador trata as chamadas como *same-site*: o cookie de sessão `SameSite=Lax` acompanha as
requisições `fetch(..., { credentials: 'include' })` do frontend à API.

## 2. Variáveis do backend (`backend/.env`)

O modelo é `backend/.env.example` (**já vem com padrões seguros de produção e sem nenhum segredo**). O `.env` real nunca é versionado.

| Variável | Valor em produção | Observação |
|---|---|---|
| `APP_ENV` | `production` | também ativa HTTPS obrigatório, HSTS e cookie `Secure` por padrão |
| `APP_DEBUG` | `false` | nunca `true`; mesmo por engano, o 500 continua genérico, mas não conte com isso |
| `APP_KEY` | gerada no servidor: `php artisan key:generate` | segredo; guarde no cofre da equipe; trocar invalida sessões |
| `APP_URL` | `https://api.<domínio>` | o redirecionamento HTTP→HTTPS usa o host daqui (nunca o cabeçalho `Host`) |
| `APP_LOCALE` / `APP_FALLBACK_LOCALE` | `en` | as mensagens de validação em inglês são traduzidas pelo frontend |
| `BCRYPT_ROUNDS` | `12` | não baixar |
| `LOG_CHANNEL` / `LOG_STACK` / `LOG_DAILY_DAYS` / `LOG_LEVEL` | `stack` / `daily` / `14` / `warning` | rotação diária por arquivo em `storage/logs` |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE` | `mysql`, host/porta do MariaDB, nome do banco | *depende de infraestrutura* |
| `DB_USERNAME`, `DB_PASSWORD` | usuário **restrito da aplicação** (nunca root) | criado por `docs/deploy/mariadb-privilegios.sql`; o usuário de migrations **não** entra aqui |
| `SESSION_DRIVER` | `database` | tabela `sessions` (migration inclusa) |
| `SESSION_LIFETIME` | `120` | minutos |
| `SESSION_ENCRYPT` | `true` | |
| `SESSION_DOMAIN` | `.<domínio>` (com ponto inicial) | **obrigatório** com subdomínios: o frontend precisa ler o cookie `XSRF-TOKEN` que a API define |
| `SESSION_SECURE_COOKIE` | `true` | cookie só por HTTPS (o padrão já é `true` quando `APP_ENV=production`) |
| `SESSION_SAME_SITE` | `lax` | |
| `SANCTUM_STATEFUL_DOMAINS` | `app.<domínio>` | host do frontend, sem esquema; se a lista for definida, ela **substitui** os padrões de localhost |
| `CORS_ALLOWED_ORIGINS` | `https://app.<domínio>` | origem(ns) HTTPS separadas por vírgula; nunca `*` |
| `TRUSTED_PROXIES` | vazio, ou o IP/CIDR **real** do proxy | ver seção 5 |
| `CACHE_STORE` / `QUEUE_CONNECTION` | `database` / `database` | a aplicação **não tem jobs**: não é preciso worker de fila nem agendador |
| `FILESYSTEM_DISK` | `local` | não há upload de arquivos; `storage:link` não é necessário |

Timezone: `config('app.timezone')` é `UTC` (carimbos gravados em UTC); as regras de negócio por data usam `America/Sao_Paulo` no código.
Não altere `app.timezone`.

## 3. HTTPS

- Em produção (`APP_ENV=production`) a API **exige HTTPS** (middleware `ExigirHttpsEmProducao`): `GET`/`HEAD` por HTTP recebem `308` para
  `https://<host de APP_URL>…`; qualquer outro método recebe `426 HTTPS_OBRIGATORIO` (o corpo — senha, dados financeiros — já teria
  trafegado em claro, então não se redireciona). `/up` e `/api/v1/health` são isentos (health-check do balanceador).
- Com HTTPS a resposta leva `Strict-Transport-Security: max-age=31536000; includeSubDomains`.
- O certificado TLS e o redirecionamento na porta 80 são da infraestrutura (*depende de infraestrutura*).

*Verificado localmente* (servidor `artisan serve` com `APP_ENV=production` e configuração em cache): `GET` por HTTP → `308` para o host de
`APP_URL`; `POST` por HTTP → `426`; `/api/v1/health` → `200`; requisição vinda de proxy confiável com `X-Forwarded-Proto: https` → chega à
autenticação (`401`) com `Strict-Transport-Security` e demais cabeçalhos de segurança.

## 4. Cookies, Sanctum e CORS (juntos)

Para o login funcionar entre `app.` e `api.`, **todas** estas condições precisam valer ao mesmo tempo:

1. `SANCTUM_STATEFUL_DOMAINS=app.<domínio>` — só requisições cuja `Origin`/`Referer` seja esse host recebem sessão de cookie.
2. `CORS_ALLOWED_ORIGINS=https://app.<domínio>` — a API libera credenciais **só** para essa origem.
3. `SESSION_DOMAIN=.<domínio>` — o cookie (e o `XSRF-TOKEN`) vale para os dois subdomínios.
4. `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=lax`, cookie `HttpOnly` (padrão).
5. Frontend compilado com `VITE_API_URL=https://api.<domínio>/api/v1` (o `apiClient` deriva a origem de `/sanctum/csrf-cookie` dela).

Sintoma clássico de configuração errada: login retorna `419` (CSRF) ou `403 ORIGEM_NAO_PERMITIDA` (origem fora de
`SANCTUM_STATEFUL_DOMAINS`). Ver [`operacao-e-troubleshooting.md`](operacao-e-troubleshooting.md).

## 5. Proxy reverso e `TRUSTED_PROXIES`

**Quando configurar:** somente se existir um proxy/balanceador na frente do PHP que **termina o TLS** (o PHP recebe HTTP puro e o proxy
manda `X-Forwarded-Proto: https`). Se o servidor web da própria API recebe o HTTPS direto (nginx/Apache + PHP-FPM na mesma máquina, sem
proxy na frente), **deixe vazio**.

**Onde:** `TRUSTED_PROXIES` no `.env` do backend (lido por `config/app.php` → `trusted_proxies`; **não** use `env()` em outro lugar, pois
`config:cache` não carrega o `.env`). Aceita IPs e CIDRs separados por vírgula (`10.0.0.5, 10.0.1.0/24`) ou `*`.

**Como descobrir o valor:** é o endereço **de onde o proxy conecta ao PHP** — o `REMOTE_ADDR` que o Laravel enxerga. Em uma requisição de
teste, veja-o no log do servidor web/PHP-FPM ou peça ao provedor a faixa de IPs do balanceador. Não copie valores de exemplo.

**Comportamento seguro:** lista vazia ⇒ nenhum cabeçalho `X-Forwarded-*` é acreditado (e, sem HTTPS real, a API redireciona/recusa).
Listar só os IPs do proxy ⇒ requisições de outros endereços com `X-Forwarded-Proto` forjado continuam sendo tratadas como HTTP.
`*` só é aceitável se a API for **inalcançável** sem passar pelo proxy (regra de firewall). Sintoma de proxy não confiado: redirecionamento
`308` em loop no navegador.

*Verificado localmente:* testes `ProxiesConfiaveisEHttpsTest` (proxy listado ⇒ HTTPS reconhecido; proxy não listado ou lista vazia ⇒ `308`)
e a rehearsal com `config:cache` acima (a lista vem da configuração em cache).

## 5.1 Servidor web (exemplo — não executado)

Exemplo de bloco `nginx` para a API (ajuste caminhos/socket; certificado e `server_name` reais são da infraestrutura):

```nginx
server {
    listen 443 ssl http2;
    server_name api.exemplo.org;
    root /var/www/sfg/backend/public;
    index index.php;

    location / { try_files $uri /index.php?$query_string; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
    location ~ /\.(?!well-known) { deny all; }   # .env, .git etc. nunca públicos
}
server { listen 80; server_name api.exemplo.org; return 301 https://$host$request_uri; }
```

O frontend (`app.exemplo.org`) é só um `root` para `frontend/dist/`. **A raiz do site da API é `backend/public/`** — nunca a pasta do projeto
(exporia `.env`).

## 6. Implantação do backend (passos)

Executados no servidor, dentro de `backend/`, nesta ordem (ver o checklist para as verificações):

```bash
composer install --no-dev --optimize-autoloader --no-interaction   # sem dependências de desenvolvimento
cp .env.example .env && editar .env                                # valores da seção 2
php artisan key:generate --force                                   # só na primeira instalação
# --- banco: docs/deploy/banco-e-backup.md ("Ordem de execução"): usuários, migrations com o MIGRADOR, privilégios ---
# (--seed só na PRIMEIRA instalação: cria os 5 perfis fixos e as categorias padrão; nas atualizações use só `migrate --force`)
DB_USERNAME=<migrador> DB_PASSWORD=<senha> php artisan migrate --force --seed --no-interaction
php artisan sfg:criar-pastor                                       # só na primeira instalação; ver operação
php artisan config:cache && php artisan route:cache && php artisan event:cache
# permissões: o usuário do PHP-FPM precisa de escrita em storage/ e bootstrap/cache/
```

*Verificado localmente* numa cópia **sem** `vendor`, `tests` e `.env`: `composer install --no-dev --optimize-autoloader` instala e a aplicação
sobe; `migrate --force --seed` cria as 19 tabelas num banco vazio; `config:cache`, `route:cache` e `event:cache` concluem; `artisan about`
mostra `Environment production`, `Debug Mode OFF`.

Atualizações depois do primeiro deploy: `php artisan down` → atualizar código → `composer install --no-dev …` → migrations (migrador) →
`php artisan config:cache route:cache event:cache` → `php artisan up`. Migration nova que crie tabela exige o `GRANT` correspondente em
`docs/deploy/mariadb-privilegios.sql` (o teste `PrivilegiosDoUsuarioDaAplicacaoTest` avisa se faltar).

## 7. Compilação do frontend

Na máquina de build (não precisa de Node no servidor de produção):

```bash
cd frontend
npm ci
VITE_API_URL=https://api.<domínio>/api/v1 npm run build     # gera frontend/dist/
```

`VITE_API_URL` é embutida no JavaScript (é pública por natureza — **nunca** coloque segredo em variável `VITE_*`). Publique o conteúdo de
`dist/` em `app.<domínio>`. O bundle não usa `localStorage`/`sessionStorage`; a sessão é só o cookie `HttpOnly`.
