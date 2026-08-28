<?php
/*  Verifactu104 - Módulo Veri*Factu para Dolibarr
 *  (C) 2025 104 CUBES S.L (Wayhoy!)
 *  (C) 2026 Check 4 Cyber SARL
 *  Licencia GPL v3
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonhookactions.class.php';
dol_include_once('/verifactu104/lib/verifactu104.lib.php');

use setasign\Fpdi\Tcpdf\Fpdi;

class ActionsVerifactu104 extends CommonHookActions
{
    public $context = array('pdfgeneration', 'globalcard', 'invoicecard');

    public function __construct($db)
    {
        $this->db = $db;
    }


    /**
     * Añade un evento de historial VeriFactu en la factura.
     *
     * @param Facture $object
     * @param string $code   Ej: 'SIF_HASH', 'SIF_SEND_START', etc.
     * @param string $note   Mensaje a registrar
     */
    public function verifactu_add_history($object, $code, $note)
    {
        global $db, $user;
        require_once DOL_DOCUMENT_ROOT . '/comm/action/class/actioncomm.class.php';

        if (empty($object->id)) return;

        $ev = new ActionComm($db);
        $ev->elementtype  = 'facture';
        $ev->fk_element   = $object->id;
        $ev->code         = $code;
        $ev->label        = $note;
        $ev->note         = $note;
        $ev->datep        = dol_now();
        // 🔹 Campo obligatorio en Dolibarr: userownerid
        $ev->userownerid  = (!empty($user->id) ? (int) $user->id : 1);

        try {
            $res = $ev->create($user);
            if ($res <= 0) {
                dol_syslog("VERIFACTU_HISTORY: Error al crear ActionComm (" . $ev->error . ")", LOG_ERR);
            }
        } catch (Exception $e) {
            dol_syslog("VERIFACTU_HISTORY: EXCEPCION al crear ActionComm → " . $e->getMessage(), LOG_ERR);
        }
    }
    public function doActions($parameters, &$object, &$action, $hookmanager)
    {
        global $conf;

        if ($action === 'verifactu_resend') {

            dol_syslog("VERIFACTU: Acción verifactu_resend ejecutada", LOG_DEBUG);

            // Buscar XML previo
            $ref = dol_sanitizeFileName($object->ref);
            $xml_path = $conf->facture->dir_output . '/' . $ref . '/verifactu_' . $ref . '.xml';

            if (!file_exists($xml_path)) {
                setEventMessages("No existe XML previo. Vuelva a validar la factura.", null, 'errors');
                return 0;
            }

            // Enviar de nuevo
            $ok = $this->sendToAEAT($xml_path, $object);

            if ($ok) {
                setEventMessages("Factura reenviada correctamente.", null, 'mesgs');
            } else {
                setEventMessages("No se pudo reenviar la factura. Revisa el acuse.", null, 'errors');
            }

            return 1;
        }

        if ($action === 'verifactu_subsanar') {
            dol_syslog("VERIFACTU: Acción verifactu_subsanar ejecutada", LOG_DEBUG);

            $ref = dol_sanitizeFileName($object->ref);
            $facture_dir = $conf->facture->dir_output . '/' . $ref;
            $xml_path = $facture_dir . '/verifactu_' . $ref . '.xml';

            if (!file_exists($xml_path)) {
                setEventMessages("No existe XML previo para subsanar. Vuelva a validar la factura.", null, 'errors');
                return 0;
            }

            // For subsanación, force send using existing XML
            $ok = $this->sendToAEAT($xml_path, $object);

            if ($ok) {
                setEventMessages("Subsanación enviada correctamente a AEAT.", null, 'mesgs');
            } else {
                setEventMessages("Error enviando subsanación. Consulta el acuse.", null, 'errors');
            }

            return 1;
        }

        return 0;
    }

    public function getHashPrev($object)
    {
        global $conf;
        $db = $this->db;

        // Serie = parte alfabética de la referencia
        $serie = preg_replace('/[^A-Za-z]/', '', (string) $object->ref);

        $records = [];

        // 1) Hash de facturas (alta)
        $sql = "
        SELECT ef.hash_verifactu AS hash, ef.verifactu_timestamp AS ts
        FROM " . MAIN_DB_PREFIX . "facture_extrafields ef
        JOIN " . MAIN_DB_PREFIX . "facture f ON f.rowid = ef.fk_object
        WHERE ef.hash_verifactu IS NOT NULL
          AND ef.hash_verifactu <> ''
          AND f.rowid <> " . ((int) $object->id) . "
          AND f.entity = " . ((int) $conf->entity) . "
          AND f.ref LIKE '" . $db->escape($serie) . "%'
    ";

        $res = $db->query($sql);
        if ($res) {
            while ($obj = $db->fetch_object($res)) {
                $records[] = [
                    'hash' => $obj->hash,
                    'ts'   => (int)$obj->ts,
                ];
            }
        }

        // 2) Eventos (subsanación, anulación, reenvío)
        $sql2 = "
        SELECT ace.verifactu_event_hash AS hash, ace.verifactu_event_timestamp AS ts
        FROM " . MAIN_DB_PREFIX . "actioncomm ac
        JOIN " . MAIN_DB_PREFIX . "actioncomm_extrafields ace ON ac.id = ace.fk_object
        WHERE ac.elementtype = 'facture'
          AND ac.fk_element IN (
                SELECT f2.rowid
                FROM " . MAIN_DB_PREFIX . "facture f2
                WHERE f2.ref LIKE '" . $db->escape($serie) . "%'
          )
    ";

        $res2 = $db->query($sql2);
        if ($res2) {
            while ($obj2 = $db->fetch_object($res2)) {
                if (!empty($obj2->hash)) {
                    $records[] = [
                        'hash' => $obj2->hash,
                        'ts'   => (int)$obj2->ts,
                    ];
                }
            }
        }

        if (empty($records)) return '';

        // Ordenar por timestamp DESC
        usort($records, function ($a, $b) {
            return ($b['ts'] <=> $a['ts']);
        });

        return $records[0]['hash'];
    }

    /** Return the latest earlier invoice record in the same inferred series. */
    public function getPreviousInvoiceRecord($object)
    {
        global $conf;

        $serie = preg_replace('/[^A-Za-z]/', '', (string) $object->ref);
        $sql = "SELECT f.ref, f.datef, ef.hash_verifactu AS hash
            FROM " . MAIN_DB_PREFIX . "facture f
            JOIN " . MAIN_DB_PREFIX . "facture_extrafields ef ON ef.fk_object = f.rowid
            WHERE f.entity = " . ((int) $conf->entity) . "
              AND f.rowid <> " . ((int) $object->id) . "
              AND f.ref LIKE '" . $this->db->escape($serie) . "%'
              AND ef.hash_verifactu IS NOT NULL AND ef.hash_verifactu <> ''
            ORDER BY ef.verifactu_timestamp DESC, f.rowid DESC";
        $res = $this->db->query($sql);
        if (!$res || !($row = $this->db->fetch_object($res))) {
            return null;
        }

        return array(
            'ref' => $row->ref,
            'date' => dol_print_date($this->db->jdate($row->datef), '%d-%m-%Y'),
            'hash' => strtoupper($row->hash),
        );
    }

    /**
     * Build the exact field sequence used by RRSIF for a RegistroAlta SHA-256.
     * The timestamp must be generated once and reused in both the hash and XML.
     */
    public function buildRegistroAltaHashInput($object, $timestamp)
    {
        global $conf;

        $issuer = getDolGlobalString('MAIN_INFO_SIREN');
        if ($issuer === '') {
            $issuer = getDolGlobalString('MAIN_INFO_TVAINTRA');
        }
        if ($issuer === '') {
            throw new RuntimeException('Falta el NIF del emisor en la configuración de la entidad.');
        }
        $type = ((int) $object->type === Facture::TYPE_CREDIT_NOTE) ? 'R1' : 'F1';

        return 'IDEmisorFactura=' . $issuer
            . '&NumSerieFactura=' . $object->ref
            . '&FechaExpedicionFactura=' . dol_print_date($object->date, '%d-%m-%Y')
            . '&TipoFactura=' . $type
            . '&CuotaTotal=' . number_format((float) $object->total_tva, 2, '.', '')
            . '&ImporteTotal=' . number_format((float) $object->total_ttc, 2, '.', '')
            . '&Huella=' . strtoupper((string) ($object->array_options['options_hash_prev'] ?? ''))
            . '&FechaHoraHusoGenRegistro=' . $timestamp;
    }

    // Método que envía el XML a la AEAT

    public function sendToAEAT($xml_path, $object)
    {
        global $conf, $db;

        require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
        require_once DOL_DOCUMENT_ROOT . '/core/lib/fichinter.lib.php';

        dol_syslog("VERIFACTU_SEND: Iniciando envío a AEAT", LOG_DEBUG);
        $this->verifactu_add_history($object, 'SIF_SEND_START', 'Iniciando envío a AEAT');

        // Solo enviar si el auto envío está activado
        if (empty($conf->global->VERIFACTU_AUTO_SEND)) {
            dol_syslog("VERIFACTU_SEND: Envío automático desactivado", LOG_INFO);
            return 0;
        }
        // Modo de envío (test / producción) desde la configuración
        $mode = isset($conf->global->VERIFACTU_MODE) ? trim((string) $conf->global->VERIFACTU_MODE) : '';
        dol_syslog("VERIFACTU_SEND: VERIFACTU_MODE='$mode'", LOG_DEBUG);

        if (in_array($mode, array('test', 'pruebas'), true)) {
            // Entorno de pruebas
            $base = 'https://prewww1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/';
            dol_syslog("VERIFACTU_SEND: Usando entorno de PRUEBAS", LOG_DEBUG);
        } elseif (in_array($mode, array('prod', 'produccion', 'producción'), true)) {
            if (empty($conf->global->VERIFACTU_PRODUCTION_ACK)) {
                dol_syslog('VERIFACTU_SEND: Producción bloqueada: falta confirmación explícita', LOG_ERR);
                setEventMessages('Envío a producción bloqueado. Complete la validación y confirmación de producción en la configuración.', null, 'errors');
                return false;
            }
            // Entorno de producción
            $base = 'https://www.agenciatributaria.gob.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/';
            dol_syslog("VERIFACTU_SEND: Usando entorno de PRODUCCIÓN", LOG_DEBUG);
        } else {
            dol_syslog("VERIFACTU_SEND: Modo no definido o inválido (VERIFACTU_MODE='$mode'), no se envía nada", LOG_ERR);
            return 0;
        }
        // Determinar endpoint según hash y estado de la factura anterior ---
        // Nueva lógica simplificada — se basa únicamente en el último hash generado de la serie
        $hash_prev_global = $this->getHashPrev($object);
        $is_first_or_not_sent = empty($hash_prev_global);



        // Si no hay hash previo o la factura anterior no está enviada → usar RequerimientoSOAP
        if ($is_first_or_not_sent) {
            $url = $base . 'RequerimientoSOAP';
            dol_syslog("VERIFACTU_SEND: Usando endpoint RequerimientoSOAP (primera factura o pendiente)", LOG_DEBUG);
        } else {
            $url = $base . 'VerifactuSOAP';
            dol_syslog("VERIFACTU_SEND: Usando endpoint VerifactuSOAP (cadena activa)", LOG_DEBUG);
        }

        // Rutas de certificados
        $cert_file = DOL_DATA_ROOT . '/verifactu104/certs/cert.pem';
        $key_file  = DOL_DATA_ROOT . '/verifactu104/certs/key.pem';

        if (!file_exists($xml_path)) {
            dol_syslog("VERIFACTU_SEND: XML no encontrado en $xml_path", LOG_ERR);
            return false;
        }

        if (!is_readable($cert_file) || !is_readable($key_file)) {
            dol_syslog('VERIFACTU_SEND: Certificado o clave privada ausentes/no legibles', LOG_ERR);
            setEventMessages('No se puede enviar: certificado o clave privada ausentes/no legibles.', null, 'errors');
            return false;
        }

        if (!function_exists('curl_init')) {
            setEventMessages('La extensión PHP cURL es obligatoria para el envío.', null, 'errors');
            return false;
        }

        $xml_data = file_get_contents($xml_path);

        // === Envío real ===
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_SSLCERT        => $cert_file,
            CURLOPT_SSLKEY         => $key_file,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $xml_data,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: ""',
                'User-Agent: VeriFactu104/1.0'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        // === Analizar resultado ===
        $facture_dir = dirname($xml_path);
        $resp_path = $facture_dir . '/acuse_verifactu.xml';


        try {
            $dom_resp = new DOMDocument('1.0', 'UTF-8');
            $dom_resp->formatOutput = true;

            if (!empty($response)) {
                file_put_contents($resp_path, $response);
            } else {
                file_put_contents($resp_path, "<Error>Sin respuesta</Error>");
            }

            dol_syslog("VERIFACTU_SEND: Acuse XML guardado en $resp_path", LOG_DEBUG);
            $this->verifactu_add_history($object, 'SIF_RESP_SAVED', 'Respuesta guardada');
        } catch (Exception $e) {
            dol_syslog("VERIFACTU_SEND: ERROR al guardar XML de respuesta → " . $e->getMessage(), LOG_ERR);
        }
        // === Analizar resultado ===
        $estado = 'error';
        $mensaje = "Factura NO enviada, revisa el XML generado para ver los motivos.";
        $type = 'errors';
        $retryDetected = false;
        if ($response === false || $curl_error !== '') {
            $mensaje = 'Error de transporte al contactar con AEAT: ' . $curl_error;
        } elseif ($http_code < 200 || $http_code >= 300) {
            $mensaje = 'AEAT devolvió HTTP ' . ((int) $http_code) . '.';
        }
        if (!empty($response)) {
            libxml_use_internal_errors(true);
            $dom = new DOMDocument();

            if ($dom->loadXML($response)) {
                // Buscar <Estado>
                $estado_nodes = $dom->getElementsByTagName('Estado');
                if ($estado_nodes->length > 0) {
                    $estado_val = strtoupper(trim($estado_nodes->item(0)->nodeValue));
                    if ($estado_val === 'CORRECTO') {
                        $estado = 'enviado';
                        $mensaje = "Factura enviada correctamente a AEAT.";
                        $type = 'mesgs';
                    } elseif ($estado_val === 'INCORRECTO') {
                        $estado = 'subsanar';
                        $mensaje = "La AEAT devolvió 'INCORRECTO'. Requiere subsanación.";
                        $type = 'warnings';
                    }
                }
                // Buscar <CodigoError>
                $error_nodes = $dom->getElementsByTagName('CodigoError');
                if ($error_nodes->length > 0) {
                    $estado = 'rechazado';
                    $mensaje = "Factura rechazada por AEAT: " . $error_nodes->item(0)->nodeValue;
                }
            }

            // Detectar RetryAfter
            $retry_nodes = isset($dom) ? $dom->getElementsByTagName('RetryAfter') : null;
            if ($retry_nodes && $retry_nodes->length > 0) {
                $wait = (int) trim($retry_nodes->item(0)->nodeValue);
                if ($wait > 0) {
                    $retryDetected = true;
                    $estado = 'reintentar';
                    $mensaje .= " AEAT solicita reintento en {$wait} segundos.";
                    $type = 'warnings';
                }
            }
        }

        if ($estado === 'rechazado') {
            $this->verifactu_add_history($object, 'SIF_REJECT', $mensaje);
        } elseif ($estado === 'reintentar') {
            $this->verifactu_add_history($object, 'SIF_RETRY', $mensaje);
        } elseif ($estado === 'error') {
            $this->verifactu_add_history($object, 'SIF_ERR', $mensaje);
        } else {
            $this->verifactu_add_history($object, 'SIF_OK', $mensaje);
        }




        /* === Actualizar extrafield verifactu_estado correctamente === */
        try {
            // Asegurar extrafields cargados
            if (empty($object->array_options) || !array_key_exists('options_verifactu_estado', $object->array_options)) {
                $object->fetch_optionals();
            }

            // Asignar estado
            $object->array_options['options_verifactu_estado'] = $estado;

            // Guardar
            $res = $object->insertExtraFields();

            if ($res > 0) {
                dol_syslog("VERIFACTU_SEND: verifactu_estado actualizado a '$estado'", LOG_DEBUG);
            } else {
                dol_syslog("VERIFACTU_SEND: ERROR insertExtraFields() → " . $object->error, LOG_ERR);
            }
        } catch (Throwable $e) {
            dol_syslog("VERIFACTU_SEND: EXCEPCION al actualizar verifactu_estado → " . $e->getMessage(), LOG_ERR);
        }

        // === Mensaxe Dolibarr ===

        setEventMessages($mensaje, null, $type);

        dol_syslog("VERIFACTU_SEND: Envío completado (HTTP $http_code, estado=$estado). Acuse guardado en $resp_path", LOG_DEBUG);

        return ($estado == 'enviado');
    }




    /**
     * Hook: pdfgeneration
     * Añade página adicional con QR y hash al generar el PDF de una factura validada.
     */
    public function afterPDFCreation($parameters, &$pdf, &$action, $hookmanager)
    {
        global $conf;

        dol_syslog("VERIFACTU_HOOK: afterPDFCreation() EJECUTADO", LOG_DEBUG);

        // =============================
        // 1) VERIFICAR QUE ES UNA FACTURA
        // =============================
        if (empty($parameters['object']) || ! is_object($parameters['object'])) {
            dol_syslog("VF_HOOK: No hay objeto → abortando", LOG_DEBUG);
            return 0;
        }

        $raw = $parameters['object'];

        if ($raw->element !== 'facture') {
            dol_syslog("VF_HOOK: No es factura → es '$raw->element' → abortando", LOG_DEBUG);
            return 0;
        }

        // =============================
        // 2) RECONSTRUIR FACTURA COMPLETA
        // =============================
        $id = !empty($raw->id) ? $raw->id : (!empty($raw->rowid) ? $raw->rowid : 0);
        if (!$id) {
            dol_syslog("VF_HOOK: No se pudo determinar ID factura", LOG_ERR);
            return 0;
        }

        $facture = new Facture($this->db);
        if ($facture->fetch($id) <= 0) {
            dol_syslog("VF_HOOK: fetch() falló para ID=$id", LOG_ERR);
            return 0;
        }
        $facture->fetch_thirdparty();
        $facture->fetch_optionals();

        // =============================
        // 3) SOLO FACTURAS VALIDADAS (NO PROVISIONALES)
        // =============================
        if ($facture->statut != Facture::STATUS_VALIDATED) {
            dol_syslog("VF_HOOK: Factura NO validada (statut=$facture->statut) → abortando", LOG_DEBUG);
            return 0;
        }

        // =============================
        // 4) SOLO FACTURAS "REALES"
        //    Y NO PRESUPUESTOS/PEDIDOS/ALBARANES
        // =============================
        if (!empty($facture->type) && !in_array($facture->type, [
            Facture::TYPE_STANDARD,
            Facture::TYPE_DEPOSIT,
            Facture::TYPE_CREDIT_NOTE
        ])) {
            dol_syslog("VF_HOOK: Tipo factura no válido para VeriFactu → tipo=$facture->type", LOG_DEBUG);
            return 0;
        }

        // A partir de aquí ya puedes ejecutar el resto del hook

        // Detectar ID correctamente
        $id = 0;
        if (!empty($raw->id))            $id = $raw->id;
        elseif (!empty($raw->rowid))     $id = $raw->rowid;
        elseif (!empty($raw->ref)) {
            $tmp = new Facture($this->db);
            $id = $tmp->fetch('', $raw->ref);
        }

        if ($id <= 0) {
            dol_syslog("VERIFACTU_HOOK: ERROR → No se pudo determinar ID de factura", LOG_ERR);
            return 0;
        }

        // Cargar factura COMPLETA
        $facture = new Facture($this->db);
        if ($facture->fetch($id) <= 0) {
            dol_syslog("VERIFACTU_HOOK: ERROR → fetch() falló para ID $id", LOG_ERR);
            return 0;
        }
        $facture->fetch_thirdparty();
        $facture->fetch_optionals();

        // Sustituimos el objeto incompleto recibido por el real
        $object = $facture;

        // Solo facturas validadas (estat 1)
        if ($object->statut != Facture::STATUS_VALIDATED) {
            dol_syslog("VERIFACTU_HOOK: FACTURA NO VALIDADA → no generar QR/XML", LOG_DEBUG);
            return 0;
        }

        dol_syslog("VERIFACTU_HOOK: factura reconstruida correctamente ID=$object->id REF=$object->ref", LOG_DEBUG);
        dol_syslog("Datos factura: " . print_r($object, true), LOG_DEBUG);

        // Rutas de archivos
        try {
            $ref = dol_sanitizeFileName($object->ref);
            $facture_dir = $conf->facture->dir_output . '/' . $ref;
            dol_syslog("VERIFACTU_HOOK: facture_dir = $facture_dir", LOG_DEBUG);
            $pdf_file = $facture_dir . "/" . $ref . ".pdf";
            dol_syslog("VERIFACTU_HOOK: getDir() OK → $facture_dir", LOG_DEBUG);
        } catch (Throwable $e) {
            dol_syslog("VERIFACTU_HOOK: ERROR en getDir() → " . $e->getMessage(), LOG_ERR);
            dol_syslog("TRACE: " . $e->getTraceAsString(), LOG_ERR);
            return -1;
        }
        $qr_file = $facture_dir . "/verifactu_qr.png";
        dol_mkdir($facture_dir);
        // Buscar PDF real
        if (empty($pdf_file) || !file_exists($pdf_file)) {
            $files = dol_dir_list($facture_dir, 'files', 0, '\.pdf$', '', 'date', SORT_DESC);
            if (!empty($files)) {
                $pdf_file = $facture_dir . $files[0]['name'];
                dol_syslog("VERIFACTU_HOOK: PDF detectado automáticamente: $pdf_file", LOG_DEBUG);
            } else {
                dol_syslog("VERIFACTU_HOOK: ERROR → No se encontró ningún PDF en $facture_dir", LOG_ERR);
                return 0;
            }
        }
        // The upstream implementation attempted to embed the QR before creating it and
        // labelled the page as a certification. Keep the original invoice untouched in
        // the experimental line; a later, tested document hook will render QR/legend.


        // === GENERAR XML VERIFACTU (usando VerifactuXMLBuilder) ===
        dol_syslog("VERIFACTU_HOOK: INICIO generación XML VeriFactu", LOG_DEBUG);
        try {
            dol_include_once('/verifactu104/class/VerifactuXMLBuilder.class.php');
            $ref = dol_sanitizeFileName($object->ref);
            $xml_path = $facture_dir . "/verifactu_" . $ref . ".xml";
            dol_syslog("VERIFACTU_HOOK: Ruta XML = $xml_path", LOG_DEBUG);
            // Obtener hash anterior desde extrafields
            $hash_prev   = $object->array_options['options_hash_prev'] ?? '';
            $hash_actual = $object->array_options['options_hash_verifactu'] ?? '';
            $timestamp   = $object->array_options['options_verifactu_timestamp'] ?? dol_now();

            // === Si los hashes no existen (primera generación), generarlos y guardarlos aquí ===
            if (empty($hash_actual)) {
                dol_syslog("VERIFACTU_HOOK: Hash vacío → generando nuevo hash y guardando extrafields", LOG_DEBUG);

                // Obtener hash previo correcto según la serie
                $previous_record = $this->getPreviousInvoiceRecord($object);
                $hash_prev = $previous_record ? $previous_record['hash'] : '';
                $object->array_options['options_verifactu_prev_ref'] = $previous_record ? $previous_record['ref'] : '';
                $object->array_options['options_verifactu_prev_date'] = $previous_record ? $previous_record['date'] : '';

                // Normalizar a mayúsculas el hash previo (si existe)
                if (!empty($hash_prev)) {
                    $hash_prev = strtoupper($hash_prev);
                }

                $timestamp   = dol_now();

                // Persist the timestamp once and hash the official field sequence.
                $timestamp_iso = date('c', $timestamp);
                $object->array_options['options_hash_prev'] = $hash_prev;
                $hash_actual = strtoupper(hash('sha256', $this->buildRegistroAltaHashInput($object, $timestamp_iso)));

                // Guardar extrafields AHORA, cuando la factura ya está con su ref definitiva
                $object->array_options['options_hash_verifactu']      = $hash_actual;
                $object->array_options['options_hash_prev']           = $hash_prev;
                $object->array_options['options_verifactu_timestamp'] = $timestamp;
                $object->insertExtraFields();

                dol_syslog("VERIFACTU_HOOK: Hashes generados y guardados en afterPDFCreation", LOG_DEBUG);
            }

            // Normalizar SIEMPRE a mayúsculas antes de usarlos en QR/XML
            $hash_actual = strtoupper((string) $hash_actual);
            $hash_prev   = strtoupper((string) $hash_prev);
            if ($hash_prev !== '' && empty($object->array_options['options_verifactu_prev_ref'])) {
                $previous_record = $this->getPreviousInvoiceRecord($object);
                if ($previous_record) {
                    $object->array_options['options_verifactu_prev_ref'] = $previous_record['ref'];
                    $object->array_options['options_verifactu_prev_date'] = $previous_record['date'];
                }
            }
            dol_syslog("VERIFACTU_HOOK: hash_prev=$hash_prev hash_actual=$hash_actual timestamp=$timestamp", LOG_DEBUG);
            if (empty($hash_actual)) {
                throw new Exception("hash_actual vacío en extrafields. No se puede generar XML.");
            }
            // Construir QR
            require_once dirname(__FILE__) . '/../lib/phpqrcode.php';
            if (empty($object->thirdparty)) {
                $object->fetch_thirdparty();
            }
            // Emisor
            $emisor_nif = $conf->global->MAIN_INFO_SIREN
                ?: $conf->global->MAIN_INFO_TVAINTRA
                ?: 'B00000000';
            // Datos factura
            $ref_factura = !empty($object->newref) ? $object->newref : $object->ref;
            $fecha_qr    = dol_print_date($object->date, '%d-%m-%Y');     // MISMA fecha que hash
            $total_fmt   = number_format((float) $object->total_ttc, 2, '.', '');
            // Seleccionar URL
            $mode = getDolGlobalString('VERIFACTU_MODE');
            if (in_array($mode, ['test', 'prod'], true)) {
                $qr_url_base = "https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR";
            } else {
                $qr_url_base = "https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQRNoVerifactu";
            }
            // QR oficial
            $qr_content = $qr_url_base
                . "?nif=" . urlencode($emisor_nif)
                . "&numserie=" . urlencode($ref_factura)
                . "&fecha=" . urlencode($fecha_qr)
                . "&importe=" . urlencode($total_fmt)
                . "&hash=" . urlencode($hash_actual);
            // Guardar QR
            dol_mkdir($facture_dir);

            QRcode::png($qr_content, $qr_file, QR_ECLEVEL_M, 4);
            // Detectar el tipo de factura para AEAT
            dol_syslog("VF_QR: Convirtiendo PNG a JPG para FPDI", LOG_DEBUG);

            if (function_exists('imagecreatefrompng')) {
                $png = @imagecreatefrompng($qr_file);
                if ($png !== false) {
                    $jpg_file = $facture_dir . '/verifactu_qr.jpg';

                    $width  = imagesx($png);
                    $height = imagesy($png);

                    $bg = imagecreatetruecolor($width, $height);
                    $white = imagecolorallocate($bg, 255, 255, 255);
                    imagefilledrectangle($bg, 0, 0, $width, $height, $white);

                    imagecopy($bg, $png, 0, 0, 0, 0, $width, $height);

                    if (imagejpeg($bg, $jpg_file, 95)) {
                        dol_syslog("VF_QR: JPG creado correctamente en $jpg_file", LOG_DEBUG);
                        $qr_file = $jpg_file;
                    } else {
                        dol_syslog("VF_QR: ERROR al escribir JPG → $jpg_file", LOG_ERR);
                    }

                    imagedestroy($bg);
                    imagedestroy($png);
                } else {
                    dol_syslog("VF_QR: ERROR imagecreatefrompng devolvió false", LOG_ERR);
                }
            } else {
                dol_syslog("VF_QR: ERROR GD no disponible → NO se puede convertir PNG a JPG", LOG_ERR);
            }
            $tipoFacturaAeat = 'F1';
            if (!empty($object->type) && (int)$object->type === Facture::TYPE_CREDIT_NOTE) {
                $tipoFacturaAeat = 'R1';
            }
            // Construir XML
            $builder = new VerifactuXMLBuilder($this->db, $conf, $tipoFacturaAeat);
            // $xml_soap = $builder->buildAltaSoapAndSave($object, $hash_prev, $hash_actual, $timestamp, $xml_path);
            // === Crear XML interno ===
            $xml_registro = $builder->buildRegistroAltaXML($object, $tipoFacturaAeat);

            // === Envolver en SOAP ===
            //    $xml_soap = $builder->wrapSoapEnvelope($xml_registro);

            // === Guardar
            dol_mkdir(dirname($xml_path));
            file_put_contents($xml_path, $xml_registro);
            $this->verifactu_add_history($object, 'SIF_XML', 'XML generado (builder): ' . basename($xml_path));
            dol_syslog("VERIFACTU_HOOK: XML VeriFactu SOAP generado correctamente en $xml_path", LOG_DEBUG);


            // ENVÍO AUTOMÁTICO (si está activado)
            $this->sendToAEAT($xml_path, $object);
        } catch (Throwable $e) {
            dol_syslog("VERIFACTU_HOOK: ERROR GENERANDO XML → " . $e->getMessage(), LOG_ERR);
            dol_syslog("TRACE: " . $e->getTraceAsString(), LOG_ERR);
            setEventMessages("ERROR generando XML VeriFactu: " . $e->getMessage(), null, 'errors');
            $this->verifactu_add_history($object, 'SIF_XML_ERR', 'Error XML: ' . $e->getMessage());
            return -1;
        }
        return 0;
    }

    /**
     * Hook: addMoreActionsButtons
     * Inserta botones adicionales en la ficha de factura
     */
    /**
     * Hook: addMoreActionsButtons
     * Inserta botones adicionales en la ficha de factura
     */
    /**
     * Hook: formObjectOptions
     * Inserta botones adicionales en la ficha de factura
     */
    public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
    {
        dol_syslog("VERIFACTU_HOOK: formObjectOptions() llamado", LOG_DEBUG);

        try {
            global $langs, $conf;

            // Solo facturas
            if (!is_object($object) || $object->element !== 'facture') {
                dol_syslog("VERIFACTU_HOOK: ignorado (no es factura)", LOG_DEBUG);
                return 0;
            }
            // Solo si está validada
            if ($object->statut != Facture::STATUS_VALIDATED) {
                dol_syslog("VERIFACTU_HOOK: ignorado (factura no validada)", LOG_DEBUG);
                return 0;
            }

            // Solo si está rechazada o con error
            $sql = "SELECT verifactu_estado FROM " . MAIN_DB_PREFIX . "facture_extrafields WHERE fk_object = " . ((int)$object->id) . " LIMIT 1";
            $res = $this->db->query($sql);
            $estado = '';
            if ($res && $obj = $this->db->fetch_object($res)) {
                $estado = $obj->verifactu_estado ?: '';
            }
            dol_syslog("VERIFACTU_HOOK: estado_from_sql='$estado'", LOG_DEBUG);

            // Determinar acción y etiqueta por defecto
            $accion = 'verifactu_resend';
            $label  = "Reenviar a AEAT";

            // Si no está en un estado válido, no insertamos nada
            if (!in_array($estado, ['rechazado', 'error', 'reintentar', 'subsanar'], true)) {
                dol_syslog("VERIFACTU_HOOK: estado '$estado' → no insertar botón", LOG_DEBUG);
                return 0;
            }

            // Caso especial: subsanar
            if ($estado === 'subsanar') {
                $accion = 'verifactu_subsanar';
                $label  = "Subsanar incorrección en envío a AEAT";
            }
            // Crear botón
            $url = $_SERVER['PHP_SELF'] . '?id=' . (int) $object->id . '&action=' . $accion . '&token=' . newToken();


            $html = '<div class="inline-block">'
                . '<a class="butAction" id="miboton" style="background-color:red" href="' . $url . '">' . dol_escape_htmltag($label) . '</a>'
                . '</div>';

            // Añadir al output del hook
            if (empty($this->resprints)) {
                $this->resprints = '';
            }

            $this->resprints .= $html;

            dol_syslog("VERIFACTU_HOOK: Botón insertado correctamente", LOG_DEBUG);
            return 1;
        } catch (Throwable $e) {
            dol_syslog("VERIFACTU_HOOK: EXCEPCION en formObjectOptions → " . $e->getMessage(), LOG_ERR);
            return 0;
        }
    }
}
