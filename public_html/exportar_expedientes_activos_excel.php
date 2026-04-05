<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit;
}

$mi_rol = $_SESSION['rol'] ?? 'Ejecutivo';
if (!in_array($mi_rol, ['Admin', 'Gerente'], true)) {
    http_response_code(403);
    exit('Sin permiso.');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/calcular_vacaciones.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset("utf8mb4");

/* =========================================================
   Helpers
========================================================= */
function clean_value($v): string {
    if ($v === null) return '';
    if ($v === '0000-00-00') return '';
    return trim((string)$v);
}

function antiguedad_legible($fechaIngreso): string {
    $fechaIngreso = clean_value($fechaIngreso);
    if ($fechaIngreso === '') return '';

    try {
        $inicio = new DateTime($fechaIngreso);
        $hoy    = new DateTime();
        $diff   = $inicio->diff($hoy);
        return "{$diff->y} año(s) {$diff->m} mes(es)";
    } catch (Throwable $e) {
        return '';
    }
}

/*
  Para CSV y Excel: fuerza que Excel trate el valor como texto
  y no lo convierta a notación científica ni elimine ceros.
*/
function excel_text($v): string {
    $v = clean_value($v);
    if ($v === '') return '';
    return '="' . str_replace('"', '""', $v) . '"';
}

/* =========================================================
   Consulta de empleados activos
   Incluye actas administrativas por existencia en usuario_documentos
========================================================= */
$sql = "
    SELECT
        u.id AS numero_empleado,
        u.id AS usuario_id,
        u.nombre,
        u.usuario,
        u.correo,
        u.rol AS puesto,
        u.activo,
        s.nombre AS sucursal,
        s.zona AS zona,

        ue.tel_contacto,
        ue.fecha_nacimiento,
        ue.fecha_ingreso,
        ue.fecha_baja,
        ue.motivo_baja,
        ue.curp,
        ue.nss,
        ue.rfc,
        ue.genero,
        ue.contacto_emergencia,
        ue.tel_emergencia,
        ue.clabe,
        ue.banco,
        ue.edad_years,
        ue.antiguedad_meses,
        ue.antiguedad_years,
        ue.contrato_status,
        ue.registro_patronal,
        ue.fecha_alta_imss,
        ue.talla_uniforme,
        ue.payjoy_status,
        ue.krediya_status,
        ue.lespago_status,
        ue.innovm_status,
        ue.central_status,
        ue.created_at,
        ue.updated_at,

        CASE 
            WHEN EXISTS (
                SELECT 1
                FROM usuario_documentos ud
                WHERE ud.usuario_id = u.id
                  AND ud.doc_tipo_id = 25
            ) THEN 'Sí'
            ELSE 'No'
        END AS acta_administrativa_1,

        CASE 
            WHEN EXISTS (
                SELECT 1
                FROM usuario_documentos ud
                WHERE ud.usuario_id = u.id
                  AND ud.doc_tipo_id = 26
            ) THEN 'Sí'
            ELSE 'No'
        END AS acta_administrativa_2,

        CASE 
            WHEN EXISTS (
                SELECT 1
                FROM usuario_documentos ud
                WHERE ud.usuario_id = u.id
                  AND ud.doc_tipo_id = 27
            ) THEN 'Sí'
            ELSE 'No'
        END AS acta_administrativa_3

    FROM usuarios u
    LEFT JOIN sucursales s ON s.id = u.id_sucursal
    LEFT JOIN usuarios_expediente ue ON ue.usuario_id = u.id
    WHERE u.activo = 1
      AND (s.subtipo IS NULL OR s.subtipo NOT IN ('Subdistribuidor','Master Admin'))
    ORDER BY s.nombre ASC, u.nombre ASC
";

$res = $conn->query($sql);
$rows = [];

while ($row = $res->fetch_assoc()) {
    $usuarioId = (int)($row['usuario_id'] ?? 0);

    $vacPendientes = '';
    $vacTomadosPeriodo = '';
    $vacDisponibles = '';

    if ($usuarioId > 0) {
        try {
            $resumenVac = obtener_resumen_vacaciones_usuario($conn, $usuarioId);

            if (!empty($resumenVac['ok'])) {
                $diasOtorgados = (int)($resumenVac['dias_otorgados'] ?? 0);
                $diasTomados   = (int)($resumenVac['dias_tomados'] ?? 0);
                $diasDispon    = isset($resumenVac['dias_disponibles'])
                    ? (int)$resumenVac['dias_disponibles']
                    : max(0, $diasOtorgados - $diasTomados);

                $vacPendientes     = (string)$diasDispon;
                $vacTomadosPeriodo = (string)$diasTomados;
                $vacDisponibles    = (string)$diasDispon;
            }
        } catch (Throwable $e) {
            $vacPendientes = '';
            $vacTomadosPeriodo = '';
            $vacDisponibles = '';
        }
    }

    $row['antiguedad_legible'] = antiguedad_legible($row['fecha_ingreso'] ?? null);
    $row['vacaciones_pendientes'] = $vacPendientes;
    $row['vacaciones_tomados_periodo'] = $vacTomadosPeriodo;
    $row['vacaciones_disponibles'] = $vacDisponibles;

    $rows[] = $row;
}

/* =========================================================
   Encabezados del archivo
========================================================= */
$headers = [
    'SUCURSAL',
    'NOMBRE',
    'PUESTO',
    'FECHA DE INGRESO',
    'ANTIGÜEDAD',
    'CONTRATO',
    'REGISTRO PATRONAL',
    'FECHA DE ALTA IMSS',
    'NSS',
    'CURP',
    'RFC',
    'NUMERO TELEFONICO',
    'CORREO ELECTRONICO',
    'CONTACTO EMERGENCIA',
    'TELEFONO CONTACTO EMERGENCIA',
    'FECHA DE NACIMIENTO',
    'TALLA UNIFORMES',
    'PAYJOY',
    'KREDIYA',
    'LESPAGO',
    'INNOVM',
    'CENTRAL',
    'FECHA DE BAJA',
    'MOTIVO',
    'ACTA ADMINISTRATIVA 1',
    'ACTA ADMINISTRATIVA 2',
    'ACTA ADMINISTRATIVA 3',
    'VACACIONES DIAS PENDIENTES POR TOMAR',
    'VACACIONES DIAS TOMADOS (PERIODO)',
    'DIAS DISPONIBLES DE VACACIONES'
];

/* =========================================================
   Intentar XLSX con PhpSpreadsheet
========================================================= */
$autoloadPaths = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
];

$autoloadFound = null;
foreach ($autoloadPaths as $ap) {
    if (is_file($ap)) {
        $autoloadFound = $ap;
        break;
    }
}

if ($autoloadFound) {
    require_once $autoloadFound;

    if (class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Plantilla Personal');

        // Encabezados
        $col = 1;
        foreach ($headers as $header) {
            $sheet->setCellValueByColumnAndRow($col, 1, $header);
            $col++;
        }

        // Datos
        $rowNum = 2;
        foreach ($rows as $r) {
            // Campos normales
            $sheet->setCellValueByColumnAndRow(1,  $rowNum, clean_value($r['numero_empleado']));
            $sheet->setCellValueByColumnAndRow(2,  $rowNum, clean_value($r['sucursal']));
            $sheet->setCellValueByColumnAndRow(3,  $rowNum, clean_value($r['nombre']));
            $sheet->setCellValueByColumnAndRow(4,  $rowNum, clean_value($r['puesto']));
            $sheet->setCellValueByColumnAndRow(5,  $rowNum, clean_value($r['fecha_ingreso']));
            $sheet->setCellValueByColumnAndRow(6,  $rowNum, clean_value($r['antiguedad_legible']));
            $sheet->setCellValueByColumnAndRow(7,  $rowNum, clean_value($r['contrato_status']));
            $sheet->setCellValueByColumnAndRow(8,  $rowNum, clean_value($r['registro_patronal']));
            $sheet->setCellValueByColumnAndRow(9,  $rowNum, clean_value($r['fecha_alta_imss']));

            // Campos sensibles como TEXTO
            $sheet->setCellValueExplicitByColumnAndRow(10, $rowNum, clean_value($r['nss']), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValueExplicitByColumnAndRow(11, $rowNum, clean_value($r['curp']), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValueExplicitByColumnAndRow(12, $rowNum, clean_value($r['rfc']), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValueExplicitByColumnAndRow(13, $rowNum, clean_value($r['tel_contacto']), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);

            // Resto normales
            $sheet->setCellValueByColumnAndRow(14, $rowNum, clean_value($r['correo']));
            $sheet->setCellValueByColumnAndRow(15, $rowNum, clean_value($r['contacto_emergencia']));
            $sheet->setCellValueExplicitByColumnAndRow(16, $rowNum, clean_value($r['tel_emergencia']), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValueByColumnAndRow(17, $rowNum, clean_value($r['fecha_nacimiento']));
            $sheet->setCellValueByColumnAndRow(18, $rowNum, clean_value($r['talla_uniforme']));
            $sheet->setCellValueByColumnAndRow(19, $rowNum, clean_value($r['payjoy_status']));
            $sheet->setCellValueByColumnAndRow(20, $rowNum, clean_value($r['krediya_status']));
            $sheet->setCellValueByColumnAndRow(21, $rowNum, clean_value($r['lespago_status']));
            $sheet->setCellValueByColumnAndRow(22, $rowNum, clean_value($r['innovm_status']));
            $sheet->setCellValueByColumnAndRow(23, $rowNum, clean_value($r['central_status']));
            $sheet->setCellValueByColumnAndRow(24, $rowNum, clean_value($r['fecha_baja']));
            $sheet->setCellValueByColumnAndRow(25, $rowNum, clean_value($r['motivo_baja']));
            $sheet->setCellValueByColumnAndRow(26, $rowNum, clean_value($r['acta_administrativa_1']));
            $sheet->setCellValueByColumnAndRow(27, $rowNum, clean_value($r['acta_administrativa_2']));
            $sheet->setCellValueByColumnAndRow(28, $rowNum, clean_value($r['acta_administrativa_3']));
            $sheet->setCellValueByColumnAndRow(29, $rowNum, clean_value($r['vacaciones_pendientes']));
            $sheet->setCellValueByColumnAndRow(30, $rowNum, clean_value($r['vacaciones_tomados_periodo']));
            $sheet->setCellValueByColumnAndRow(31, $rowNum, clean_value($r['vacaciones_disponibles']));
            $rowNum++;
        }

        // Estilos encabezado
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        $headerRange = "A1:{$lastCol}1";

        $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($headerRange)->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('1D4ED8');

        $sheet->freezePane('A2');
        $sheet->setAutoFilter($headerRange);

        // Formato texto para columnas sensibles
        $sheet->getStyle('A:A')->getNumberFormat()->setFormatCode('@'); // Número empleado
        $sheet->getStyle('J:J')->getNumberFormat()->setFormatCode('@'); // NSS
        $sheet->getStyle('K:K')->getNumberFormat()->setFormatCode('@'); // CURP
        $sheet->getStyle('L:L')->getNumberFormat()->setFormatCode('@'); // RFC
        $sheet->getStyle('M:M')->getNumberFormat()->setFormatCode('@'); // Teléfono
        $sheet->getStyle('P:P')->getNumberFormat()->setFormatCode('@'); // Tel emergencia

        // Auto width
        for ($i = 1; $i <= count($headers); $i++) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
        }

        $filename = 'plantilla_personal_' . date('Ymd_His') . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }
}

/* =========================================================
   Fallback CSV compatible con Excel
========================================================= */
$filename = 'plantilla_personal_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// BOM UTF-8 para Excel
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Encabezados
fputcsv($output, $headers);

// Filas
foreach ($rows as $r) {
    fputcsv($output, [
        excel_text($r['numero_empleado']),
        clean_value($r['sucursal']),
        clean_value($r['nombre']),
        clean_value($r['puesto']),
        clean_value($r['fecha_ingreso']),
        clean_value($r['antiguedad_legible']),
        clean_value($r['contrato_status']),
        clean_value($r['registro_patronal']),
        clean_value($r['fecha_alta_imss']),
        excel_text($r['nss']),
        excel_text($r['curp']),
        excel_text($r['rfc']),
        excel_text($r['tel_contacto']),
        clean_value($r['correo']),
        clean_value($r['contacto_emergencia']),
        excel_text($r['tel_emergencia']),
        clean_value($r['fecha_nacimiento']),
        clean_value($r['talla_uniforme']),
        clean_value($r['payjoy_status']),
        clean_value($r['krediya_status']),
        clean_value($r['lespago_status']),
        clean_value($r['innovm_status']),
        clean_value($r['central_status']),
        clean_value($r['fecha_baja']),
        clean_value($r['motivo_baja']),
        clean_value($r['acta_administrativa_1']),
        clean_value($r['acta_administrativa_2']),
        clean_value($r['acta_administrativa_3']),
        clean_value($r['vacaciones_pendientes']),
        clean_value($r['vacaciones_tomados_periodo']),
        clean_value($r['vacaciones_disponibles']),
    ]);
}

fclose($output);
exit;