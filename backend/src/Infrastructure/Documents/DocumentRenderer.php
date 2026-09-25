<?php

declare(strict_types=1);

namespace App\Infrastructure\Documents;

use App\Application\Settings\CompanyProfile;
use App\Infrastructure\Persistence\Entity\Documents\CommercialDocument;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Renders commercial documents with templates/documents/document.html.php. The same
 * HTML becomes the PDF (through dompdf) and the browser preview behind share links,
 * so both always look alike.
 */
final class DocumentRenderer
{
    /** Calibri-compatible font installed in the API image (Debian fonts-crosextra-carlito). */
    private const CARLITO_DIR = '/usr/share/fonts/truetype/crosextra';

    /** The paper template always shows at least this many table rows, blank ones included. */
    private const MIN_TABLE_ROWS = 7;

    /** Box the stamp/signature image is fitted into, in points. */
    private const STAMP_MAX_WIDTH = 160.0;
    private const STAMP_MAX_HEIGHT = 105.0;

    /** Single line spacing of Calibri/Carlito, as in the paper template (13.4pt at 11pt). */
    private const LINE_HEIGHT = '1.22';

    /**
     * dompdf multiplies line-height by the font's own height, which for Carlito includes
     * its line gap (1.221em) plus dompdf's baseline strut, so a line would come out 1.343x
     * taller than asked. This ratio gives the same 13.4pt lines in the PDF.
     */
    private const CARLITO_PDF_LINE_HEIGHT = '0.908';

    public function __construct(
        #[Autowire('%kernel.project_dir%/templates/documents')]
        private string $templateDir,
        #[Autowire('%kernel.cache_dir%/dompdf')]
        private string $fontCacheDir,
    ) {
    }

    public function renderPdf(CommercialDocument $document, CompanyProfile $company): string
    {
        $fonts = $this->carlito();
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont('Helvetica');
        $options->setDefaultPaperSize('A4');
        // Local files dompdf may read: the template and the Carlito font files.
        $options->setChroot($fonts !== null ? [$this->templateDir, self::CARLITO_DIR] : [$this->templateDir]);
        // Font metrics are computed once and cached here, since vendor/ may be read-only.
        (new Filesystem())->mkdir($this->fontCacheDir);
        $options->setFontDir($this->fontCacheDir);
        $options->setFontCache($this->fontCacheDir);
        $options->setTempDir(sys_get_temp_dir());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->render($document, $company, forPdf: true, fonts: $fonts), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();
        $dompdf->addInfo('Title', $document->getDocumentType()->printedTitle().' '.($document->getDocumentNumber() ?? ''));

        return (string) $dompdf->output();
    }

    public function renderHtml(CommercialDocument $document, CompanyProfile $company): string
    {
        return $this->render($document, $company, forPdf: false, fonts: null);
    }

    /** @param array{regular: string, bold: string}|null $fonts */
    private function render(CommercialDocument $document, CompanyProfile $company, bool $forPdf, ?array $fonts): string
    {
        $view = $this->view($document, $company, $forPdf, $fonts);

        ob_start();

        try {
            (static function (string $template, array $view): void {
                require $template;
            })($this->templateDir.'/document.html.php', $view);
        } catch (\Throwable $exception) {
            ob_end_clean();

            throw $exception;
        }

        return (string) ob_get_clean();
    }

    /**
     * @param array{regular: string, bold: string}|null $fonts
     *
     * @return array<string, mixed>
     */
    private function view(CommercialDocument $document, CompanyProfile $company, bool $forPdf, ?array $fonts): array
    {
        $currency = $document->getCurrency();
        $lines = [];

        foreach ($document->getLines() as $line) {
            $lines[] = [
                'description' => $line->getDescription(),
                'quantity' => self::number($line->getQuantity()->amount()),
                'unit_price' => self::money($line->getUnitPrice()->amount(), $currency),
                'total' => self::money($line->getLineSubtotal()->amount(), $currency),
            ];
        }

        $hasTax = !$document->getTaxTotal()->isZero();
        $totals = [['label' => $hasTax ? 'Total HT' : 'Total', 'value' => self::money($document->getSubtotal()->amount(), $currency)]];

        if (!$document->getDiscountTotal()->isZero()) {
            $totals[] = ['label' => 'Remise', 'value' => '- '.self::money($document->getDiscountTotal()->amount(), $currency)];
        }

        if ($hasTax) {
            $totals[] = ['label' => 'TVA', 'value' => self::money($document->getTaxTotal()->amount(), $currency)];
        }

        if (!$document->getStampDuty()->isZero()) {
            $totals[] = ['label' => 'Timbre', 'value' => self::money($document->getStampDuty()->amount(), $currency)];
        }

        $totals[] = ['label' => 'Montant Total', 'value' => self::money($document->getGrandTotal()->amount(), $currency)];
        $date = $document->getIssuedAt() ?? $document->getCreatedAt();

        return [
            'for_pdf' => $forPdf,
            'fonts' => $fonts,
            'line_height' => $fonts !== null ? self::CARLITO_PDF_LINE_HEIGHT : self::LINE_HEIGHT,
            'title' => $document->getDocumentType()->printedTitle(),
            'number' => $document->getDocumentNumber() ?? 'Brouillon',
            'date' => $date->format('d/m/Y'),
            'company' => $company,
            'customer' => [
                'name' => $document->getCustomerLegalName() ?: $document->getCustomerDisplayName(),
                'address' => self::address($document->getBillingAddress()),
                'tax_id' => $document->getCustomerTaxId() ?: $document->getCustomerVatNumber(),
            ],
            'lines' => $lines,
            'blank_rows' => max(0, self::MIN_TABLE_ROWS - count($lines)),
            'totals' => $totals,
            'notes' => $document->getNotes(),
            'stamp' => self::stamp($company->stampImage),
        ];
    }

    /**
     * Scales the stamp image, up or down, to fill the stamp area of the paper template.
     *
     * @return array{src: string, width: string, height: string}|null
     */
    private static function stamp(?string $dataUri): ?array
    {
        $comma = $dataUri !== null ? strpos($dataUri, ',') : false;
        $size = $comma !== false ? @getimagesizefromstring((string) base64_decode(substr((string) $dataUri, $comma + 1), true)) : false;

        if ($dataUri === null || !is_array($size) || $size[0] === 0 || $size[1] === 0) {
            return null;
        }

        $scale = min(self::STAMP_MAX_WIDTH / $size[0], self::STAMP_MAX_HEIGHT / $size[1]);

        return [
            'src' => $dataUri,
            'width' => sprintf('%.1fpt', $size[0] * $scale),
            'height' => sprintf('%.1fpt', $size[1] * $scale),
        ];
    }

    /** @return array{regular: string, bold: string}|null */
    private function carlito(): ?array
    {
        $regular = self::CARLITO_DIR.'/Carlito-Regular.ttf';
        $bold = self::CARLITO_DIR.'/Carlito-Bold.ttf';

        if (!is_readable($regular) || !is_readable($bold)) {
            return null;
        }

        return ['regular' => 'file://'.$regular, 'bold' => 'file://'.$bold];
    }

    /** @param array<string, mixed>|null $address */
    private static function address(?array $address): ?string
    {
        if ($address === null) {
            return null;
        }

        $country = is_string($address['country'] ?? null) ? strtoupper($address['country']) : '';
        $parts = [
            $address['line1'] ?? null,
            $address['line2'] ?? null,
            trim(sprintf('%s %s', $address['city'] ?? '', $address['postal_code'] ?? '')),
            // Local customers do not need the country spelled out.
            $country !== '' && $country !== 'TN' ? $country : null,
        ];
        $parts = array_filter(array_map(static fn (mixed $part): string => trim((string) $part), $parts), static fn (string $part): bool => $part !== '');

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * Whole amounts print without decimals ("55 TND"), like the paper invoices; others keep
     * the currency's precision ("12,500 TND" for dinars, which have 3 decimals).
     */
    private static function money(string $amount, string $currency): string
    {
        $value = (float) $amount;
        $decimals = floor($value) === $value ? 0 : ($currency === 'TND' ? 3 : 2);

        return number_format($value, $decimals, ',', "\u{00A0}")."\u{00A0}".$currency;
    }

    /** "7.0000" -> "7", "2.5000" -> "2,5". */
    private static function number(string $amount): string
    {
        $trimmed = str_contains($amount, '.') ? rtrim(rtrim($amount, '0'), '.') : $amount;

        return str_replace('.', ',', $trimmed);
    }
}
