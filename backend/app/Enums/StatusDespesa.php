<?php

namespace App\Enums;

enum StatusDespesa: string
{
    case Pendente = 'pendente';
    case Paga = 'paga';
    case Estornada = 'estornada';
    case Cancelada = 'cancelada';
}
