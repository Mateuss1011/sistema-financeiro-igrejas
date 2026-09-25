import Modal from './Modal'

function ConfirmDialog({ title, message, confirmLabel = 'Confirmar', confirmVariant = 'danger', busy = false, onConfirm, onCancel }) {
  return (
    <Modal
      title={title}
      onClose={onCancel}
      busy={busy}
      level={2}
      footer={
        <>
          <button type="button" className="btn btn-outline-secondary" onClick={onCancel} disabled={busy}>
            Cancelar
          </button>
          <button type="button" className={`btn btn-${confirmVariant}`} onClick={onConfirm} disabled={busy}>
            {busy && <span className="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>}
            {confirmLabel}
          </button>
        </>
      }
    >
      <p className="mb-0">{message}</p>
    </Modal>
  )
}

export default ConfirmDialog
