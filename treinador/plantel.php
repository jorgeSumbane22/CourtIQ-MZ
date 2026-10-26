<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Treinador: Jogadores
 * A página é comum aos painéis de admin e de treinador: includes/panel_pages/jogadores.php
 */

$panel = 'treinador';   // usa o layout e as permissões do treinador
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/panel_helpers.php';
require __DIR__ . '/../includes/panel_pages/jogadores.php';
