<?php

declare(strict_types=1);
header("Content-Type: application/json");

require __DIR__ . '/../../vendor/autoload.php';
require 'domain.php';
require __DIR__ . '/r2_client.php'; // ✅ conexión R2

use Greenter\Model\Response\BillResult;
use Greenter\Model\Sale\Note;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Model\Sale\Legend;
use Greenter\Ws\Services\SunatEndpoints;
use Aws\Exception\AwsException;
use Greenter\Model\Client\Client;
use Greenter\Model\Company\Address;

$util = Util::getInstance();

// 📥 Leer JSON
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// ❌ Validar JSON
if (!$data) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "JSON inválido"]);
    exit();
}

// ✅ Validaciones esenciales
$required = ['serie', 'correlativo', 'moneda', 'comprobante_modifica', 'motivo', 'tipo_motivo', 'items', 'cliente'];
foreach ($required as $key) {
    if (empty($data[$key])) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Falta el campo requerido: $key"]);
        exit();
    }
}

// ✅ Validar estructura del comprobante afectado
$comp = $data['comprobante_modifica'];
if (empty($comp['tipo']) || empty($comp['serie']) || empty($comp['correlativo'])) {
    echo json_encode(["success" => false, "message" => "Datos incompletos en comprobante_modifica"]);
    exit();
}

// ✅ Validar cliente
$cliente = $data['cliente'];
if (empty($cliente['tipoDoc']) || empty($cliente['numDoc']) || empty($cliente['nombre'])) {
    echo json_encode(["success" => false, "message" => "Datos incompletos del cliente"]);
    exit();
}

// ✅ Validar y normalizar datos base
$serie = strtoupper(trim($data['serie']));
$correlativo = str_pad((string)$data['correlativo'], 8, '0', STR_PAD_LEFT);

$tipoDocAfectado = $comp['tipo']; // 01 o 03
$serieAfectada = strtoupper(trim($comp['serie']));
$correlativoAfectado = str_pad((string)$comp['correlativo'], 8, '0', STR_PAD_LEFT);

$gravadas = floatval($data['gravadas'] ?? 0);
$igv = floatval($data['igv'] ?? 0);
$total = floatval($data['total'] ?? 0);

if ($total <= 0) {
    echo json_encode(["success" => false, "message" => "El total debe ser mayor a 0"]);
    exit();
}
$client = (new Client())
    ->setTipoDoc($cliente['tipoDoc'])
    ->setNumDoc($cliente['numDoc'])
    ->setRznSocial($cliente['nombre'])
    ->setAddress((new Address())
        ->setDireccion($cliente['direccion'] ?? 'SIN DIRECCIÓN'));

// 🔹 Crear la nota
try {
    $note = (new Note())
        ->setUblVersion('2.1')
        ->setTipoDoc('07')
        ->setSerie($serie)
        ->setCorrelativo($correlativo)
        ->setFechaEmision(new DateTime())
        ->setTipDocAfectado($tipoDocAfectado)
        ->setNumDocfectado($serieAfectada . '-' . $correlativoAfectado)
        ->setCodMotivo($data['tipo_motivo'])
        ->setDesMotivo($data['motivo'])
        ->setTipoMoneda($data['moneda'])
        ->setCompany($util->getGRECompany())
        ->setClient($client)
        ->setMtoOperGravadas($gravadas)
        ->setMtoIGV($igv)
        ->setTotalImpuestos($igv)
        ->setMtoImpVenta($total);

    // 🔸 Detalles
    $details = [];
    foreach ($data['items'] as $index => $item) {
        $detalle = (new SaleDetail())
            ->setCodProducto($item['codigo'] ?? 'P' . str_pad((string)($index + 1), 3, '0', STR_PAD_LEFT))
            ->setUnidad($item['unidad'] ?? 'NIU')
            ->setCantidad(floatval($item['cantidad'] ?? 1))
            ->setDescripcion($item['descripcion'])
            ->setMtoBaseIgv(floatval($item['baseIgv'] ?? 0))
            ->setPorcentajeIgv(floatval($item['porcentajeIgv'] ?? 18))
            ->setIgv(floatval($item['igv'] ?? 0))
            ->setTipAfeIgv($item['tipoAfectacionIgv'] ?? '10')
            ->setTotalImpuestos(floatval($item['totalImpuestos'] ?? 0))
            ->setMtoValorVenta(floatval($item['valorVenta'] ?? 0))
            ->setMtoValorUnitario(floatval($item['valorUnitario'] ?? 0))
            ->setMtoPrecioUnitario(floatval($item['precioUnitario'] ?? 0));
        $details[] = $detalle;
    }
    $note->setDetails($details);

    // 🔹 Leyenda opcional
    if (!empty($data['leyenda'])) {
        $legend = (new Legend())->setCode('1000')->setValue($data['leyenda']);
        $note->setLegends([$legend]);
    }

    // 📨 Enviar a SUNAT
    $see = $util->getSee(SunatEndpoints::FE_PRODUCCION);
    $res = $see->send($note);

    if (!$res->isSuccess()) {
        $error = $res->getError();
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "SUNAT rechazó la nota de crédito",
            "error" => $util->getErrorResponse($error)
        ]);
        exit();
    }

    // 📦 Obtener CDR
    $cdr = $res->getCdrResponse();

    // ✅ Crear archivos en memoria
    $xmlContent = $see->getFactory()->getLastXml();
    $cdrContent = $res->getCdrZip();
    $pdfContent = $util->getPdf($note, "note");

    // ✅ Subir a R2
    $r2 = r2Client();

    function subirR2($r2, $bucket, $key, $body, $contentType = 'application/octet-stream')
    {
        try {
            $r2->putObject([
                'Bucket' => $bucket,
                'Key' => $key,
                'Body' => $body,
                'ACL' => 'private',
                'ContentType' => $contentType,
            ]);
            return true;
        } catch (AwsException $e) {
            error_log('Error R2: ' . $e->getMessage());
            return false;
        }
    }

    // ✅ Nombres y rutas
    $xmlName = "{$note->getName()}.xml";
    $pdfName = "{$note->getName()}.pdf";
    $cdrName = "R-{$note->getName()}.zip";

    $endpoint = SunatEndpoints::FE_PRODUCCION; // o FE_PROD según tu entorno
    $see = $util->getSee($endpoint);

    $isBeta = $endpoint === SunatEndpoints::FE_BETA;

    if ($isBeta) {
        $xmlName = str_replace('.xml', '_beta.xml', $xmlName);
        $pdfName = str_replace('.pdf', '_beta.pdf', $pdfName);
        $cdrName = str_replace('.zip', '_beta.zip', $cdrName);
    }
    subirR2($r2, R2_BUCKET, "notas/xml/{$xmlName}", $xmlContent, 'application/xml');
    subirR2($r2, R2_BUCKET, "notas/pdf/{$pdfName}", $pdfContent, 'application/pdf');
    subirR2($r2, R2_BUCKET, "notas/cdr/{$cdrName}", $cdrContent, 'application/zip');

    // ✅ URLs públicas
    $xmlUrl = R2_BASE_URL . "/notas/xml/{$xmlName}";
    $pdfUrl = R2_BASE_URL . "/notas/pdf/{$pdfName}";
    $cdrUrl = R2_BASE_URL . "/notas/cdr/{$cdrName}";

    // ✅ Respuesta final
    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Nota de Crédito procesada y subida a R2 correctamente",
        "nota_id" => $note->getName(),
        "serie" => $serie,
        "correlativo" => $correlativo,
        "cdr_codigo" => $cdr->getCode(),
        "cdr_descripcion" => $cdr->getDescription(),
        "xml_url" => $xmlUrl,
        "pdf_url" => $pdfUrl,
        "cdr_url" => $cdrUrl
    ], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Error interno al procesar la nota de crédito",
        "error" => $e->getMessage()
    ]);
    exit();
}
