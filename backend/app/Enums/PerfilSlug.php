<?php

namespace App\Enums;

enum PerfilSlug: string
{
    case Pastor = 'pastor';
    case Administrador = 'administrador';
    case Tesoureiro = 'tesoureiro';
    case AuxiliarFinanceiro = 'auxiliar_financeiro';
    case Secretario = 'secretario';

    public function nomeExibicao(): string
    {
        return match ($this) {
            self::Pastor => 'Pastor',
            self::Administrador => 'Administrador',
            self::Tesoureiro => 'Tesoureiro',
            self::AuxiliarFinanceiro => 'Auxiliar financeiro',
            self::Secretario => 'Secretário',
        };
    }
}
