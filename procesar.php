<?php
// Configuración inicial
ini_set('max_execution_time', 0);
date_default_timezone_set('America/Managua');

// Configuración de buffers para salida en tiempo real
header('Content-Type: text/html; charset=UTF-8');
ob_implicit_flush(true);
while (ob_get_level()) ob_end_flush();

function timestamp() {
    $microtime = explode(' ', microtime());
    $milis = str_pad((int)($microtime[0] * 1000), 3, '0', STR_PAD_LEFT);
    return "[" . date('Y-m-d H:i:s') . ".$milis]";
}

function enviarMensaje($mensaje) {
    $mensaje = str_replace(["\n", "\r", "'"], ['\\n', '', "\\'"], $mensaje);
    $mensaje = htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8');
    echo "<script>"
         . "if(parent && parent.actualizarConsola) parent.actualizarConsola('$mensaje');"
         . "</script>\n";
    ob_flush();
    flush();
}

function actualizarProgreso($progreso, $enviados, $total) {
    echo "<script>"
         . "if(parent && parent.actualizarBarraEnvio) parent.actualizarBarraEnvio($progreso);"
         . "if(parent && parent.actualizarContadores) parent.actualizarContadores($enviados, $total);"
         . "</script>\n";
    ob_flush();
    flush();
}

// Reemplazar la función generarXMLNI en procesar.php con esta nueva versión
function generarXMLNI($user, $pass, $numero, $imsi) {
    $contextId = date('YmdHis') . rand(100, 999); // Generar un ID de contexto único
    
    return '<S:Envelope xmlns:S="http://schemas.xmlsoap.org/soap/envelope/">
  <S:Header>
    <wsse:Security xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
      <wsse:UsernameToken>
        <wsse:Username>' . htmlspecialchars($user, ENT_QUOTES) . '</wsse:Username>
        <wsse:Password>' . htmlspecialchars($pass, ENT_QUOTES) . '</wsse:Password>
      </wsse:UsernameToken>
    </wsse:Security>
    <Context xmlns="http://schemas.ericsson.com/cai3g1.2/">' . $contextId . '</Context>
  </S:Header>
  <S:Body>
    <ns2:Set xmlns:ns2="http://schemas.ericsson.com/cai3g1.2/" xmlns:ns3="http://www.w3.org/2005/08/addressing" xmlns:ns4="http://schemas.ericsson.com/ma/CA/AMXCAmovilDecisionEL/" xmlns:ns5="http://schemas.ericsson.com/async/">
      <ns2:MOType>Subscriber@http://schemas.ericsson.com/ma/CA/AMXCAmovilDecisionEL/</ns2:MOType>
      <ns2:MOId>
        <ns4:msisdn>' . $numero . '</ns4:msisdn>
        <ns4:imsi>' . $imsi . '</ns4:imsi>
      </ns2:MOId>
      <ns2:MOAttributes>
        <ns4:SetSubscriber imsi="' . $imsi . '" msisdn="' . $numero . '">
          <ns4:client_service_add>WV</ns4:client_service_add>
          <ns4:client_service_info xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:type="xs:string"></ns4:client_service_info>
        </ns4:SetSubscriber>
      </ns2:MOAttributes>
    </ns2:Set>
  </S:Body>
</S:Envelope>';
}

function enviarXML($url, $xml) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Content-Type: text/xml; charset=ISO-8859-1",
        "Content-Length: " . strlen($xml)
    ));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        $response = "❌ cURL Error: " . curl_error($ch);
    }
    curl_close($ch);
    return $response;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario    = isset($_POST['usuario']) ? trim($_POST['usuario']) : '';
    $contrasena = isset($_POST['contrasena']) ? trim($_POST['contrasena']) : '';
    $pais       = 'Nicaragua'; // Fijo para Nicaragua
    $delay      = isset($_POST['delay']) ? intval($_POST['delay']) : 3000;
    $endpoint   = isset($_POST['endpoint']) ? trim($_POST['endpoint']) : '';
    
    if (!is_dir('logs')) mkdir('logs', 0755, true);
    $logFile = 'logs/log_' . date('Ymd_His') . '.txt';
    $log = fopen($logFile, 'w');

    $total = 0; $enviados = 0; $omitidos = 0; $errores = 0;

    if (isset($_FILES['archivo']) && $_FILES['archivo']['error'] === 0) {
        $archivo = $_FILES['archivo']['tmp_name'];
        $datos = array_map('str_getcsv', file($archivo));

        if (count($datos) === 0) {
            enviarMensaje('❌ El archivo está vacío o no tiene el formato correcto');
            fclose($log);
            exit;
        }

        // Verificar si la primera fila es encabezado (no numérica)
        if (!is_numeric($datos[0][0])) {
            array_shift($datos);
            enviarMensaje('ℹ️ Se detectó encabezado en el archivo, se omitirá la primera línea');
            fwrite($log, timestamp() . " Se omitió encabezado del archivo\n");
        }

        @unlink("detener.txt");
        @unlink("pausar.txt");

        enviarMensaje('🔄 Inicio: ' . date('Y-m-d H:i:s'));
        enviarMensaje('🌍 País: ' . $pais);
        enviarMensaje('📍 Endpoint: ' . $endpoint);
        enviarMensaje('👤 Usuario: ' . $usuario);
        enviarMensaje('🕒 Delay: ' . $delay . ' ms');
        enviarMensaje('');

        fwrite($log, timestamp() . " Inicio del envío\n");
        fwrite($log, "País: $pais\nEndpoint: $endpoint\nUsuario: $usuario\nDelay: {$delay} ms\n\n");

        $numRegistros = count($datos);
        actualizarProgreso(0, 0, $numRegistros);

        for ($i = 0; $i < $numRegistros; $i++) {
            if (file_exists("detener.txt")) {
                enviarMensaje('🛑 Envío detenido por el usuario');
                fwrite($log, timestamp() . " Proceso detenido manualmente\n");
                break;
            }
            
            while (file_exists("pausar.txt")) {
                enviarMensaje('⏸ Envío pausado...');
                sleep(1);
            }

            $fila = $datos[$i];
            $total++;

            // Validar que la fila tenga al menos 2 columnas
            if (count($fila) < 2) {
                enviarMensaje("⚠️ [$i] Saltado: formato incorrecto (se esperaba número,imsi)");
                fwrite($log, timestamp() . " [$i] Saltado: formato incorrecto\n");
                $omitidos++;
                continue;
            }

            $numero = trim($fila[0]);
            $imsi = trim($fila[1]);

            if ($numero === '' || $imsi === '') {
                enviarMensaje("⚠️ [$i] Saltado: número o IMSI vacío");
                fwrite($log, timestamp() . " [$i] Saltado: número o IMSI vacío\n");
                $omitidos++;
                continue;
            }

            $xml = generarXMLNI($usuario, $contrasena, $numero, $imsi);
            fwrite($log, timestamp() . " [$i] XML generado:\n" . $xml . "\n\n");

            enviarMensaje('➡️ [' . $i . '] Enviando número: msisdn=' . $numero . ', imsi='.$imsi . ' - ' . timestamp());
            $inicio = microtime(true);
            $respuesta = enviarXML($endpoint, $xml);
            $duracion = round(microtime(true) - $inicio, 2);

            if (strpos($respuesta, 'Fault') !== false || strpos($respuesta, 'error') !== false) {
                $errores++;
                enviarMensaje('❌ [' . $i . '] Error en respuesta - ' . timestamp());
                fwrite($log, timestamp() . " [$i] Error en respuesta\n$respuesta\n\n");
            } else {
                $enviados++;
                enviarMensaje('✅ [' . $i . '] Envío exitoso - Duración: ' . $duracion . 's - ' . timestamp());
                fwrite($log, timestamp() . " [$i] Envío exitoso - Duración: {$duracion}s\n\n");
            }

            fwrite($log, "----------------------------------------\n\n");

            $progreso = round((($i+1) / $numRegistros) * 100);
            actualizarProgreso($progreso, $enviados, $numRegistros);

            usleep($delay * 1000);
        }

        enviarMensaje("\n🎯 Resumen:");
        enviarMensaje("📄 Total registros: " . $total);
        enviarMensaje("📤 Enviados OK: " . $enviados);
        enviarMensaje("⚠️ Omitidos: " . $omitidos);
        enviarMensaje("❌ Errores: " . $errores);
        enviarMensaje("🕓 Fin: " . date('Y-m-d H:i:s'));
        enviarMensaje('📁 <a href="' . $logFile . '" target="_blank">Descargar log completo</a>');

        fwrite($log, timestamp() . " Resumen del proceso:\n");
        fwrite($log, "Total registros: $total\n");
        fwrite($log, "Enviados exitosos: $enviados\n");
        fwrite($log, "Registros omitidos: $omitidos\n");
        fwrite($log, "Errores detectados: $errores\n");
        fwrite($log, timestamp() . " Proceso finalizado\n");
        fclose($log);

        echo "<script>"
           . "if(parent && parent.finalizarBarra) parent.finalizarBarra();"
           . "if(parent && parent.actualizarLogFileName) parent.actualizarLogFileName('$logFile');"
           . "</script>";
    } else {
        enviarMensaje('❌ Error al subir el archivo');
        echo "<script>if(parent && parent.showToast) parent.showToast('Error al subir el archivo', 'error');</script>";
    }
}

ob_flush();
flush();
exit;
?>