<?php require __DIR__ . '/../partials/header.php'; ?>
<?php
$summary = $summary ?? [];
$cashEntries = $cashEntries ?? [];
$pendingPayments = $pendingPayments ?? [];
$tickets = $tickets ?? [];
$bookmakers = $bookmakers ?? [];
$cashFilters = $cashFilters ?? [
    'entry_type' => 'all',
    'status' => 'all',
    'date_from' => '',
    'date_to' => '',
];
$isManager = ($user['role'] ?? '') === 'manager';
?>
<div class="admin-shell" data-admin-shell>
    <aside class="admin-sidebar">
        <div class="admin-brand">
            <span>Painel do agente</span>
            <strong><?= htmlspecialchars($user['name'], ENT_QUOTES) ?></strong>
        </div>

        <nav class="admin-nav" data-admin-nav>
            <a href="#visao-geral" class="active">Visao geral</a>
            <a href="#pagamentos">Pagamentos</a>
            <a href="#caixa">Caixa</a>
            <a href="#bilhetes">Bilhetes</a>
            <?php if ($isManager): ?>
                <a href="#cambistas">Cambistas</a>
            <?php endif; ?>
        </nav>

        <div class="admin-sidebar-actions">
            <a class="btn-outline full" href="<?= htmlspecialchars(app_url('/'), ENT_QUOTES) ?>">Ver jogos</a>
            <a class="btn-dark full" href="<?= htmlspecialchars(app_url('/logout'), ENT_QUOTES) ?>">Sair</a>
        </div>
    </aside>

    <main class="admin-content">
        <section class="admin-header">
            <div>
                <span class="eyebrow">PAINEL RESTRITO</span>
                <h1><?= htmlspecialchars(($user['role'] ?? '') === 'manager' ? 'Gerente' : 'Cambista', ENT_QUOTES) ?></h1>
            </div>
            <button class="btn-outline admin-menu-toggle" type="button" data-admin-menu-toggle>Menu</button>
        </section>

        <section class="admin-panel active" id="visao-geral" data-admin-section>
            <div class="admin-metrics">
                <article><span>Caixa do agente</span><strong>R$ <?= number_format((float) ($summary['agent_balance'] ?? 0), 2, ',', '.') ?></strong></article>
                <article><span>Comissao</span><strong><?= number_format((float) ($summary['commission_rate'] ?? 0), 2, ',', '.') ?>%</strong></article>
                <article><span>Bilhetes</span><strong><?= (int) ($summary['tickets_total'] ?? 0) ?></strong></article>
                <article><span>Bilhetes abertos</span><strong><?= (int) ($summary['open_tickets'] ?? 0) ?></strong></article>
                <article><span>Ganhos/comissoes</span><strong>R$ <?= number_format((float) ($summary['commissions_total'] ?? 0), 2, ',', '.') ?></strong></article>
                <article><span>Comissao disponivel</span><strong>R$ <?= number_format((float) ($summary['commissions_available'] ?? 0), 2, ',', '.') ?></strong></article>
                <?php if ($isManager): ?>
                    <article><span>Cambistas</span><strong><?= (int) ($summary['bookmakers_total'] ?? 0) ?></strong></article>
                <?php endif; ?>
            </div>
            <div class="admin-grid one-column">
                <article class="admin-card">
                    <h2>Saque de comissao</h2>
                    <p class="admin-helper-text">
                        Solicite saque do saldo de comissao disponivel no painel.
                    </p>
                    <form method="post" action="<?= htmlspecialchars(app_url('/agent/commission/withdraw'), ENT_QUOTES) ?>" class="form-grid">
                        <input type="number" step="0.01" min="1" name="amount" placeholder="Valor do saque" required>
                        <button type="submit" class="btn-dark">Solicitar saque da comissao</button>
                    </form>
                </article>
            </div>
        </section>

        <section class="admin-panel" id="pagamentos" data-admin-section>
            <div class="admin-grid one-column">
                <article class="admin-card">
                    <h2>Pagamentos Pix pendentes</h2>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Referencia</th><th>Agente</th><th>Modo</th><th>Valor</th><th>Status</th><th>Acao</th></tr></thead>
                            <tbody>
                            <?php if ($pendingPayments === []): ?>
                                <tr><td colspan="6">Nenhum pagamento recente encontrado.</td></tr>
                            <?php else: ?>
                                <?php foreach ($pendingPayments as $payment): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) $payment['reference_code'], ENT_QUOTES) ?></td>
                                        <td><?= htmlspecialchars((string) ($payment['agent_name'] ?? '-'), ENT_QUOTES) ?></td>
                                        <td><?= htmlspecialchars((string) ($payment['payment_mode'] ?? '-'), ENT_QUOTES) ?></td>
                                        <td>R$ <?= number_format((float) ($payment['stake'] ?? 0), 2, ',', '.') ?></td>
                                        <td><?= htmlspecialchars(strtoupper((string) ($payment['status'] ?? 'pending')), ENT_QUOTES) ?></td>
                                        <td>
                                            <?php if (($payment['status'] ?? '') !== 'issued'): ?>
                                                <?php if (($payment['payment_mode'] ?? '') === 'gateway'): ?>
                                                    <form method="post" action="<?= htmlspecialchars(app_url('/agent/payment/status'), ENT_QUOTES) ?>" style="display:inline;">
                                                        <input type="hidden" name="reference_code" value="<?= htmlspecialchars((string) $payment['reference_code'], ENT_QUOTES) ?>">
                                                        <input type="hidden" name="return_to" value="/agent#pagamentos">
                                                        <button type="submit" class="btn-outline">Atualizar</button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="post" action="<?= htmlspecialchars(app_url('/agent/payment/custom-confirm'), ENT_QUOTES) ?>" style="display:inline;" onsubmit="return confirm('Confirmar recebimento e emitir o bilhete?');">
                                                        <input type="hidden" name="reference_code" value="<?= htmlspecialchars((string) $payment['reference_code'], ENT_QUOTES) ?>">
                                                        <input type="hidden" name="return_to" value="/agent#pagamentos">
                                                        <button type="submit" class="btn-dark">Confirmar</button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                Bilhete emitido
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </div>
        </section>

        <section class="admin-panel" id="caixa" data-admin-section>
            <div class="admin-grid one-column">
                <article class="admin-card">
                    <div class="admin-card-head">
                        <h2>Caixa do agente</h2>
                        <form method="get" action="<?= htmlspecialchars(app_url('/agent'), ENT_QUOTES) ?>" class="admin-ticket-filter-form">
                            <input type="hidden" name="tab" value="caixa">
                            <select name="cash_entry_type">
                                <option value="all">Todos os tipos</option>
                                <option value="bookmaker_commission" <?= ($cashFilters['entry_type'] ?? '') === 'bookmaker_commission' ? 'selected' : '' ?>>Comissao cambista</option>
                                <option value="manager_commission" <?= ($cashFilters['entry_type'] ?? '') === 'manager_commission' ? 'selected' : '' ?>>Comissao gerente</option>
                                <option value="self_commission" <?= ($cashFilters['entry_type'] ?? '') === 'self_commission' ? 'selected' : '' ?>>Comissao propria</option>
                                <option value="commission_withdrawal" <?= ($cashFilters['entry_type'] ?? '') === 'commission_withdrawal' ? 'selected' : '' ?>>Saque de comissao</option>
                                <option value="ticket_win" <?= ($cashFilters['entry_type'] ?? '') === 'ticket_win' ? 'selected' : '' ?>>Bilhete ganho</option>
                                <option value="manual_adjustment" <?= ($cashFilters['entry_type'] ?? '') === 'manual_adjustment' ? 'selected' : '' ?>>Ajuste manual</option>
                            </select>
                            <select name="cash_status">
                                <option value="all">Todos os status</option>
                                <option value="paid" <?= ($cashFilters['status'] ?? '') === 'paid' ? 'selected' : '' ?>>Pago</option>
                                <option value="pending" <?= ($cashFilters['status'] ?? '') === 'pending' ? 'selected' : '' ?>>Pendente</option>
                                <option value="failed" <?= ($cashFilters['status'] ?? '') === 'failed' ? 'selected' : '' ?>>Falhou</option>
                            </select>
                            <input type="date" name="cash_date_from" value="<?= htmlspecialchars((string) ($cashFilters['date_from'] ?? ''), ENT_QUOTES) ?>">
                            <input type="date" name="cash_date_to" value="<?= htmlspecialchars((string) ($cashFilters['date_to'] ?? ''), ENT_QUOTES) ?>">
                            <button type="submit" class="btn-outline">Filtrar</button>
                            <a class="btn-dark" href="<?= htmlspecialchars(app_url('/agent#caixa'), ENT_QUOTES) ?>">Limpar</a>
                        </form>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Data</th><th>Agente</th><th>Origem</th><th>Tipo</th><th>Valor</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php if ($cashEntries === []): ?>
                                <tr><td colspan="6">Nenhum lancamento encontrado.</td></tr>
                            <?php else: ?>
                                <?php foreach ($cashEntries as $entry): ?>
                                    <tr>
                                        <td><?= date('d/m/Y H:i', strtotime((string) $entry['created_at'])) ?></td>
                                        <td><?= htmlspecialchars((string) ($entry['agent_name'] ?? '-'), ENT_QUOTES) ?></td>
                                        <td><?= htmlspecialchars((string) ($entry['source_agent_name'] ?? '-'), ENT_QUOTES) ?></td>
                                        <td><?= htmlspecialchars((string) ($entry['entry_type'] ?? '-'), ENT_QUOTES) ?></td>
                                        <td><?= (($entry['direction'] ?? 'credit') === 'debit' ? '-' : '+') ?> R$ <?= number_format((float) ($entry['amount'] ?? 0), 2, ',', '.') ?></td>
                                        <td><?= htmlspecialchars(strtoupper((string) ($entry['status'] ?? 'paid')), ENT_QUOTES) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </div>
        </section>

        <section class="admin-panel" id="bilhetes" data-admin-section>
            <div class="admin-grid one-column">
                <article class="admin-card">
                    <h2>Bilhetes do agente</h2>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Codigo</th><th>Vendedor</th><th>Canal</th><th>Stake</th><th>Retorno</th><th>Status</th><th>Data</th></tr></thead>
                            <tbody>
                            <?php if ($tickets === []): ?>
                                <tr><td colspan="7">Nenhum bilhete encontrado.</td></tr>
                            <?php else: ?>
                                <?php foreach ($tickets as $ticket): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) $ticket['ticket_code'], ENT_QUOTES) ?></td>
                                        <td><?= htmlspecialchars((string) ($ticket['seller_name'] ?? '-'), ENT_QUOTES) ?></td>
                                        <td><?= htmlspecialchars((string) ($ticket['sales_channel'] ?? '-'), ENT_QUOTES) ?></td>
                                        <td>R$ <?= number_format((float) ($ticket['stake'] ?? 0), 2, ',', '.') ?></td>
                                        <td>R$ <?= number_format((float) ($ticket['potential_return'] ?? 0), 2, ',', '.') ?></td>
                                        <td><?= htmlspecialchars((string) ($ticket['status'] ?? '-'), ENT_QUOTES) ?></td>
                                        <td><?= date('d/m/Y H:i', strtotime((string) $ticket['created_at'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </div>
        </section>

        <?php if ($isManager): ?>
            <section class="admin-panel" id="cambistas" data-admin-section>
                <div class="admin-grid one-column">
                    <article class="admin-card">
                        <h2>Meus cambistas</h2>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Nome</th><th>E-mail</th><th>Status</th><th>Comissao</th><th>Caixa</th><th>Ganhos</th></tr></thead>
                                <tbody>
                                <?php if ($bookmakers === []): ?>
                                    <tr><td colspan="6">Nenhum cambista vinculado.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($bookmakers as $bookmaker): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string) $bookmaker['name'], ENT_QUOTES) ?></td>
                                            <td><?= htmlspecialchars((string) $bookmaker['email'], ENT_QUOTES) ?></td>
                                            <td><?= (int) ($bookmaker['is_active'] ?? 0) === 1 ? 'Ativo' : 'Inativo' ?></td>
                                            <td><?= number_format((float) ($bookmaker['commission_rate'] ?? 0), 2, ',', '.') ?>%</td>
                                            <td>R$ <?= number_format((float) ($bookmaker['agent_balance'] ?? 0), 2, ',', '.') ?></td>
                                            <td>R$ <?= number_format((float) ($bookmaker['commissions_total'] ?? 0), 2, ',', '.') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </article>
                </div>
            </section>
        <?php endif; ?>
    </main>
</div>
<script src="<?= htmlspecialchars(app_url('public/assets/js/app.js?v=' . (@filemtime(__DIR__ . '/../../../public/assets/js/app.js') ?: time())), ENT_QUOTES) ?>"></script>
<?php require __DIR__ . '/../partials/footer.php'; ?>
