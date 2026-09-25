import { useEffect, useId } from 'react'

/**
 * Modal simples com as classes CSS do Bootstrap (sem o JavaScript do Bootstrap).
 * `level` permite empilhar um modal (ex.: confirmação) sobre outro.
 */
function Modal({ title, onClose, busy = false, size = '', level = 1, children, footer }) {
  const titleId = useId()
  const zIndex = 1050 + level * 10

  useEffect(() => {
    function handleKeyDown(event) {
      if (event.key === 'Escape' && !busy) {
        event.stopPropagation()
        onClose()
      }
    }

    document.addEventListener('keydown', handleKeyDown)
    document.body.classList.add('modal-open')

    return () => {
      document.removeEventListener('keydown', handleKeyDown)
      if (level === 1) document.body.classList.remove('modal-open')
    }
  }, [busy, onClose, level])

  return (
    <>
      <div className="modal-backdrop fade show" style={{ zIndex }}></div>
      <div
        className="modal fade show d-block"
        style={{ zIndex: zIndex + 5 }}
        tabIndex="-1"
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        onMouseDown={(event) => {
          if (event.target === event.currentTarget && !busy) onClose()
        }}
      >
        <div className={`modal-dialog modal-dialog-scrollable ${size}`}>
          <div className="modal-content">
            <div className="modal-header">
              <h2 className="modal-title h5" id={titleId}>
                {title}
              </h2>
              <button type="button" className="btn-close" aria-label="Fechar" onClick={onClose} disabled={busy}></button>
            </div>
            <div className="modal-body">{children}</div>
            {footer && <div className="modal-footer">{footer}</div>}
          </div>
        </div>
      </div>
    </>
  )
}

export default Modal
