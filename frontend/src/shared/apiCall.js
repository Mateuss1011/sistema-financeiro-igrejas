import { apiDownload, apiRequest } from './apiClient'

export const MESSAGES = {
  connection: 'Não foi possível conectar ao servidor. Verifique sua conexão e tente novamente.',
  unauthenticated: 'Sua sessão expirou. Faça login novamente.',
  forbidden: 'Você não tem permissão para realizar esta ação.',
  notFound: 'Registro não encontrado. Ele pode ter sido removido.',
  server: 'Ocorreu um erro no servidor. Tente novamente em instantes.',
  rateLimited: 'Muitas requisições em pouco tempo. Aguarde alguns instantes e tente novamente.',
  unexpected: 'Não foi possível concluir a operação. Tente novamente.',
  validation: 'Verifique os campos destacados e tente novamente.',
  conflict: 'Esta operação não é permitida no estado atual do registro.',
}

// Mensagens do backend em inglês (validação padrão do Laravel) traduzidas para exibição.
function translateValidation(message) {
  const text = String(message).toLowerCase()

  if (text.includes('already been taken')) return 'Este valor já está em uso.'
  if (text.includes('valid email')) return 'Informe um e-mail válido.'
  if (text.includes('at least')) return 'Valor muito curto (mínimo de 8 caracteres para senha).'
  if (text.includes('greater than')) return 'Valor muito longo.'
  if (text.includes('required')) return 'Campo obrigatório.'
  if (text.includes('selected') && text.includes('invalid')) return 'Seleção inválida.'
  if (text.includes('true or false')) return 'Valor inválido.'
  if (text.includes('uncompromised') || text.includes('data leak')) return 'Esta senha apareceu em vazamentos de dados. Escolha outra.'

  // Mensagens já em português vindas do backend (regras de saldo inicial das contas, entradas etc.).
  if (text.includes('saldo inicial')) return message
  if (/^(o|a|um|uma|esta|este|informe)\s/i.test(String(message))) return message

  return 'Valor inválido.'
}

function insufficientBalanceMessage(context) {
  if (context === 'pay-expense') return 'Saldo insuficiente no caixa para pagar esta despesa. O caixa não pode ficar negativo; escolha outra conta.'
  if (context === 'transfer') return 'Saldo insuficiente no caixa de origem. O caixa não pode ficar negativo; escolha outra conta de origem ou reduza o valor.'
  if (context === 'reverse-transfer') return 'Saldo insuficiente no caixa que devolveria o dinheiro. O caixa não pode ficar negativo, então esta transferência não pode ser estornada agora.'
  if (context === 'adjust') return 'Saldo insuficiente no caixa para este ajuste de débito. O caixa não pode ficar negativo.'

  return 'Saldo insuficiente no caixa para estornar esta entrada. O caixa não pode ficar negativo.'
}

// Códigos de negócio conhecidos (HTTP 409). `context` refina a mensagem quando necessário.
function conflictMessage(code, context) {
  if (code === 'CONTA_EM_USO') {
    return 'Esta conta já possui movimentações e não pode ser excluída. Inative-a para que deixe de receber novos lançamentos.'
  }

  if (code === 'CATEGORIA_EM_USO') {
    return 'Esta categoria já está em uso e não pode ser excluída. Inative-a para que deixe de ser oferecida em novos lançamentos.'
  }

  const entryConflicts = {
    CONTA_INATIVA: 'A conta selecionada está inativa e não pode receber lançamentos. Escolha outra conta.',
    CATEGORIA_INATIVA: 'A categoria selecionada está inativa. Escolha outra categoria.',
    PERIODO_FECHADO: 'O período financeiro desta data está fechado. Não é possível criar, alterar, pagar, cancelar, excluir ou estornar lançamentos nele.',
    PERIODO_JA_FECHADO: 'Este período já está fechado.',
    PERIODO_JA_ABERTO: 'Este período já está aberto.',
    ENTRADA_JA_ESTORNADA: 'Esta entrada já foi estornada.',
    ESTORNO_NAO_ESTORNAVEL: 'Um estorno não pode ser estornado. Se necessário, registre um novo lançamento.',
    SALDO_INSUFICIENTE: insufficientBalanceMessage(context),
    TRANSFERENCIA_JA_ESTORNADA: 'Esta transferência já foi estornada.',
    TRANSFERENCIA_NAO_ESTORNAVEL: 'Uma transferência de estorno não pode ser estornada. Se necessário, registre uma nova transferência.',
    DESPESA_NAO_PENDENTE: 'Esta despesa não está mais Pendente (já foi paga, cancelada, estornada ou alterada). Atualize a lista.',
    DESPESA_NAO_PAGA: 'Só é possível estornar uma despesa paga.',
    DESPESA_JA_ESTORNADA: 'Esta despesa já foi estornada.',
    JANELA_EXCLUSAO_EXPIRADA: 'A janela de 48 horas para excluir esta despesa expirou. Cancele-a em vez de excluir.',
    IDEMPOTENCY_KEY_REUTILIZADA: 'Esta tentativa já foi registrada com dados diferentes. Feche o formulário e tente novamente.',
  }
  if (entryConflicts[code]) return entryConflicts[code]

  if (code === 'ULTIMO_PASTOR_ATIVO') {
    return context === 'deactivate'
      ? 'Não é possível desativar este usuário porque ele é o último Pastor ativo da igreja.'
      : 'Não é possível alterar este usuário porque ele é o último Pastor ativo da igreja.'
  }

  return MESSAGES.conflict
}

function describeError(response, context) {
  const { status, data } = response

  if (status === 401) return { kind: 'unauthenticated', status, message: MESSAGES.unauthenticated }
  if (status === 403) return { kind: 'forbidden', status, message: MESSAGES.forbidden }
  if (status === 404) return { kind: 'notFound', status, message: MESSAGES.notFound }

  // Rate limiting (Fase 13): exportações e consultas pesadas têm cota por minuto. A operação NÃO foi executada.
  if (status === 429) return { kind: 'rateLimited', status, message: MESSAGES.rateLimited }

  if (status === 409) {
    return { kind: 'conflict', status, code: data?.code ?? null, message: conflictMessage(data?.code, context) }
  }

  // Exportação (Fase 12): grande demais para o limite síncrono. A mensagem do backend é em português e segura.
  if (status === 422 && data?.code === 'EXPORTACAO_MUITO_GRANDE') {
    return { kind: 'tooLarge', status, code: data.code, message: data.message ?? MESSAGES.validation }
  }

  if (status === 422) {
    const fieldErrors = {}
    for (const [field, messages] of Object.entries(data?.errors ?? {})) {
      fieldErrors[field] = translateValidation(Array.isArray(messages) ? messages[0] : messages)
    }
    return { kind: 'validation', status, message: MESSAGES.validation, fieldErrors }
  }

  // A exportação é sempre auditada: se a auditoria falhar o arquivo não sai (mensagem já é amigável e sem detalhes).
  if (status === 500 && data?.code === 'EXPORTACAO_NAO_AUDITADA') {
    return { kind: 'server', status, code: data.code, message: data.message ?? MESSAGES.server }
  }

  if (status >= 500) return { kind: 'server', status, message: MESSAGES.server }

  return { kind: 'unexpected', status, message: MESSAGES.unexpected }
}

/**
 * Chama a API sem nunca lançar exceção.
 * Retorna { ok: true, data } ou { ok: false, error: { kind, message, code?, fieldErrors? } }.
 * `kind`: connection | unauthenticated | forbidden | notFound | conflict | validation | server | unexpected
 * Nenhuma mensagem exibida ao usuário contém detalhes internos da API.
 */
export async function callApi(path, options, { context } = {}) {
  try {
    const response = await apiRequest(path, options)

    if (response.ok) {
      return { ok: true, data: response.data }
    }

    return { ok: false, error: describeError(response, context) }
  } catch {
    return { ok: false, error: { kind: 'connection', message: MESSAGES.connection } }
  }
}

/**
 * Baixa um arquivo (exportação) sem nunca lançar exceção. Retorna { ok: true, blob, filename } ou
 * { ok: false, error } (mesmo formato de `callApi`). `filename` vem do Content-Disposition; `fallbackName` cobre o caso
 * de o cabeçalho não estar disponível.
 */
export async function callDownload(path, { fallbackName = 'arquivo' } = {}) {
  try {
    const response = await apiDownload(path)

    if (!response.ok) {
      return { ok: false, error: describeError(response) }
    }

    const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(response.disposition ?? '')
    const filename = match ? decodeURIComponent(match[1]) : fallbackName

    return { ok: true, blob: response.blob, filename }
  } catch {
    return { ok: false, error: { kind: 'connection', message: MESSAGES.connection } }
  }
}
