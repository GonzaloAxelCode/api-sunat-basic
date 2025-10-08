<?php

declare(strict_types=1);
header("Content-Type: application/json");
require __DIR__ . '/../../vendor/autoload.php';

use Greenter\Model\Response\BillResult;
use Greenter\Model\Sale\Note;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Model\Sale\Legend;
use Greenter\Ws\Services\SunatEndpoints;

$util = Util::getInstance();

// 📥 Leer JSON desde Django
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// ❌ Validar JSON válido
if (!$data) {
    echo json_encode(["success" => false, "message" => "JSON inválido"]);
    exit();
}

// ✅ Validar campos requeridos
$required = ['serie', 'correlativo', 'moneda', 'comprobante_modifica', 'motivo', 'tipo_motivo', 'items', 'cliente'];
foreach ($required as $key) {
    if (!isset($data[$key])) {
        echo json_encode(["success" => false, "message" => "Falta el campo requerido: $key"]);
        exit();
    }
}

// ✅ Validar estructura del comprobante que modifica
if (!isset($data['comprobante_modifica']['tipo']) || !isset($data['comprobante_modifica']['serie']) || !isset($data['comprobante_modifica']['correlativo'])) {
    echo json_encode(["success" => false, "message" => "Datos incompletos en comprobante_modifica (tipo, serie, correlativo)"]);
    exit();
}

// ✅ Validar datos del cliente
if (!isset($data['cliente']['tipoDoc']) || !isset($data['cliente']['numDoc']) || !isset($data['cliente']['nombre'])) {
    echo json_encode(["success" => false, "message" => "Datos incompletos del cliente (tipoDoc, numDoc, nombre)"]);
    exit();
}

// ✅ Validar y formatear SERIE (debe tener exactamente 4 caracteres)
$serie = strtoupper(trim($data['serie']));
if (strlen($serie) !== 4) {
    echo json_encode([
        "success" => false,
        "message" => "La serie debe tener exactamente 4 caracteres. Ejemplos: NC01, FC01, BC01"
    ]);
    exit();
}

// ✅ Validar formato de serie según el tipo de comprobante
$primerCaracter = substr($serie, 0, 1);
if (!in_array($primerCaracter, ['F', 'B', 'N'])) {
    echo json_encode([
        "success" => false,
        "message" => "La serie debe comenzar con F (Factura), B (Boleta) o N (Nota). Ejemplo: FC01, BC01, NC01"
    ]);
    exit();
}

// ✅ Validar CORRELATIVO (debe ser numérico entre 1 y 99999999)
if (!preg_match('/^\d{1,8}$/', (string)$data['correlativo'])) {
    echo json_encode([
        "success" => false,
        "message" => "El correlativo debe ser numérico entre 1 y 99999999"
    ]);
    exit();
}

$correlativo = str_pad((string)$data['correlativo'], 8, '0', STR_PAD_LEFT);

// ✅ Validar tipo de documento afectado (01=Factura, 03=Boleta)
$tipoDocAfectado = $data['comprobante_modifica']['tipo'];
if (!in_array($tipoDocAfectado, ['01', '03'])) {
    echo json_encode([
        "success" => false,
        "message" => "El tipo de documento afectado debe ser '01' (Factura) o '03' (Boleta)"
    ]);
    exit();
}

// ✅ Validar serie del comprobante afectado
$serieAfectada = strtoupper(trim($data['comprobante_modifica']['serie']));
if (strlen($serieAfectada) !== 4) {
    echo json_encode([
        "success" => false,
        "message" => "La serie del comprobante afectado debe tener 4 caracteres"
    ]);
    exit();
}

// ✅ Validar correlativo del comprobante afectado
$correlativoAfectado = str_pad((string)$data['comprobante_modifica']['correlativo'], 8, '0', STR_PAD_LEFT);

// ✅ Validar tipo de motivo de nota de crédito
$tiposMotivo = ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12', '13'];
if (!in_array($data['tipo_motivo'], $tiposMotivo)) {
    echo json_encode([
        "success" => false,
        "message" => "Tipo de motivo inválido. Debe estar entre 01 y 13"
    ]);
    exit();
}

// ✅ Validar moneda
if (!in_array($data['moneda'], ['PEN', 'USD'])) {
    echo json_encode([
        "success" => false,
        "message" => "Moneda inválida. Use 'PEN' o 'USD'"
    ]);
    exit();
}

// ✅ Validar que haya items
if (empty($data['items']) || !is_array($data['items'])) {
    echo json_encode([
        "success" => false,
        "message" => "Debe incluir al menos un item en el array 'items'"
    ]);
    exit();
}

// ✅ Validar montos numéricos
$gravadas = floatval($data['gravadas'] ?? 0);
$igv = floatval($data['igv'] ?? 0);
$total = floatval($data['total'] ?? 0);

if ($total <= 0) {
    echo json_encode([
        "success" => false,
        "message" => "El total debe ser mayor a 0"
    ]);
    exit();
}

// ✅ Validar coherencia de montos (total debe ser aprox. gravadas + igv)
$totalCalculado = $gravadas + $igv;
if (abs($total - $totalCalculado) > 0.02) {
    echo json_encode([
        "success" => false,
        "message" => "Los montos no son coherentes. Total debe ser igual a Gravadas + IGV",
        "detalle" => [
            "gravadas" => $gravadas,
            "igv" => $igv,
            "total_recibido" => $total,
            "total_calculado" => $totalCalculado
        ]
    ]);
    exit();
}

// 🔹 Crear la Nota de Crédito
try {
    $note = new Note();
    $note->setUblVersion('2.1')
        ->setTipoDoc('07') // Nota de Crédito
        ->setSerie($serie)
        ->setCorrelativo($correlativo)
        ->setFechaEmision(new DateTime())
        ->setTipDocAfectado($tipoDocAfectado)
        ->setNumDocfectado($serieAfectada . '-' . $correlativoAfectado)
        ->setCodMotivo($data['tipo_motivo'])
        ->setDesMotivo($data['motivo'])
        ->setTipoMoneda($data['moneda'])
        ->setCompany($util->getGRECompany())
        ->setClient($util->shared->getClient(
            $data['cliente']['tipoDoc'],
            $data['cliente']['numDoc'],
            $data['cliente']['nombre']
        ))
        ->setMtoOperGravadas($gravadas)
        ->setMtoIGV($igv)
        ->setTotalImpuestos($igv)
        ->setMtoImpVenta($total);

    // 🔸 Agregar ítems con validaciones
    $details = [];
    foreach ($data['items'] as $index => $item) {
        // Validar campos requeridos del item
        if (!isset($item['descripcion']) || empty($item['descripcion'])) {
            echo json_encode([
                "success" => false,
                "message" => "El item #" . ($index + 1) . " no tiene descripción"
            ]);
            exit();
        }

        if (!isset($item['cantidad']) || floatval($item['cantidad']) <= 0) {
            echo json_encode([
                "success" => false,
                "message" => "El item #" . ($index + 1) . " tiene cantidad inválida"
            ]);
            exit();
        }

        $detail = new SaleDetail();
        $detail->setCodProducto($item['codigo'] ?? 'P' . str_pad((string)($index + 1), 3, '0', STR_PAD_LEFT))
            ->setUnidad($item['unidad'] ?? 'NIU')
            ->setCantidad(floatval($item['cantidad']))
            ->setDescripcion($item['descripcion'])
            ->setMtoBaseIgv(floatval($item['baseIgv'] ?? 0))
            ->setPorcentajeIgv(floatval($item['porcentajeIgv'] ?? 18))
            ->setIgv(floatval($item['igv'] ?? 0))
            ->setTipAfeIgv($item['tipoAfectacionIgv'] ?? '10')
            ->setTotalImpuestos(floatval($item['totalImpuestos'] ?? 0))
            ->setMtoValorVenta(floatval($item['valorVenta'] ?? 0))
            ->setMtoValorUnitario(floatval($item['valorUnitario'] ?? 0))
            ->setMtoPrecioUnitario(floatval($item['precioUnitario'] ?? 0));

        $details[] = $detail;
    }
    $note->setDetails($details);

    // 🔹 Agregar leyenda
    if (!empty($data['leyenda'])) {
        $legend = new Legend();
        $legend->setCode('1000')->setValue($data['leyenda']);
        $note->setLegends([$legend]);
    }

    // 📨 Enviar a SUNAT
    $see = $util->getSee(SunatEndpoints::FE_BETA); // Cambiar a FE_PRODUCCION en producción
    $res = $see->send($note);
    $util->writeXml($note, $see->getFactory()->getLastXml());

    // ⚠️ Verificar respuesta de SUNAT
    if (!$res->isSuccess()) {
        $error = $res->getError();
        echo json_encode([
            "success" => false,
            "message" => "SUNAT rechazó la nota de crédito",
            "error" => $util->getErrorResponse($error),
            "codigo_error" => $error ? $error->getCode() : null,
            "descripcion_error" => $error ? $error->getMessage() : null
        ]);
        exit();
    }

    // 📦 Guardar respuesta CDR
    $cdr = $res->getCdrResponse();
    $util->writeCdr($note, $res->getCdrZip());

    // 🧾 Generar PDF
    $pdf = $util->getPdf($note, "note");
    $pdfPath = __DIR__ . "/../../public/notas/pdf/" . $note->getName() . ".pdf";

    // Crear directorio si no existe
    $pdfDir = dirname($pdfPath);
    if (!is_dir($pdfDir)) {
        mkdir($pdfDir, 0755, true);
    }

    file_put_contents($pdfPath, $pdf);

    // ✅ Respuesta exitosa
    echo json_encode([
        "success" => true,
        "message" => "Nota de Crédito procesada correctamente",
        "nota_id" => $note->getName(),
        "serie" => $serie,
        "correlativo" => $correlativo,
        "cdr_codigo" => $cdr->getCode(),
        "cdr_descripcion" => $cdr->getDescription(),
        "xml_url" => $domain . "/public/notas/xml/" . $note->getName() . ".xml",
        "cdr_url" =>  $domain . "/public/notas/cdr/R-" . $note->getName() . ".zip",
        "pdf_url" => $domain . "/public/notas/pdf/" . $note->getName() . ".pdf"
    ]);
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Error interno al procesar la nota de crédito",
        "error" => $e->getMessage(),
        "trace" => $e->getTraceAsString()
    ]);
    exit();
}
