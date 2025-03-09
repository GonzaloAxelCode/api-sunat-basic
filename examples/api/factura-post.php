<?php

declare(strict_types=1);

header("Content-Type: application/json"); // Indicar que la respuesta será JSON

use Greenter\Model\Client\Client;
use Greenter\Model\Company\Address;
use Greenter\Model\Response\BillResult;
use Greenter\Model\Sale\FormaPagos\FormaPagoContado;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Model\Sale\Legend;
use Greenter\Ws\Services\SunatEndpoints;

require __DIR__ . '/../../vendor/autoload.php';

$util = Util::getInstance();

// Definir la URL base para los archivos (CAMBIAR SI SE USA EN PRODUCCIÓN)
$baseUrl = "http://localhost:8080/public/facturas/";

// Leer el JSON de la solicitud POST
$json = file_get_contents("php://input");
$data = json_decode($json, true);

// Validar que el JSON es correcto
if (!$data) {
    echo json_encode(["success" => false, "message" => "JSON inválido"]);
    exit();
}

// Crear la factura con los datos recibidos

$clientData = $data['cliente'] ?? [];

$client = new Client();
$client->setTipoDoc($clientData['tipoDoc'] ?? '6') // RUC por defecto
    ->setNumDoc($clientData['numDoc'] ?? '20000000001')
    ->setRznSocial($clientData['nombre'] ?? 'Cliente Genérico')
    ->setAddress((new Address())
        ->setDireccion($clientData['direccion'] ?? 'Dirección no especificada'))
    ->setEmail($clientData['email'] ?? 'sincorreo@correo.com')
    ->setTelephone($clientData['telefono'] ?? '000-000000');
$invoice = new Invoice();
$invoice
    ->setUblVersion('2.1')
    ->setFecVencimiento(new DateTime())
    ->setTipoOperacion('0101')
    ->setTipoDoc('01')
    ->setSerie($data['serie'] ?? 'F001')
    ->setCorrelativo($data['correlativo'] ?? '123')
    ->setFechaEmision(new DateTime())
    ->setFormaPago(new FormaPagoContado())
    ->setTipoMoneda($data['moneda'] ?? 'PEN')
    ->setCompany($util->shared->getCompany())
    ->setClient($client)
    ->setMtoOperGravadas($data['gravadas'] ?? 200)
    ->setMtoOperExoneradas($data['exoneradas'] ?? 100)
    ->setMtoIGV($data['igv'] ?? 36)
    ->setTotalImpuestos($data['igv'] ?? 36)
    ->setValorVenta($data['valorVenta'] ?? 300)
    ->setSubTotal($data['subTotal'] ?? 336)
    ->setMtoImpVenta($data['total'] ?? 336);

// Agregar los ítems de la factura
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
    ->setLegends([
        (new Legend())->setCode('1000')->setValue($data['leyenda'] ?? 'Monto en letras')
    ]);

// Enviar a SUNAT
$see = $util->getSee(SunatEndpoints::FE_BETA);
$res = $see->send($invoice);

// Definir rutas de almacenamiento (en carpeta pública)
$xmlFilename = "{$invoice->getSerie()}-{$invoice->getCorrelativo()}.xml";
$pdfFilename = "{$invoice->getSerie()}-{$invoice->getCorrelativo()}.pdf";
$cdrFilename = "R-{$invoice->getSerie()}-{$invoice->getCorrelativo()}.zip"; // SUNAT agrega "R-" al nombre del CDR

$xmlPath = __DIR__ . "/../../public/facturas/xml/" . $xmlFilename;
$pdfPath = __DIR__ . "/../../public/facturas/pdf/" . $pdfFilename;
$cdrPath = __DIR__ . "/../../public/facturas/cdr/" . $cdrFilename;

// Crear directorios si no existen
if (!is_dir(dirname($xmlPath))) {
    mkdir(dirname($xmlPath), 0777, true);
}
if (!is_dir(dirname($pdfPath))) {
    mkdir(dirname($pdfPath), 0777, true);
}
if (!is_dir(dirname($cdrPath))) {
    mkdir(dirname($cdrPath), 0777, true);
}

// Guardar el XML en el servidor
file_put_contents($xmlPath, $see->getFactory()->getLastXml());

// Si la factura no fue aceptada, devolver error
if (!$res->isSuccess()) {
    echo json_encode([
        "success" => false,
        "message" => "Error al enviar la factura",
        "error" => $util->getErrorResponse($res->getError())
    ]);
    exit();
}

/**@var $res BillResult*/
$cdr = $res->getCdrResponse();
file_put_contents($cdrPath, $res->getCdrZip()); // Guardar el CDR.zip

// Generar el PDF de la factura
try {
    $pdf = $util->getPdf($invoice);
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
$xmlUrl = $baseUrl . "xml/" . $xmlFilename;
$pdfUrl = $baseUrl . "pdf/" . $pdfFilename;
$cdrUrl = $baseUrl . "cdr/" . $cdrFilename; // URL del CDR.zip

// Respuesta en JSON con las URLs de los archivos generados
$response = [
    "success" => true,
    "message" => "Factura procesada con éxito",
    "factura_id" => $invoice->getSerie() . '-' . $invoice->getCorrelativo(),
    "cdr_codigo" => $cdr->getCode(),
    "cdr_descripcion" => $cdr->getDescription(),
    "notas" => $cdr->getNotes(),
    "xml_url" => $xmlUrl,
    "pdf_url" => $pdfUrl,
    "cdr_url" => $cdrUrl // Devolver la URL del CDR
];

echo json_encode($response, JSON_PRETTY_PRINT);
