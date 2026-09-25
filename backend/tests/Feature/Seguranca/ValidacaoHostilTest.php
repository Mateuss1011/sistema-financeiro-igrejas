<?php

namespace Tests\Feature\Seguranca;

use App\Enums\PerfilSlug;
use App\Models\Transferencia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fase 13 — validação sob entrada hostil. Para CADA campo de CADA endpoint de escrita e para CADA parâmetro de consulta,
 * valores de tipo errado, enormes, negativos, decimais, datas absurdas, arrays, objetos, nulos, bytes nulos, SQL e HTML
 * são enviados pela API real. Contrato: a resposta é SEMPRE < 500 (nunca erro interno), é JSON, e não vaza nada do
 * servidor. Cada requisição roda num savepoint que é desfeito, então os alvos ficam novos e o banco não acumula lixo.
 */
class ValidacaoHostilTest extends TestCase
{
    use RefreshDatabase, CenarioSeguranca;

    /** @return array<string, mixed> */
    private function valoresHostis(): array
    {
        return [
            'array' => ['x'],
            'objeto aninhado' => ['a' => ['b' => ['c' => 1]]],
            'lista vazia' => [],
            'vazio' => '',
            'nulo' => null,
            'string enorme' => str_repeat('A', 70000),
            'negativo' => '-1',
            'zero' => '0',
            'decimal 3 casas' => '1.005',
            'notacao cientifica' => '1e309',
            'booleano' => true,
            'sql' => "1' OR '1'='1' --",
            'byte nulo' => "a\0b",
            'html' => '<script>alert(1)</script><img src=x onerror=alert(2)>',
            'unicode' => str_repeat('😀', 300),
            'inteiro gigante' => '99999999999999999999999999',
            'data absurda' => '9999-99-99',
            'data futura' => '2999-01-01',
        ];
    }

    private function alvo($dados, string $rotulo, $resposta): void
    {
        $this->assertLessThan(500, $resposta->status(), "{$rotulo} => {$resposta->status()}: " . substr($resposta->getContent(), 0, 300));
        $this->assertStringContainsString('application/json', (string) $resposta->headers->get('Content-Type'), $rotulo);
        $this->assertSemVazamento($resposta);
    }

    public function test_nenhum_campo_de_escrita_causa_erro_interno_com_valores_hostis(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $contaA = $this->conta('A', 'banco', '1000.00');
        $contaB = $this->conta('B', 'caixa', '10.00');
        $catE = $this->categoria('Dízimos');
        $catD = $this->categoriaDespesa('Energia');
        $alvoUsuario = $this->como(PerfilSlug::Tesoureiro);
        $entrada = $this->entrada($contaA, $catE, $pastor, '10.00');
        $pendente = $this->despesaPendente($catD, $pastor);
        $paga = $this->despesaPaga($contaA, $catD, $pastor);
        $transf = Transferencia::create(['conta_origem_id' => $contaA->id, 'conta_destino_id' => $contaB->id, 'valor' => '5.00',
            'data_transferencia' => $this->hoje(), 'status' => 'confirmada', 'criado_por' => $pastor->id]);
        $hoje = $this->hoje();

        $casos = [
            ['POST', 'contas', ['nome' => 'Nova', 'tipo' => 'banco', 'saldo_inicial' => '10.00']],
            ['PUT', "contas/{$contaB->id}", ['nome' => 'Renomeada', 'ativa' => true]],
            ['POST', 'categorias', ['nome' => 'Nova cat', 'tipo' => 'entrada']],
            ['PUT', "categorias/{$catE->id}", ['nome' => 'Renomeada', 'ativa' => true]],
            ['POST', 'entradas', ['categoria_id' => $catE->id, 'conta_id' => $contaA->id, 'valor' => '10.00', 'data_competencia' => $hoje, 'descricao' => 'x', 'contribuinte_nome' => 'y', 'idempotency_key' => 'k1']],
            ['POST', "entradas/{$entrada->id}/estornar", ['justificativa' => 'Motivo válido', 'confirmar_saldo_negativo' => false]],
            ['POST', 'despesas', ['categoria_id' => $catD->id, 'valor' => '10.00', 'data_competencia' => $hoje, 'descricao' => 'x', 'fornecedor_nome' => 'y', 'idempotency_key' => 'k2']],
            ['PUT', "despesas/{$pendente->id}", ['categoria_id' => $catD->id, 'valor' => '11.00', 'data_competencia' => $hoje, 'descricao' => 'novo', 'fornecedor_nome' => 'z']],
            ['POST', "despesas/{$pendente->id}/pagar", ['conta_id' => $contaA->id, 'data_pagamento' => $hoje, 'confirmar_saldo_negativo' => false]],
            ['POST', "despesas/{$pendente->id}/cancelar", ['justificativa' => 'Motivo válido']],
            ['POST', "despesas/{$paga->id}/estornar", ['justificativa' => 'Motivo válido']],
            ['POST', 'transferencias', ['conta_origem_id' => $contaA->id, 'conta_destino_id' => $contaB->id, 'valor' => '1.00', 'data_transferencia' => $hoje, 'descricao' => 'x', 'confirmar_saldo_negativo' => false, 'idempotency_key' => 'k3']],
            ['POST', "transferencias/{$transf->id}/estornar", ['justificativa' => 'Motivo válido', 'confirmar_saldo_negativo' => false]],
            ['POST', 'ajustes', ['conta_id' => $contaA->id, 'valor' => '1.00', 'sentido' => 'credito', 'data_ajuste' => $hoje, 'justificativa' => 'Motivo válido', 'confirmar_saldo_negativo' => false, 'idempotency_key' => 'k4']],
            ['POST', 'periodos-financeiros/2026-01/reabrir', ['justificativa' => 'Motivo válido']],
            ['POST', 'usuarios', ['name' => 'Fulano', 'email' => 'fulano@exemplo.com', 'password' => 'Senh4-Forte!2026', 'perfil_id' => $alvoUsuario->perfil_id]],
            ['PUT', "usuarios/{$alvoUsuario->id}", ['name' => 'Outro nome', 'email' => 'outro@exemplo.com', 'perfil_id' => $alvoUsuario->perfil_id, 'ativo' => true]],
            ['POST', "usuarios/{$alvoUsuario->id}/permissoes-excecao", ['permissao' => 'entradas.operar']],
        ];

        $enviados = 0;
        foreach ($casos as [$metodo, $rota, $base]) {
            foreach (array_keys($base) as $campo) {
                foreach ($this->valoresHostis() as $rotuloValor => $valor) {
                    DB::beginTransaction();
                    $this->novaRequisicao();
                    Cache::flush();
                    $resposta = $this->actingAs($pastor)->json($metodo, "/api/v1/{$rota}", array_merge($base, [$campo => $valor]));
                    DB::rollBack();

                    $this->alvo(null, "{$metodo} {$rota} [{$campo}={$rotuloValor}]", $resposta);
                    $enviados++;
                }
            }
        }

        $this->assertGreaterThan(900, $enviados);
    }

    public function test_corpos_malformados_ou_de_tipo_errado_dao_4xx_e_nunca_500(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $contaA = $this->conta('A', 'banco', '100.00');

        $rotas = ['POST contas', 'POST categorias', 'POST entradas', 'POST despesas', 'POST transferencias', 'POST ajustes', 'POST usuarios',
            "PUT contas/{$contaA->id}", 'POST periodos-financeiros/2026-01/reabrir', 'POST auth/login'];
        $corpos = [
            'json quebrado' => '{"valor": ',
            'json truncado' => '{"a":',
            'lista no topo' => '[1,2,3]',
            'string no topo' => '"texto"',
            'numero no topo' => '42',
            'nulo no topo' => 'null',
            'vazio' => '',
            'profundo' => str_repeat('[', 500) . str_repeat(']', 500),
            'utf8 invalido' => "{\"nome\": \"\xB1\xB2\"}",
        ];

        foreach ($rotas as $rota) {
            [$metodo, $uri] = explode(' ', $rota, 2);
            foreach ($corpos as $rotulo => $corpo) {
                $this->novaRequisicao();
                $resposta = $this->actingAs($pastor)->call($metodo, "/api/v1/{$uri}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $corpo);
                $this->alvo(null, "{$rota} [{$rotulo}]", $resposta);
            }
        }
    }

    public function test_nenhum_parametro_de_consulta_causa_erro_interno_com_valores_hostis(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $this->massaDoMes($this->mesPassado(1));

        $parametros = ['page', 'por_pagina', 'ordenar', 'ano_mes', 'data_de', 'data_ate', 'conta_id', 'categoria_id', 'status', 'tipo', 'estorno',
            'ativa', 'ativo', 'modulo', 'acao', 'user_id', 'sem_usuario', 'registro_id', 'busca', 'q', 'perfil', 'formato'];
        $listagens = ['contas', 'categorias', 'entradas', 'despesas', 'transferencias', 'ajustes', 'periodos-financeiros', 'usuarios',
            'auditoria', 'auditoria/catalogo', 'dashboard', 'perfis', 'permissoes-excecao', 'relatorios',
            'relatorios/resumo', 'relatorios/entradas', 'relatorios/despesas', 'relatorios/movimentacoes', 'relatorios/saldos'];

        $valores = [
            'texto' => 'x', 'sql' => "1' OR '1'='1", 'negativo' => '-1', 'zero' => '0', 'gigante' => '99999999999999999999999',
            'longo' => str_repeat('A', 5000), 'html' => '<script>alert(1)</script>', 'byte nulo' => "a\0b", 'vazio' => '', 'notacao' => '1e309',
            'array' => ['x'], 'mapa' => ['a' => 'b'], 'aninhado' => ['a' => ['b' => 'c']], 'duplicado como lista' => ['1', '2'],
        ];

        foreach ($listagens as $listagem) {
            foreach ($valores as $rotulo => $valor) {
                $query = array_fill_keys($parametros, $valor);
                $this->novaRequisicao();
                Cache::flush();
                $resposta = $this->actingAs($pastor)->getJson("/api/v1/{$listagem}?" . http_build_query($query));
                $this->alvo(null, "GET {$listagem} [todos={$rotulo}]", $resposta);
            }
        }
    }

    public function test_parametros_duplicados_e_desconhecidos_sao_tratados_sem_erro(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);

        // Duplicado na query string: vale o último (PHP); desconhecido: ignorado.
        $this->actingAs($pastor)->getJson('/api/v1/entradas?por_pagina=5&por_pagina=200')->assertStatus(422);
        $this->novaRequisicao();
        $this->actingAs($pastor)->getJson('/api/v1/entradas?por_pagina=200&por_pagina=5')->assertOk();
        $this->novaRequisicao();
        $resposta = $this->actingAs($pastor)->getJson('/api/v1/entradas?campo_que_nao_existe=1&outro[]=2&__proto__[x]=1&constructor=1');
        $resposta->assertOk();
        $this->assertSemVazamento($resposta);
    }

    public function test_limites_de_tamanho_e_faixa_dos_campos_de_texto_e_dinheiro(): void
    {
        $pastor = $this->como(PerfilSlug::Pastor);
        $contaA = $this->conta('A', 'banco', '1000.00');
        $catE = $this->categoria('Dízimos');
        $base = ['categoria_id' => $catE->id, 'conta_id' => $contaA->id, 'valor' => '10.00', 'data_competencia' => $this->hoje()];

        $invalidos = [
            'descricao acima de 255' => ['descricao' => str_repeat('a', 256)],
            'contribuinte acima de 150' => ['contribuinte_nome' => str_repeat('a', 151)],
            'valor zero' => ['valor' => '0.00'],
            'valor negativo' => ['valor' => '-10.00'],
            'valor com 3 casas' => ['valor' => '10.001'],
            'valor acima de DECIMAL(14,2)' => ['valor' => '1000000000000.00'],
            'valor texto' => ['valor' => 'dez'],
            'data no futuro' => ['data_competencia' => '2999-12-31'],
            'data em formato errado' => ['data_competencia' => '31/12/2020'],
            'data inexistente' => ['data_competencia' => '2026-02-30'],
            'categoria inexistente' => ['categoria_id' => 987654321],
            'conta inexistente' => ['conta_id' => 987654321],
            'categoria de despesa numa entrada' => ['categoria_id' => $this->categoriaDespesa('Luz')->id],
        ];

        foreach ($invalidos as $rotulo => $troca) {
            $this->novaRequisicao();
            $resposta = $this->actingAs($pastor)->postJson('/api/v1/entradas', array_merge($base, $troca));
            $this->assertSame(422, $resposta->status(), "Entrada com {$rotulo} deveria ser 422, veio {$resposta->status()}");
        }

        // Limites exatos ainda passam (não há rejeição indevida).
        $this->novaRequisicao();
        $this->actingAs($pastor)->postJson('/api/v1/entradas', array_merge($base, ['descricao' => str_repeat('a', 255), 'valor' => '999999999999.99']))->assertCreated();
    }
}
