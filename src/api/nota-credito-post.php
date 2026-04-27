<?php

declare(strict_types=1);
header("Content-Type: application/json");

require 'domain.php';
require __DIR__ . '/r2_client.php';
require __DIR__ . '/../../vendor/autoload.php';

use Greenter\Model\Client\Client;
use Greenter\Model\Company\Address;
use Greenter\Model\Company\Company;
use Greenter\Model\Sale\Note;
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
$cliente = $data['cliente'] ?? [];

$client = (new Client())
    ->setTipoDoc($cliente['tipoDoc'] ?? '1')
    ->setNumDoc($cliente['numDoc'] ?? '00000000')
    ->setRznSocial($cliente['nombre'] ?? 'Cliente Genérico');

/* ============================
   TIPO DOCUMENTO
============================ */
$tipoNombre = 'nota_credito';

/* ============================
   DATOS AFECTADOS
============================ */
$comp = $data['comprobante_modifica'];

$docAfectado = $comp['serie'] . '-' . str_pad($comp['correlativo'], 8, '0', STR_PAD_LEFT);

/* ============================
   NOTA DE CRÉDITO
============================ */
$note = (new Note())
    ->setUblVersion('2.1')
    ->setTipoDoc('07')
    ->setSerie($data['serie'])
    ->setCorrelativo($data['correlativo'])
    ->setFechaEmision(new DateTime())
    ->setTipDocAfectado($comp['tipo'])
    ->setNumDocfectado($docAfectado)
    ->setCodMotivo($data['tipo_motivo'])
    ->setDesMotivo($data['motivo'])
    ->setTipoMoneda($data['moneda'])
    ->setCompany($company)
    ->setClient($client)
    ->setMtoOperGravadas($data['gravadas'])
    ->setMtoIGV($data['igv'])
    ->setTotalImpuestos($data['igv'])
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

$note->setDetails($details)
    ->setLegends([
        (new Legend())
            ->setCode('1000')
            ->setValue($data['leyenda'] ?? 'MONTO EN LETRAS')
    ]);

/* ============================
   SUNAT PRODUCCIÓN
============================ */
$endpoint = SunatEndpoints::FE_PRODUCCION;
$see = $util->getSee($endpoint);

$res = $see->send($note);

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
$pdfContent = $util->getPdf($note, "note");

/* ============================
   TIENDA
============================ */
$tienda = preg_replace('/[^a-zA-Z0-9_-]/', '', $emisor['nombreComercial'] ?? 'tienda');

/* ============================
   NOMBRES
============================ */
$baseName = $note->getSerie() . '-' . $note->getCorrelativo();

$xmlName = "{$baseName}.xml";
$pdfName = "{$baseName}.pdf";
$cdrName = "R-{$baseName}.zip";

/* ============================
   PATH R2
============================ */
$basePath = "{$tienda}/nota_credito";

/* ============================
   R2
============================ */
$r2 = r2Client();

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
   SUBIR
============================ */
$ok1 = subirR2($r2, "{$basePath}/xml/{$xmlName}", $xmlContent, 'application/xml');
$ok2 = subirR2($r2, "{$basePath}/pdf/{$pdfName}", $pdfContent, 'application/pdf');
$ok3 = subirR2($r2, "{$basePath}/cdr/{$cdrName}", $cdrContent, 'application/zip');

if (!$ok1 || !$ok2 || !$ok3) {
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

echo json_encode([
    "success" => true,
    "message" => "Nota de crédito enviada a SUNAT y guardada en R2",
    "nota_id" => $baseName,
    "cdr_codigo" => $cdr->getCode(),
    "cdr_descripcion" => $cdr->getDescription(),
    "xml_url" => "{$urlBase}/xml/{$xmlName}",
    "pdf_url" => "{$urlBase}/pdf/{$pdfName}",
    "cdr_url" => "{$urlBase}/cdr/{$cdrName}"
], JSON_PRETTY_PRINT);
