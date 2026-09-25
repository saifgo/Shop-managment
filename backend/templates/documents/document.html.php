<?php
/**
 * Commercial document (invoice, quote, delivery note…) rendered to PDF by dompdf and
 * shown as-is in the share-link preview. Measurements are in points on an A4 page and
 * follow the company's paper invoice template.
 *
 * @var array{
 *     for_pdf: bool,
 *     fonts: array{regular: string, bold: string}|null,
 *     line_height: string,
 *     title: string,
 *     number: string,
 *     date: string,
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
    @page { margin-top: 22.5pt; margin-right: 0; margin-bottom: 30pt; margin-left: 0; }
    /* dompdf applies the @page margins to <html>, so only <body> is reset. */
    body {
        margin: 0;
        padding: 0;
        background: #fff;
        font-family: Carlito, Calibri, 'Segoe UI', Helvetica, Arial, sans-serif;
        font-size: 11pt;
        line-height: <?= $e($view['line_height']) ?>;
        color: #000;
    }
    .sheet { position: relative; }
<?php if (!$view['for_pdf']): ?>
    /* The browser preview has no @page box, so the sheet recreates the A4 page and its margins. */
    body { overflow-x: hidden; }
    .sheet { box-sizing: border-box; width: 595.3pt; min-height: 841.9pt; padding: 22.5pt 0 30pt 0; overflow: hidden; }
<?php endif; ?>
    .accent { background-color: #A97045; }
    .top-bar { margin-left: 19.5pt; width: 552pt; height: 36.8pt; border-radius: 6pt; }

    .parties { width: 100%; margin-top: 29pt; border-collapse: collapse; }
    .parties td { padding: 0; vertical-align: top; }
    .seller { width: 326pt; padding-left: 14pt !important; }
    .seller p { margin: 0; }
    .seller .seller-name { font-size: 14pt; font-weight: bold; margin-bottom: 20pt; }
    .seller a { color: #0563C1; text-decoration: underline; }
    .customer p { margin: 0 0 9pt 0; padding-right: 45pt; }
    .customer .document-date { font-size: 12pt; margin: 0; }
    .customer .document-title { font-size: 14pt; font-weight: bold; margin-bottom: 5pt; }
    .customer .customer-name { font-size: 12pt; font-weight: bold; }
    .strong { font-weight: bold; }

    table.lines { margin: 70pt 0 0 71.6pt; width: 452pt; border-collapse: collapse; }
    table.lines th, table.lines td {
        height: 17.5pt;
        padding: 0 4pt;
        border: 0.48pt solid #999;
        text-align: center;
        vertical-align: middle;
    }
    table.lines th { color: #fff; font-weight: bold; border-bottom-color: #000; }
    table.lines tr.shaded td { background-color: #E7E6E6; }
    /* Column widths exclude the 8pt of horizontal cell padding: 187 + 63 + 85 + 117 = 452pt. */
    .col-description { width: 179pt; }
    .col-quantity { width: 55pt; }
    .col-price { width: 77pt; }
    .col-total { width: 109pt; }

    table.summary { margin: 56pt 0 0 71.6pt; width: 453.8pt; border-collapse: collapse; }
    table.summary > tbody > tr > td { padding: 0; vertical-align: top; }
    .notes { width: 196pt; padding-right: 10pt !important; font-size: 10pt; white-space: pre-line; }
    table.totals { width: 247.4pt; border-collapse: collapse; }
    table.totals td { height: 21.5pt; padding: 1pt 6pt; border: 0.48pt solid #A5A5A5; vertical-align: top; }
    table.totals td.label { width: 119.6pt; font-size: 10pt; font-weight: bold; }
    table.totals td.value { font-size: 11pt; }

    .stamp { height: 114pt; margin: 2pt 0 0 94.7pt; }

    .bank { position: relative; height: 46pt; margin-left: 71.6pt; }
    .bank-label { font-weight: bold; margin: 0 0 8pt 0; }
    .bank-number { font-size: 12pt; font-weight: bold; margin: 0; }
    .pill { position: absolute; top: 15.6pt; left: 394.7pt; width: 129pt; height: 24.8pt; border-radius: 12.4pt 0 0 12.4pt; }
</style>
</head>
<body>
<div class="sheet">
    <div class="top-bar accent"></div>

    <table class="parties">
        <tr>
            <td class="seller">
                <p class="seller-name"><?= $e($company->name) ?></p>
                <?php if ($company->address !== null): ?>
                    <p>Adresse :</p>
                    <p><?= $e($company->address) ?></p>
                <?php endif; ?>
                <?php if ($company->phone !== null): ?>
                    <p>Numéro de téléphone :</p>
                    <p><?= $e($company->phone) ?></p>
                <?php endif; ?>
                <?php if ($company->email !== null): ?>
                    <p>Email :</p>
                    <p><a href="mailto:<?= $e($company->email) ?>"><?= $e($company->email) ?></a></p>
                <?php endif; ?>
                <?php if ($company->taxId !== null): ?>
                    <p>M.F :</p>
                    <p class="strong"><?= $e($company->taxId) ?></p>
                <?php endif; ?>
            </td>
            <td class="customer">
                <p class="document-date">Date <?= $e($view['date']) ?></p>
                <p class="document-title"><?= $e($view['title']) ?> N° <?= $e($view['number']) ?></p>
                <p class="customer-name"><?= $e($customer['name']) ?></p>
                <?php if ($customer['address'] !== null): ?>
                    <p>Adresse: <?= $e($customer['address']) ?></p>
                <?php endif; ?>
                <?php if ($customer['tax_id'] !== null): ?>
                    <p>M. F: <?= $e($customer['tax_id']) ?></p>
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th class="accent col-description">Description</th>
                <th class="accent col-quantity">Quantité</th>
                <th class="accent col-price">Prix Unitaire</th>
                <th class="accent col-total">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($view['lines'] as $index => $line): ?>
                <tr class="<?= $index % 2 === 1 ? 'shaded' : '' ?>">
                    <td><?= $e($line['description']) ?></td>
                    <td><?= $e($line['quantity']) ?></td>
                    <td><?= $e($line['unit_price']) ?></td>
                    <td><?= $e($line['total']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php for ($index = count($view['lines']); $index < count($view['lines']) + $view['blank_rows']; ++$index): ?>
                <tr class="<?= $index % 2 === 1 ? 'shaded' : '' ?>"><td></td><td></td><td></td><td></td></tr>
            <?php endfor; ?>
        </tbody>
    </table>

    <table class="summary">
        <tr>
            <td class="notes"><?= $e($view['notes']) ?></td>
            <td>
                <table class="totals">
                    <?php foreach ($view['totals'] as $total): ?>
                        <tr>
                            <td class="label"><?= $e($total['label']) ?></td>
                            <td class="value"><?= $e($total['value']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </td>
        </tr>
    </table>

    <div class="stamp">
        <?php if ($view['stamp'] !== null): ?>
            <img src="<?= $e($view['stamp']['src']) ?>" alt="" style="width: <?= $e($view['stamp']['width']) ?>; height: <?= $e($view['stamp']['height']) ?>;">
        <?php endif; ?>
    </div>

    <div class="bank">
        <?php if ($company->bankAccount !== null): ?>
            <p class="bank-label"><?= $e($company->bankLabel) ?> :</p>
            <p class="bank-number"><?= $e($company->bankAccount) ?></p>
        <?php endif; ?>
        <div class="pill accent"></div>
    </div>
</div>
</body>
</html>
