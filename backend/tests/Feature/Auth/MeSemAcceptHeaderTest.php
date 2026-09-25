<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeSemAcceptHeaderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Regressão: sem "Accept: application/json" (ex: curl puro, fetch básico),
     * o Laravel tentava redirecionar para uma rota "login" que não existe
     * neste backend 100% API, quebrando com 500 em vez de 401.
     */
    public function test_me_sem_accept_header_retorna_401_json_em_vez_de_500(): void
    {
        $response = $this->get('/api/v1/auth/me');

        $response->assertStatus(401);
        $response->assertJson(['message' => 'Unauthenticated.']);
    }
}
