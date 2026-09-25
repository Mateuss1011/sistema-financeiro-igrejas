<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\LimiteExcedidoException;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UsuarioResource;
use App\Models\User;
use App\Services\AuditoriaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Falhas de login toleradas por minuto para o par e-mail+IP; a partir daí, 429 sem nem tentar autenticar. */
    private const MAX_FALHAS_POR_MINUTO = 5;

    public function __construct(private AuditoriaService $auditoria)
    {
    }

    public function login(LoginRequest $request)
    {
        // Sem sessão (origem fora de SANCTUM_STATEFUL_DOMAINS) não há como autenticar por cookie: recusa limpa (nunca 500),
        // antes de olhar credenciais — e sem revelar se elas estavam certas.
        if (! $request->hasSession()) {
            return response()->json([
                'message' => 'Origem não autorizada para login.',
                'code' => 'ORIGEM_NAO_PERMITIDA',
                'errors' => [],
            ], 403);
        }

        $credenciais = $request->validated();
        $chaveDeLimite = Str::lower($credenciais['email']) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($chaveDeLimite, self::MAX_FALHAS_POR_MINUTO)) {
            throw new LimiteExcedidoException(RateLimiter::availableIn($chaveDeLimite));
        }

        $usuarioIdentificado = User::where('email', $credenciais['email'])->first();

        if (! Auth::attempt($credenciais)) {
            RateLimiter::hit($chaveDeLimite, 60);
            $this->auditoria->registrar(
                acao: 'login_failed',
                modulo: 'auth',
                registroId: $usuarioIdentificado?->id,
                justificativa: 'Credenciais inválidas',
                usuario: $usuarioIdentificado,
            );

            throw ValidationException::withMessages([
                'email' => ['Credenciais inválidas.'],
            ]);
        }

        $usuario = Auth::user();

        if (! $usuario->ativo) {
            Auth::logout();
            RateLimiter::hit($chaveDeLimite, 60);

            $this->auditoria->registrar(
                acao: 'login_failed',
                modulo: 'auth',
                registroId: $usuario->id,
                justificativa: 'Usuário inativo',
                usuario: $usuario,
            );

            throw ValidationException::withMessages([
                'email' => ['Usuário inativo.'],
            ]);
        }

        RateLimiter::clear($chaveDeLimite);
        $request->session()->regenerate();
        $usuario->forceFill(['ultimo_login_em' => now()])->save();

        $this->auditoria->registrar(
            acao: 'login',
            modulo: 'auth',
            registroId: $usuario->id,
            usuario: $usuario,
        );

        return new UsuarioResource($usuario->load('perfil'));
    }

    public function logout(Request $request)
    {
        $usuario = $request->user();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $this->auditoria->registrar(
            acao: 'logout',
            modulo: 'auth',
            registroId: $usuario?->id,
            usuario: $usuario,
        );

        return response()->json(['data' => ['message' => 'Sessão encerrada.']]);
    }

    public function me(Request $request)
    {
        return new UsuarioResource($request->user()->load('perfil'));
    }
}
