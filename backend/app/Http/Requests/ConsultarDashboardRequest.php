<?php

namespace App\Http\Requests;

use App\Support\AnoMes;
use App\Support\Dashboard;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/** authorize() roda antes das regras: quem não pode consultar recebe 403 (nunca 422). */
class ConsultarDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Dashboard::class);
    }

    public function rules(): array
    {
        return [
            'ano_mes' => ['nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/', function (string $atributo, mixed $valor, Closure $falhar) {
                if ($valor > self::mesCorrente()) {
                    $falhar('Não é possível consultar um mês futuro.');
                }
            }],
        ];
    }

    public function messages(): array
    {
        return [
            'ano_mes.regex' => 'Informe o mês no formato AAAA-MM.',
        ];
    }

    /** Mês pedido; se omitido, o mês corrente (fuso da igreja). */
    public function anoMes(): string
    {
        return $this->validated('ano_mes') ?? self::mesCorrente();
    }

    /** Delega para a regra única do filtro de mês (compartilhada com os Relatórios). */
    public static function mesCorrente(): string
    {
        return AnoMes::corrente();
    }
}
