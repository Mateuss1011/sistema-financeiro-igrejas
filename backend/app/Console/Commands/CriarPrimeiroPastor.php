<?php

namespace App\Console\Commands;

use App\Enums\PerfilSlug;
use App\Models\Perfil;
use App\Models\User;
use App\Services\AuditoriaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Bootstrap do sistema (Fase 14): cria o PRIMEIRO Pastor de uma instalação nova. O plano oficial não define como o
 * primeiro usuário nasce (nenhum seeder cria usuário, para nunca haver credencial no código); este comando é o
 * mecanismo mínimo e seguro para isso.
 *
 * Garantias:
 *  - só funciona com a tabela `users` VAZIA (não é atalho para criar Pastores depois; depois disso, só o Pastor pela API);
 *  - a senha é sempre pedida no terminal, oculta e confirmada — nunca por argumento, opção ou variável de ambiente
 *    (não fica em histórico de shell nem em lista de processos);
 *  - mesmas regras de nome/e-mail/senha do cadastro pela API (`StoreUsuarioRequest`);
 *  - grava o registro de auditoria `usuarios/created` (sem a senha).
 */
class CriarPrimeiroPastor extends Command
{
    protected $signature = 'sfg:criar-pastor {--nome= : Nome completo} {--email= : E-mail de login}';

    protected $description = 'Cria o primeiro Pastor (somente em instalação nova, sem nenhum usuário). A senha é pedida de forma oculta.';

    public function handle(AuditoriaService $auditoria): int
    {
        if (User::withTrashed()->exists()) {
            $this->error('Já existem usuários cadastrados. Este comando só serve para a instalação inicial.');

            return self::FAILURE;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Execute em modo interativo: a senha precisa ser digitada no terminal (nunca por argumento ou variável).');

            return self::FAILURE;
        }

        $perfil = Perfil::where('slug', PerfilSlug::Pastor->value)->first();
        if ($perfil === null) {
            $this->error('Perfis não encontrados. Rode as migrations e os seeders antes: php artisan migrate --force --seed');

            return self::FAILURE;
        }

        $nome = $this->option('nome') ?: $this->ask('Nome completo do Pastor');
        $email = $this->option('email') ?: $this->ask('E-mail de login');
        $senha = $this->secret('Senha (não aparece na tela)');
        $confirmacao = $this->secret('Repita a senha');

        $validador = Validator::make(
            ['name' => $nome, 'email' => $email, 'password' => $senha],
            [
                'name' => ['required', 'string', 'max:150'],
                'email' => ['required', 'string', 'email', 'max:150', 'unique:users,email'],
                'password' => ['required', 'string', 'max:255', Password::defaults()],
            ],
        );

        if ($senha !== $confirmacao) {
            $this->error('As senhas não conferem.');

            return self::FAILURE;
        }

        if ($validador->fails()) {
            foreach ($validador->errors()->all() as $mensagem) {
                $this->error($mensagem);
            }

            return self::FAILURE;
        }

        $pastor = DB::transaction(function () use ($nome, $email, $senha, $perfil, $auditoria) {
            $pastor = User::create(['name' => $nome, 'email' => $email, 'password' => $senha, 'perfil_id' => $perfil->id, 'ativo' => true]);

            $auditoria->registrar(
                acao: 'created',
                modulo: 'usuarios',
                registroId: $pastor->id,
                dadosNovos: $pastor->only(['name', 'email', 'perfil_id', 'ativo']),
                justificativa: 'Bootstrap do primeiro Pastor (sfg:criar-pastor)',
                usuario: $pastor,
            );

            return $pastor;
        });

        $this->info("Pastor criado (id {$pastor->id}). Entre pelo sistema com o e-mail informado.");

        return self::SUCCESS;
    }
}
