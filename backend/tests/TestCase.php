<?php

namespace Tests;

use Database\Seeders\PerfilSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Todo teste que usa RefreshDatabase reseeda os 5 perfis fixos automaticamente.
     */
    protected $seeder = PerfilSeeder::class;

    protected bool $seed = true;
}
