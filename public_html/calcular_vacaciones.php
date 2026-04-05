<?php
/**
 * calcular_vacaciones.php
 *
 * Motor central para cálculo de vacaciones conforme a la ley mexicana
 * + vigencia de 1 año por periodo vacacional.
 *
 * Uso:
 *   require_once __DIR__ . '/calcular_vacaciones.php';
 *   $resumen = obtener_resumen_vacaciones_usuario($conn, $usuarioId);
 */

if (!function_exists('vac_empty_date')) {
    function vac_empty_date($v): bool {
        return empty($v) || $v === '0000-00-00';
    }
}

if (!function_exists('vac_fmt_date')) {
    function vac_fmt_date($v): string {
        if (vac_empty_date($v)) return '—';
        $ts = strtotime((string)$v);
        return $ts ? date('d/m/Y', $ts) : '—';
    }
}

if (!function_exists('vacaciones_dias_por_anios_servicio')) {
    function vacaciones_dias_por_anios_servicio(int $anios): int {
        if ($anios < 1) return 0;

        return match (true) {
            $anios === 1 => 12,
            $anios === 2 => 14,
            $anios === 3 => 16,
            $anios === 4 => 18,
            $anios === 5 => 20,
            $anios >= 6  => 22 + (int)floor(($anios - 6) / 5) * 2,
            default      => 0,
        };
    }
}

if (!function_exists('obtener_periodo_vacacional_actual')) {
    function obtener_periodo_vacacional_actual(?string $fechaIngreso): ?array {
        if (vac_empty_date($fechaIngreso)) return null;

        try {
            $ingreso = new DateTime($fechaIngreso);
            $hoy     = new DateTime();

            $anioActual = (int)$hoy->format('Y');
            $mesDiaIngreso = $ingreso->format('m-d');

            $aniversarioEsteAnio = new DateTime($anioActual . '-' . $mesDiaIngreso);

            if ($hoy >= $aniversarioEsteAnio) {
                $inicioPeriodo = clone $aniversarioEsteAnio;
            } else {
                $inicioPeriodo = (clone $aniversarioEsteAnio)->modify('-1 year');
            }

            $finPeriodo = (clone $inicioPeriodo)->modify('+1 year')->modify('-1 day');
            $aniosCumplidosAlInicio = (int)$ingreso->diff($inicioPeriodo)->y;
            $proximoAniversario = (clone $inicioPeriodo)->modify('+1 year');

            return [
                'inicio' => $inicioPeriodo->format('Y-m-d'),
                'fin' => $finPeriodo->format('Y-m-d'),
                'anios_cumplidos' => $aniosCumplidosAlInicio,
                'proximo_aniversario' => $proximoAniversario->format('Y-m-d'),
                'label' => $inicioPeriodo->format('d/m/Y') . ' al ' . $finPeriodo->format('d/m/Y'),
            ];
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('obtener_fecha_ingreso_usuario')) {
    function obtener_fecha_ingreso_usuario(mysqli $conn, int $usuarioId): ?string {
        $sql = "SELECT fecha_ingreso FROM usuarios_expediente WHERE usuario_id = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $fechaIngreso = $row['fecha_ingreso'] ?? null;
        return vac_empty_date($fechaIngreso) ? null : $fechaIngreso;
    }
}

if (!function_exists('obtener_dias_tomados_periodo')) {
    function obtener_dias_tomados_periodo(mysqli $conn, int $usuarioId, string $periodoInicio, string $periodoFin, ?int $excluirSolicitudId = null): int {
        $sql = "
            SELECT COALESCE(SUM(dias), 0) AS dias_tomados
            FROM vacaciones_solicitudes
            WHERE id_usuario = ?
              AND LOWER(COALESCE(status_admin,'')) = 'aprobado'
              AND fecha_inicio >= ?
              AND fecha_inicio <= ?
        ";

        $types = 'iss';
        $params = [$usuarioId, $periodoInicio, $periodoFin];

        if ($excluirSolicitudId !== null && $excluirSolicitudId > 0) {
            $sql .= " AND id <> ? ";
            $types .= 'i';
            $params[] = $excluirSolicitudId;
        }

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)($row['dias_tomados'] ?? 0);
    }
}

if (!function_exists('obtener_resumen_vacaciones_usuario')) {
    function obtener_resumen_vacaciones_usuario(mysqli $conn, int $usuarioId, ?int $excluirSolicitudId = null): array {
        $fechaIngreso = obtener_fecha_ingreso_usuario($conn, $usuarioId);

        $base = [
            'ok' => false,
            'usuario_id' => $usuarioId,
            'fecha_ingreso' => $fechaIngreso,
            'periodo' => null,
            'anios_cumplidos' => 0,
            'dias_otorgados' => 0,
            'dias_tomados' => 0,
            'dias_disponibles' => 0,
            'vigencia_inicio' => null,
            'vigencia_fin' => null,
            'vigencia_texto' => '—',
            'mensaje' => '',
        ];

        if (!$fechaIngreso) {
            $base['mensaje'] = 'El usuario no tiene fecha de ingreso válida.';
            return $base;
        }

        $periodo = obtener_periodo_vacacional_actual($fechaIngreso);
        if (!$periodo) {
            $base['mensaje'] = 'No fue posible calcular el periodo vacacional.';
            return $base;
        }

        $aniosCumplidos = (int)$periodo['anios_cumplidos'];
        $diasOtorgados  = vacaciones_dias_por_anios_servicio($aniosCumplidos);
        $diasTomados    = obtener_dias_tomados_periodo(
            $conn,
            $usuarioId,
            $periodo['inicio'],
            $periodo['fin'],
            $excluirSolicitudId
        );
        $diasDisponibles = max(0, $diasOtorgados - $diasTomados);

        return [
            'ok' => true,
            'usuario_id' => $usuarioId,
            'fecha_ingreso' => $fechaIngreso,
            'periodo' => $periodo,
            'anios_cumplidos' => $aniosCumplidos,
            'dias_otorgados' => $diasOtorgados,
            'dias_tomados' => $diasTomados,
            'dias_disponibles' => $diasDisponibles,
            'vigencia_inicio' => $periodo['inicio'],
            'vigencia_fin' => $periodo['fin'],
            'vigencia_texto' => $periodo['label'],
            'mensaje' => '',
        ];
    }
}

if (!function_exists('calcular_dias_solicitud_rango')) {
    function calcular_dias_solicitud_rango(string $fechaInicio, string $fechaFin): int {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaInicio) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaFin)) {
            return 0;
        }

        if ($fechaFin < $fechaInicio) {
            return 0;
        }

        try {
            $d1 = new DateTime($fechaInicio);
            $d2 = new DateTime($fechaFin);
            return (int)$d1->diff($d2)->days + 1;
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('calcular_fecha_retorno_vacaciones')) {
    function calcular_fecha_retorno_vacaciones(string $fechaFin): ?string {
        if (vac_empty_date($fechaFin)) return null;

        try {
            $f = new DateTime($fechaFin);
            $f->modify('+1 day');
            return $f->format('Y-m-d');
        } catch (Throwable $e) {
            return null;
        }
    }
}