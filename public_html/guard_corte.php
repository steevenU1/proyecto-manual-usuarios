<?php
// guard_corte.php — Candado de captura por cortes pendientes
// Regla: bloquear si EXISTEN cobros de la sucursal con corte_generado=0
//        cuya fecha_cobro sea ANTERIOR a HOY (zona MX).

if (!function_exists('debe_bloquear_captura')) {
  function debe_bloquear_captura(mysqli $conn, int $id_sucursal): array
  {
    // Asegura TZ consistente para el cálculo de "hoy"
    @date_default_timezone_set('America/Mexico_City');
    // Opcional (si tu MySQL lo permite): fija TZ de la sesión
    @$conn->query("SET time_zone = '-06:00'");

    // Para el mensaje usamos “ayer” solo como referencia visual
    $ayer = (new DateTime('now', new DateTimeZone('America/Mexico_City')))
              ->modify('-1 day')->format('Y-m-d');

    // 🔎 Regla clave: SOLO contar cobros de fechas ANTERIORES a HOY
    // Usamos DATE(fecha_cobro) < CURDATE() para ignorar la hora
    $sql = "
      SELECT COUNT(*) AS pendientes
      FROM cobros
      WHERE id_sucursal      = ?
        AND corte_generado   = 0
        AND DATE(fecha_cobro) < CURDATE()
    ";

    $pendientes = 0;
    if ($stmt = $conn->prepare($sql)) {
      $stmt->bind_param('i', $id_sucursal);
      $stmt->execute();
      $stmt->bind_result($pendientes);
      $stmt->fetch();
      $stmt->close();
    }

    if ($pendientes > 0) {
      $motivo = "Tienes cobros pendientes de corte de <strong>días anteriores</strong>. "
              . "Genera el corte correspondiente para continuar.";
      return [true, $motivo, $ayer];
    }

    return [false, '', $ayer];
  }
}
