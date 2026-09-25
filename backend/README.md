# SFG — backend (API Laravel)

API REST do Sistema Financeiro para Igrejas: Laravel 12, PHP 8.2, MariaDB/MySQL, Laravel Sanctum (sessão por cookie).

A documentação completa está na raiz do projeto:

- Instalação local, testes e visão geral: [`../README.md`](../README.md)
- Convenções e endpoints da API: [`../docs/api/`](../docs/api/README.md)
- Produção, banco, backup, operação e checklist: [`../docs/deploy/`](../docs/deploy/checklist-de-producao.md)

Comandos frequentes (dentro de `backend/`):

```bash
php artisan test                 # suíte completa (usa o banco sfg_testing)
php artisan migrate --seed       # schema + perfis + categorias padrão
php artisan sfg:criar-pastor     # primeiro Pastor (instalação nova; senha digitada de forma oculta)
php artisan serve                # http://localhost:8000
```

Estrutura: `app/Http` (controllers finos, Form Requests, Resources, middlewares), `app/Services` (regras de negócio e cálculo financeiro),
`app/Policies` (autorização por perfil), `app/Models`, `app/Support` (catálogos, exportação), `database/migrations`, `tests/Feature`.
