<?php
declare(strict_types=1);

/**
 * layout/footer.php — o rodapé e o fecho de todas as páginas.
 * No site público mostra o rodapé completo (ligações, contactos, créditos);
 * nos painéis, uma linha discreta. Depois fecha o conteúdo aberto em navbar.php,
 * carrega os scripts comuns e termina o documento.
 */

$footerBase  = $basePath ?? '../';
$footerPanel = (string) ($layout['panel'] ?? 'public');
?>
            <?php if ($footerPanel === 'public'):
                $footerTeamId = public_team_chosen();   // na página da liga, sem clube escolhido, o rodapé não mostra nenhum
                $footerTeam   = $footerTeamId ? TeamHelper::getTeamInfo($footerTeamId) : null;
                $footerStats  = $footerTeamId ? TeamHelper::getTeamStats($footerTeamId) : [];
            ?>
            <div class="lp lp-footer-wrap">
                <footer class="lp-footer">
                    <div class="lp-footer-grid">
                        <div>
                            <div class="lp-brand">Court<em>IQ</em>MZ</div>
                            <p class="lp-footer-about">Plataforma inteligente para gestão de clubes de basquetebol. Tecnologia ao serviço do desporto.</p>
                            <div class="lp-social">
                                <a href="#" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a>
                                <a href="#" aria-label="Instagram"><i class="fa-brands fa-instagram"></i></a>
                                <a href="#" aria-label="Twitter"><i class="fa-brands fa-twitter"></i></a>
                                <a href="#" aria-label="YouTube"><i class="fa-brands fa-youtube"></i></a>
                                <a href="#" aria-label="LinkedIn"><i class="fa-brands fa-linkedin-in"></i></a>
                            </div>
                        </div>

                        <div>
                            <h3>Equipa</h3>
                            <ul>
                                <li><a href="<?= e($footerBase) ?>visitante/jogadores.php"><i class="fa-solid fa-user-group"></i>Jogadores</a></li>
                                <li><a href="<?= e($footerBase) ?>visitante/jogos.php"><i class="fa-solid fa-trophy"></i>Jogos</a></li>
                                <li><a href="<?= e($footerBase) ?>visitante/classificacao.php"><i class="fa-solid fa-ranking-star"></i>Classificação</a></li>
                                <li><a href="<?= e($footerBase) ?>visitante/noticias.php"><i class="fa-solid fa-newspaper"></i>Notícias</a></li>
                            </ul>
                        </div>

                        <div>
                            <h3>Clube</h3>
                            <ul>
                                <li><a href="<?= e($footerBase) ?>visitante/galeria.php"><i class="fa-solid fa-images"></i>Galeria</a></li>
                                <li><a href="<?= e($footerBase) ?>visitante/equipa-tecnica.php"><i class="fa-solid fa-chalkboard-user"></i>Equipa técnica</a></li>
                                <li><a href="<?= e($footerBase) ?>visitante/historia.php"><i class="fa-solid fa-book-open"></i>História</a></li>
                                <li><a href="<?= e($footerBase) ?>visitante/bilhetes.php"><i class="fa-solid fa-ticket"></i>Bilhetes</a></li>
                            </ul>
                        </div>

                        <div>
                            <h3>Contacto</h3>
                            <ul>
                                <li><a href="mailto:jsumbane22@gmail.com"><i class="fa-solid fa-envelope"></i>jsumbane22@gmail.com</a></li>
                                <li><a href="tel:+258845872347"><i class="fa-solid fa-phone"></i>+258 84 587 2347</a></li>
                                <li><span><i class="fa-solid fa-location-dot"></i><?= e(($footerTeam['city'] ?? '') ?: 'Maputo') ?>, Moçambique</span></li>
                                <li><a href="<?= e($footerBase) ?>visitante/contacto.php"><i class="fa-solid fa-paper-plane"></i>Enviar mensagem</a></li>
                            </ul>
                        </div>
                    </div>

                    <div class="lp-footer-bottom">
                        <div>
                            &copy; <?= date('Y') ?> <strong>CourtIQMZ</strong> &mdash; Gestão de Basquetebol. Todos os direitos reservados.
                            <div class="lp-footer-meta">
                                <?php if ($footerTeam): ?>
                                <span><i class="fa-solid fa-basketball"></i><?= e($footerTeam['name']) ?><?= !empty($footerTeam['founded_year']) ? ' &bull; Desde ' . e($footerTeam['founded_year']) : '' ?></span>
                                <span><i class="fa-solid fa-chart-line"></i><?= (int) ($footerStats['total_jogos'] ?? 0) ?> jogos &bull; <?= (int) ($footerStats['total_vitorias'] ?? 0) ?> vitórias</span>
                                <?php endif; ?>
                                <span><i class="fa-solid fa-layer-group"></i><?= implode(" &bull; ", team_escaloes($footerTeam ?: null)) ?></span>
                            </div>
                        </div>

                        <div class="lp-dev" aria-label="Créditos do desenvolvedor">
                            <span class="lp-dev-icon" aria-hidden="true"><i class="fa-solid fa-code"></i></span>
                            <div>
                                <small>Desenvolvido por</small>
                                <b>Jorge Sumbane</b>
                            </div>
                            <div class="lp-dev-links">
                                <a href="mailto:jsumbane22@gmail.com" aria-label="Enviar email para Jorge Sumbane"><i class="fa-solid fa-envelope"></i></a>
                                <a href="tel:+258845872347" aria-label="Telefonar para Jorge Sumbane"><i class="fa-solid fa-phone"></i></a>
                            </div>
                        </div>
                    </div>
                </footer>
            </div>
            <?php else: ?>
            <footer class="panel-footer">
                <span>&copy; <?= date('Y') ?> <strong>CourtIQMZ</strong> &mdash; Gestão de Basquetebol</span>
                <span>Desenvolvido por Jorge Sumbane</span>
            </footer>
            <?php endif; ?>
        </div><!-- /.content -->
    </main><!-- /.main -->

    <button class="dark-mode-toggle-global" id="darkModeToggle" title="Alternar tema" type="button" aria-label="Alternar entre tema claro e escuro">
        <i class="fa-solid fa-moon icon-moon"></i>
        <i class="fa-solid fa-sun icon-sun"></i>
    </button>
    <span class="theme-label-global" id="themeLabel">Modo claro</span>

    <div class="toast" id="toastEl" role="status" aria-live="polite">
        <i class="fa-solid fa-circle-info" id="toastIcon"></i>
        <span id="toastMsg"></span>
    </div>

    <script src="<?= e($footerBase) ?>assets/js/layout.js?v=22" defer></script>
    <script src="<?= e($footerBase) ?>assets/js/public.js?v=21" defer></script>
    <?php if ($footerPanel !== 'public'): ?><script src="<?= e($footerBase) ?>assets/js/panel.js?v=21" defer></script><?php endif; ?>
</body>
</html>
