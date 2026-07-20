<?php
// Copia este archivo como config.php y rellena tu API key
return [
    'openai_api_key' => 'sk-...',
    'openai_model'   => 'gpt-4o-mini', // gpt-4o para mejor calidad, gpt-4o-mini para más barato
    'language'       => 'es',           // idioma de la respuesta

    // Riot Games API — https://developer.riotgames.com/
    // Las claves de desarrollo (RGAPI-...) caducan cada 24h. Renuévala cuando la web te lo pida.
    'riot_api_key'   => 'RGAPI-...',
    'riot_platform'  => 'euw1',    // euw1, eun1, na1, kr, br1, jp1, la1, la2, oc1, tr1, ru
    'riot_regional'  => 'europe',  // americas, europe, asia, sea (deriva del platform)
];
