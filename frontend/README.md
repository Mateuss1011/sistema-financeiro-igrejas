# SFG — frontend (React + Vite)

SPA do Sistema Financeiro para Igrejas: React 19, Vite, JavaScript, Bootstrap 5 (puro) e `fetch` nativo. Não usa TypeScript, Tailwind,
React-Bootstrap nem `localStorage`: a sessão é só o cookie `HttpOnly` da API.

```bash
cp .env.example .env     # VITE_API_URL=http://localhost:8000/api/v1
npm install
npm run dev              # http://localhost:5173
npm run lint             # oxlint
npm run build            # gera dist/ (VITE_API_URL é embutida no bundle — nunca coloque segredo em variável VITE_*)
```

Organização: `src/features/<módulo>` (uma pasta por funcionalidade), `src/shared` (cliente da API, componentes e utilitários),
`src/app` (shell e navegação). A autorização real é sempre do backend; o frontend só mostra o que a API permite.

Documentação geral e de produção: [`../README.md`](../README.md) e [`../docs/deploy/`](../docs/deploy/checklist-de-producao.md).
