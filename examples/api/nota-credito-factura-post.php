<?php

declare(strict_types=1);

header("Content-Type: application/json");

use Greenter\Model\Response\BillResult;
use Greenter\Model\Sale\Document;
use Greenter\Model\Sale\Note;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Model\Sale\Legend;
use Greenter\Ws\Services\SunatEndpoints;

require __DIR__ . '/../../vendor/autoload.php';

$util = Util::getInstance();

// Leer el JSON de la solicitud POST
$json = file_get_contents("php://input");
$data = json_decode($json, true);

// Validar JSON
if (!$data) {
    echo json_encode(["success" => false, "message" => "JSON inválido"]);
    exit();
}

// Datos del cliente
$clientData = $data['cliente'] ?? [];
$client = $util->shared->getClient();
$client->setTipoDoc($clientData['tipoDoc'] ?? '6')
    ->setNumDoc($clientData['numDoc'] ?? '20123456789')
    ->setRznSocial($clientData['nombre'] ?? 'Cliente Genérico');

// Crear Nota de Crédito
$note = new Note();
$note
    ->setUblVersion('2.1')
    ->setTipoDoc('07') // Nota de Crédito
    ->setSerie($data['serie'] ?? 'FF01')
    ->setCorrelativo($data['correlativo'] ?? '123')
    ->setFechaEmision(new DateTime())
    ->setTipDocAfectado($data['tipoDocAfectado'] ?? '01')
    ->setNumDocfectado($data['numDocAfectado'] ?? 'F001-111')
    ->setCodMotivo($data['codMotivo'] ?? '07')
    ->setDesMotivo($data['desMotivo'] ?? 'DEVOLUCION POR ITEM')
    ->setTipoMoneda($data['moneda'] ?? 'PEN')
    ->setCompany($util->shared->getCompany())
    ->setClient($client)
    ->setMtoOperGravadas($data['gravadas'] ?? 200)
    ->setMtoIGV($data['igv'] ?? 36)
    ->setTotalImpuestos($data['igv'] ?? 36)
    ->setMtoImpVenta($data['total'] ?? 236);

// Agregar los ítems
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
$note->setDetails($items)
    ->setLegends([
        (new Legend())->setCode('1000')->setValue($data['leyenda'] ?? 'Monto en letras')
    ]);

// Enviar a SUNAT
$see = $util->getSee(SunatEndpoints::FE_BETA);
$res = $see->send($note);

// Definir rutas de almacenamiento
$xmlFilename = "{$note->getSerie()}-{$note->getCorrelativo()}.xml";
$cdrFilename = "R-{$note->getSerie()}-{$note->getCorrelativo()}.zip";
$pdfFilename = "{$note->getSerie()}-{$note->getCorrelativo()}.pdf";

$xmlPath = __DIR__ . "/../../public/notas/xml/" . $xmlFilename;
$cdrPath = __DIR__ . "/../../public/notas/cdr/" . $cdrFilename;
$pdfPath = __DIR__ . "/../../public/notas/pdf/" . $pdfFilename;

// Crear directorios si no existen
if (!is_dir(dirname($xmlPath))) {
    mkdir(dirname($xmlPath), 0777, true);
}
if (!is_dir(dirname($cdrPath))) {
    mkdir(dirname($cdrPath), 0777, true);
}
if (!is_dir(dirname($pdfPath))) {
    mkdir(dirname($pdfPath), 0777, true);
}

// Guardar XML
file_put_contents($xmlPath, $see->getFactory()->getLastXml());

// Si la nota no fue aceptada, devolver error
if (!$res->isSuccess()) {
    echo json_encode([
        "success" => false,
        "message" => "Error al enviar la Nota de Crédito",
        "error" => $util->getErrorResponse($res->getError())
    ]);
    exit();
}

/** @var $res BillResult */
$cdr = $res->getCdrResponse();
file_put_contents($cdrPath, $res->getCdrZip()); // Guardar el CDR.zip

// Generar el PDF de la Nota de Crédito
try {
    $pdf = $util->getPdf($note);
    file_put_contents($pdfPath, $pdf);
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Error al generar el PDF",
        "error" => $e->getMessage()
    ]);
    exit();
}

// Construir las URLs accesibles
$baseUrl = "http://localhost:8080/public/notas/";
$xmlUrl = $baseUrl . "xml/" . $xmlFilename;
$cdrUrl = $baseUrl . "cdr/" . $cdrFilename;
$pdfUrl = $baseUrl . "pdf/" . $pdfFilename;

// Respuesta JSON con las URLs de los archivos generados
$response = [
    "success" => true,
    "message" => "Nota de Crédito procesada con éxito",
    "nota_id" => $note->getSerie() . '-' . $note->getCorrelativo(),
    "cdr_codigo" => $cdr->getCode(),
    "cdr_descripcion" => $cdr->getDescription(),
    "notas" => $cdr->getNotes(),
    "xml_url" => $xmlUrl,
    "cdr_url" => $cdrUrl,
    "pdf_url" => $pdfUrl
];

echo json_encode($response, JSON_PRETTY_PRINT);
