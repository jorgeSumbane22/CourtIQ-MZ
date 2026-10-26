<?php
/**
 * lang/en.php — dicionário português → inglês usado por includes/lang.php.
 *
 * Junta os ficheiros de lang/en/. Cada um devolve ['texto em português' => 'English text'].
 * A chave é o texto tal como aparece na página, com os números trocados por {n} e os
 * nomes (clubes, pessoas, adversários) por {0}, {1}… Para traduzir um texto novo basta
 * acrescentar uma linha; o script de recolha lista os que ainda não têm tradução.
 */
$dicionario = [];
foreach (glob(__DIR__ . '/en/*.php') as $ficheiro) {
    $dicionario += (array) require $ficheiro;
}
return $dicionario;
