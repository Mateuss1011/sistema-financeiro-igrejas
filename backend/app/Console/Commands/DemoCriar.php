<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Demo\AmbienteDemo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Throwable;

/**
 * `php artisan sfg:demo` — monta (ou completa) o ambiente de demonstração: 5 usuários fictícios e uma massa financeira pequena.
 *
 * Segurança:
 *  - recusa rodar em produção (sem `--force` nem qualquer outra forma de burlar);
 *  - a senha dos usuários demo NUNCA existe no código: vem da variável de ambiente SFG_DEMO_PASSWORD (execução local/controlada)
 *    ou é digitada de forma oculta; nunca por argumento, nunca é impressa, gravada em arquivo ou registrada em log;
 *  - idempotente: rodar de novo não duplica nada.
 */
class DemoCriar extends Command
{
    protected $signature = 'sfg:demo';

    protected $description = 'Cria (ou completa) o ambiente de demonstração: 5 usuários fictícios e dados financeiros de exemplo.';

    public function handle(AmbienteDemo $demo): int
    {
        if (app()->isProduction()) {
            $this->error(AmbienteDemo::MENSAGEM_PRODUCAO);

            return self::FAILURE;
        }

        $senha = $this->obterSenha();
        if ($senha === null) {
            return self::FAILURE;
        }

        try {
            $resultado = $demo->criar($senha);
        } catch (Throwable $e) {
            // Só a mensagem de negócio; nunca dados da requisição (a senha não faz parte de nenhuma mensagem).
            $this->error('Não foi possível montar o ambiente de demonstração: ' . $e->getMessage());
            $this->line('Nenhuma alteração foi mantida (a operação é atômica).');

            return self::FAILURE;
        }

        $this->info('Ambiente de demonstração pronto. Todos os dados são fictícios.');
        $this->newLine();
        $this->table(['Grupo', 'Criados agora', 'Já existiam'], collect($resultado['contagem'])->map(fn ($v, $k) => [$k, $v['criados'], $v['reaproveitados']])->values()->all());
        $this->newLine();
        $this->table(['Perfil', 'Nome', 'E-mail'], collect(AmbienteDemo::USUARIOS)->map(fn ($d) => [$d['perfil']->nomeExibicao(), $d['nome'], $d['email']])->values()->all());
        $this->newLine();
        $this->table(['Total no banco (registros demo)', 'Quantidade'], collect($demo->resumo())->map(fn ($v, $k) => [$k, $v])->values()->all());

        foreach ($resultado['avisos'] as $aviso) {
            $this->warn($aviso);
        }
        $this->line('A senha é a que você acabou de informar (não é exibida nem guardada em lugar nenhum).');

        return self::SUCCESS;
    }

    private function obterSenha(): ?string
    {
        $doAmbiente = getenv('SFG_DEMO_PASSWORD');
        $confirmada = null;

        if (is_string($doAmbiente) && $doAmbiente !== '') {
            $senha = $doAmbiente;
        } else {
            if (! $this->input->isInteractive()) {
                $this->error('Informe a senha de demonstração: execute em modo interativo ou defina SFG_DEMO_PASSWORD (apenas em execução local/controlada).');

                return null;
            }

            $senha = (string) $this->secret('Senha de demonstração (não aparece na tela)');
            $confirmada = (string) $this->secret('Repita a senha');
            if ($senha !== $confirmada) {
                $this->error('As senhas não conferem.');

                return null;
            }
        }

        $validador = Validator::make(['password' => $senha], ['password' => ['required', 'string', 'max:255', Password::defaults()]]);
        if ($validador->fails()) {
            foreach ($validador->errors()->all() as $mensagem) {
                $this->error($mensagem);
            }

            return null;
        }

        return $senha;
    }
}
