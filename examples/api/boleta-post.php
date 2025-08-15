<?php

declare(strict_types=1);

header("Content-Type: application/json"); // La respuesta será JSON

use Greenter\Model\Client\Client;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Model\Sale\Legend;
use Greenter\Ws\Services\SunatEndpoints;

require __DIR__ . '/../../vendor/autoload.php';

$util = Util::getInstance();

// URL base para los archivos generados (CAMBIAR EN PRODUCCIÓN)
$baseUrl = "https://api-sunat-basic.onrender.com/public/boletas/";

// Leer el JSON de la solicitud POST
$json = file_get_contents("php://input");
$data = json_decode($json, true);

if (!$data) {
    http_response_code(400); // Código 400: Bad Request
    echo json_encode(["success" => false, "message" => "JSON inválido"]);
    exit();
}

// Crear el cliente
$clientData = $data['cliente'] ?? [];
$client = new Client();
$client->setTipoDoc($clientData['tipoDoc'] ?? '1') // DNI por defecto
    ->setNumDoc($clientData['numDoc'] ?? '00000000')
    ->setRznSocial($clientData['nombre'] ?? 'Cliente Genérico');

// Crear la boleta electrónica
$invoice = new Invoice();
$invoice
    ->setUblVersion('2.1')
    ->setTipoOperacion('0101')
    ->setTipoDoc('03') // 03 es Boleta
    ->setSerie($data['serie'] ?? 'B001')
    ->setCorrelativo($data['correlativo'] ?? '123')
    ->setFechaEmision(new DateTime())
    ->setTipoMoneda($data['moneda'] ?? 'PEN')
    ->setCompany($util->shared->getCompany())
    ->setClient($client)
    ->setMtoOperGravadas($data['gravadas'] ?? 200)
    ->setMtoIGV($data['igv'] ?? 36)
    ->setTotalImpuestos($data['igv'] ?? 36)
    ->setValorVenta($data['valorVenta'] ?? 300)
    ->setSubTotal($data['subTotal'] ?? 336)
    ->setMtoImpVenta($data['total'] ?? 336);

// Agregar los ítems de la boleta
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

// Si la boleta no fue aceptada, devolver error
if (!$res->isSuccess()) {
    http_response_code(500); // Código 500: Error interno del servidor
    echo json_encode([
        "success" => false,
        "message" => "Error al enviar la boleta",
        "error" => $util->getErrorResponse($res->getError())
    ]);
    exit();
}

/**@var $res \Greenter\Model\Response\BillResult */
$cdr = $res->getCdrResponse();

// Definir rutas de almacenamiento
$xmlFilename = "{$invoice->getSerie()}-{$invoice->getCorrelativo()}.xml";
$pdfFilename = "{$invoice->getSerie()}-{$invoice->getCorrelativo()}.pdf";
$cdrFilename = "R-{$invoice->getSerie()}-{$invoice->getCorrelativo()}.zip";
$ticketFilename = "{$invoice->getSerie()}-{$invoice->getCorrelativo()}-ticket.pdf";

// Directorios de almacenamiento
$xmlPath = __DIR__ . "/../../public/boletas/xml/" . $xmlFilename;
$pdfPath = __DIR__ . "/../../public/boletas/pdf/" . $pdfFilename;
$cdrPath = __DIR__ . "/../../public/boletas/cdr/" . $cdrFilename;
$ticketPath = __DIR__ . "/../../public/boletas/pdf/" . $ticketFilename;

// Crear directorios si no existen
foreach ([$xmlPath, $pdfPath, $cdrPath, $ticketPath] as $path) {
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
}

// Guardar el XML en el servidor
file_put_contents($xmlPath, $see->getFactory()->getLastXml());
file_put_contents($cdrPath, $res->getCdrZip()); // Guardar el CDR.zip

// Generar el PDF normal de la boleta
try {
    $pdf = $util->getPdf($invoice);
    file_put_contents($pdfPath, $pdf);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Error al generar el PDF",
        "error" => $e->getMessage()
    ]);
    exit();
}

// Generar el Ticket (PDF más pequeño para impresora térmica)
try {
    $ticketPdf = $util->getPdf($invoice, 'ticket');
    file_put_contents($ticketPath, $ticketPdf);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Error al generar el ticket",
        "error" => $e->getMessage()
    ]);
    exit();
}

// Construir las URLs accesibles
$xmlUrl = $baseUrl . "xml/" . $xmlFilename;
$pdfUrl = $baseUrl . "pdf/" . $pdfFilename;
$cdrUrl = $baseUrl . "cdr/" . $cdrFilename;
$ticketUrl = $baseUrl . "pdf/" . $ticketFilename;

// Responder con los datos generados
http_response_code(200); // Código 200: OK
echo json_encode([
    "success" => true,
    "message" => "Boleta procesada con éxito",
    "boleta_id" => $invoice->getSerie() . '-' . $invoice->getCorrelativo(),
    "cdr_codigo" => $cdr->getCode(),
    "cdr_descripcion" => $cdr->getDescription(),
    "notas" => $cdr->getNotes(),
    "xml_url" => $xmlUrl,
    "pdf_url" => $pdfUrl,
    "cdr_url" => $cdrUrl,
    "ticket_url" => $ticketUrl // URL del ticket
], JSON_PRETTY_PRINT);
