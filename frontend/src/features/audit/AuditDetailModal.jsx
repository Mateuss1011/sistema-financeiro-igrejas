import { Fragment } from 'react'
import Modal from '../../shared/components/Modal'
import { formatDataValue, formatDateTime, formatProfile } from './uiFormat'

function DataBlock({ title, data, testId }) {
  const entries = data ? Object.entries(data) : []

  return (
    <div className="mb-3" data-testid={testId}>
      <h3 className="h6">{title}</h3>
      {entries.length === 0 ? (
        <p className="text-body-secondary small mb-0">Nenhum dado registrado.</p>
      ) : (
        <dl className="row small mb-0">
          {entries.map(([key, value]) => (
            <Fragment key={key}>
              <dt className="col-sm-4 text-break">
                <code>{key}</code>
              </dt>
              <dd className="col-sm-8 text-break">{formatDataValue(value)}</dd>
            </Fragment>
          ))}
        </dl>
      )}
    </div>
  )
}

/** Detalhe somente leitura de um registro de auditoria. Nada aqui edita ou exclui o log. */
function AuditDetailModal({ log, onClose }) {
  const profile = formatProfile(log.usuario?.perfil)

  return (
    <Modal
      title={`${log.modulo_rotulo} · ${log.acao_rotulo}`}
      size="modal-lg"
      onClose={onClose}
      footer={
        <button type="button" className="btn btn-outline-secondary" onClick={onClose}>
          Fechar
        </button>
      }
    >
      <dl className="row mb-3">
        <dt className="col-sm-4">Registro nº</dt>
        <dd className="col-sm-8">{log.id}</dd>
        <dt className="col-sm-4">Data e hora</dt>
        <dd className="col-sm-8">{formatDateTime(log.created_at)}</dd>
        <dt className="col-sm-4">Módulo</dt>
        <dd className="col-sm-8">{log.modulo_rotulo}</dd>
        <dt className="col-sm-4">Ação</dt>
        <dd className="col-sm-8">{log.acao_rotulo}</dd>
        <dt className="col-sm-4">Registro afetado</dt>
        <dd className="col-sm-8">{log.registro_id ? `#${log.registro_id}` : '—'}</dd>
        <dt className="col-sm-4">Responsável</dt>
        <dd className="col-sm-8">
          {log.usuario?.nome ? (
            <>
              {log.usuario.nome}
              {profile && <span className="text-body-secondary"> · {profile}</span>}
            </>
          ) : (
            <span className="text-body-secondary">Sem usuário identificado</span>
          )}
        </dd>
        <dt className="col-sm-4">Justificativa</dt>
        <dd className="col-sm-8 text-break">{log.justificativa ?? '—'}</dd>
        <dt className="col-sm-4">Endereço IP</dt>
        <dd className="col-sm-8">{log.ip ?? '—'}</dd>
        <dt className="col-sm-4">Navegador</dt>
        <dd className="col-sm-8 text-break small">{log.user_agent || '—'}</dd>
      </dl>

      <DataBlock title="Dados anteriores" data={log.dados_anteriores} testId="audit-before" />
      <DataBlock title="Dados novos" data={log.dados_novos} testId="audit-after" />
    </Modal>
  )
}

export default AuditDetailModal
