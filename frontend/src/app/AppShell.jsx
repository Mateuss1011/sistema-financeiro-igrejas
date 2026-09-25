import { useState } from 'react'
import { logout } from '../features/auth/authService'
import AccountsPage from '../features/accounts/AccountsPage'
import { canAccessAccounts } from '../features/accounts/uiHints'
import CategoriesPage from '../features/categories/CategoriesPage'
import EntriesPage from '../features/entries/EntriesPage'
import ExpensesPage from '../features/expenses/ExpensesPage'
import { canAccessExpenses } from '../features/expenses/uiHints'
import { canAccessEntries } from '../features/entries/uiHints'
import TransfersPage from '../features/transfers/TransfersPage'
import { canAccessTransfers } from '../features/transfers/uiHints'
import DashboardPage from '../features/dashboard/DashboardPage'
import ReportsPage from '../features/reports/ReportsPage'
import { canAccessReports } from '../features/reports/uiHints'
import { canAccessDashboard } from '../features/dashboard/uiHints'
import AuditPage from '../features/audit/AuditPage'
import { canAccessAudit } from '../features/audit/uiHints'
import PeriodsPage from '../features/periods/PeriodsPage'
import { canAccessPeriods } from '../features/periods/uiHints'
import UsersPage from '../features/users/UsersPage'
import { canAccessUsers } from '../features/users/uiHints'
import HomePage from './HomePage'

const PAGES = {
  home: 'Início',
  dashboard: 'Dashboard',
  entries: 'Entradas',
  expenses: 'Despesas',
  transfers: 'Transferências',
  accounts: 'Contas',
  categories: 'Categorias',
  periods: 'Fechamento',
  reports: 'Relatórios',
  audit: 'Auditoria',
  users: 'Usuários',
}

/** Estrutura inicial da aplicação: navbar + área principal. Navegação simples por estado (sem router). */
function AppShell({ user, onLoggedOut, onSessionExpired }) {
  const [page, setPage] = useState('home')
  const [loggingOut, setLoggingOut] = useState(false)
  const [logoutError, setLogoutError] = useState(null)

  const availablePages = [
    'home',
    ...(canAccessDashboard(user.perfil?.slug) ? ['dashboard'] : []),
    ...(canAccessEntries(user.perfil?.slug) ? ['entries'] : []),
    ...(canAccessExpenses(user.perfil?.slug) ? ['expenses'] : []),
    ...(canAccessTransfers(user.perfil?.slug) ? ['transfers'] : []),
    ...(canAccessAccounts(user.perfil?.slug) ? ['accounts'] : []),
    'categories',
    ...(canAccessPeriods(user.perfil?.slug) ? ['periods'] : []),
    ...(canAccessReports(user.perfil?.slug) ? ['reports'] : []),
    ...(canAccessAudit(user.perfil?.slug) ? ['audit'] : []),
    ...(canAccessUsers(user.perfil?.slug) ? ['users'] : [])]

  async function handleLogout() {
    setLoggingOut(true)
    setLogoutError(null)

    const result = await logout()

    setLoggingOut(false)

    if (result.ok) {
      onLoggedOut()
      return
    }

    setLogoutError(result.message)
  }

  return (
    <div className="min-vh-100 d-flex flex-column">
      <header className="navbar bg-body border-bottom shadow-sm">
        <div className="container-fluid flex-wrap gap-2 px-3">
          <div className="d-flex align-items-center gap-3 flex-wrap">
            <span className="navbar-brand fw-bold mb-0">SFG</span>
            <nav aria-label="Navegação principal">
              <ul className="nav nav-pills gap-1">
                {availablePages.map((key) => (
                  <li className="nav-item" key={key}>
                    <button
                      type="button"
                      className={`nav-link py-1 ${page === key ? 'active' : ''}`}
                      aria-current={page === key ? 'page' : undefined}
                      onClick={() => setPage(key)}
                    >
                      {PAGES[key]}
                    </button>
                  </li>
                ))}
              </ul>
            </nav>
          </div>

          <div className="d-flex align-items-center gap-2">
            <span className="small text-end lh-sm">
              <span className="d-block fw-semibold">{user.name}</span>
              <span className="text-body-secondary">{user.perfil?.nome_exibicao}</span>
            </span>
            <button type="button" className="btn btn-sm btn-outline-secondary" onClick={handleLogout} disabled={loggingOut}>
              {loggingOut ? 'Saindo...' : 'Sair'}
            </button>
          </div>
        </div>
      </header>

      <main className="container-xl flex-grow-1 py-4">
        {logoutError && (
          <div className="alert alert-danger py-2" role="alert">
            {logoutError}
          </div>
        )}

        {page === 'dashboard' && availablePages.includes('dashboard') ? (
          <DashboardPage currentUser={user} onSessionExpired={onSessionExpired} />
        ) : page === 'reports' && availablePages.includes('reports') ? (
          <ReportsPage currentUser={user} onSessionExpired={onSessionExpired} />
        ) : page === 'entries' && availablePages.includes('entries') ? (
          <EntriesPage currentUser={user} onSessionExpired={onSessionExpired} />
        ) : page === 'expenses' && availablePages.includes('expenses') ? (
          <ExpensesPage currentUser={user} onSessionExpired={onSessionExpired} />
        ) : page === 'transfers' && availablePages.includes('transfers') ? (
          <TransfersPage currentUser={user} onSessionExpired={onSessionExpired} />
        ) : page === 'accounts' && availablePages.includes('accounts') ? (
          <AccountsPage currentUser={user} onSessionExpired={onSessionExpired} />
        ) : page === 'categories' ? (
          <CategoriesPage currentUser={user} onSessionExpired={onSessionExpired} />
        ) : page === 'periods' && availablePages.includes('periods') ? (
          <PeriodsPage currentUser={user} onSessionExpired={onSessionExpired} />
        ) : page === 'audit' && availablePages.includes('audit') ? (
          <AuditPage currentUser={user} onSessionExpired={onSessionExpired} />
        ) : page === 'users' && availablePages.includes('users') ? (
          <UsersPage currentUser={user} onSessionExpired={onSessionExpired} />
        ) : (
          <HomePage user={user} />
        )}
      </main>
    </div>
  )
}

export default AppShell
