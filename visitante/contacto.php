<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Site público: Contacto
 * O visitante escreve à equipa que está a ver; a mensagem fica guardada com o team_id dessa equipa.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/public_helpers.php';
require_once __DIR__ . '/../includes/repositories/ContactRepository.php';

[$teamId, $team] = pub_team();
$basePath = '../';

$erros   = [];
$enviado = isset($_GET['enviado']);
$form    = [
    'name'    => '',
    'email'   => '',
    'subject' => trim((string) ($_GET['assunto'] ?? '')),
    'message' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $campo => $_) {
        $form[$campo] = trim((string) ($_POST[$campo] ?? ''));
    }

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $erros[] = 'A sessão expirou. Envie o formulário novamente.';
    }
    if ($form['name'] === '' || mb_strlen($form['name']) > 100) {
        $erros[] = 'Indique o seu nome.';
    }
    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($form['email']) > 100) {
        $erros[] = 'Indique um email válido.';
    }
    if ($form['subject'] === '' || mb_strlen($form['subject']) > 200) {
        $erros[] = 'Indique o assunto.';
    }
    if (mb_strlen($form['message']) < 10) {
        $erros[] = 'A mensagem deve ter pelo menos 10 caracteres.';
    }
    if (!$teamId) {
        $erros[] = 'Não há nenhuma equipa selecionada.';
    }

    if (!$erros) {
        try {
            (new ContactRepository())->create($form['name'], $form['email'], $form['subject'], $form['message'], $teamId);
            redirect('contacto.php?enviado=1');
        } catch (Throwable $e) {
            error_log('[CourtIQ] Erro ao guardar mensagem de contacto: ' . $e->getMessage());
            $erros[] = 'Não foi possível enviar a mensagem. Tente novamente dentro de momentos.';
        }
    }
}

$pageTitle = 'Contacto';
$pageIcon  = 'fa-solid fa-phone';
$activeNav = 'contacto';
require __DIR__ . '/../includes/layout/public_top.php';
?>

<div class="lp">
    <?php pub_page_head($team['name'], 'Contacto', 'Dúvidas, sugestões ou propostas? Escreva à equipa.'); ?>

    <?php if ($enviado): ?>
        <div class="lp-notice" role="status"><i class="fa-solid fa-circle-check"></i><div><b>Mensagem enviada.</b><br>O <?= pub_e($team['name']) ?> recebeu a sua mensagem e responderá por email.</div></div>
    <?php endif; ?>
    <?php if ($erros): ?>
        <div class="lp-notice lp-notice--error" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div><?= implode('<br>', array_map('pub_e', $erros)) ?></div></div>
    <?php endif; ?>

    <div class="lp-duo">
        <section class="lp-card lp-pad">
            <h2 class="lp-sub">Enviar mensagem a <?= pub_e($team['name']) ?></h2>
            <form method="post" action="contacto.php" class="lp-form">
                <input type="hidden" name="csrf_token" value="<?= pub_e(csrf_token()) ?>">
                <div class="lp-form-row">
                    <div class="lp-field">
                        <label for="c-name">Nome</label>
                        <input id="c-name" name="name" type="text" maxlength="100" required autocomplete="name" value="<?= pub_e($form['name']) ?>">
                    </div>
                    <div class="lp-field">
                        <label for="c-email">Email</label>
                        <input id="c-email" name="email" type="email" maxlength="100" required autocomplete="email" value="<?= pub_e($form['email']) ?>">
                    </div>
                </div>
                <div class="lp-field">
                    <label for="c-subject">Assunto</label>
                    <input id="c-subject" name="subject" type="text" maxlength="200" required value="<?= pub_e($form['subject']) ?>">
                </div>
                <div class="lp-field">
                    <label for="c-message">Mensagem</label>
                    <textarea id="c-message" name="message" required minlength="10"><?= pub_e($form['message']) ?></textarea>
                </div>
                <div>
                    <button type="submit" class="lp-btn lp-btn--primary"><i class="fa-solid fa-paper-plane"></i> Enviar mensagem</button>
                </div>
            </form>
        </section>

        <section class="lp-card lp-pad">
            <h2 class="lp-sub">Onde nos encontrar</h2>
            <ul class="lp-info">
                <li><span class="lp-info-icon"><i class="fa-solid fa-location-dot"></i></span><div><small>Pavilhão</small><b><?= pub_e($team['stadium'] ?: '—') ?></b></div></li>
                <li><span class="lp-info-icon"><i class="fa-solid fa-city"></i></span><div><small>Cidade</small><b><?= pub_e($team['city'] ?: '—') ?>, Moçambique</b></div></li>
                <li><span class="lp-info-icon"><i class="fa-solid fa-ticket"></i></span><div><small>Bilhetes</small><a href="bilhetes.php">Comprar bilhetes para os jogos em casa</a></div></li>
                <li><span class="lp-info-icon"><i class="fa-solid fa-calendar-days"></i></span><div><small>Próximos jogos</small><a href="jogos.php">Ver todos os jogos</a></div></li>
                <li><span class="lp-info-icon"><i class="fa-solid fa-headset"></i></span><div><small>Suporte da plataforma CourtIQMZ</small><a href="mailto:jsumbane22@gmail.com">jsumbane22@gmail.com</a></div></li>
            </ul>
        </section>
    </div>

</div>

<?php require __DIR__ . '/../includes/layout/public_bottom.php'; ?>
