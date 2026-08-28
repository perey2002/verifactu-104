<?php

require_once DOL_DOCUMENT_ROOT . '/core/triggers/dolibarrtriggers.class.php';
dol_include_once('/verifactu104/lib/verifactu104.lib.php');
dol_include_once('/verifactu104/class/VerifactuXMLBuilder.class.php');
dol_include_once('/verifactu104/class/actions_verifactu104.class.php');
class InterfaceVerifactu104Triggers extends DolibarrTriggers
{
    public function __construct($db)
    {
        parent::__construct($db);
        $this->family      = "billing";
        $this->description = "Triggers Verifactu104: hash encadenado y QR";
        $this->version     = self::VERSIONS['dev'];
        $this->picto       = 'verifactu104@verifactu104';
    }

    /**
     * Construye la cadena OFICIAL AEAT para la huella encadenada del Registro de Alta.
     *
     * Formato:
     *  IDEmisorFactura=...&
     *  NumSerieFactura=...&
     *  FechaExpedicionFactura=DD-MM-AAAA&
     *  TipoFactura=F1&
     *  CuotaTotal=...&
     *  ImporteTotal=...&
     *  Huella=...&
     *  FechaHoraHusoGenRegistro=YYYY-MM-DDThh:mm:ss+zz:zz
     */
    public function buildHashStringAEAT($object, $conf, $hash_prev)
    {
        if (empty($object->thirdparty)) {
            $object->fetch_thirdparty();
        }

        // Emisor
        $emisor_nif = $conf->global->MAIN_INFO_SIREN
            ?: $conf->global->MAIN_INFO_TVAINTRA
            ?: 'B00000000';

        // Nº factura (NumSerieFactura en la doc de AEAT)
        $ref_factura = !empty($object->newref) ? $object->newref : $object->ref;

        // Fecha de expedición (DD-MM-AAAA)
        // Puedes cambiar a date_validation si quieres ser más estricto:
        $fecha_exp = dol_print_date($object->date, '%d-%m-%Y');

        // Tipo de factura: simplificamos a F1 (factura completa normal)
        // En el futuro se puede mapear según $object->type
        $tipo_factura = 'F1';

        // Cuota total (IVA total)
        $cuota_total = number_format((float) $object->total_tva, 2, '.', '');

        // Importe total factura
        $importe_total = number_format((float) $object->total_ttc, 2, '.', '');

        // Huella anterior (puede ser vacía en la primera factura)
        $hash_prev = $hash_prev ?: "";

        // Fecha-hora de generación del registro en formato ISO con huso (YYYY-MM-DDThh:mm:ss+zz:zz)
        $fecha_hora_registro = date('c'); // p.ej. 2025-11-16T10:23:45+01:00

        $parts = [];
        $parts[] = 'IDEmisorFactura=' . $emisor_nif;
        $parts[] = 'NumSerieFactura=' . $ref_factura;
        $parts[] = 'FechaExpedicionFactura=' . $fecha_exp;
        $parts[] = 'TipoFactura=' . $tipo_factura;
        $parts[] = 'CuotaTotal=' . $cuota_total;
        $parts[] = 'ImporteTotal=' . $importe_total;
        $parts[] = 'Huella=' . $hash_prev;
        $parts[] = 'FechaHoraHusoGenRegistro=' . $fecha_hora_registro;

        return implode('&', $parts);
    }
    /**
     * Obtiene el último hash generado para la misma serie de la factura
     * (puede ser de un alta o de un evento registrado en actioncomm),
     * para encadenar correctamente la huella.
     */

    public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
    {
        if (!isModEnabled('verifactu104')) return 0;

        switch ($action) {

            case 'BILL_VALIDATE':

                // 🔽 AÑADE ESTO:
                global $db, $conf;
                $sqlDel = "DELETE FROM " . MAIN_DB_PREFIX . "ecm_files
                    WHERE filepath = 'facture/" . $db->escape($object->ref) . "'
                        AND filename = 'verifactu_qr.png'
                        AND entity = " . ((int) $conf->entity);
                $db->query($sqlDel);

                $ref = dol_sanitizeFileName(!empty($object->newref) ? $object->newref : $object->ref);
                $qr_file = $conf->facture->dir_output . "/" . $ref . "/verifactu_qr.png";

                if (file_exists($qr_file)) {
                    @unlink($qr_file);
                }

                return 0;


            case 'BILL_UNVALIDATE':
                $object->fetch_optionals();
                $state = $object->array_options['options_verifactu_estado'] ?? '';
                if (in_array($state, array('enviado', 'anulado_enviado'), true)) {
                    setEventMessages("No se puede pasar a borrador una factura enviada a la AEAT.", null, 'errors');
                    return -1;
                }
                return 0;
            case 'BILL_CANCEL':
                // Upstream called non-existent builder/hash methods here. Never create a
                // fictitious cancellation record. Block only invoices already transmitted;
                // cancellation support will be enabled after XSD and AEAT integration tests.
                $object->fetch_optionals();
                if (($object->array_options['options_verifactu_estado'] ?? '') === 'enviado') {
                    setEventMessages('La anulación VeriFactu aún no está validada en esta versión experimental.', null, 'errors');
                    return -1;
                }
                return 0;


            default:
                return 0;
        }
    }
}
