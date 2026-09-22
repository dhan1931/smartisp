<?php
/**
 * SmartISP - Motor Autónomo de Despacho de Correos Electrónicos
 * 
 * Soporta:
 * 1. Conexión SMTP directa vía sockets seguros (Hostinger, Gmail, cPanel, etc.)
 * 2. Fallback automático a mail() nativo de PHP
 * 3. Plantilla de comprobante para el cliente (Receipt)
 * 4. Plantilla de alerta operacional con botones de llamada y WhatsApp para el administrador
 */

// ------------------------------------------------------------------
// 1. OBTENCIÓN DE CONFIGURACIÓN DE CORREO DESDE LA BASE DE DATOS
// ------------------------------------------------------------------
function getMailSettings(PDO $pdo, ?array $override = null): array {
    $defaults = [
        'admin_email'   => 'admin@smart-isp.com.ec',
        'smtp_provider' => 'hostinger',
        'smtp_host'     => 'smtp.hostinger.com',
        'smtp_port'     => 465,
        'smtp_user'     => '',
        'smtp_pass'     => '',
        'smtp_from'     => 'SmartISP <notificaciones@smart-isp.com.ec>',
        'smtp_secure'   => 'true'
    ];

    try {
        $stmt = $pdo->query("SELECT setting_key as `key`, setting_value as `value` FROM settings_rows WHERE setting_key LIKE 'smtp_%' OR setting_key = 'admin_email' OR setting_key = 'email_from'");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $r) {
            $k = $r['key'] ?? '';
            $v = trim((string)($r['value'] ?? ''));
            if ($k && $v !== '') {
                $defaults[$k] = $v;
            }
        }
    } catch (Throwable $e) {
        error_log('getMailSettings DB warning: ' . $e->getMessage());
    }

    if (!empty($defaults['email_from']) && empty($defaults['smtp_from'])) {
        $defaults['smtp_from'] = $defaults['email_from'];
    }

    if (is_array($override)) {
        foreach ($override as $ok => $ov) {
            if ($ov !== null && $ov !== '') {
                $defaults[$ok] = is_string($ov) ? trim($ov) : $ov;
            }
        }
    }

    if ($defaults['smtp_provider'] === 'gmail' && empty($defaults['smtp_host'])) {
        $defaults['smtp_host'] = 'smtp.gmail.com';
        $defaults['smtp_port'] = 465;
    } elseif ($defaults['smtp_provider'] === 'hostinger' && empty($defaults['smtp_host'])) {
        $defaults['smtp_host'] = 'smtp.hostinger.com';
        $defaults['smtp_port'] = 465;
    }

    return $defaults;
}

// ------------------------------------------------------------------
// 2. CONECTOR SMTP DIRECTO VIA SOCKETS (SIN DEPENDENCIAS EXTERNAS)
// ------------------------------------------------------------------
function smtpSendSocket(
    string $host,
    int $port,
    string $user,
    string $pass,
    string $from,
    string $to,
    string $subject,
    string $htmlBody,
    bool $isSecure = true
): array {
    $timeout = 12;
    $scheme = '';
    
    // Puerto 465 utiliza SSL implícito (ssl://)
    if ($port === 465 || $isSecure) {
        $scheme = 'ssl://';
    }

    $socketAddress = $scheme . $host . ':' . $port;
    $context = stream_context_create([
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true
        ]
    ]);

    $socket = @stream_socket_client($socketAddress, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        return ['ok' => false, 'error' => "No se pudo conectar a $socketAddress ($errno: $errstr)"];
    }

    stream_set_timeout($socket, $timeout);

    $readResponse = function() use ($socket): string {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $response;
    };

    $sendCommand = function(string $cmd, array $expectedCodes) use ($socket, $readResponse): array {
        fputs($socket, $cmd . "\r\n");
        $res = $readResponse();
        $code = (int)substr($res, 0, 3);
        if (!in_array($code, $expectedCodes, true)) {
            return ['ok' => false, 'code' => $code, 'response' => trim($res)];
        }
        return ['ok' => true, 'code' => $code, 'response' => trim($res)];
    };

    // 1. Saludo inicial del servidor
    $greet = $readResponse();
    if ((int)substr($greet, 0, 3) !== 220) {
        fclose($socket);
        return ['ok' => false, 'error' => "Saludo SMTP falló: " . trim($greet)];
    }

    // 2. EHLO
    $ehlo = $sendCommand("EHLO " . (gethostname() ?: 'smart-isp.com.ec'), [250]);
    if (!$ehlo['ok']) {
        fclose($socket);
        return ['ok' => false, 'error' => "EHLO rechazado: " . $ehlo['response']];
    }

    // 3. STARTTLS si es puerto 587
    if ($port === 587 && strpos($scheme, 'ssl') === false) {
        $tls = $sendCommand("STARTTLS", [220]);
        if ($tls['ok']) {
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                fclose($socket);
                return ['ok' => false, 'error' => 'Fallo al establecer cifrado TLS'];
            }
            $ehlo2 = $sendCommand("EHLO " . (gethostname() ?: 'smart-isp.com.ec'), [250]);
            if (!$ehlo2['ok']) {
                fclose($socket);
                return ['ok' => false, 'error' => "EHLO pos-TLS rechazado: " . $ehlo2['response']];
            }
        }
    }

    // 4. Autenticación AUTH LOGIN si hay credenciales
    if ($user !== '' && $pass !== '') {
        $auth = $sendCommand("AUTH LOGIN", [334]);
        if (!$auth['ok']) {
            fclose($socket);
            return ['ok' => false, 'error' => "AUTH LOGIN no soportado: " . $auth['response']];
        }
        $usrRes = $sendCommand(base64_encode($user), [334]);
        if (!$usrRes['ok']) {
            fclose($socket);
            return ['ok' => false, 'error' => "Usuario SMTP rechazado: " . $usrRes['response']];
        }
        $pwdRes = $sendCommand(base64_encode($pass), [235]);
        if (!$pwdRes['ok']) {
            fclose($socket);
            return ['ok' => false, 'error' => "Contraseña SMTP incorrecta para $user: " . $pwdRes['response']];
        }
    }

    // 5. Extraer correo limpio para MAIL FROM
    $fromEmail = $user;
    if (preg_match('/<([^>]+)>/', $from, $m)) {
        $fromEmail = trim($m[1]);
    } elseif (filter_var($from, FILTER_VALIDATE_EMAIL)) {
        $fromEmail = trim($from);
    }
    if (!$fromEmail) $fromEmail = $user;

    // 6. MAIL FROM
    $mf = $sendCommand("MAIL FROM: <$fromEmail>", [250]);
    if (!$mf['ok']) {
        fclose($socket);
        return ['ok' => false, 'error' => "MAIL FROM rechazado: " . $mf['response']];
    }

    // 7. RCPT TO
    $toEmail = $to;
    if (preg_match('/<([^>]+)>/', $to, $m)) {
        $toEmail = trim($m[1]);
    }
    $rcpt = $sendCommand("RCPT TO: <$toEmail>", [250, 251]);
    if (!$rcpt['ok']) {
        fclose($socket);
        return ['ok' => false, 'error' => "Destinatario rechazado por el servidor: " . $rcpt['response']];
    }

    // 8. DATA
    $data = $sendCommand("DATA", [354]);
    if (!$data['ok']) {
        fclose($socket);
        return ['ok' => false, 'error' => "Comando DATA rechazado: " . $data['response']];
    }

    // 9. Construir cabeceras MIME y cuerpo
    $date = date(DATE_RFC2822);
    $b64Subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $messageId = '<' . md5(uniqid((string)microtime(true), true)) . '@' . (parse_url('https://' . ($host ?: 'smart-isp.com.ec'), PHP_URL_HOST) ?: 'smart-isp.com.ec') . '>';

    $headers = [];
    $headers[] = "Date: $date";
    $headers[] = "From: $from";
    $headers[] = "To: $to";
    $headers[] = "Subject: $b64Subject";
    $headers[] = "Message-ID: $messageId";
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Content-Type: text/html; charset=UTF-8";
    $headers[] = "Content-Transfer-Encoding: 8bit";
    $headers[] = "X-Mailer: SmartISP DirectMailer/2.0";

    $payload = implode("\r\n", $headers) . "\r\n\r\n" . $htmlBody . "\r\n.";
    fputs($socket, $payload . "\r\n");

    $dataRes = $readResponse();
    $dataCode = (int)substr($dataRes, 0, 3);
    if ($dataCode !== 250) {
        fclose($socket);
        return ['ok' => false, 'error' => "Envío de mensaje falló: " . trim($dataRes)];
    }

    // 10. QUIT
    $sendCommand("QUIT", [221]);
    fclose($socket);

    return ['ok' => true, 'message' => 'Despachado exitosamente por SMTP'];
}

// ------------------------------------------------------------------
// 3. DESPACHADOR UNIVERSAL (SMTP CON FALLBACK A PHP MAIL)
// ------------------------------------------------------------------
function sendSmartEmail(
    PDO $pdo,
    string $to,
    string $subject,
    string $htmlBody,
    ?array $overrideConfig = null
): array {
    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => "Dirección de correo inválida: '$to'"];
    }

    $cfg = getMailSettings($pdo, $overrideConfig);
    $host = trim($cfg['smtp_host'] ?? '');
    $user = trim($cfg['smtp_user'] ?? '');
    $pass = trim($cfg['smtp_pass'] ?? '');
    $port = (int)($cfg['smtp_port'] ?? 465);
    $from = trim($cfg['smtp_from'] ?? 'SmartISP <notificaciones@smart-isp.com.ec>');
    $isSecure = ($cfg['smtp_secure'] === 'true' || $cfg['smtp_secure'] === true || $port === 465);

    // Si hay usuario y host configurados, intentar SMTP directo
    if ($host !== '' && $user !== '') {
        $smtpResult = smtpSendSocket($host, $port, $user, $pass, $from, $to, $subject, $htmlBody, $isSecure);
        if ($smtpResult['ok']) {
            return [
                'ok'        => true,
                'transport' => 'smtp',
                'recipient' => $to,
                'provider'  => $cfg['smtp_provider'] ?? 'custom'
            ];
        }
        error_log("SmartISP SMTP warning para $to: " . ($smtpResult['error'] ?? ''));
    }

    // Fallback a mail() nativo de PHP
    $b64Subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'From: ' . $from,
        'Reply-To: ' . ($cfg['admin_email'] ?: $from),
        'X-Mailer: SmartISP Fallback Mailer/1.0'
    ];

    $mailOk = @mail($to, $b64Subject, $htmlBody, implode("\r\n", $headers));
    if ($mailOk) {
        return [
            'ok'        => true,
            'transport' => 'php_mail',
            'recipient' => $to,
            'note'      => 'Despachado vía servidor local PHP mail()'
        ];
    }

    return [
        'ok'    => false,
        'error' => "No se pudo despachar el correo a $to (falló SMTP y mail() nativo)."
    ];
}

// ------------------------------------------------------------------
// 4. PLANTILLA HTML: COMPROBANTE DE COMPRA PARA EL CLIENTE
// ------------------------------------------------------------------
function buildCustomerReceiptHtml(array $order): string {
    $orderId = htmlspecialchars($order['id'] ?? 'PED-001', ENT_QUOTES, 'UTF-8');
    $customerName = htmlspecialchars($order['customerName'] ?? ($order['name'] ?? 'Cliente Estimado'), ENT_QUOTES, 'UTF-8');
    $customerEmail = htmlspecialchars($order['customerEmail'] ?? ($order['email'] ?? ''), ENT_QUOTES, 'UTF-8');
    $customerPhone = htmlspecialchars($order['customerPhone'] ?? ($order['phone'] ?? ''), ENT_QUOTES, 'UTF-8');
    $customerAddress = htmlspecialchars($order['address'] ?? '', ENT_QUOTES, 'UTF-8');
    $customerCity = htmlspecialchars($order['city'] ?? '', ENT_QUOTES, 'UTF-8');
    $customerNotes = htmlspecialchars($order['notes'] ?? '', ENT_QUOTES, 'UTF-8');
    $total = (float)($order['total'] ?? 0);
    $items = is_array($order['items'] ?? null) ? $order['items'] : [];
    $dateFormatted = date('d/m/Y - H:i') . ' (Ecuador)';

    $itemsRowsHtml = '';
    foreach ($items as $item) {
        $iName = htmlspecialchars($item['name'] ?? 'Producto TI', ENT_QUOTES, 'UTF-8');
        $iCategory = htmlspecialchars($item['category'] ?? 'Equipamiento', ENT_QUOTES, 'UTF-8');
        $iQty = (int)($item['quantity'] ?? 1);
        $iPrice = (float)($item['price'] ?? 0);
        $iSubtotal = $iQty * $iPrice;
        $priceText = $iPrice > 0 ? '$' . number_format($iPrice, 2) : 'Cotizar';
        $subtotalText = $iSubtotal > 0 ? '$' . number_format($iSubtotal, 2) : 'A convenir';

        $itemsRowsHtml .= "
        <tr>
            <td style=\"padding: 12px 14px; border-bottom: 1px solid #e6f0f3;\">
                <strong style=\"color: #102c3d; font-size: 14px;\">$iName</strong><br>
                <span style=\"color: #6b7f88; font-size: 12px;\">$iCategory</span>
            </td>
            <td style=\"padding: 12px 14px; border-bottom: 1px solid #e6f0f3; text-align: center; color: #163342; font-size: 14px; font-weight: 600;\">
                $iQty
            </td>
            <td style=\"padding: 12px 14px; border-bottom: 1px solid #e6f0f3; text-align: right; color: #163342; font-size: 14px;\">
                $priceText
            </td>
            <td style=\"padding: 12px 14px; border-bottom: 1px solid #e6f0f3; text-align: right; color: #087ea4; font-size: 14px; font-weight: 700;\">
                $subtotalText
            </td>
        </tr>";
    }

    $totalDisplay = $total > 0 ? '$' . number_format($total, 2) : 'A coordinar';

    $shippingInfoHtml = '';
    if ($customerAddress || $customerCity || $customerPhone) {
        $shippingInfoHtml = "
        <div style=\"background-color: #f3f9f8; border: 1px solid #cce8e2; border-radius: 10px; padding: 16px 20px; margin-top: 24px;\">
            <h4 style=\"margin: 0 0 10px; color: #087ea4; font-size: 14px; text-transform: uppercase; letter-spacing: 0.05em;\">
                📍 Información de Entrega y Contacto
            </h4>
            <table style=\"width: 100%; font-size: 13px; color: #163342; border-collapse: collapse;\">
                " . ($customerCity ? "<tr><td style=\"padding: 3px 0; width: 110px; color: #6b7f88;\"><b>Ciudad:</b></td><td>$customerCity</td></tr>" : "") . "
                " . ($customerAddress ? "<tr><td style=\"padding: 3px 0; color: #6b7f88;\"><b>Dirección:</b></td><td>$customerAddress</td></tr>" : "") . "
                " . ($customerPhone ? "<tr><td style=\"padding: 3px 0; color: #6b7f88;\"><b>Teléfono:</b></td><td>$customerPhone</td></tr>" : "") . "
                " . ($customerNotes ? "<tr><td style=\"padding: 3px 0; color: #6b7f88;\"><b>Indicaciones:</b></td><td>$customerNotes</td></tr>" : "") . "
            </table>
        </div>";
    }

    return "
    <!DOCTYPE html>
    <html lang=\"es\">
    <head><meta charset=\"UTF-8\"></head>
    <body style=\"margin: 0; padding: 20px 10px; background-color: #f0f4f6; font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, Roboto, Helvetica, Arial, sans-serif; color: #163342;\">
        <table align=\"center\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"max-width: 620px; margin: 0 auto; background-color: #ffffff; border-radius: 14px; overflow: hidden; box-shadow: 0 10px 30px rgba(16,44,61,0.08); border: 1px solid #d8e5e7;\">
            <!-- CABECERA -->
            <tr>
                <td style=\"background: linear-gradient(135deg, #102c3d 0%, #087ea4 100%); padding: 32px 28px; text-align: center;\">
                    <div style=\"font-size: 26px; font-weight: 800; color: #ffffff; letter-spacing: -0.04em;\">
                        smart<span style=\"color: #7de3d6;\">isp</span><span style=\"color: #f5a524;\">.</span>
                    </div>
                    <div style=\"color: #d6f2ee; font-size: 13px; margin-top: 5px; letter-spacing: 0.08em; text-transform: uppercase;\">
                        Infraestructura TI & Telecomunicaciones
                    </div>
                </td>
            </tr>

            <!-- CUERPO PRINCIPAL -->
            <tr>
                <td style=\"padding: 32px 28px;\">
                    <div style=\"text-align: center; margin-bottom: 24px;\">
                        <span style=\"display: inline-block; background-color: #eaf7f5; color: #087ea4; font-size: 12px; font-weight: 700; padding: 5px 14px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.06em;\">
                            ✓ Solicitud Registrada con Éxito
                        </span>
                        <h2 style=\"margin: 12px 0 6px; color: #102c3d; font-size: 24px;\">¡Gracias por tu pedido, $customerName!</h2>
                        <p style=\"margin: 0; color: #6b7f88; font-size: 14px; line-height: 1.5;\">
                            Hemos recibido tu requerimiento y nuestro equipo comercial ya está preparando tu solicitud.
                        </p>
                    </div>

                    <!-- CAJA DE DETALLES DEL PEDIDO -->
                    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color: #f8fbfb; border: 1px solid #dce8ea; border-radius: 10px; margin-bottom: 24px;\">
                        <tr>
                            <td style=\"padding: 14px 18px;\">
                                <span style=\"color: #6b7f88; font-size: 12px; text-transform: uppercase;\">Nº de Pedido:</span><br>
                                <strong style=\"color: #102c3d; font-size: 17px;\">#$orderId</strong>
                            </td>
                            <td style=\"padding: 14px 18px; text-align: right;\">
                                <span style=\"color: #6b7f88; font-size: 12px; text-transform: uppercase;\">Fecha y Hora:</span><br>
                                <span style=\"color: #163342; font-size: 13px; font-weight: 600;\">$dateFormatted</span>
                            </td>
                        </tr>
                    </table>

                    <!-- TABLA DE ARTÍCULOS -->
                    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"border-collapse: collapse; margin-bottom: 12px;\">
                        <thead>
                            <tr style=\"background-color: #eaf3f5;\">
                                <th style=\"padding: 10px 14px; text-align: left; color: #102c3d; font-size: 12px; font-weight: 700; text-transform: uppercase; border-radius: 6px 0 0 6px;\">Producto</th>
                                <th style=\"padding: 10px 14px; text-align: center; color: #102c3d; font-size: 12px; font-weight: 700; text-transform: uppercase;\">Cant.</th>
                                <th style=\"padding: 10px 14px; text-align: right; color: #102c3d; font-size: 12px; font-weight: 700; text-transform: uppercase;\">Precio</th>
                                <th style=\"padding: 10px 14px; text-align: right; color: #102c3d; font-size: 12px; font-weight: 700; text-transform: uppercase; border-radius: 0 6px 6px 0;\">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            $itemsRowsHtml
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan=\"3\" style=\"padding: 16px 14px; text-align: right; font-size: 15px; font-weight: 700; color: #102c3d;\">
                                    Total del Pedido:
                                </td>
                                <td style=\"padding: 16px 14px; text-align: right; font-size: 20px; font-weight: 800; color: #087ea4;\">
                                    $totalDisplay
                                </td>
                            </tr>
                        </tfoot>
                    </table>

                    $shippingInfoHtml

                    <!-- PASOS SIGUIENTES -->
                    <div style=\"background-color: #f0f7fb; border-left: 4px solid #087ea4; padding: 14px 18px; border-radius: 6px; margin-top: 24px;\">
                        <strong style=\"color: #102c3d; font-size: 13.5px;\">¿Cuáles son los siguientes pasos?</strong>
                        <p style=\"margin: 6px 0 0; color: #435b71; font-size: 13px; line-height: 1.55;\">
                            1. Un asesor de SmartISP verificará la disponibilidad física en bodega.<br>
                            2. Te contactaremos vía WhatsApp o llamada telefónica para coordinar la factura electrónica y el envío seguro hasta tu dirección.
                        </p>
                    </div>

                    <!-- BOTÓN WHATSAPP -->
                    <div style=\"text-align: center; margin-top: 28px;\">
                        <a href=\"https://smart-isp.com.ec/\" style=\"display: inline-block; background-color: #087ea4; color: #ffffff; text-decoration: none; font-size: 14px; font-weight: 700; padding: 13px 26px; border-radius: 8px; box-shadow: 0 4px 14px rgba(8,126,164,0.3);\">
                            Volver a la Tienda SmartISP
                        </a>
                    </div>
                </td>
            </tr>

            <!-- FOOTER -->
            <tr>
                <td style=\"background-color: #f7fafb; border-top: 1px solid #e5eff2; padding: 22px 28px; text-align: center; font-size: 12px; color: #889ba6;\">
                    <p style=\"margin: 0 0 6px;\">
                        SmartISP · Guayaquil, Ecuador · Atención: Lun a Vie 08:30 a 18:00
                    </p>
                    <p style=\"margin: 0;\">
                        Si tienes dudas inmediatas, escríbenos a <a href=\"mailto:contacto@smart-isp.com.ec\" style=\"color: #087ea4; text-decoration: none;\">contacto@smart-isp.com.ec</a>
                    </p>
                </td>
            </tr>
        </table>
    </body>
    </html>";
}

// ------------------------------------------------------------------
// 5. PLANTILLA HTML: ALERTA OPERACIONAL PARA EL ADMINISTRADOR
// ------------------------------------------------------------------
function buildAdminAlertHtml(array $order): string {
    $orderId = htmlspecialchars($order['id'] ?? 'PED-001', ENT_QUOTES, 'UTF-8');
    $customerName = htmlspecialchars($order['customerName'] ?? ($order['name'] ?? 'Cliente Nuevo'), ENT_QUOTES, 'UTF-8');
    $customerEmail = htmlspecialchars($order['customerEmail'] ?? ($order['email'] ?? 'Sin correo'), ENT_QUOTES, 'UTF-8');
    $customerPhone = htmlspecialchars($order['customerPhone'] ?? ($order['phone'] ?? ''), ENT_QUOTES, 'UTF-8');
    $customerAddress = htmlspecialchars($order['address'] ?? 'No especificada', ENT_QUOTES, 'UTF-8');
    $customerCity = htmlspecialchars($order['city'] ?? 'No especificada', ENT_QUOTES, 'UTF-8');
    $customerNotes = htmlspecialchars($order['notes'] ?? '', ENT_QUOTES, 'UTF-8');
    $total = (float)($order['total'] ?? 0);
    $items = is_array($order['items'] ?? null) ? $order['items'] : [];
    $dateFormatted = date('d/m/Y - H:i:s') . ' (Ecuador)';

    // Limpieza de número de teléfono para enlace tel: y WhatsApp
    $rawDigits = preg_replace('/[^\d]/', '', $customerPhone);
    $waPhone = $rawDigits;
    // Si inicia con 0 (ej. 0991234567 Ecuador), convertir a 593991234567
    if (strlen($rawDigits) === 10 && substr($rawDigits, 0, 1) === '0') {
        $waPhone = '593' . substr($rawDigits, 1);
    } elseif (strlen($rawDigits) === 9 && substr($rawDigits, 0, 1) === '9') {
        $waPhone = '593' . $rawDigits;
    }

    $waMessage = "Hola $customerName, te saluda SmartISP. Te contactamos respecto a tu pedido #$orderId realizado en nuestra tienda online.";
    $waUrl = "https://wa.me/$waPhone?text=" . urlencode($waMessage);

    $itemsListHtml = '';
    foreach ($items as $item) {
        $iName = htmlspecialchars($item['name'] ?? 'Producto TI', ENT_QUOTES, 'UTF-8');
        $iQty = (int)($item['quantity'] ?? 1);
        $iPrice = (float)($item['price'] ?? 0);
        $iSub = $iQty * $iPrice;
        $itemsListHtml .= "
        <tr>
            <td style=\"padding: 8px 10px; border-bottom: 1px solid #edf3f5; color: #102c3d; font-size: 13px;\">
                <b>$iName</b>
            </td>
            <td style=\"padding: 8px 10px; border-bottom: 1px solid #edf3f5; text-align: center; color: #163342; font-size: 13px; font-weight: 600;\">
                $iQty
            </td>
            <td style=\"padding: 8px 10px; border-bottom: 1px solid #edf3f5; text-align: right; color: #087ea4; font-size: 13px; font-weight: 700;\">
                " . ($iSub > 0 ? '$' . number_format($iSub, 2) : 'Cotizar') . "
            </td>
        </tr>";
    }

    $totalDisplay = $total > 0 ? '$' . number_format($total, 2) : 'A convenir';

    return "
    <!DOCTYPE html>
    <html lang=\"es\">
    <head><meta charset=\"UTF-8\"></head>
    <body style=\"margin: 0; padding: 20px 10px; background-color: #f4f7f8; font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, Roboto, Helvetica, Arial, sans-serif;\">
        <table align=\"center\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"max-width: 640px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 12px 35px rgba(16,44,61,0.1); border: 2px solid #e1ecf0;\">
            <!-- BANNER DE ALERTA -->
            <tr>
                <td style=\"background: linear-gradient(135deg, #102c3d 0%, #0d6482 100%); padding: 24px 28px; color: #ffffff;\">
                    <span style=\"display: inline-block; background-color: #f5a524; color: #172b3a; font-size: 11px; font-weight: 800; padding: 4px 10px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 8px;\">
                        🚨 ALERTA COMERCIAL INMEDIATA
                    </span>
                    <h2 style=\"margin: 0; font-size: 22px; color: #ffffff;\">Nuevo Pedido #$orderId Registrado</h2>
                    <div style=\"color: #c9e8f5; font-size: 12.5px; margin-top: 4px;\">Recibido el $dateFormatted</div>
                </td>
            </tr>

            <!-- DATOS DEL CLIENTE CON BOTONES DE ACCIÓN RÁPIDA -->
            <tr>
                <td style=\"padding: 26px 28px;\">
                    <div style=\"background-color: #f0fdf4; border: 2px solid #86efac; border-radius: 10px; padding: 18px 20px; margin-bottom: 22px;\">
                        <div style=\"color: #166534; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 10px;\">
                            👤 Datos de Contacto del Cliente
                        </div>
                        <table style=\"width: 100%; font-size: 14px; color: #0f172a; border-collapse: collapse;\">
                            <tr><td style=\"padding: 4px 0; width: 110px; color: #64748b;\"><b>Nombre:</b></td><td><strong style=\"color:#0f172a; font-size:15px;\">$customerName</strong></td></tr>
                            <tr><td style=\"padding: 4px 0; color: #64748b;\"><b>Celular / Tel:</b></td><td><strong style=\"color:#087ea4; font-size:16px;\">$customerPhone</strong></td></tr>
                            <tr><td style=\"padding: 4px 0; color: #64748b;\"><b>Correo:</b></td><td><a href=\"mailto:$customerEmail\" style=\"color:#087ea4; text-decoration:none;\">$customerEmail</a></td></tr>
                            <tr><td style=\"padding: 4px 0; color: #64748b;\"><b>Ciudad:</b></td><td>$customerCity</td></tr>
                            <tr><td style=\"padding: 4px 0; color: #64748b;\"><b>Dirección:</b></td><td>$customerAddress</td></tr>
                            " . ($customerNotes ? "<tr><td style=\"padding: 4px 0; color: #64748b;\"><b>Notas:</b></td><td style=\"color:#b45309;\">$customerNotes</td></tr>" : "") . "
                        </table>

                        <!-- BOTONES DE CONTACTO DIRECTO -->
                        <div style=\"margin-top: 16px; padding-top: 14px; border-top: 1px dashed #bbf7d0; display: flex; gap: 10px;\">
                            " . ($customerPhone ? "
                            <a href=\"tel:$rawDigits\" style=\"display: inline-block; background-color: #087ea4; color: #ffffff; text-decoration: none; font-size: 13.5px; font-weight: 700; padding: 10px 18px; border-radius: 7px; margin-right: 8px;\">
                                📞 Llamar al Cliente
                            </a>
                            <a href=\"$waUrl\" target=\"_blank\" style=\"display: inline-block; background-color: #22c55e; color: #ffffff; text-decoration: none; font-size: 13.5px; font-weight: 700; padding: 10px 18px; border-radius: 7px;\">
                                💬 Abrir WhatsApp
                            </a>" : "<span style=\"color:#94a3b8; font-size:12px;\">Sin número de celular registrado</span>") . "
                        </div>
                    </div>

                    <!-- DESGLOSE DE PRODUCTOS DEL PEDIDO -->
                    <h3 style=\"margin: 0 0 10px; color: #102c3d; font-size: 15px;\">Artículos Solicitados:</h3>
                    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"border-collapse: collapse; margin-bottom: 20px; background-color: #fafcfc; border: 1px solid #e5edf0; border-radius: 8px;\">
                        <thead>
                            <tr style=\"background-color: #edf4f6;\">
                                <th style=\"padding: 8px 10px; text-align: left; font-size: 12px; color: #102c3d;\">Ítem</th>
                                <th style=\"padding: 8px 10px; text-align: center; font-size: 12px; color: #102c3d;\">Cantidad</th>
                                <th style=\"padding: 8px 10px; text-align: right; font-size: 12px; color: #102c3d;\">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            $itemsListHtml
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan=\"2\" style=\"padding: 12px 10px; text-align: right; font-weight: 700; font-size: 14px; color: #102c3d;\">Total del Pedido:</td>
                                <td style=\"padding: 12px 10px; text-align: right; font-weight: 800; font-size: 17px; color: #087ea4;\">$totalDisplay</td>
                            </tr>
                        </tfoot>
                    </table>

                    <!-- ACCESO AL PANEL DE ADMINISTRACIÓN -->
                    <div style=\"text-align: center; margin-top: 24px;\">
                        <a href=\"https://smart-isp.com.ec/admin.html\" style=\"display: inline-block; background-color: #102c3d; color: #ffffff; text-decoration: none; font-size: 13.5px; font-weight: 700; padding: 12px 24px; border-radius: 8px;\">
                            Ir al Panel de Administración de SmartISP
                        </a>
                    </div>
                </td>
            </tr>

            <!-- PIE DE CORREO -->
            <tr>
                <td style=\"background-color: #f7fafb; border-top: 1px solid #e8eff2; padding: 16px 28px; text-align: center; font-size: 12px; color: #94a3b8;\">
                    Notificación automática generada por el sistema web de SmartISP (smart-isp.com.ec)
                </td>
            </tr>
        </table>
    </body>
    </html>";
}

// ------------------------------------------------------------------
// 6. CONTROLADOR DE DESPACHO DUAL (CLIENTE + ADMINISTRADOR)
// ------------------------------------------------------------------
function sendOrderEmails(PDO $pdo, array $orderData): array {
    $results = [
        'customer' => null,
        'admin'    => null
    ];

    $customerEmail = trim($orderData['customerEmail'] ?? ($orderData['email'] ?? ''));
    $customerName  = trim($orderData['customerName'] ?? ($orderData['name'] ?? 'Cliente'));
    $orderId       = trim($orderData['id'] ?? 'PED-001');

    // 1. Envío al Cliente
    if ($customerEmail !== '' && filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        try {
            $customerHtml = buildCustomerReceiptHtml($orderData);
            $results['customer'] = sendSmartEmail(
                $pdo,
                $customerEmail,
                "🧾 Comprobante de Compra #$orderId - SmartISP",
                $customerHtml
            );
        } catch (Throwable $e) {
            error_log("Fallo al enviar correo al cliente ($customerEmail): " . $e->getMessage());
            $results['customer'] = ['ok' => false, 'error' => $e->getMessage()];
        }
    } else {
        $results['customer'] = ['ok' => false, 'error' => 'Correo de cliente no válido o ausente'];
    }

    // 2. Envío al Administrador
    $cfg = getMailSettings($pdo);
    $adminEmail = trim($cfg['admin_email'] ?? 'admin@smart-isp.com.ec');
    if ($adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        try {
            $adminHtml = buildAdminAlertHtml($orderData);
            $results['admin'] = sendSmartEmail(
                $pdo,
                $adminEmail,
                "🚨 NUEVO PEDIDO #$orderId - Asesoría Requerida: $customerName",
                $adminHtml
            );
        } catch (Throwable $e) {
            error_log("Fallo al enviar correo al administrador ($adminEmail): " . $e->getMessage());
            $results['admin'] = ['ok' => false, 'error' => $e->getMessage()];
        }
    } else {
        $results['admin'] = ['ok' => false, 'error' => 'Correo de administrador no configurado'];
    }

    return $results;
}

// ------------------------------------------------------------------
// 7. DIAGNÓSTICO Y CORREO DE PRUEBA DESDE EL PANEL ADMIN
// ------------------------------------------------------------------
function testEmailConnection(PDO $pdo, ?array $overrideConfig = null, ?string $testTo = null): array {
    $cfg = getMailSettings($pdo, $overrideConfig);
    $recipient = trim($testTo ?: ($cfg['admin_email'] ?? ''));

    if (!$recipient || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        return [
            'ok'    => false,
            'error' => 'No hay una dirección de correo válida configurada como administrador para recibir la prueba.'
        ];
    }

    $subject = '🧪 Correo de Prueba - Sistema de Notificaciones SmartISP';
    $date = date('d/m/Y H:i:s');
    $provider = htmlspecialchars($cfg['smtp_provider'] ?? 'personalizado', ENT_QUOTES, 'UTF-8');
    $host = htmlspecialchars($cfg['smtp_host'] ?? 'localhost', ENT_QUOTES, 'UTF-8');
    $user = htmlspecialchars($cfg['smtp_user'] ?? 'No configurado', ENT_QUOTES, 'UTF-8');

    $html = "
    <div style=\"font-family: 'Segoe UI', Roboto, sans-serif; padding: 24px; max-width: 560px; margin: 0 auto; background: #ffffff; border: 1px solid #d8e5e7; border-radius: 12px;\">
        <div style=\"background: #102c3d; padding: 18px 20px; border-radius: 8px; color: #ffffff; text-align: center;\">
            <h2 style=\"margin: 0; font-size: 20px;\">smart<span style=\"color:#087ea4;\">isp</span>.</h2>
            <div style=\"font-size: 13px; color: #d6ecf7; margin-top: 4px;\">Prueba de Conectividad de Correo</div>
        </div>
        <div style=\"padding: 20px 0;\">
            <p style=\"color: #163342; font-size: 14.5px;\">¡Felicitaciones! Si estás leyendo este correo, la configuración de despacho de SmartISP está funcionando correctamente.</p>
            <div style=\"background: #f0faf8; border-left: 4px solid #087ea4; padding: 12px 16px; border-radius: 6px; font-size: 13px; color: #163342;\">
                <b>Proveedor:</b> $provider<br>
                <b>Servidor SMTP:</b> $host<br>
                <b>Usuario remitente:</b> $user<br>
                <b>Fecha y hora:</b> $date
            </div>
        </div>
        <div style=\"border-top: 1px solid #eef2f4; padding-top: 12px; font-size: 11.5px; color: #889ba6; text-align: center;\">
            SmartISP · Panel de Administración
        </div>
    </div>";

    return sendSmartEmail($pdo, $recipient, $subject, $html, $overrideConfig);
}

// ------------------------------------------------------------------
// 8. PLANTILLA HTML: RECUPERACIÓN DE CONTRASEÑA
// ------------------------------------------------------------------
function buildPasswordResetHtml(string $email, string $resetUrl): string {
    $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');
    $year = date('Y');

    return "
    <div style=\"background-color: #eef5f6; padding: 36px 12px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;\">
        <table align=\"center\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\" width=\"100%\" style=\"max-width: 580px; background-color: #ffffff; border-radius: 14px; overflow: hidden; box-shadow: 0 10px 30px rgba(16, 44, 61, 0.08); border: 1px solid #d8e5e7;\">
            <!-- HEADER -->
            <tr>
                <td style=\"background-color: #102c3d; padding: 28px 32px; text-align: center;\">
                    <h1 style=\"margin: 0; font-size: 26px; font-weight: 800; color: #ffffff; letter-spacing: -0.5px;\">
                        smart<span style=\"color: #087ea4;\">isp</span><span style=\"color: #25D366;\">.</span>
                    </h1>
                    <p style=\"margin: 6px 0 0; color: #9bbcd0; font-size: 13px; font-weight: 500;\">Infraestructura Tecnológica y Telecomunicaciones</p>
                </td>
            </tr>

            <!-- BODY -->
            <tr>
                <td style=\"padding: 36px 32px 28px;\">
                    <h2 style=\"margin: 0 0 14px; color: #163342; font-size: 20px; font-weight: 700;\">
                        Recuperación de Contraseña
                    </h2>
                    <p style=\"margin: 0 0 18px; color: #475569; font-size: 15px; line-height: 1.6;\">
                        Hola, recibimos una solicitud para restablecer la contraseña asociada a tu cuenta de SmartISP (<strong>$safeEmail</strong>).
                    </p>
                    <p style=\"margin: 0 0 24px; color: #475569; font-size: 15px; line-height: 1.6;\">
                        Haz clic en el siguiente botón seguro para definir una nueva contraseña:
                    </p>

                    <!-- BOTÓN CTA -->
                    <table border=\"0\" cellpadding=\"0\" cellspacing=\"0\" width=\"100%\" style=\"margin: 10px 0 26px;\">
                        <tr>
                            <td align=\"center\">
                                <a href=\"$safeUrl\" target=\"_blank\" style=\"display: inline-block; background-color: #087ea4; color: #ffffff; font-size: 15px; font-weight: 700; text-decoration: none; padding: 14px 34px; border-radius: 8px; box-shadow: 0 4px 12px rgba(8, 126, 164, 0.25);\">
                                    Restablecer mi contraseña &rarr;
                                </a>
                            </td>
                        </tr>
                    </table>

                    <!-- AVISO DE CADUCIDAD -->
                    <div style=\"background-color: #f8fafc; border-left: 4px solid #087ea4; padding: 14px 16px; border-radius: 6px; margin-bottom: 24px;\">
                        <p style=\"margin: 0; font-size: 13px; color: #334155; line-height: 1.5;\">
                            ⏱️ <strong>Aviso de seguridad:</strong> Este enlace tiene una validez de <strong>60 minutos</strong> y solo puede ser utilizado una única vez.
                        </p>
                    </div>

                    <!-- ENLACE DE TEXTO POR SI FALLA EL BOTON -->
                    <p style=\"margin: 0 0 8px; font-size: 12px; color: #64748b;\">
                        Si tienes problemas con el botón, copia y pega el siguiente enlace directamente en tu navegador:
                    </p>
                    <div style=\"background-color: #f1f5f9; padding: 10px 12px; border-radius: 6px; word-break: break-all; font-size: 12px; color: #087ea4;\">
                        <a href=\"$safeUrl\" target=\"_blank\" style=\"color: #087ea4; text-decoration: underline;\">$safeUrl</a>
                    </div>

                    <p style=\"margin: 26px 0 0; font-size: 13px; color: #94a3b8; line-height: 1.5;\">
                        Si tú no solicitaste este cambio, puedes ignorar este mensaje de forma segura. Tu contraseña actual permanecerá intacta y nadie podrá acceder a tu cuenta sin este enlace.
                    </p>
                </td>
            </tr>

            <!-- FOOTER -->
            <tr>
                <td style=\"background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 20px 32px; text-align: center;\">
                    <p style=\"margin: 0; font-size: 12px; color: #64748b;\">
                        © $year SmartISP. Todos los derechos reservados.
                    </p>
                    <p style=\"margin: 4px 0 0; font-size: 11px; color: #94a3b8;\">
                        Ecuador · <a href=\"mailto:contacto@smart-isp.com.ec\" style=\"color: #087ea4; text-decoration: none;\">contacto@smart-isp.com.ec</a>
                    </p>
                </td>
            </tr>
        </table>
    </div>";
}

