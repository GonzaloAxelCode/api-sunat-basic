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


    public function buildSee(string $endpoint, string $clavePrivada, string $clavePublica, string $ruc, string $usuarioSol, string $passwordSol): See
    {
        $clavePrivada = $this->extractPem($clavePrivada, 'PRIVATE KEY');
        $clavePublica = $this->extractPem($clavePublica, 'CERTIFICATE');

        $pemCompleto = $clavePrivada . "\n" . $clavePublica;
        $see = new See();
        $see->setService($endpoint);
        $see->setCertificate($pemCompleto);
        $see->setClaveSOL($ruc, $usuarioSol, $passwordSol);
        $see->setCachePath(__DIR__ . '/../cache');

        return $see;
    }

    /**
     * Construye un See a partir del emisor enviado en el POST, validando
     * las credenciales y que el certificado corresponda al RUC del emisor.
     *
     * @param array<string,mixed> $emisor
     * @return See
     * @throws \InvalidArgumentException
     */
    public function buildSeeFromEmisor(string $endpoint, array $emisor): See
    {
        $ruc        = trim((string)($emisor['ruc'] ?? ''));
        $certPriv   = $emisor['certPriv'] ?? null;
        $certPublic = $emisor['certPublic'] ?? null;
        $userSol    = $emisor['userSol'] ?? null;
        $claveSol   = $emisor['claveSol'] ?? null;

        if (preg_match('/^\d{11}$/', $ruc) !== 1) {
            throw new \InvalidArgumentException('El RUC del emisor debe tener exactamente 11 dígitos.');
        }
        if (!is_string($certPriv) || trim($certPriv) === '' || stripos($certPriv, 'PRIVATE KEY') === false) {
            throw new \InvalidArgumentException("Falta 'certPriv' (llave privada PEM) del emisor.");
        }
        if (!is_string($certPublic) || trim($certPublic) === '' || stripos($certPublic, 'CERTIFICATE') === false) {
            throw new \InvalidArgumentException("Falta 'certPublic' (certificado PEM) del emisor.");
        }
        if (!is_string($userSol) || trim($userSol) === '') {
            throw new \InvalidArgumentException("Falta 'userSol' (usuario SOL) del emisor.");
        }
        if (!is_string($claveSol) || trim($claveSol) === '') {
            throw new \InvalidArgumentException("Falta 'claveSol' (clave SOL) del emisor.");
        }

        // Verificar que el RUC del certificado corresponda al RUC enviado en la petición.
        $rucCertificado = $this->getRucFromPem($this->extractPem($certPublic, 'CERTIFICATE'));
        if ($rucCertificado !== null && $rucCertificado !== $ruc) {
            throw new \InvalidArgumentException(
                "El certificado recibido corresponde al RUC {$rucCertificado}, no al RUC enviado ({$ruc})."
            );
        }

        return $this->buildSee(
            $endpoint,
            $certPriv,
            $certPublic,
            $ruc,
            $userSol,
            $claveSol
        );
    }

    /**
     * Extrae únicamente el bloque PEM correspondiente, descartando
     * cualquier texto previo a los marcadores BEGIN/END.
     *
     * Ej.: extrae desde "-----BEGIN PRIVATE KEY-----" hasta la línea
     * siguiente al "-----END PRIVATE KEY-----" que le corresponda.
     */
    private function extractPem(string $content, string $tipo): string
    {
        $contenido = trim($content);
        $markerOut  = '-----END ' . $tipo . '-----';

        $pos = stripos($contenido, '-----BEGIN ' . $tipo . '-----');
        if ($pos === false) {
            return '';
        }

        $bloque = $contenido;
        if ($pos > 0) {
            $bloque = substr($contenido, $pos);
        }

        $fin = stripos($bloque, $markerOut);
        if ($fin !== false) {
            $bloque = substr($bloque, 0, $fin + strlen($markerOut));
        }

        return trim($bloque);
    }

    /**
     * Extrae el RUC (11 dígitos) contenido en un certificado PEM.
     */
    private function getRucFromPem(string $pem): ?string
    {
        $cert = openssl_x509_parse($pem);
        if ($cert === false) {
            return null;
        }

        $campos = [];
        foreach (['subject', 'issuer'] as $seccion) {
            $campos = array_merge($campos, $this->flatten((array)($cert[$seccion] ?? [])));
        }

        foreach ($campos as $valor) {
            if (is_string($valor) && preg_match('/\b(\d{11})\b/', $valor, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $arr
     * @return array<string>
     */
    private function flatten(array $arr): array
    {
        $out = [];
        foreach ($arr as $value) {
            if (is_array($value)) {
                $out = array_merge($out, $this->flatten($value));
            } elseif (is_string($value)) {
                $out[] = $value;
            }
        }

        return $out;
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

    public function getPdf(DocumentInterface $document, string $ticketType, See $see, ?string $boletaTicketStyle = null, ?string $facturaPdfStyle = null, ?string $boletaPdfStyle = null, ?string $logoUrl = null): ?string
    {
        $tipoDoc = method_exists($document, 'getTipoDoc') ? $document->getTipoDoc() : 'N/A';
        error_log("[PDF] tipoDoc={$tipoDoc} format={$ticketType} boletaTicketStyle=" . ($boletaTicketStyle ?? 'null') . " boletaPdfStyle=" . ($boletaPdfStyle ?? 'null') . " facturaStyle=" . ($facturaPdfStyle ?? 'null'));

        $html = new HtmlReport(__DIR__ . '/templates', [
            'cache' => __DIR__ . '/../cache',
            'strict_variables' => false,
        ]);
        $resolver = new MyTemplateResolver($ticketType, $boletaTicketStyle, $facturaPdfStyle, $boletaPdfStyle);
        $template = $resolver->getTemplate($document);
        error_log("[PDF] template_selected=" . $template);

        $html->setTemplate($template);

        $render = new \PdfReportBrowsershot($html, $ticketType, $this->getChromePath());
        $hash = $this->getHash($document, $see);
        $params = self::getParametersPdf($logoUrl);
        $params['system']['hash'] = $hash;
        $params['user']['footer'] = '<div style="font-size:12px;text-align:left;">

Emitido conforme a lo dispuesto en el Reglamento de Comprobantes de Pago - SUNAT.<br>
Consulte la validez de este comprobante en: <a href="https://e-consulta.sunat.gob.pe" target="_blank">https://e-consulta.sunat.gob.pe</a>
</div>';

        try {
            $pdf = $render->render($document, $params);
        } catch (\Throwable $e) {
            error_log("[PDF] Browsershot error: " . $e->getMessage());
            echo 'Error: ' . $e->getMessage();
            exit();
        }

        if ($pdf === null) {
            echo 'Error: No se pudo generar el PDF';
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

    public static function getChromePath(): ?string
    {
        if (self::isWindows()) {
            $paths = [
                'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
                'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
            ];

            foreach ($paths as $path) {
                if (file_exists($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    public static function isWindows(): bool
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    private function getHash(DocumentInterface $document, See $see): ?string
    {
        $xml = $see->getXmlSigned($document);

        return (new XmlUtils())->getHashSign($xml);
    }

    /**
     * @return array<string, array<string, array<int, array<string, string>>|bool|string>>
     */
    private static function getParametersPdf(?string $logoUrl = null): array
    {
        $logoPath = __DIR__ . '/../resources/logo.png';
        $logo = is_file($logoPath) ? file_get_contents($logoPath) : '';

        return [
            'system' => [
                'logo' => $logo,
                'logo_url' => $logoUrl,
                'hash' => ''
            ],
            'user' => [
                'resolucion' => '212321',
                'header' => 'Telf: <b>(+51) 934 545 535</b>',
                'extras' => [
                    ['name' => 'FORMA DE PAGO', 'value' => 'Contado'],

                ],
            ]
        ];
    }
}


class MyTemplateResolver implements TemplateResolverInterface
{
    private string $format;
    private ?string $boletaTicketStyle;
    private ?string $facturaPdfStyle;
    private ?string $boletaPdfStyle;

    public function __construct(string $format, ?string $boletaTicketStyle = null, ?string $facturaPdfStyle = null, ?string $boletaPdfStyle = null)
    {
        $this->format = $format;
        $this->boletaTicketStyle = $boletaTicketStyle;
        $this->facturaPdfStyle = $facturaPdfStyle;
        $this->boletaPdfStyle = $boletaPdfStyle;
    }

    public function getTemplate(DocumentInterface $document): string
    {
        $tipoDoc = method_exists($document, 'getTipoDoc') ? $document->getTipoDoc() : 'N/A';
        error_log("[RESOLVER] tipoDoc={$tipoDoc} format={$this->format} boletaTicketStyle=" . ($this->boletaTicketStyle ?? 'null') . " boletaPdfStyle=" . ($this->boletaPdfStyle ?? 'null') . " facturaStyle=" . ($this->facturaPdfStyle ?? 'null'));

        // 03 = Boleta de Venta
        if ($tipoDoc === '03') {
            if ($this->format === 'ticket' && $this->boletaTicketStyle !== null) {
                $style = preg_replace('/[^a-zA-Z0-9_-]/', '', $this->boletaTicketStyle);
                error_log("[RESOLVER] boleta ticket style_raw=" . ($this->boletaTicketStyle ?? 'null') . " style_sanitized={$style}");
                if ($style !== '') {
                    $candidate = 'boleta_ticket_80mm/' . $style . '.html.twig';
                    $fullPath = __DIR__ . '/templates/' . $candidate;
                    $exists = file_exists($fullPath);
                    error_log("[RESOLVER] candidate={$candidate} fullPath={$fullPath} exists=" . ($exists ? 'yes' : 'no'));
                    if ($exists) {
                        return $candidate;
                    }
                }
            }
            if ($this->format === 'a4' && $this->boletaPdfStyle !== null) {
                $style = preg_replace('/[^a-zA-Z0-9_-]/', '', $this->boletaPdfStyle);
                error_log("[RESOLVER] boleta pdf style_raw=" . ($this->boletaPdfStyle ?? 'null') . " style_sanitized={$style}");
                if ($style !== '') {
                    $candidate = 'boleta_pdf/' . $style . '.html.twig';
                    $fullPath = __DIR__ . '/templates/' . $candidate;
                    $exists = file_exists($fullPath);
                    error_log("[RESOLVER] candidate={$candidate} fullPath={$fullPath} exists=" . ($exists ? 'yes' : 'no'));
                    if ($exists) {
                        return $candidate;
                    }
                }
            }
            error_log("[RESOLVER] fallback boleta format={$this->format}");
            return $this->format === 'ticket' ? 'default/ticket.html.twig' : 'default/ticket_pdf.html.twig';
        }

        // 01 = Factura
        if ($tipoDoc === '01') {
            if ($this->format === 'a4' && $this->facturaPdfStyle !== null) {
                $style = preg_replace('/[^a-zA-Z0-9_-]/', '', $this->facturaPdfStyle);
                error_log("[RESOLVER] factura pdf style_raw=" . ($this->facturaPdfStyle ?? 'null') . " style_sanitized={$style}");
                if ($style !== '') {
                    $candidate = 'factura_pdf/' . $style . '.html.twig';
                    $fullPath = __DIR__ . '/templates/' . $candidate;
                    $exists = file_exists($fullPath);
                    error_log("[RESOLVER] candidate={$candidate} fullPath={$fullPath} exists=" . ($exists ? 'yes' : 'no'));
                    if ($exists) {
                        return $candidate;
                    }
                }
            }
            error_log("[RESOLVER] fallback factura format={$this->format}");
            return 'default/factura_pdf.html.twig';
        }

        // 07 = Nota de Crédito
        if ($tipoDoc === '07') {
            return $this->format === 'ticket' ? 'default/note_credit_ticket.html.twig' : 'default/note_credit_pdf.html.twig';
        }

        // Otros documentos
        return 'default/voided.html.twig';
    }
}
