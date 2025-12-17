<?php

declare(strict_types=1);
header("Content-Type: application/json");
require 'domain.php';
require __DIR__ . '/r2_client.php'; // ✅ Nuevo

use Greenter\Model\Client\Client;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Model\Sale\Legend;
use Greenter\Ws\Services\SunatEndpoints;
use Aws\Exception\AwsException;

require __DIR__ . '/../../vendor/autoload.php';

$util = Util::getInstance();

$json = file_get_contents("php://input");
$data = json_decode($json, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "JSON inválido"]);
    exit();
}

$clientData = $data['cliente'] ?? [];
$client = (new Client())
    ->setTipoDoc($clientData['tipoDoc'] ?? '1')
    ->setNumDoc($clientData['numDoc'] ?? '00000000')
    ->setRznSocial($clientData['nombre'] ?? 'Cliente Genérico');

$invoice = (new Invoice())
    ->setUblVersion('2.1')
    ->setTipoOperacion('0101')
    ->setTipoDoc('03')
    ->setSerie($data['serie'] ?? 'B001')
    ->setCorrelativo($data['correlativo'] ?? '123')
    ->setFechaEmision(new DateTime())
    ->setTipoMoneda($data['moneda'] ?? 'PEN')
    ->setCompany($util->getGRECompany())
    ->setClient($client)
    ->setMtoOperGravadas($data['gravadas'] ?? 200)
    ->setMtoIGV($data['igv'] ?? 36)
    ->setTotalImpuestos($data['igv'] ?? 36)
    ->setValorVenta($data['valorVenta'] ?? 300)
    ->setSubTotal($data['subTotal'] ?? 336)
    ->setMtoImpVenta($data['total'] ?? 336);

$items = [];
foreach ($data['items'] as $item) {
    $detalle = (new SaleDetail())
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

    $items[] = $detalle;
}

$invoice->setDetails($items)
    ->setLegends([(new Legend())->setCode('1000')->setValue($data['leyenda'] ?? 'Monto en letras')]);

$see = $util->getSee(SunatEndpoints::FE_PRODUCCION);
$res = $see->send($invoice);

if (!$res->isSuccess()) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Error al enviar la boleta",
        "error" => $util->getErrorResponse($res->getError())
    ]);
    exit();
}

$cdr = $res->getCdrResponse();

// ✅ Crear archivos en memoria
$xmlContent = $see->getFactory()->getLastXml();
$cdrContent = $res->getCdrZip();
$pdfContent = $util->getPdf($invoice, "a4");
$ticketContent = $util->getPdf($invoice, "ticket");

// ✅ Subir directamente a R2
$r2 = r2Client();

function subirR2($r2, $bucket, $key, $body, $contentType = 'application/octet-stream')
{
    try {
        $r2->putObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'Body' => $body,
            'ACL' => 'private', // o 'public-read' si deseas acceso directo
            'ContentType' => $contentType,
        ]);
        return true;
    } catch (AwsException $e) {
        error_log('Error R2: ' . $e->getMessage());
        return false;
    }
}
// ✅ Nombres y rutas base
$xmlName = "{$invoice->getSerie()}-{$invoice->getCorrelativo()}.xml";
$pdfName = "{$invoice->getSerie()}-{$invoice->getCorrelativo()}.pdf";
$cdrName = "R-{$invoice->getSerie()}-{$invoice->getCorrelativo()}.zip";
$ticketName = "{$invoice->getSerie()}-{$invoice->getCorrelativo()}-ticket.pdf";

// ✅ Detectar si estamos en BETA y agregar sufijo _beta
$endpoint = SunatEndpoints::FE_PRODUCCION; // o FE_PROD según tu entorno
$see = $util->getSee($endpoint);

$isBeta = $endpoint === SunatEndpoints::FE_BETA;

if ($isBeta) {
    $xmlName = str_replace('.xml', '_beta.xml', $xmlName);
    $pdfName = str_replace('.pdf', '_beta.pdf', $pdfName);
    $cdrName = str_replace('.zip', '_beta.zip', $cdrName);
    $ticketName = str_replace('.pdf', '_beta.pdf', $ticketName);
}

// ✅ Subir a R2
subirR2($r2, R2_BUCKET, "xml/{$xmlName}", $xmlContent, 'application/xml');
subirR2($r2, R2_BUCKET, "pdf/{$pdfName}", $pdfContent, 'application/pdf');
subirR2($r2, R2_BUCKET, "cdr/{$cdrName}", $cdrContent, 'application/zip');
subirR2($r2, R2_BUCKET, "ticket/{$ticketName}", $ticketContent, 'application/pdf');

// ✅ Construir URLs públicas
$xmlUrl = R2_BASE_URL . "/xml/{$xmlName}";
$pdfUrl = R2_BASE_URL . "/pdf/{$pdfName}";
$cdrUrl = R2_BASE_URL . "/cdr/{$cdrName}";
$ticketUrl = R2_BASE_URL . "/ticket/{$ticketName}";


http_response_code(200);
echo json_encode([
    "success" => true,
    "message" => "Boleta procesada con éxito y subida a R2",
    "boleta_id" => $invoice->getSerie() . '-' . $invoice->getCorrelativo(),
    "cdr_codigo" => $cdr->getCode(),
    "cdr_descripcion" => $cdr->getDescription(),
    "notas" => $cdr->getNotes(),
    "xml_url" => $xmlUrl,
    "pdf_url" => $pdfUrl,
    "cdr_url" => $cdrUrl,
    "ticket_url" => $ticketUrl
], JSON_PRETTY_PRINT);
