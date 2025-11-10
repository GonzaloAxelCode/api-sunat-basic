<?php


declare(strict_types=1);


use Greenter\Model\DocumentInterface;
use Greenter\Report\Resolver\TemplateResolverInterface;


use Greenter\Data\DocumentGeneratorInterface;
use Greenter\Data\GeneratorFactory;
use Greenter\Data\SharedStore;

use Greenter\Model\Response\CdrResponse;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Report\HtmlReport;
use Greenter\Report\PdfReport;
use Greenter\Report\Resolver\DefaultTemplateResolver;
use Greenter\Report\XmlUtils;
use Greenter\See;

final class Util
{
    /**
     * @var Util
     */
    private static $current;
    /**
     * @var SharedStore
     */
    public $shared;

    private function __construct()
    {
        $this->shared = new SharedStore();
    }

    public static function getInstance(): Util
    {
        if (!self::$current instanceof self) {
            self::$current = new self();
        }

        return self::$current;
    }

    public function getSee(?string $endpoint)
    {
        $see = new See();
        $see->setService($endpoint);
        $certificate = file_get_contents(__DIR__ . '/../resources/cert.pem');
        if ($certificate === false) {
            throw new Exception('No se pudo cargar el certificado');
        }
        $see->setCertificate($certificate);
        $see->setClaveSOL('10720180885', 'AXELSE12', 'Axel9r2t5');
        $see->setCachePath(__DIR__ . '/../cache');

        return $see;
    }
    /**
    public function getSeeApi()
    {
        $api = new \Greenter\Api([
            'auth' => 'https://gre-test.nubefact.com/v1',
            'cpe' => 'https://gre-test.nubefact.com/v1',
        ]);
        $certificate = file_get_contents(__DIR__ . '/../resources/cert.pem');
        if ($certificate === false) {
            throw new Exception('No se pudo cargar el certificado');
        }
        return $api->setBuilderOptions([
            'strict_variables' => true,
            'optimizations' => 0,
            'debug' => true,
            'cache' => false,
        ])
            ->setApiCredentials('test-85e5b0ae-255c-4891-a595-0b98c65c9854', 'test-Hty/M6QshYvPgItX2P0+Kw==')

            ->setClaveSOL('10720180885', 'TORYNEPI', 'ychbyebra');
    }
     **/
    public function getGRECompany(): \Greenter\Model\Company\Company
    {
        return (new \Greenter\Model\Company\Company())
            ->setRuc('10720180885')
            ->setRazonSocial('SILVA ESPINOZA WALTER FREDDY')
            ->setNombreComercial('MOVIL AXEL')
            ->setAddress((new \Greenter\Model\Company\Address())
                ->setUbigueo('15121 ') // Código de distrito (ejemplo Lima)
                ->setDepartamento('LIMA')
                ->setProvincia('LIMA')
                ->setDistrito('LIMA')
                ->setUrbanizacion('-')
                ->setDireccion('Av san Lorenzo 202 Las Vegas  Puente Piedra'));
    }


    public function showResponse(DocumentInterface $document, CdrResponse $cdr): void
    {
        $filename = $document->getName();

        require __DIR__ . '/../views/response.php';
    }

    public function getErrorResponse(\Greenter\Model\Response\Error $error): string
    {
        $result = <<<HTML
        <h2 class="text-danger">Error:</h2><br>
        <b>Código:</b>{$error->getCode()}<br>
        <b>Descripción:</b>{$error->getMessage()}<br>
HTML;

        return $result;
    }

    public function writeXml(DocumentInterface $document, ?string $xml): void
    {
        $this->writeFile($document->getName() . '.xml', $xml);
    }

    public function writeCdr(DocumentInterface $document, ?string $zip): void
    {
        $this->writeFile('R-' . $document->getName() . '.zip', $zip);
    }

    public function writeFile(?string $filename, ?string $content): void
    {
        if (getenv('GREENTER_NO_FILES')) {
            return;
        }

        $fileDir = __DIR__ . '/../files';

        if (!file_exists($fileDir)) {
            mkdir($fileDir, 0777, true);
        }

        file_put_contents($fileDir . DIRECTORY_SEPARATOR . $filename, $content);
    }

    public function getPdf(DocumentInterface $document, string $ticketType): ?string
    {
        $html = new HtmlReport(__DIR__ . '/templates', [
            'cache' => __DIR__ . '/../cache',
            'strict_variables' => false,
        ]);
        $resolver = new MyTemplateResolver($ticketType);
        $template = $resolver->getTemplate($document);
        $html->setTemplate($template);

        $render = new PdfReport($html);
        $render->setOptions([
            'no-outline',
            'print-media-type',
            'disable-smart-shrinking', // opcional, evita que wkhtml reduzca el contenido
            'viewport-size' => '800x600',
            'page-width' => '80mm',   // ancho del ticket
            'page-height' => '200mm', // altura máxima, puede ajustarse dinámicamente
            'footer-html' => __DIR__ . '/../resources/footer.html',
        ]);
        $binPath = self::getPathBin();
        if (file_exists($binPath)) {
            $render->setBinPath($binPath);
        }
        $hash = $this->getHash($document);
        $params = self::getParametersPdf();
        $params['system']['hash'] = $hash;
        $params['user']['footer'] = '<div style="font-size:12px;text-align:left;">

Emitido conforme a lo dispuesto en el Reglamento de Comprobantes de Pago - SUNAT.<br>
Consulte la validez de este comprobante en: <a href="https://e-consulta.sunat.gob.pe" target="_blank">https://e-consulta.sunat.gob.pe</a>
</div>';


        $pdf = $render->render($document, $params);
        if ($pdf === null) {
            $error = $render->getExporter()->getError();
            echo 'Error: ' . $error;
            exit();
        }
        $this->writeFile($document->getName() . '.html', $render->getHtml());
        return $pdf;
    }

    public function getGenerator(string $type): ?DocumentGeneratorInterface
    {
        $factory = new GeneratorFactory();
        $factory->shared = $this->shared;

        return $factory->create($type);
    }

    /**
     * @param SaleDetail $item
     * @param int $count
     * @return array<SaleDetail>
     */
    public function generator(SaleDetail $item, int $count): array
    {
        $items = [];

        for ($i = 0; $i < $count; $i++) {
            $items[] = $item;
        }

        return $items;
    }

    public function showPdf(?string $content, ?string $filename): void
    {
        $this->writeFile($filename, $content);
        header('Content-type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Transfer-Encoding: binary');
        header('Content-Length: ' . strlen($content));

        echo $content;
    }

    public static function getPathBin(): string
    {
        $path = __DIR__ . '/../vendor/bin/wkhtmltopdf';
        if (self::isWindows()) {
            $path .= '.exe';
        }

        return $path;
    }

    public static function isWindows(): bool
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    private function getHash(DocumentInterface $document): ?string
    {
        $see = $this->getSee('');
        $xml = $see->getXmlSigned($document);

        return (new XmlUtils())->getHashSign($xml);
    }

    /**
     * @return array<string, array<string, array<int, array<string, string>>|bool|string>>
     */
    private static function getParametersPdf(): array
    {
        $logo = file_get_contents(__DIR__ . '/../resources/logo.png');

        return [
            'system' => [
                'logo' => $logo,
                'hash' => ''
            ],
            'user' => [
                'resolucion' => '212321',
                'header' => 'Telf: <b>(+51) 934 545 535</b>',
                'extras' => [
                    ['name' => 'FORMA DE PAGO', 'value' => 'Contado'],
                    ['name' => 'VENDEDOR', 'value' => 'SILVAESPINOZAWALTER FREDDY'],
                ],
            ]
        ];
    }
}


class MyTemplateResolver implements TemplateResolverInterface
{
    private string $format;

    public function __construct(string $format)
    {
        $this->format = $format;
    }

    public function getTemplate(DocumentInterface $document): string
    {
        $tipoDoc = method_exists($document, 'getTipoDoc') ? $document->getTipoDoc() : 'N/A';
        error_log("Tipo de documento: " . $tipoDoc);

        // 03 = Boleta de Venta
        if ($tipoDoc === '03') {
            return $this->format === 'ticket' ? 'ticket.html.twig' : 'ticket_pdf.html.twig';
        }

        // 01 = Factura
        if ($tipoDoc === '01') {
            return 'factura_pdf.html.twig';
        }

        // 07 = Nota de Crédito
        if ($tipoDoc === '07') {
            return $this->format === 'ticket' ? 'note_credit_ticket.html.twig' : 'note_credit_pdf.html.twig';
        }



        // Otros documentos
        return 'voided.html.twig';
    }
}
