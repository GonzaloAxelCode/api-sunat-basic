<?php

declare(strict_types=1);

use Greenter\Model\DocumentInterface;
use Greenter\Report\ReportInterface;
use Spatie\Browsershot\Browsershot;

class PdfReportBrowsershot implements ReportInterface
{
    private ReportInterface $htmlReport;
    private ?string $html = null;
    private string $format;
    private ?string $chromePath = null;

    public function __construct(ReportInterface $htmlReport, string $format = 'a4', ?string $chromePath = null)
    {
        $this->htmlReport = $htmlReport;
        $this->format = $format;
        $this->chromePath = $chromePath;
    }

    public function getHtml(): ?string
    {
        return $this->html;
    }

    public function render(DocumentInterface $document, array $parameters = []): ?string
    {
        $this->html = $this->htmlReport->render($document, $parameters);

        if ($this->html === null) {
            return null;
        }

        $browsershot = Browsershot::html($this->html)
            ->noSandbox()
            ->showBackground();

        if ($this->chromePath) {
            $browsershot->setChromePath($this->chromePath);
        }

        if ($this->format === 'ticket') {
            $browsershot->paperSize(80, 230, 'mm')
                ->margins(0, 0, 0, 0);
        } else {
            $browsershot->format('A4')
                ->margins(0, 0, 0, 0);
        }

        return $browsershot->pdf();
    }
}
