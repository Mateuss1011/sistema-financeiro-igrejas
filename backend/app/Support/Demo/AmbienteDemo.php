<?php

namespace App\Support\Demo;

use App\Enums\PerfilSlug;
use App\Models\AjusteSaldo;
use App\Models\Categoria;
use App\Models\Conta;
use App\Models\Despesa;
use App\Models\Entrada;
use App\Models\Perfil;
use App\Models\PeriodoFinanceiro;
use App\Models\Transferencia;
use App\Models\User;
use App\Services\AjusteSaldoService;
use App\Services\AuditoriaService;
use App\Services\CategoriaService;
use App\Services\ContaService;
use App\Services\DespesaService;
use App\Services\EntradaService;
use App\Services\PeriodoFinanceiroService;
use App\Services\TransferenciaService;
use App\Services\UsuarioService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Ambiente de demonstração do SFG: 5 usuários fictícios e uma massa financeira pequena e coerente.
 *
 * Princípios (não negociáveis):
 *  - TUDO é criado pelos Services reais do sistema (mesmas regras, travas, período fechado, idempotência e AUDITORIA de
 *    verdade). Nenhuma regra é contornada e nenhum log é inserido à mão;
 *  - cada lançamento é feito por um perfil que a Policy atual já autoriza (nenhuma exceção de permissão é concedida);
 *  - os registros demo são identificáveis SEM alterar o schema: usuários pelos e-mails fixos `@sfg.demo`, contas e
 *    categorias criadas aqui pelo prefixo `DEMO - `, lançamentos por `criado_por` (usuário demo) e pela chave de idempotência `demo-*`;
 *  - jamais roda em produção (sem flag para burlar);
 *  - a senha é sempre recebida de fora (nunca existe neste código).
 */
class AmbienteDemo
{
    public const PREFIXO = 'DEMO - ';

    /** @var array<string, array{nome: string, email: string, perfil: PerfilSlug}> */
    public const USUARIOS = [
        'pastor' => ['nome' => 'Demo Pastor', 'email' => 'demo.pastor@sfg.demo', 'perfil' => PerfilSlug::Pastor],
        'administrador' => ['nome' => 'Demo Administrador', 'email' => 'demo.administrador@sfg.demo', 'perfil' => PerfilSlug::Administrador],
        'tesoureiro' => ['nome' => 'Demo Tesoureiro', 'email' => 'demo.tesoureiro@sfg.demo', 'perfil' => PerfilSlug::Tesoureiro],
        'auxiliar' => ['nome' => 'Demo Auxiliar Financeiro', 'email' => 'demo.auxiliar@sfg.demo', 'perfil' => PerfilSlug::AuxiliarFinanceiro],
        'secretario' => ['nome' => 'Demo Secretário', 'email' => 'demo.secretario@sfg.demo', 'perfil' => PerfilSlug::Secretario],
    ];

    public const MENSAGEM_PRODUCAO = 'O ambiente de demonstração não pode ser executado em produção.';

    /** @var array<string, array{criados: int, reaproveitados: int}> */
    private array $contagem = [];

    /** @var list<string> */
    private array $avisos = [];

    private Carbon $hoje;

    public function __construct(
        private UsuarioService $usuarios,
        private ContaService $contas,
        private CategoriaService $categorias,
        private EntradaService $entradas,
        private DespesaService $despesas,
        private TransferenciaService $transferencias,
        private AjusteSaldoService $ajustes,
        private PeriodoFinanceiroService $periodos,
        private AuditoriaService $auditoria,
    ) {
    }

    /** @return list<string> */
    public static function emails(): array
    {
        return array_column(self::USUARIOS, 'email');
    }

    private function garantirNaoProducao(): void
    {
        if (app()->isProduction()) {
            throw new LogicException(self::MENSAGEM_PRODUCAO);
        }
    }

    // ================================================================== criação

    /**
     * Cria (ou reaproveita) o ambiente. Seguro para rodar várias vezes: nada é duplicado.
     *
     * @return array{contagem: array<string, array{criados: int, reaproveitados: int}>, avisos: list<string>}
     */
    public function criar(string $senha): array
    {
        $this->garantirNaoProducao();
        $this->contagem = [];
        $this->avisos = [];
        $this->hoje = Carbon::now('America/Sao_Paulo')->startOfDay();

        // Tudo ou nada: se qualquer passo falhar, nenhum registro parcial fica para trás.
        DB::transaction(function () use ($senha) {
            $u = $this->usuariosDemo($senha);
            $c = $this->contasDemo($u['pastor']);
            $cat = $this->categoriasDemo($u['pastor']);

            $this->mesAnterior($u, $c, $cat);
            $this->mesAtual($u, $c, $cat);
        });

        return ['contagem' => $this->contagem, 'avisos' => $this->avisos];
    }

    private function contar(string $grupo, bool $criado): void
    {
        $this->contagem[$grupo] ??= ['criados' => 0, 'reaproveitados' => 0];
        $this->contagem[$grupo][$criado ? 'criados' : 'reaproveitados']++;
    }

    /** @return array<string, User> */
    private function usuariosDemo(string $senha): array
    {
        $perfis = Perfil::query()->pluck('id', 'slug');
        $resultado = [];

        foreach (self::USUARIOS as $chave => $def) {
            $perfilId = $perfis[$def['perfil']->value] ?? null;
            if ($perfilId === null) {
                throw new LogicException('Perfis não encontrados. Rode as migrations com o seed (php artisan migrate --seed).');
            }

            $existente = User::withTrashed()->where('email', $def['email'])->first();
            if ($existente !== null) {
                if ($existente->trashed()) {
                    $existente->restore();
                }
                $existente->forceFill(['name' => $def['nome'], 'perfil_id' => $perfilId, 'ativo' => true, 'password' => $senha])->save();
                $this->contar('usuarios', false);
                $resultado[$chave] = $existente->refresh();
                continue;
            }

            $dados = ['name' => $def['nome'], 'email' => $def['email'], 'password' => $senha, 'perfil_id' => $perfilId];
            if ($chave === 'pastor') {
                // O primeiro usuário não tem quem o crie: nasce como no bootstrap do sistema, com a auditoria dele mesmo.
                $pastor = User::create($dados + ['ativo' => true]);
                $this->auditoria->registrar(
                    acao: 'created',
                    modulo: 'usuarios',
                    registroId: $pastor->id,
                    dadosNovos: $pastor->only(['name', 'email', 'perfil_id', 'ativo']),
                    justificativa: 'Ambiente de demonstração (sfg:demo)',
                    usuario: $pastor,
                );
                $resultado[$chave] = $pastor->refresh();
            } else {
                // Os demais são cadastrados pelo Demo Pastor, pelo mesmo Service da API (com auditoria).
                $resultado[$chave] = $this->usuarios->criar($dados, $resultado['pastor'])->refresh();
            }
            $this->contar('usuarios', true);
        }

        return $resultado;
    }

    /** @return array<string, Conta> */
    private function contasDemo(User $pastor): array
    {
        $definicoes = [
            'banco' => ['nome' => self::PREFIXO . 'Conta Bancária Principal', 'tipo' => 'banco', 'saldo_inicial' => '5000.00', 'ativa' => true],
            'caixa' => ['nome' => self::PREFIXO . 'Caixa Geral', 'tipo' => 'caixa', 'saldo_inicial' => '300.00', 'ativa' => true],
            'inativa' => ['nome' => self::PREFIXO . 'Conta Reserva (inativa)', 'tipo' => 'banco', 'saldo_inicial' => '0.00', 'ativa' => false],
        ];

        $resultado = [];
        foreach ($definicoes as $chave => $def) {
            $conta = Conta::query()->where('nome', $def['nome'])->first();
            if ($conta !== null) {
                $this->contar('contas', false);
                $resultado[$chave] = $conta;
                continue;
            }

            $conta = $this->contas->criar(['nome' => $def['nome'], 'tipo' => $def['tipo'], 'saldo_inicial' => $def['saldo_inicial']], $pastor);
            if (! $def['ativa']) {
                $conta = $this->contas->atualizar($conta, ['ativa' => false], $pastor);
            }
            $this->contar('contas', true);
            $resultado[$chave] = $conta;
        }

        return $resultado;
    }

    /**
     * Reaproveita a categoria padrão do sistema quando existe e está ativa; senão cria uma "DEMO - ...".
     *
     * @return array<string, Categoria>
     */
    private function categoriasDemo(User $pastor): array
    {
        $usadas = [
            'dizimo' => ['entrada', 'Dízimo'],
            'oferta' => ['entrada', 'Oferta'],
            'doacao' => ['entrada', 'Doação'],
            'outras_receitas' => ['entrada', 'Outras receitas'],
            'aluguel' => ['despesa', 'Aluguel/Manutenção do templo'],
            'agua_luz' => ['despesa', 'Água/Luz/Internet'],
            'material' => ['despesa', 'Material de limpeza/escritório'],
            'outras_despesas' => ['despesa', 'Outras despesas'],
        ];

        $resultado = [];
        foreach ($usadas as $chave => [$tipo, $nome]) {
            $padrao = Categoria::query()->where('tipo', $tipo)->where('nome', $nome)->where('ativa', true)->first();
            if ($padrao !== null) {
                $resultado[$chave] = $padrao;
                continue;
            }

            $demo = Categoria::query()->where('tipo', $tipo)->where('nome', self::PREFIXO . $nome)->first();
            if ($demo !== null) {
                $this->contar('categorias', false);
                $resultado[$chave] = $demo;
                continue;
            }

            $resultado[$chave] = $this->categorias->criar(['nome' => self::PREFIXO . $nome, 'tipo' => $tipo], $pastor);
            $this->contar('categorias', true);
        }

        return $resultado;
    }

    // ------------------------------------------------------------------ mês anterior (depois fechado)

    private function mesAnterior(array $u, array $c, array $cat): void
    {
        $anterior = $this->hoje->copy()->startOfMonth()->subMonth();
        $anoMes = $anterior->format('Y-m');
        $dia = fn (int $d): string => $anterior->copy()->day($d)->toDateString();

        // Já existe fechamento por outro motivo e os dados demo do mês ainda não foram criados: não dá para lançar nele.
        if ($this->periodos->estaFechado($anoMes) && ! Entrada::query()->where('chave_idempotencia', 'demo-ant-e1')->exists()) {
            $this->avisos[] = "O mês anterior ({$anoMes}) já está fechado; os lançamentos demo desse mês não foram criados.";

            return;
        }

        $this->entrada($u['pastor'], 'demo-ant-e1', ['categoria_id' => $cat['dizimo']->id, 'conta_id' => $c['banco']->id, 'valor' => '1850.00', 'data_competencia' => $dia(5), 'descricao' => 'Dízimos do mês (demonstração)', 'contribuinte_nome' => 'Contribuinte Demo 1']);
        $this->entrada($u['tesoureiro'], 'demo-ant-e2', ['categoria_id' => $cat['oferta']->id, 'conta_id' => $c['banco']->id, 'valor' => '640.50', 'data_competencia' => $dia(12), 'descricao' => 'Oferta do culto de domingo (demonstração)', 'contribuinte_nome' => null]);
        $this->entrada($u['pastor'], 'demo-ant-e3', ['categoria_id' => $cat['doacao']->id, 'conta_id' => $c['banco']->id, 'valor' => '1000.00', 'data_competencia' => $dia(20), 'descricao' => 'Doação para a reforma (demonstração)', 'contribuinte_nome' => 'Contribuinte Demo 2']);
        $this->entrada($u['auxiliar'], 'demo-ant-e4', ['categoria_id' => $cat['dizimo']->id, 'conta_id' => $c['banco']->id, 'valor' => '420.00', 'data_competencia' => $dia(26), 'descricao' => 'Dízimos recebidos pelo auxiliar (demonstração)', 'contribuinte_nome' => 'Contribuinte Demo 3']);

        [$aluguel, $nova] = $this->despesa($u['tesoureiro'], 'demo-ant-d1', ['categoria_id' => $cat['aluguel']->id, 'valor' => '1200.00', 'data_competencia' => $dia(1), 'descricao' => 'Aluguel do salão (demonstração)', 'fornecedor_nome' => 'Imobiliária Demo']);
        if ($nova) {
            $this->despesas->pagar($aluguel->id, ['conta_id' => $c['banco']->id, 'data_pagamento' => $dia(10)], false, $u['tesoureiro']);
        }
        [$luz, $nova] = $this->despesa($u['tesoureiro'], 'demo-ant-d2', ['categoria_id' => $cat['agua_luz']->id, 'valor' => '310.40', 'data_competencia' => $dia(8), 'descricao' => 'Água e energia (demonstração)', 'fornecedor_nome' => 'Companhia Demo']);
        if ($nova) {
            $this->despesas->pagar($luz->id, ['conta_id' => $c['banco']->id, 'data_pagamento' => $dia(15)], false, $u['tesoureiro']);
        }
        $this->despesa($u['auxiliar'], 'demo-ant-d3', ['categoria_id' => $cat['material']->id, 'valor' => '89.90', 'data_competencia' => $dia(25), 'descricao' => 'Material de escritório ainda a pagar (demonstração)', 'fornecedor_nome' => 'Papelaria Demo']);

        if (! $this->periodos->estaFechado($anoMes)) {
            $this->periodos->fechar($anoMes, $u['pastor']);
            $this->contar('periodos', true);
        } else {
            $this->contar('periodos', false);
        }
    }

    // ------------------------------------------------------------------ mês atual (aberto)

    private function mesAtual(array $u, array $c, array $cat): void
    {
        $inicio = $this->hoje->copy()->startOfMonth();
        $dia = fn (int $d): string => $inicio->copy()->day(min($d, $this->hoje->day))->toDateString();

        // Entradas (Pastor, Tesoureiro e Auxiliar — o Auxiliar precisa de lançamentos próprios E de outros que não vê).
        $this->entrada($u['pastor'], 'demo-atu-e1', ['categoria_id' => $cat['dizimo']->id, 'conta_id' => $c['banco']->id, 'valor' => '2300.00', 'data_competencia' => $dia(3), 'descricao' => 'Dízimos da semana (demonstração)', 'contribuinte_nome' => 'Contribuinte Demo 4']);
        $this->entrada($u['pastor'], 'demo-atu-e2', ['categoria_id' => $cat['doacao']->id, 'conta_id' => $c['banco']->id, 'valor' => '750.00', 'data_competencia' => $dia(9), 'descricao' => 'Doação para missões (demonstração)', 'contribuinte_nome' => 'Contribuinte Demo 5']);
        $this->entrada($u['tesoureiro'], 'demo-atu-e3', ['categoria_id' => $cat['oferta']->id, 'conta_id' => $c['banco']->id, 'valor' => '980.25', 'data_competencia' => $dia(10), 'descricao' => 'Oferta de domingo (demonstração)', 'contribuinte_nome' => null]);
        $this->entrada($u['tesoureiro'], 'demo-atu-e4', ['categoria_id' => $cat['outras_receitas']->id, 'conta_id' => $c['caixa']->id, 'valor' => '150.00', 'data_competencia' => $dia(14), 'descricao' => 'Venda de itens do bazar (demonstração)', 'contribuinte_nome' => null]);
        $this->entrada($u['auxiliar'], 'demo-atu-e5', ['categoria_id' => $cat['dizimo']->id, 'conta_id' => $c['banco']->id, 'valor' => '310.00', 'data_competencia' => $dia(6), 'descricao' => 'Dízimos recebidos pelo auxiliar (demonstração)', 'contribuinte_nome' => 'Contribuinte Demo 6']);
        $this->entrada($u['auxiliar'], 'demo-atu-e6', ['categoria_id' => $cat['oferta']->id, 'conta_id' => $c['caixa']->id, 'valor' => '125.75', 'data_competencia' => $dia(13), 'descricao' => 'Oferta recebida pelo auxiliar (demonstração)', 'contribuinte_nome' => null]);

        // Estorno de entrada: lançada em duplicidade e corrigida pelo caminho oficial (estorno), nunca por edição.
        [$duplicada, $nova] = $this->entrada($u['tesoureiro'], 'demo-atu-e7', ['categoria_id' => $cat['oferta']->id, 'conta_id' => $c['banco']->id, 'valor' => '200.00', 'data_competencia' => $dia(15), 'descricao' => 'Oferta lançada em duplicidade (demonstração)', 'contribuinte_nome' => null]);
        if ($nova) {
            $this->entradas->estornar($duplicada->id, 'Lançamento em duplicidade — corrigido por estorno (demonstração)', false, $u['tesoureiro']);
            $this->contar('entradas', true); // a linha de estorno também é uma entrada
        }

        // Transferência banco → caixa (não é receita nem despesa) e um ajuste de saldo justificado.
        [, $nova] = $this->transferencia($u['tesoureiro'], 'demo-atu-t1', ['conta_origem_id' => $c['banco']->id, 'conta_destino_id' => $c['caixa']->id, 'valor' => '800.00', 'data_transferencia' => $dia(11), 'descricao' => 'Reforço do caixa físico (demonstração)']);
        $this->ajuste($u['tesoureiro'], 'demo-atu-a1', ['conta_id' => $c['caixa']->id, 'valor' => '12.50', 'sentido' => 'credito', 'data_ajuste' => $dia(16), 'justificativa' => 'Conferência do caixa: sobra encontrada (demonstração)']);

        // Despesas pagas, pendentes, cancelada e estornada.
        [$aluguel, $nova] = $this->despesa($u['tesoureiro'], 'demo-atu-d1', ['categoria_id' => $cat['aluguel']->id, 'valor' => '1200.00', 'data_competencia' => $dia(1), 'descricao' => 'Aluguel do salão (demonstração)', 'fornecedor_nome' => 'Imobiliária Demo']);
        if ($nova) {
            $this->despesas->pagar($aluguel->id, ['conta_id' => $c['banco']->id, 'data_pagamento' => $dia(5)], false, $u['tesoureiro']);
        }
        [$luz, $nova] = $this->despesa($u['tesoureiro'], 'demo-atu-d2', ['categoria_id' => $cat['agua_luz']->id, 'valor' => '285.60', 'data_competencia' => $dia(8), 'descricao' => 'Água e energia (demonstração)', 'fornecedor_nome' => 'Companhia Demo']);
        if ($nova) {
            $this->despesas->pagar($luz->id, ['conta_id' => $c['banco']->id, 'data_pagamento' => $dia(12)], false, $u['tesoureiro']);
        }
        [$caixaPaga, $nova] = $this->despesa($u['pastor'], 'demo-atu-d3', ['categoria_id' => $cat['outras_despesas']->id, 'valor' => '180.00', 'data_competencia' => $dia(12), 'descricao' => 'Lanche do evento (demonstração)', 'fornecedor_nome' => 'Mercado Demo']);
        if ($nova) {
            $this->despesas->pagar($caixaPaga->id, ['conta_id' => $c['caixa']->id, 'data_pagamento' => $dia(12)], false, $u['pastor']);
        }
        [$estornavel, $nova] = $this->despesa($u['pastor'], 'demo-atu-d4', ['categoria_id' => $cat['material']->id, 'valor' => '95.00', 'data_competencia' => $dia(13), 'descricao' => 'Material pago por engano (demonstração)', 'fornecedor_nome' => 'Papelaria Demo']);
        if ($nova) {
            $this->despesas->pagar($estornavel->id, ['conta_id' => $c['caixa']->id, 'data_pagamento' => $dia(13)], false, $u['pastor']);
            $this->despesas->estornar($estornavel->id, 'Pagamento feito por engano — estornado (demonstração)', $u['pastor']);
            $this->contar('despesas', true); // a linha de estorno também é uma despesa
        }
        [$cancelada, $nova] = $this->despesa($u['tesoureiro'], 'demo-atu-d5', ['categoria_id' => $cat['outras_despesas']->id, 'valor' => '45.00', 'data_competencia' => $dia(14), 'descricao' => 'Compra cancelada (demonstração)', 'fornecedor_nome' => 'Loja Demo']);
        if ($nova) {
            $this->despesas->cancelar($cancelada->id, 'Compra não realizada — cancelada (demonstração)', $u['tesoureiro']);
        }
        $this->despesa($u['tesoureiro'], 'demo-atu-d6', ['categoria_id' => $cat['material']->id, 'valor' => '74.30', 'data_competencia' => $dia(15), 'descricao' => 'Material de limpeza a pagar (demonstração)', 'fornecedor_nome' => 'Distribuidora Demo']);
        $this->despesa($u['tesoureiro'], 'demo-atu-d7', ['categoria_id' => $cat['agua_luz']->id, 'valor' => '129.90', 'data_competencia' => $dia(16), 'descricao' => 'Internet do mês a pagar (demonstração)', 'fornecedor_nome' => 'Provedor Demo']);
        $this->despesa($u['auxiliar'], 'demo-atu-d8', ['categoria_id' => $cat['material']->id, 'valor' => '62.40', 'data_competencia' => $dia(7), 'descricao' => 'Material de escritório a pagar, pedido pelo auxiliar (demonstração)', 'fornecedor_nome' => 'Papelaria Demo']);
        $this->despesa($u['auxiliar'], 'demo-atu-d9', ['categoria_id' => $cat['outras_despesas']->id, 'valor' => '38.90', 'data_competencia' => $dia(12), 'descricao' => 'Reembolso de transporte a pagar (demonstração)', 'fornecedor_nome' => null]);
    }

    // ------------------------------------------------------------------ helpers de criação idempotente

    /** @return array{0: Entrada, 1: bool} */
    private function entrada(User $ator, string $chave, array $dados): array
    {
        $existente = Entrada::query()->where('criado_por', $ator->id)->where('chave_idempotencia', $chave)->first();
        if ($existente !== null) {
            $this->contar('entradas', false);

            return [$existente, false];
        }

        [$entrada] = $this->entradas->criar($dados, $ator, $chave);
        $this->contar('entradas', true);

        return [$entrada, true];
    }

    /** @return array{0: Despesa, 1: bool} */
    private function despesa(User $ator, string $chave, array $dados): array
    {
        $existente = Despesa::query()->where('criado_por', $ator->id)->where('chave_idempotencia', $chave)->first();
        if ($existente !== null) {
            $this->contar('despesas', false);

            return [$existente, false];
        }

        [$despesa] = $this->despesas->criar($dados, $ator, $chave);
        $this->contar('despesas', true);

        return [$despesa, true];
    }

    /** @return array{0: Transferencia, 1: bool} */
    private function transferencia(User $ator, string $chave, array $dados): array
    {
        $existente = Transferencia::query()->where('criado_por', $ator->id)->where('chave_idempotencia', $chave)->first();
        if ($existente !== null) {
            $this->contar('transferencias', false);

            return [$existente, false];
        }

        [$transferencia] = $this->transferencias->criar($dados, false, $ator, $chave);
        $this->contar('transferencias', true);

        return [$transferencia, true];
    }

    /** @return array{0: AjusteSaldo, 1: bool} */
    private function ajuste(User $ator, string $chave, array $dados): array
    {
        $existente = AjusteSaldo::query()->where('criado_por', $ator->id)->where('chave_idempotencia', $chave)->first();
        if ($existente !== null) {
            $this->contar('ajustes', false);

            return [$existente, false];
        }

        [$ajuste] = $this->ajustes->criar($dados, false, $ator, $chave);
        $this->contar('ajustes', true);

        return [$ajuste, true];
    }

    // ================================================================== resumo

    /** Quantidades atuais dos registros demo (para o resumo dos comandos). @return array<string, int> */
    public function resumo(): array
    {
        $ids = User::withTrashed()->whereIn('email', self::emails())->pluck('id');

        return [
            'usuarios' => $ids->count(),
            'contas' => Conta::withTrashed()->where('nome', 'like', self::PREFIXO . '%')->count(),
            'categorias (criadas pela demo)' => Categoria::query()->where('nome', 'like', self::PREFIXO . '%')->count(),
            'entradas' => Entrada::query()->whereIn('criado_por', $ids)->count(),
            'despesas' => Despesa::query()->whereIn('criado_por', $ids)->count(),
            'transferencias' => Transferencia::query()->whereIn('criado_por', $ids)->count(),
            'ajustes' => AjusteSaldo::query()->whereIn('criado_por', $ids)->count(),
            'periodos fechados' => PeriodoFinanceiro::query()->whereIn('fechado_por', $ids)->count(),
        ];
    }

    // ================================================================== limpeza

    /**
     * Remove SOMENTE o que a demonstração criou, na ordem que respeita as chaves estrangeiras. Nunca apaga tabela inteira,
     * nunca toca em dado de outro usuário. Se algo que não é demo estiver ligado aos usuários demo, aborta SEM apagar nada.
     *
     * @return array<string, int>|null  quantidades removidas, ou null quando não havia nada de demonstração
     */
    public function limpar(): ?array
    {
        $this->garantirNaoProducao();

        $ids = User::withTrashed()->whereIn('email', self::emails())->pluck('id')->all();
        $contas = Conta::withTrashed()->where('nome', 'like', self::PREFIXO . '%')->pluck('id')->all();
        $categorias = Categoria::query()->where('nome', 'like', self::PREFIXO . '%')->pluck('id')->all();

        if ($ids === [] && $contas === [] && $categorias === []) {
            return null;
        }

        return DB::transaction(function () use ($ids, $contas, $categorias) {
            $removidos = [];

            // Movimentos dos usuários demo. Linhas de estorno primeiro (chave estrangeira para a própria tabela).
            $removidos['ajustes'] = DB::table('ajustes_saldo')->whereIn('criado_por', $ids)->delete();
            foreach (['transferencias' => 'transferencia_estornada_id', 'entradas' => 'entrada_estornada_id', 'despesas' => 'despesa_estornada_id'] as $tabela => $coluna) {
                $estornos = DB::table($tabela)->whereIn('criado_por', $ids)->whereNotNull($coluna)->delete();
                $originais = DB::table($tabela)->whereIn('criado_por', $ids)->delete();
                $removidos[$tabela] = $estornos + $originais;
            }

            $removidos['periodos'] = DB::table('periodos_financeiros')->where(fn ($q) => $q->whereIn('fechado_por', $ids)->orWhereIn('reaberto_por', $ids))->delete();
            DB::table('permissoes_excecao')->where(fn ($q) => $q->whereIn('user_id', $ids)->orWhereIn('concedida_por', $ids))->delete();

            // Trilha de auditoria produzida pelos atores demo (o sistema em produção nunca faz isto: o comando é bloqueado lá
            // e o usuário de banco de produção nem tem DELETE em audit_logs). Query Builder de propósito: o model é imutável.
            $removidos['auditoria'] = DB::table('audit_logs')->whereIn('user_id', $ids)->delete();
            DB::table('sessions')->whereIn('user_id', $ids)->delete();

            // Contas e categorias criadas pela demo — só se nada mais as referencia (nunca apaga dado alheio).
            $removidos['contas'] = 0;
            foreach ($contas as $contaId) {
                if (! $this->contaReferenciada($contaId)) {
                    $removidos['contas'] += DB::table('contas')->where('id', $contaId)->delete();
                }
            }
            $removidos['categorias'] = 0;
            foreach ($categorias as $categoriaId) {
                $emUso = DB::table('entradas')->where('categoria_id', $categoriaId)->exists() || DB::table('despesas')->where('categoria_id', $categoriaId)->exists();
                if (! $emUso) {
                    $removidos['categorias'] += DB::table('categorias')->where('id', $categoriaId)->delete();
                }
            }

            $removidos['usuarios'] = DB::table('users')->whereIn('id', $ids)->delete();

            return $removidos;
        });
    }

    private function contaReferenciada(int $contaId): bool
    {
        return DB::table('entradas')->where('conta_id', $contaId)->exists()
            || DB::table('despesas')->where('conta_id', $contaId)->exists()
            || DB::table('ajustes_saldo')->where('conta_id', $contaId)->exists()
            || DB::table('transferencias')->where(fn ($q) => $q->where('conta_origem_id', $contaId)->orWhere('conta_destino_id', $contaId))->exists();
    }
}
