<?php

namespace App\Services;

use App\Exceptions\ExportacaoNaoAuditadaException;
use App\Models\User;
use App\Support\Exportacao\CsvRelatorioWriter;
use App\Support\Exportacao\XlsxRelatorioWriter;
use Illuminate\Support\Facades\Log;

/**
 * Fase 12: exportação (CSV/XLSX) SÍNCRONA de TODO o conjunto filtrado (nunca só a página da tela), sempre auditada.
 *
 * Ordem obrigatória do fluxo (a autorização e a validação dos filtros já aconteceram na Request/Policy):
 *   1. consultar os dados (todas as linhas, limite `relatorios.limite_exportacao`) e calcular os totais;
 *   2. montar o conteúdo do arquivo;
 *   3. REGISTRAR A AUDITORIA;
 *   4. só então devolver o arquivo ao controller.
 * Se a auditoria falhar, o arquivo NÃO sai: o erro técnico vai para o log e a resposta é 500 padronizado.
 */
class ExportacaoRelatorioService
{
    private const TIPOS = [
        'csv' => 'text/csv; charset=UTF-8',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public function __construct(
        private RelatorioService $relatorios,
        private AuditoriaService $auditoria,
        private CsvRelatorioWriter $csv,
        private XlsxRelatorioWriter $xlsx,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filtros  já validados (inclui `ano_mes`)
     * @return array{conteudo: string, nome: string, tipo: string}
     */
    public function exportar(User $ator, string $relatorio, string $formato, array $filtros): array
    {
        // 1. dados + totais (todas as linhas)
        $resultado = $this->relatorios->consultar($ator, $relatorio, $filtros, null, 1, (int) config('relatorios.limite_exportacao'));

        // 2. conteúdo do arquivo
        $conteudo = $formato === 'xlsx' ? $this->xlsx->gerar($resultado) : $this->csv->gerar($resultado);
        $nome = "sfg-relatorio-{$relatorio}-{$resultado->anoMes}.{$formato}";

        // 3. auditoria — obrigatória e ANTES da entrega
        try {
            $this->auditoria->registrar(
                acao: 'exported',
                modulo: 'exportacoes',
                dadosNovos: [
                    'relatorio' => $relatorio,
                    'formato' => $formato,
                    'ano_mes' => $resultado->anoMes,
                    'filtros' => $resultado->filtros,
                    'linhas' => count($resultado->linhas),
                    'totais' => collect($resultado->totais)->mapWithKeys(fn ($t) => [$t['chave'] => $t['valor']])->all(),
                    'arquivo' => $nome,
                    'resultado' => 'gerado',
                ],
                usuario: $ator,
            );
        } catch (\Throwable $e) {
            Log::error('Falha ao auditar exportação; arquivo NÃO entregue.', [
                'usuario_id' => $ator->id,
                'relatorio' => $relatorio,
                'formato' => $formato,
                'erro' => $e->getMessage(),
            ]);

            throw new ExportacaoNaoAuditadaException($e);
        }

        // 4. entrega
        return ['conteudo' => $conteudo, 'nome' => $nome, 'tipo' => self::TIPOS[$formato]];
    }
}
