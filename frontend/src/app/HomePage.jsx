function HomePage({ user }) {
  return (
    <section aria-labelledby="home-title">
      <h1 className="h4 mb-3" id="home-title">
        Bem-vindo(a), {user.name}
      </h1>

      <div className="card shadow-sm" style={{ maxWidth: '32rem' }}>
        <div className="card-body">
          <div className="d-flex align-items-center justify-content-between mb-3">
            <h2 className="h6 mb-0">Sua conta</h2>
            <span className="badge text-bg-success">Sessão autenticada</span>
          </div>
          <dl className="row mb-0">
            <dt className="col-sm-4">Nome</dt>
            <dd className="col-sm-8">{user.name}</dd>

            <dt className="col-sm-4">E-mail</dt>
            <dd className="col-sm-8">{user.email}</dd>

            <dt className="col-sm-4">Perfil</dt>
            <dd className="col-sm-8 mb-0">{user.perfil?.nome_exibicao ?? '—'}</dd>
          </dl>
        </div>
      </div>
    </section>
  )
}

export default HomePage
