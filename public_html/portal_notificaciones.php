<?php
// portal_notificaciones.php
// Helper de correos para Portal Intercentrales

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/mail_hostinger.php';

/* =========================================================
   CONFIG
========================================================= */

// Sistemas
const PORTAL_SISTEMAS_IDS = [6];

// Costos
const PORTAL_COSTOS_IDS = [66, 8, 7];

// URL base de LUGA para links internos
const PORTAL_LUGA_BASE_URL = 'https://lugaph.site';

/* =========================================================
   HELPERS
========================================================= */

function portal_h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function portal_get_user_emails_by_ids(mysqli $conn, array $ids): array {
    $ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
    if (!$ids) return [];

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    $sql = "SELECT correo
            FROM usuarios
            WHERE id IN ($placeholders)
              AND correo IS NOT NULL
              AND correo <> ''";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $rs = $stmt->get_result();

    $emails = [];
    while ($row = $rs->fetch_assoc()) {
        $mail = trim((string)($row['correo'] ?? ''));
        if ($mail !== '') $emails[] = $mail;
    }
    $stmt->close();

    return array_values(array_unique($emails));
}

function portal_get_sistemas_emails(mysqli $conn): array {
    return portal_get_user_emails_by_ids($conn, PORTAL_SISTEMAS_IDS);
}

function portal_get_costos_emails(mysqli $conn): array {
    return portal_get_user_emails_by_ids($conn, PORTAL_COSTOS_IDS);
}

function portal_get_solicitante_email(mysqli $conn, array $sol): array {
    $emails = [];

    $mailDirecto = trim((string)($sol['solicitante_correo'] ?? ''));
    if ($mailDirecto !== '') {
        $emails[] = $mailDirecto;
    }

    $usuarioId = (int)($sol['usuario_solicitante_id'] ?? 0);
    if ($usuarioId > 0) {
        $stmt = $conn->prepare("SELECT correo FROM usuarios WHERE id=? LIMIT 1");
        $stmt->bind_param("i", $usuarioId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $mailUsuario = trim((string)($row['correo'] ?? ''));
        if ($mailUsuario !== '') {
            $emails[] = $mailUsuario;
        }
    }

    return array_values(array_unique($emails));
}

function portal_detalle_url(int $solicitudId): string {
    return rtrim(PORTAL_LUGA_BASE_URL, '/') . '/portal_proyectos_detalle.php?id=' . $solicitudId;
}

function portal_send_mail(array $to, string $subject, string $htmlBody): bool {
    $to = array_values(array_unique(array_filter(array_map('trim', $to))));
    if (!$to) {
        error_log('PORTAL send mail: lista TO vacía');
        return false;
    }

    $okGlobal = true;
    $textBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody)));

    foreach ($to as $email) {
        error_log('PORTAL send mail: enviando a ' . $email . ' asunto=' . $subject);

        $resp = send_mail_hostinger([
            'to'      => $email,
            'subject' => $subject,
            'html'    => $htmlBody,
            'text'    => $textBody,
        ]);

        error_log('PORTAL send mail response [' . $email . ']: ' . json_encode($resp));

        if (empty($resp['ok'])) {
            $okGlobal = false;
            error_log('PORTAL MAIL ERROR [' . $email . ']: ' . ($resp['error'] ?? 'Error desconocido'));
        }
    }

    return $okGlobal;
}

function portal_mail_template(string $title, array $rows, string $ctaText = 'Ver detalle', string $ctaUrl = ''): string {
    $rowsHtml = '';
    foreach ($rows as $label => $value) {
        $rowsHtml .= '
          <tr>
            <td style="padding:8px 0; color:#6c757d; width:160px; vertical-align:top;"><b>' . portal_h($label) . '</b></td>
            <td style="padding:8px 0; color:#212529;">' . nl2br(portal_h((string)$value)) . '</td>
          </tr>';
    }

    $cta = '';
    if ($ctaUrl !== '') {
        $cta = '
          <div style="margin-top:20px;">
            <a href="' . portal_h($ctaUrl) . '" style="display:inline-block; background:#0d6efd; color:#fff; text-decoration:none; padding:12px 18px; border-radius:10px; font-weight:600;">
              ' . portal_h($ctaText) . '
            </a>
          </div>';
    }

    return '
    <div style="background:#f5f7fb; padding:24px; font-family:Arial,Helvetica,sans-serif;">
      <div style="max-width:760px; margin:0 auto; background:#ffffff; border-radius:16px; padding:24px; border:1px solid rgba(0,0,0,.08);">
        <div style="font-size:22px; font-weight:700; color:#212529; margin-bottom:16px;">' . portal_h($title) . '</div>
        <table style="width:100%; border-collapse:collapse;">' . $rowsHtml . '</table>
        ' . $cta . '
      </div>
    </div>';
}

/* =========================================================
   CARGA DE DATOS DE SOLICITUD
========================================================= */

function portal_get_solicitud_basica(mysqli $conn, int $solicitudId): ?array {
    $stmt = $conn->prepare("
        SELECT s.id, s.folio, s.titulo, s.descripcion, s.tipo, s.prioridad, s.estatus,
               s.costo_mxn, s.solicitante_nombre, s.solicitante_correo, s.usuario_solicitante_id,
               s.created_at, s.updated_at,
               e.clave AS empresa_clave, e.nombre AS empresa_nombre
        FROM portal_proyectos_solicitudes s
        JOIN portal_empresas e ON e.id = s.empresa_id
        WHERE s.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $solicitudId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function portal_get_ultima_revision(mysqli $conn, int $solicitudId): ?array {
    $stmt = $conn->prepare("
        SELECT r.version, r.horas_min, r.horas_max, r.plan_acciones, r.riesgos_dependencias,
               r.created_at, u.nombre AS usuario_nombre
        FROM portal_proyectos_revision_sistemas r
        LEFT JOIN usuarios u ON u.id = r.usuario_sistemas_id
        WHERE r.solicitud_id = ?
        ORDER BY r.version DESC, r.id DESC
        LIMIT 1
    ");
    $stmt->bind_param("i", $solicitudId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function portal_get_ultimo_costeo(mysqli $conn, int $solicitudId): ?array {
    $stmt = $conn->prepare("
        SELECT c.version, c.costo_mxn, c.desglose, c.condiciones,
               c.created_at, u.nombre AS usuario_nombre
        FROM portal_proyectos_costeo c
        LEFT JOIN usuarios u ON u.id = c.usuario_validador_id
        WHERE c.solicitud_id = ?
        ORDER BY c.version DESC, c.id DESC
        LIMIT 1
    ");
    $stmt->bind_param("i", $solicitudId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

/* =========================================================
   NOTIFICACIONES
========================================================= */

function portal_notify_nueva_solicitud(mysqli $conn, int $solicitudId): bool {
    $sol = portal_get_solicitud_basica($conn, $solicitudId);
    if (!$sol) {
        error_log('PORTAL notify nueva: solicitud no encontrada ID ' . $solicitudId);
        return false;
    }

    $to = portal_get_sistemas_emails($conn);
    error_log('PORTAL notify nueva: destinatarios sistemas = ' . json_encode($to));

    if (!$to) {
        error_log('PORTAL notify nueva: sin destinatarios para sistemas');
        return false;
    }

    $subject = '[Portal Intercentrales] Nueva solicitud ' . $sol['folio'];
    $html = portal_mail_template(
        'Nueva solicitud para revisión de Sistemas',
        [
            'Folio'       => $sol['folio'],
            'Empresa'     => $sol['empresa_clave'] . ' - ' . $sol['empresa_nombre'],
            'Título'      => $sol['titulo'],
            'Tipo'        => $sol['tipo'],
            'Prioridad'   => $sol['prioridad'],
            'Solicitante' => $sol['solicitante_nombre'],
            'Descripción' => $sol['descripcion'],
        ],
        'Ver solicitud',
        portal_detalle_url((int)$sol['id'])
    );

    $ok = portal_send_mail($to, $subject, $html);
    error_log('PORTAL notify nueva: resultado envio = ' . ($ok ? 'OK' : 'FAIL'));

    return $ok;
}

function portal_notify_enviado_a_costeo(mysqli $conn, int $solicitudId): bool {
    $sol = portal_get_solicitud_basica($conn, $solicitudId);
    $rev = portal_get_ultima_revision($conn, $solicitudId);
    if (!$sol) return false;

    $to = portal_get_costos_emails($conn);
    if (!$to) return false;

    $subject = '[Portal Intercentrales] Solicitud enviada a Costeo ' . $sol['folio'];
    $html = portal_mail_template(
        'Solicitud enviada a Costeo',
        [
            'Folio'            => $sol['folio'],
            'Empresa'          => $sol['empresa_clave'] . ' - ' . $sol['empresa_nombre'],
            'Título'           => $sol['titulo'],
            'Prioridad'        => $sol['prioridad'],
            'Horas estimadas'  => $rev ? (($rev['horas_min'] ?? '—') . ' - ' . ($rev['horas_max'] ?? '—')) : '—',
            'Plan de acciones' => $rev['plan_acciones'] ?? '—',
            'Riesgos'          => $rev['riesgos_dependencias'] ?? '—',
        ],
        'Ver solicitud',
        portal_detalle_url((int)$sol['id'])
    );

    return portal_send_mail($to, $subject, $html);
}

function portal_notify_costo_capturado(mysqli $conn, int $solicitudId): bool {
    $sol  = portal_get_solicitud_basica($conn, $solicitudId);
    $cost = portal_get_ultimo_costeo($conn, $solicitudId);
    if (!$sol) return false;

    $to = portal_get_sistemas_emails($conn);
    if (!$to) return false;

    $subject = '[Portal Intercentrales] Costo capturado para revisión ' . $sol['folio'];
    $html = portal_mail_template(
        'Costeo capturado, pendiente de validación por Sistemas',
        [
            'Folio'       => $sol['folio'],
            'Empresa'     => $sol['empresa_clave'] . ' - ' . $sol['empresa_nombre'],
            'Título'      => $sol['titulo'],
            'Costo'       => isset($cost['costo_mxn']) ? ('$' . number_format((float)$cost['costo_mxn'], 2) . ' MXN') : '—',
            'Desglose'    => $cost['desglose'] ?? '—',
            'Condiciones' => $cost['condiciones'] ?? '—',
        ],
        'Revisar solicitud',
        portal_detalle_url((int)$sol['id'])
    );

    return portal_send_mail($to, $subject, $html);
}

function portal_notify_lista_para_aprobacion(mysqli $conn, int $solicitudId): bool {
    $sol  = portal_get_solicitud_basica($conn, $solicitudId);
    $rev  = portal_get_ultima_revision($conn, $solicitudId);
    $cost = portal_get_ultimo_costeo($conn, $solicitudId);
    if (!$sol) return false;

    $to = portal_get_solicitante_email($conn, $sol);
    if (!$to) return false;

    $subject = '[Portal Intercentrales] Solicitud lista para aprobación ' . $sol['folio'];
    $html = portal_mail_template(
        'Tu solicitud está lista para aprobación',
        [
            'Folio'            => $sol['folio'],
            'Empresa'          => $sol['empresa_clave'] . ' - ' . $sol['empresa_nombre'],
            'Título'           => $sol['titulo'],
            'Horas estimadas'  => $rev ? (($rev['horas_min'] ?? '—') . ' - ' . ($rev['horas_max'] ?? '—')) : '—',
            'Plan de acciones' => $rev['plan_acciones'] ?? '—',
            'Riesgos'          => $rev['riesgos_dependencias'] ?? '—',
            'Costo'            => isset($cost['costo_mxn']) ? ('$' . number_format((float)$cost['costo_mxn'], 2) . ' MXN') : '—',
            'Desglose'         => $cost['desglose'] ?? '—',
            'Condiciones'      => $cost['condiciones'] ?? '—',
        ],
        'Revisar y responder',
        portal_detalle_url((int)$sol['id'])
    );

    return portal_send_mail($to, $subject, $html);
}

function portal_notify_solicitante_respuesta(mysqli $conn, int $solicitudId, string $accion, string $comentario = ''): bool {
    $sol  = portal_get_solicitud_basica($conn, $solicitudId);
    $cost = portal_get_ultimo_costeo($conn, $solicitudId);
    if (!$sol) return false;

    $to = array_values(array_unique(array_merge(
        portal_get_sistemas_emails($conn),
        portal_get_costos_emails($conn)
    )));
    if (!$to) return false;

    $accion = strtoupper(trim($accion));
    $esAutorizado = ($accion === 'AUTORIZAR');

    $subject = $esAutorizado
        ? '[Portal Intercentrales] Solicitud autorizada por solicitante ' . $sol['folio']
        : '[Portal Intercentrales] Solicitud rechazada por solicitante ' . $sol['folio'];

    $html = portal_mail_template(
        $esAutorizado ? 'La solicitud fue autorizada por el solicitante' : 'La solicitud fue rechazada por el solicitante',
        [
            'Folio'       => $sol['folio'],
            'Empresa'     => $sol['empresa_clave'] . ' - ' . $sol['empresa_nombre'],
            'Título'      => $sol['titulo'],
            'Costo'       => isset($cost['costo_mxn']) ? ('$' . number_format((float)$cost['costo_mxn'], 2) . ' MXN') : '—',
            'Comentario'  => $comentario !== '' ? $comentario : 'Sin comentario',
            'Estatus'     => $esAutorizado ? 'AUTORIZADO' : 'RECHAZADO',
        ],
        'Ver solicitud',
        portal_detalle_url((int)$sol['id'])
    );

    return portal_send_mail($to, $subject, $html);
}