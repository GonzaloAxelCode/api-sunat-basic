<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Greenter\Model\Response\BillResult;
use Greenter\Model\Sale\Note;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Model\Sale\Legend;
use Greenter\Ws\Services\SunatEndpoints;

header('Content-Type: application/json');

$util = Util::getInstance();

// Leer JSON enviado desde Postman
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data) {
    echo json_encode(["success" => false, "message" => "JSON inválido"]);
    exit();
}

// Crear Nota de Débito para Factura
$note = new Note();
$note->setUblVersion('2.1')
    ->setTipoDoc('08') // Nota de Débito
    ->setSerie($data['serie']) // Serie de la ND
    ->setCorrelativo($data['correlativo']) // Correlativo de la ND
    ->setFechaEmision(new DateTime())
    ->setTipDocAfectado('01') // Tipo Doc: Factura
    ->setNumDocfectado($data['numDocAfectado']) // Serie-Correlativo de la factura afectada
    ->setCodMotivo($data['codMotivo']) // Código de motivo
    ->setDesMotivo($data['desMotivo'])
    ->setTipoMoneda($data['moneda'])
    ->setCompany($util->shared->getCompany())
    ->setClient($util->shared->getClient())
    ->setMtoOperGravadas($data['gravadas'])
    ->setMtoIGV($data['igv'])
    ->setTotalImpuestos($data['igv'])
    ->setMtoImpVenta($data['total']);

// Agregar detalles de los productos
$details = [];
foreach ($data['items'] as $item) {
    $detail = new SaleDetail();
    $detail->setCodProducto($item['codigo'])
        ->setUnidad($item['unidad'])
        ->setCantidad($item['cantidad'])
        ->setDescripcion($item['descripcion'])
        ->setMtoBaseIgv($item['baseIgv'])
        ->setPorcentajeIgv($item['porcentajeIgv'])
        ->setIgv($item['igv'])
        ->setTipAfeIgv($item['tipoAfectacionIgv'])
        ->setTotalImpuestos($item['totalImpuestos'])
        ->setMtoValorVenta($item['valorVenta'])
        ->setMtoValorUnitario($item['valorUnitario'])
        ->setMtoPrecioUnitario($item['precioUnitario']);
    $details[] = $detail;
}

$note->setDetails($details);

// Agregar leyenda
$legend = new Legend();
$legend->setCode('1000')->setValue($data['leyenda']);
$note->setLegends([$legend]);

// Enviar a SUNAT
$see = $util->getSee(SunatEndpoints::FE_BETA);
$res = $see->send($note);
$util->writeXml($note, $see->getFactory()->getLastXml());

if (!$res->isSuccess()) {
    echo json_encode(["success" => false, "message" => $util->getErrorResponse($res->getError())]);
    exit();
}

// Obtener respuesta de SUNAT
$cdr = $res->getCdrResponse();
$util->writeCdr($note, $res->getCdrZip());

// Generar PDF de la Nota de Débito
$pdf = $util->getPdf($note);
$pdfPath = __DIR__ . "/../../public/notas/pdf/" . $note->getName() . ".pdf";
file_put_contents($pdfPath, $pdf);

// Responder con JSON
echo json_encode([
    "success" => true,
    "message" => "Nota de Débito de Factura procesada con éxito",
    "nota_id" => $note->getName(),
    "cdr_codigo" => $cdr->getCode(),
    "cdr_descripcion" => $cdr->getDescription(),
    "xml_url" => "https://api-sunat-basic.onrender.com/public/notas/xml/" . $note->getName() . ".xml",
    "cdr_url" => "https://api-sunat-basic.onrender.com/public/notas/cdr/R-" . $note->getName() . ".zip",
    "pdf_url" => "https://api-sunat-basic.onrender.com/public/notas/pdf/" . $note->getName() . ".pdf"
]);
