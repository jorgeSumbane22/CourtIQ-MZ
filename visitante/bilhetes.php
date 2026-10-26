<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Site público: Bilhetes
 * Venda de bilhetes para os jogos EM CASA da equipa escolhida.
 * O jogo indicado no formulário é sempre validado: tem de ser desta equipa,
 * em casa e ainda por disputar.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/public_helpers.php';
require_once __DIR__ . '/../includes/repositories/TicketSaleRepository.php';

[$teamId, $team] = pub_team();
$basePath = '../';

const BILHETES_MAX = 10;
$tipos = [
    'normal'    => ['Normal', 250.00, 'Bancada geral'],
    'estudante' => ['Estudante', 150.00, 'Com cartão de estudante válido'],
    'vip'       => ['VIP', 600.00, 'Lugar central junto ao campo'],
];

$gameRepo   = new GameRepository();
$ticketRepo = new TicketSaleRepository();

// Jogos com bilhetes: em casa e ainda agendados
$jogos = array_values(array_filter(
    $gameRepo->upcoming($teamId, 50),
    fn($j) => ($j['home_away'] ?? 'home') !== 'away'
));
$jogosPorId = array_column($jogos, null, 'id');

$erros = [];
$form  = [
    'game_id'     => (int) ($_GET['jogo_id'] ?? 0),
    'buyer_name'  => (string) ($_SESSION['user']['name'] ?? ''),
    'buyer_email' => (string) ($_SESSION['user']['email'] ?? ''),
    'quantity'    => 1,
    'ticket_type' => 'normal',
];

// só quem tem conta compra (o visitante vê o convite para criar conta mais abaixo)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && can('site.tickets')) {
    $form['game_id']     = (int) ($_POST['game_id'] ?? 0);
    $form['buyer_name']  = trim((string) ($_POST['buyer_name'] ?? ''));
    $form['buyer_email'] = trim((string) ($_POST['buyer_email'] ?? ''));
    $form['quantity']    = (int) ($_POST['quantity'] ?? 0);
    $form['ticket_type'] = (string) ($_POST['ticket_type'] ?? 'normal');

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $erros[] = 'A sessão expirou. Envie o formulário novamente.';
    }
    if (!isset($jogosPorId[$form['game_id']])) {
        $erros[] = 'Escolha um jogo da lista.';
    }
    if ($form['buyer_name'] === '' || mb_strlen($form['buyer_name']) > 100) {
        $erros[] = 'Indique o nome completo.';
    }
    if (!filter_var($form['buyer_email'], FILTER_VALIDATE_EMAIL)) {
        $erros[] = 'Indique um email válido.';
    }
    if ($form['quantity'] < 1 || $form['quantity'] > BILHETES_MAX) {
        $erros[] = 'A quantidade deve estar entre 1 e ' . BILHETES_MAX . '.';
    }
    if (!isset($tipos[$form['ticket_type']])) {
        $erros[] = 'Tipo de bilhete inválido.';
    }
    if (empty($_POST['terms'])) {
        $erros[] = 'É necessário aceitar as condições de venda.';
    }

    if (!$erros) {
        try {
            $compra = $ticketRepo->create(
                $form['game_id'],
                $form['buyer_name'],
                $form['buyer_email'],
                $form['quantity'],
                $form['ticket_type'],
                $tipos[$form['ticket_type']][1]
            );
            // guarda o recibo na sessão para o mostrar depois do redirecionamento
            $_SESSION['ultima_compra'] = $compra + [
                'jogo'    => $jogosPorId[$form['game_id']],
                'team_id' => $teamId,
            ];
            redirect('bilhetes.php?sucesso=1');
        } catch (Throwable $e) {
            error_log('[CourtIQ] Erro ao vender bilhete: ' . $e->getMessage());
            $erros[] = 'Não foi possível concluir a compra. Tente novamente dentro de momentos.';
        }
    }
}

$recibo = null;
if (isset($_GET['sucesso']) && !empty($_SESSION['ultima_compra'])) {
    $recibo = $_SESSION['ultima_compra'];
    unset($_SESSION['ultima_compra']);
}

if (!isset($jogosPorId[$form['game_id']])) {
    $form['game_id'] = $jogos ? (int) $jogos[0]['id'] : 0;
}

$pageTitle = 'Bilhetes';
$pageIcon  = 'fa-solid fa-ticket';
$activeNav = 'bilhetes';
require __DIR__ . '/../includes/layout/public_top.php';
if (pub_account_wall('as compras de bilhetes', $basePath)) { require __DIR__ . '/../includes/layout/public_bottom.php'; return; }
?>

<div class="lp">
    <?php pub_page_head($team['name'], 'Bilhetes', 'Garanta o seu lugar nos jogos em casa' . ($team['stadium'] ? ', no ' . $team['stadium'] : '') . '.', [
        [count($jogos), 'Jogos com bilhetes'],
        [number_format($tipos['normal'][1], 0, ',', '.') . ' MZN', 'A partir de'],
    ]); ?>

    <?php if ($recibo): ?>
        <div class="lp-notice" role="status">
            <i class="fa-solid fa-circle-check"></i>
            <div>
                <b>Compra concluída.</b> Guarde o código do bilhete e apresente-o à entrada:<br>
                <code><?= pub_e($recibo['code']) ?></code><br>
                <?= (int) $recibo['quantity'] ?> bilhete(s) <?= pub_e($tipos[$recibo['ticket_type']][0] ?? $recibo['ticket_type']) ?>
                para <?= pub_e($team['name']) ?> vs <?= pub_e($recibo['jogo']['opponent']) ?>
                (<?= pub_e(pub_date($recibo['jogo']['game_date'])) ?>) &mdash;
                total <b><?= number_format((float) $recibo['total'], 2, ',', '.') ?> MZN</b>.
            </div>
        </div>
    <?php endif; ?>
    <?php if ($erros): ?>
        <div class="lp-notice lp-notice--error" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div><?= implode('<br>', array_map('pub_e', $erros)) ?></div></div>
    <?php endif; ?>

    <?php if (!$jogos): ?>
        <p class="lp-empty">Não há jogos em casa agendados neste momento. Consulte os <a href="jogos.php" style="color:var(--accent-text);font-weight:600;">jogos</a>.</p>
    <?php else: ?>

    <div class="lp-duo">
        <section>
            <div class="lp-head"><div><span class="lp-eyebrow">Jogos em casa</span><h2>Próximos jogos</h2></div></div>
            <div class="lp-stack">
                <?php foreach ($jogos as $jogo) pub_game_row($jogo, $team['name'], $basePath, ['tickets' => true]); ?>
            </div>

            <div class="lp-head" style="margin-top:32px;"><div><span class="lp-eyebrow">Preços</span><h2>Tipos de bilhete</h2></div></div>
            <div class="lp-kpis">
                <?php foreach ($tipos as [$nome, $preco, $descricao]): ?>
                <div class="lp-card lp-kpi">
                    <b><?= number_format($preco, 0, ',', '.') ?> <small style="font-size:15px;">MZN</small></b>
                    <span><strong style="color:var(--text);"><?= pub_e($nome) ?></strong> &middot; <?= pub_e($descricao) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="lp-card lp-pad" id="comprar">
            <h2 class="lp-sub">Comprar bilhetes</h2>
            <form method="post" action="bilhetes.php#comprar" class="lp-form" id="ticketForm">
                <input type="hidden" name="csrf_token" value="<?= pub_e(csrf_token()) ?>">

                <div class="lp-field">
                    <label for="t-game">Jogo</label>
                    <select id="t-game" name="game_id" required>
                        <?php foreach ($jogos as $jogo): ?>
                        <option value="<?= (int) $jogo['id'] ?>"<?= (int) $jogo['id'] === $form['game_id'] ? ' selected' : '' ?>>
                            <?= pub_e(pub_date($jogo['game_date'])) ?> &middot; vs <?= pub_e($jogo['opponent']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="lp-form-row">
                    <div class="lp-field">
                        <label for="t-type">Tipo de bilhete</label>
                        <select id="t-type" name="ticket_type" required>
                            <?php foreach ($tipos as $chave => [$nome, $preco]): ?>
                            <option value="<?= $chave ?>" data-price="<?= $preco ?>"<?= $chave === $form['ticket_type'] ? ' selected' : '' ?>>
                                <?= pub_e($nome) ?> &mdash; <?= number_format($preco, 0, ',', '.') ?> MZN
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="lp-field">
                        <label for="t-qty">Quantidade (máx. <?= BILHETES_MAX ?>)</label>
                        <input id="t-qty" name="quantity" type="number" min="1" max="<?= BILHETES_MAX ?>" required value="<?= max(1, (int) $form['quantity']) ?>">
                    </div>
                </div>

                <div class="lp-field">
                    <label for="t-name">Nome completo</label>
                    <input id="t-name" name="buyer_name" type="text" maxlength="100" required autocomplete="name" value="<?= pub_e($form['buyer_name']) ?>">
                </div>
                <div class="lp-field">
                    <label for="t-email">Email (para onde enviamos o código)</label>
                    <input id="t-email" name="buyer_email" type="email" maxlength="100" required autocomplete="email" value="<?= pub_e($form['buyer_email']) ?>">
                </div>

                <div class="lp-total" aria-live="polite">
                    <span>Total a pagar</span>
                    <b><span id="ticketTotal"><?= number_format($tipos[$form['ticket_type']][1] * max(1, (int) $form['quantity']), 2, ',', '.') ?></span> MZN</b>
                </div>

                <label class="lp-check">
                    <input type="checkbox" name="terms" value="1" required>
                    <span>Aceito as condições de venda: os bilhetes são pessoais e não reembolsáveis, exceto se o jogo for cancelado.</span>
                </label>

                <button type="submit" class="lp-btn lp-btn--primary lp-btn--block"><i class="fa-solid fa-ticket"></i> Confirmar compra</button>
            </form>
        </section>
    </div>

    <script>
        (function () {
            var type = document.getElementById('t-type');
            var qty = document.getElementById('t-qty');
            var total = document.getElementById('ticketTotal');
            function update() {
                var price = parseFloat(type.options[type.selectedIndex].getAttribute('data-price')) || 0;
                var n = Math.min(<?= BILHETES_MAX ?>, Math.max(1, parseInt(qty.value, 10) || 1));
                total.textContent = (price * n).toLocaleString('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }
            type.addEventListener('change', update);
            qty.addEventListener('input', update);
            update();
        })();
    </script>

    <?php endif; ?>

</div>

<?php require __DIR__ . '/../includes/layout/public_bottom.php'; ?>
