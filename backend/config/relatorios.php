<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Limite de linhas por exportação (Fase 12)
    |--------------------------------------------------------------------------
    |
    | A exportação (CSV/XLSX) é síncrona e representa TODO o conjunto filtrado, nunca só a página da tela. Para não
    | esgotar memória/tempo do servidor, acima deste número de linhas a API responde 422 EXPORTACAO_MUITO_GRANDE.
    |
    */

    'limite_exportacao' => 10000,

];
