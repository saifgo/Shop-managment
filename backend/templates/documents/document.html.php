<?php
/**
 * Commercial document (invoice, quote, delivery note…) rendered to PDF by dompdf and
 * shown as-is in the share-link preview. Measurements are in points on an A4 page
 * (595 x 842pt) with 40pt side margins, so the content column is 515pt wide.
 *
 * @var array{
 *     for_pdf: bool,
 *     fonts: array{regular: string, bold: string}|null,
 *     line_height: string,
 *     title: string,
 *     number: string,
 *     date: string,
 *     due_date: string|null,
 *     company: \App\Application\Settings\CompanyProfile,
 *     customer: array{name: string, address: string|null, tax_id: string|null},
 *     lines: list<array{description: string, quantity: string, unit_price: string, total: string}>,
 *     blank_rows: int,
 *     totals: list<array{label: string, value: string}>,
 *     notes: string|null,
 *     stamp: array{src: string, width: string, height: string}|null,
 * } $view
 */
$e = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$company = $view['company'];
$customer = $view['customer'];
$totals = $view['totals'];
$grandTotal = array_pop($totals);
$footerParts = array_filter([$company->name, $company->address, $company->phone, $company->email], static fn (?string $part): bool => $part !== null && $part !== '');
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title><?= $e($view['title'].' N° '.$view['number']) ?></title>
<style>
<?php if ($view['fonts'] !== null): ?>
    @font-face { font-family: 'Carlito'; font-weight: normal; font-style: normal; src: url('<?= $e($view['fonts']['regular']) ?>') format('truetype'); }
    @font-face { font-family: 'Carlito'; font-weight: bold; font-style: normal; src: url('<?= $e($view['fonts']['bold']) ?>') format('truetype'); }
<?php endif; ?>
    @page { margin: 34pt 40pt 54pt 40pt; }
    body {
        margin: 0;
        padding: 0;
        background: #fff;
        font-family: Carlito, Calibri, 'Segoe UI', Helvetica, Arial, sans-serif;
        font-size: 10.5pt;
        line-height: <?= $e($view['line_height']) ?>;
        color: #2B2622;
    }
    .sheet { position: relative; }
<?php if (!$view['for_pdf']): ?>
    /* The browser preview has no @page box, so the sheet recreates the A4 page and its margins. */
    body { overflow-x: hidden; background: #fff; }
    .sheet { box-sizing: border-box; width: 595.3pt; min-height: 841.9pt; padding: 34pt 40pt 54pt 40pt; overflow: hidden; }
    .page-bar { position: absolute; top: 0; left: 0; }
    .page-footer { position: absolute; bottom: 22pt; left: 40pt; }
<?php else: ?>
    /* Fixed elements repeat on every page and may use the page margins. */
    .page-bar { position: fixed; top: -34pt; left: -40pt; }
    .page-footer { position: fixed; bottom: -40pt; left: 0; }
<?php endif; ?>
    .accent { background-color: #A97045; }
    .muted { color: #7A6F67; }
    .page-bar { width: 595.3pt; height: 9pt; background-color: #A97045; }
    .page-footer { width: 515pt; padding-top: 7pt; border-top: 0.6pt solid #E3DAD2; text-align: center; font-size: 8.5pt; color: #7A6F67; }

    table { border-collapse: collapse; }
    .header { width: 100%; margin-top: 14pt; }
    .header td { padding: 0; vertical-align: top; }
    .seller p.seller-name { margin: 0 0 6pt 0; color: #2B2622; font-size: 19pt; font-weight: bold; }
    .seller p { margin: 0 0 2pt 0; font-size: 9.5pt; color: #5E544C; }
    .seller a { color: #5E544C; text-decoration: none; }
    .doc { text-align: right; }
    .doc-title { margin: 0; font-size: 23pt; font-weight: bold; color: #A97045; text-transform: uppercase; letter-spacing: 1.2pt; }
    .doc-number { margin: 3pt 0 0 0; font-size: 12pt; font-weight: bold; }

    .parties { width: 100%; margin-top: 26pt; }
    .parties td { padding: 0; vertical-align: top; }
    .party-card { padding: 11pt 14pt; background-color: #F8F4F0; border-left: 3pt solid #A97045; }
    .party-card .eyebrow { margin: 0 0 4pt 0; font-size: 8pt; font-weight: bold; letter-spacing: 1pt; text-transform: uppercase; color: #A97045; }
    .party-card p { margin: 0 0 2pt 0; }
    .party-card .name { font-size: 12.5pt; font-weight: bold; }
    .gap { width: 16pt; }
    .meta-card { padding: 11pt 14pt; border: 0.6pt solid #E3DAD2; }
    .meta-card table { width: 100%; }
    .meta-card td { padding: 2pt 0; font-size: 10pt; }
    .meta-card td.k { color: #7A6F67; }
    .meta-card td.v { text-align: right; font-weight: bold; }

    table.lines { width: 100%; margin-top: 24pt; }
    table.lines th {
        padding: 7pt 8pt;
        background-color: #A97045;
        color: #fff;
        font-size: 8.5pt;
        font-weight: bold;
        letter-spacing: 0.6pt;
        text-transform: uppercase;
        text-align: right;
        border: 0;
    }
    table.lines thead tr { background-color: #A97045; }
    table.lines td { padding: 8pt; border-bottom: 0.6pt solid #E9E1DA; text-align: right; vertical-align: top; }
    table.lines tr.shaded td { background-color: #FBF8F5; }
    table.lines tr { page-break-inside: avoid; }
    table.lines thead { display: table-header-group; }
    table.lines th.left, table.lines td.left { text-align: left; }
    table.lines td.total { font-weight: bold; }
    .col-description { width: 47%; }
    .col-quantity { width: 11%; }
    .col-price { width: 20%; }
    .col-total { width: 22%; }

    table.summary { width: 100%; margin-top: 18pt; page-break-inside: avoid; }
    table.summary > tbody > tr > td { padding: 0; vertical-align: top; }
    .notes { padding: 6pt 24pt 0 0 !important; font-size: 9.5pt; color: #5E544C; }
    .notes .text { white-space: pre-line; }
    .notes .eyebrow { margin: 0 0 3pt 0; font-size: 8pt; font-weight: bold; letter-spacing: 1pt; text-transform: uppercase; color: #A97045; }
    table.totals { width: 100%; }
    table.totals td { padding: 6pt 8pt; border-bottom: 0.6pt solid #E9E1DA; }
    table.totals td.label { color: #5E544C; }
    table.totals tr.grand { background-color: #A97045; }
    table.totals td.value { text-align: right; font-weight: bold; white-space: nowrap; }
    table.totals tr.grand td { padding: 9pt 8pt; border: 0; background-color: #A97045; color: #fff; font-size: 12pt; font-weight: bold; }
    .col-totals { width: 232pt; }

    table.closing { width: 100%; margin-top: 26pt; page-break-inside: avoid; }
    table.closing td { padding: 0; vertical-align: bottom; }
    .bank { padding: 10pt 14pt; border: 0.6pt solid #E3DAD2; }
    .bank .eyebrow { margin: 0 0 3pt 0; font-size: 8pt; font-weight: bold; letter-spacing: 1pt; text-transform: uppercase; color: #A97045; }
    .bank .number { margin: 0; font-size: 12pt; font-weight: bold; letter-spacing: 0.4pt; }
    .signature { text-align: right; }
    .signature .eyebrow { margin: 0 0 4pt 0; font-size: 8pt; font-weight: bold; letter-spacing: 1pt; text-transform: uppercase; color: #A97045; }
</style>
</head>
<body>
<div class="page-bar"></div>
<?php if ($footerParts !== []): ?>
    <div class="page-footer"><?= $e(implode('  ·  ', $footerParts)) ?></div>
<?php endif; ?>
<div class="sheet">
    <table class="header">
        <tr>
            <td class="seller">
                <p class="seller-name"><?= $e($company->name) ?></p>
                <?php if ($company->address !== null): ?>
                    <p><?= $e($company->address) ?></p>
                <?php endif; ?>
                <?php if ($company->phone !== null): ?>
                    <p>Tél. : <?= $e($company->phone) ?></p>
                <?php endif; ?>
                <?php if ($company->email !== null): ?>
                    <p><a href="mailto:<?= $e($company->email) ?>"><?= $e($company->email) ?></a></p>
                <?php endif; ?>
                <?php if ($company->taxId !== null): ?>
                    <p>M.F : <strong><?= $e($company->taxId) ?></strong></p>
                <?php endif; ?>
            </td>
            <td class="doc">
                <p class="doc-title"><?= $e($view['title']) ?></p>
                <p class="doc-number">N° <?= $e($view['number']) ?></p>
            </td>
        </tr>
    </table>

    <table class="parties">
        <tr>
            <td>
                <div class="party-card">
                    <p class="eyebrow">Facturé à</p>
                    <p class="name"><?= $e($customer['name']) ?></p>
                    <?php if ($customer['address'] !== null): ?>
                        <p><?= $e($customer['address']) ?></p>
                    <?php endif; ?>
                    <?php if ($customer['tax_id'] !== null): ?>
                        <p class="muted">M.F : <?= $e($customer['tax_id']) ?></p>
                    <?php endif; ?>
                </div>
            </td>
            <td class="gap"></td>
            <td style="width: 190pt;">
                <div class="meta-card">
                    <table>
                        <tr><td class="k">Date</td><td class="v"><?= $e($view['date']) ?></td></tr>
                        <?php if (($view['due_date'] ?? null) !== null): ?>
                            <tr><td class="k">Échéance</td><td class="v"><?= $e($view['due_date']) ?></td></tr>
                        <?php endif; ?>
                        <tr><td class="k">Devise</td><td class="v"><?= $e($view['currency'] ?? 'TND') ?></td></tr>
                    </table>
                </div>
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th class="left col-description">Description</th>
                <th class="col-quantity">Qté</th>
                <th class="col-price">Prix unitaire</th>
                <th class="col-total">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($view['lines'] as $index => $line): ?>
                <tr class="<?= $index % 2 === 1 ? 'shaded' : '' ?>">
                    <td class="left"><?= $e($line['description']) ?></td>
                    <td><?= $e($line['quantity']) ?></td>
                    <td><?= $e($line['unit_price']) ?></td>
                    <td class="total"><?= $e($line['total']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php for ($index = count($view['lines']); $index < count($view['lines']) + $view['blank_rows']; ++$index): ?>
                <tr class="<?= $index % 2 === 1 ? 'shaded' : '' ?>"><td class="left">&nbsp;</td><td></td><td></td><td></td></tr>
            <?php endfor; ?>
        </tbody>
    </table>

    <table class="summary">
        <tr>
            <td class="notes">
                <?php if ($view['notes'] !== null && trim($view['notes']) !== ''): ?>
                    <p class="eyebrow">Notes</p>
                    <div class="text"><?= $e(trim($view['notes'])) ?></div>
                <?php endif; ?>
            </td>
            <td class="col-totals">
                <table class="totals">
                    <?php foreach ($totals as $total): ?>
                        <tr>
                            <td class="label"><?= $e($total['label']) ?></td>
                            <td class="value"><?= $e($total['value']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="grand">
                        <td><?= $e($grandTotal['label']) ?></td>
                        <td style="text-align: right; white-space: nowrap;"><?= $e($grandTotal['value']) ?></td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <?php if ($company->bankAccount !== null || $view['stamp'] !== null): ?>
        <table class="closing">
            <tr>
                <td>
                    <?php if ($company->bankAccount !== null): ?>
                        <div class="bank" style="width: 250pt;">
                            <p class="eyebrow"><?= $e($company->bankLabel) ?></p>
                            <p class="number"><?= $e($company->bankAccount) ?></p>
                        </div>
                    <?php endif; ?>
                </td>
                <td class="signature">
                    <?php if ($view['stamp'] !== null): ?>
                        <p class="eyebrow">Cachet et signature</p>
                        <img src="<?= $e($view['stamp']['src']) ?>" alt="" style="width: <?= $e($view['stamp']['width']) ?>; height: <?= $e($view['stamp']['height']) ?>;">
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    <?php endif; ?>
</div>
</body>
</html>
