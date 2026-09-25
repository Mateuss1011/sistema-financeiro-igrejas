<?php

namespace App\Enums;

enum StatusTransferencia: string
{
    case Confirmada = 'confirmada';
    case Estornada = 'estornada';
}
