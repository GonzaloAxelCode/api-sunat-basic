<?php

declare(strict_types=1);

header("Content-Type: application/json");

require 'domain.php';
require __DIR__ . '/r2_client.php';
require __DIR__ . '/../../vendor/autoload.php';

use Greenter\Model\Client\Client;
use Greenter\Model\Company\Address;
use Greenter\Model\Company\Company;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Model\Sale\Legend;
use Greenter\Ws\Services\SunatEndpoints;
use Aws\Exception\AwsException;

$util = Util::getInstance();

/* ============================
   INPUT
============================ */
$json = file_get_contents("php://input");
$data = json_decode($json, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "JSON inválido"]);
    exit();
}

/* ============================
   EMISOR (DINÁMICO)
============================ */
$emisor = $data['emisor'] ?? null;

if (!$emisor) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Falta emisor"]);
    exit();
}

$company = (new Company())
    ->setRuc($emisor['ruc'])
    ->setRazonSocial($emisor['razonSocial'])
    ->setNombreComercial($emisor['nombreComercial'] ?? '')
    ->setAddress(
        (new Address())
            ->setUbigueo($emisor['ubigeo'])
            ->setDepartamento($emisor['departamento'])
            ->setProvincia($emisor['provincia'])
            ->setDistrito($emisor['distrito'])
            ->setDireccion($emisor['direccion'])
    );

/* ============================
   CLIENTE
============================ */
$clientData = $data['cliente'] ?? [];

$client = (new Client())
    ->setTipoDoc($clientData['tipoDoc'] ?? '1')
    ->setNumDoc($clientData['numDoc'] ?? '00000000')
    ->setRznSocial($clientData['nombre'] ?? 'Cliente Genérico');

/* ============================
   TIPO DOCUMENTO
============================ */
$tipoDoc = $data['tipoDoc'] ?? '03';

$tipoMap = [
    '01' => 'factura',
    '03' => 'boleta',
    '07' => 'nota_credito'
];

$tipoNombre = $tipoMap[$tipoDoc] ?? 'otros';

/* ============================
   COMPROBANTE
============================ */
$invoice = (new Invoice())
    ->setUblVersion('2.1')
    ->setTipoOperacion('0101')
    ->setTipoDoc($tipoDoc)
    ->setSerie($data['serie'])
    ->setCorrelativo($data['correlativo'])
    ->setFechaEmision(new DateTime())
    ->setTipoMoneda($data['moneda'] ?? 'PEN')
    ->setCompany($company)
    ->setClient($client)
    ->setMtoOperGravadas($data['gravadas'])
    ->setMtoIGV($data['igv'])
    ->setTotalImpuestos($data['igv'])
    ->setValorVenta($data['valorVenta'])
    ->setSubTotal($data['subTotal'])
    ->setMtoImpVenta($data['total']);

/* ============================
   ITEMS
============================ */
$details = [];

foreach ($data['items'] as $item) {
    $details[] = (new SaleDetail())
        ->setCodProducto($item['codigo'])
        ->setUnidad($item['unidad'])
        ->setDescripcion($item['descripcion'])
        ->setCantidad($item['cantidad'])
        ->setMtoValorUnitario($item['valorUnitario'])
        ->setMtoValorVenta($item['valorVenta'])
        ->setMtoBaseIgv($item['baseIgv'])
        ->setPorcentajeIgv($item['porcentajeIgv'])
        ->setIgv($item['igv'])
        ->setTipAfeIgv($item['tipoAfectacionIgv'])
        ->setTotalImpuestos($item['totalImpuestos'])
        ->setMtoPrecioUnitario($item['precioUnitario']);
}

$invoice->setDetails($details)
    ->setLegends([
        (new Legend())
            ->setCode('1000')
            ->setValue($data['leyenda'] ?? 'MONTO EN LETRAS')
    ]);

/* ============================
   SUNAT PRODUCCIÓN
============================ */
$endpoint = SunatEndpoints::FE_PROD;
$see = $util->getSee($endpoint);

$res = $see->send($invoice);

if (!$res->isSuccess()) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Error SUNAT",
        "error" => $util->getErrorResponse($res->getError())
    ]);
    exit();
}

$cdr = $res->getCdrResponse();

/* ============================
   ARCHIVOS EN MEMORIA
============================ */
$xmlContent = $see->getFactory()->getLastXml();
$cdrContent = $res->getCdrZip();
$pdfContent = $util->getPdf($invoice, "a4");
$ticketContent = $util->getPdf($invoice, "ticket");

/* ============================
   TIENDA (SANITIZADA)
============================ */
$tienda = $emisor['nombreComercial'] ?? 'tienda';
$tienda = preg_replace('/[^a-zA-Z0-9_-]/', '', $tienda);

/* ============================
   NOMBRES
============================ */
$baseName = $invoice->getSerie() . '-' . $invoice->getCorrelativo();

$xmlName = "{$baseName}.xml";
$pdfName = "{$baseName}.pdf";
$cdrName = "R-{$baseName}.zip";
$ticketName = "{$baseName}-ticket.pdf";

/* ============================
   PATH R2
============================ */
$basePath = "{$tienda}/{$tipoNombre}";

/* ============================
   R2 CLIENT
============================ */
$r2 = r2Client();

/* ============================
   SUBIDA SEGURA
============================ */
function subirR2($r2, $key, $body, $type)
{
    try {
        $r2->putObject([
            'Bucket' => R2_BUCKET,
            'Key' => $key,
            'Body' => $body,
            'ContentType' => $type,
        ]);
        return true;
    } catch (AwsException $e) {
        error_log("R2 ERROR: " . $e->getMessage());
        return false;
    }
}

/* ============================
   SUBIR ARCHIVOS
============================ */
$ok1 = subirR2($r2, "{$basePath}/xml/{$xmlName}", $xmlContent, 'application/xml');
$ok2 = subirR2($r2, "{$basePath}/pdf/{$pdfName}", $pdfContent, 'application/pdf');
$ok3 = subirR2($r2, "{$basePath}/cdr/{$cdrName}", $cdrContent, 'application/zip');
$ok4 = subirR2($r2, "{$basePath}/ticket/{$ticketName}", $ticketContent, 'application/pdf');

if (!$ok1 || !$ok2 || !$ok3 || !$ok4) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Error subiendo a R2"
    ]);
    exit();
}

/* ============================
   URLS
============================ */
$urlBase = R2_BASE_URL . "/{$basePath}";

$response = [
    "success" => true,
    "message" => "Comprobante enviado a SUNAT y almacenado en R2",
    "comprobante" => $baseName,
    "cdr_codigo" => $cdr->getCode(),
    "cdr_descripcion" => $cdr->getDescription(),
    "xml_url" => "{$urlBase}/xml/{$xmlName}",
    "pdf_url" => "{$urlBase}/pdf/{$pdfName}",
    "cdr_url" => "{$urlBase}/cdr/{$cdrName}",
    "ticket_url" => "{$urlBase}/ticket/{$ticketName}"
];

echo json_encode($response, JSON_PRETTY_PRINT);
