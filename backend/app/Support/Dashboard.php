<?php

namespace App\Support;

/**
 * Marcador sem estado: o dashboard não é um registro do banco, mas a autorização segue o padrão do
 * projeto (Policy registrada por classe, checada com `$ator->can('viewAny', Dashboard::class)`).
 */
final class Dashboard
{
}
