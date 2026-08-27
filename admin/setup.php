<?php
/* Setup page for Verifactu104 module */

require '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';

$langs->load("admin");
$langs->load("verifactu104@verifactu104");

if (! $user->admin) accessforbidden();

$action = GETPOST('action', 'alpha');

// Directorio seguro para certificados
$upload_dir = DOL_DATA_ROOT . '/verifactu104/certs/';
dol_mkdir($upload_dir);

// Guardar configuración
if ($action == 'save') {
	// Guardar modo y auto envío
	dolibarr_set_const($db, "VERIFACTU_MODE", GETPOST("VERIFACTU_MODE", 'alpha'), 'chaine', 0, '', $conf->entity);
	$auto_send = GETPOST("VERIFACTU_AUTO_SEND", 'alpha') ? 1 : 0;
	dolibarr_set_const($db, "VERIFACTU_AUTO_SEND", $auto_send, 'int', 0, '', $conf->entity);
	$production_ack = GETPOST("VERIFACTU_PRODUCTION_ACK", 'alpha') ? 1 : 0;
	dolibarr_set_const($db, "VERIFACTU_PRODUCTION_ACK", $production_ack, 'int', 0, '', $conf->entity);

	// ---------------------------------------------
	// PROCESAR ZIP → cert.pem + key.pem + ca-bundle.crt
	// ---------------------------------------------
	if (!empty($_FILES['cert_zip']['tmp_name'])) {
		$tmp = $_FILES['cert_zip']['tmp_name'];
		$destzip = $upload_dir . '/certificados.zip';
		move_uploaded_file($tmp, $destzip);

		echo "<pre>📦 ZIP recibido: {$_FILES['cert_zip']['name']}</pre>";

		$zip = new ZipArchive();
		if ($zip->open($destzip) === TRUE) {
			if ($zip->numFiles > 0) {
				echo "<pre>Descomprimiendo archivos...</pre>";
				$zip->extractTo($upload_dir);
				$zip->close();

				// Mostrar resultado
				$expected = ['cert.pem', 'key.pem', 'ca-bundle.crt'];
				foreach ($expected as $f) {
					if (file_exists($upload_dir . $f)) {
						echo "<pre>✅ Encontrado $f</pre>";
					} else {
						echo "<pre>⚠️ Falta $f en el ZIP</pre>";
					}
				}
			} else {
				// ZIP vacío → borrar certificados existentes
				echo "<pre>⚠️ ZIP vacío. Eliminando certificados existentes...</pre>";
				array_map('unlink', glob($upload_dir . "*.{pem,crt,key}", GLOB_BRACE));
			}
			unlink($destzip);
			echo "<pre>🗑️ ZIP eliminado</pre>";
		} else {
			echo "<pre>❌ Error al abrir el ZIP</pre>";
		}
	}

	// ---------------------------------------------
	// PROCESAR P12 → cert.pem + key.pem + ca-bundle.crt
	// ---------------------------------------------
	if (!empty($_FILES['cert_p12']['tmp_name'])) {

		$p12_tmp = $_FILES['cert_p12']['tmp_name'];
		$p12_pass = GETPOST("cert_p12_pass", "alphanohtml");

		setEventMessages("Archivo P12 recibido: " . $_FILES['cert_p12']['name'], null, 'mesgs');

		// No guardar contraseña, solo usarla en memoria
		if (empty($p12_pass)) {
			setEventMessages("Debes introducir la contraseña del archivo P12.", null, 'errors');
		} else {
			$p12_content = file_get_contents($p12_tmp);
			$certs = [];

			// Intentar leer el P12
			if (!openssl_pkcs12_read($p12_content, $certs, $p12_pass)) {

				$msg = "Error al procesar el archivo P12. ";

				// Capturar errores internos de OpenSSL
				while ($err = openssl_error_string()) {
					$msg .= "<br>🔍 OpenSSL: " . $err;
				}

				setEventMessages($msg, null, 'errors');
			} else {
				// Guardar CERT
				if (!empty($certs['cert'])) {
					file_put_contents($upload_dir . "cert.pem", $certs['cert']);
					setEventMessages("cert.pem generado correctamente", null, 'mesgs');
				}

				// Guardar KEY
				if (!empty($certs['pkey'])) {
					file_put_contents($upload_dir . "key.pem", $certs['pkey']);
					setEventMessages("key.pem generado correctamente", null, 'mesgs');
				}

				// Guardar CA si existe
				if (!empty($certs['extracerts'])) {
					// Si hay varias, las concatenamos
					file_put_contents($upload_dir . "ca-bundle.crt", implode("\n", $certs['extracerts']));
					setEventMessages("ca-bundle.crt generado correctamente", null, 'mesgs');
				} else {
					setEventMessages("No se encontraron certificados CA en el P12.", null, 'warnings');
				}
			}
		}
	}
}

// Recuperar valores actuales
$mode      = getDolGlobalString('VERIFACTU_MODE');
$auto_send = getDolGlobalInt('VERIFACTU_AUTO_SEND');
$production_ack = getDolGlobalInt('VERIFACTU_PRODUCTION_ACK');

// -------------------- VIEW --------------------
llxHeader('', 'Configuración VeriFactu 104', '', '', 0, 0, '', '', 0, 0, 'none');
print load_fiche_titre('Configuración VeriFactu 104', '', 'fa-file');
print '<div class="info" style="background:#fff3cd;border:1px solid #ffeeba;padding:12px;margin-bottom:20px;">
<b>Versión experimental mantenida por Check 4 Cyber SARL.</b><br>
No constituye una declaración responsable RSIF. Use primero un entorno aislado y AEAT de pruebas. La producción permanece bloqueada hasta confirmación explícita.
</div>';

// Inicio formulario
print '<form method="POST" enctype="multipart/form-data">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="save">
<input type="hidden" name="MAX_FILE_SIZE" value="52428800">
	';


// --- Parámetros de configuración ---
print '<table class="noborder" width="100%">';
print '<tr class="liste_titre"><th colspan="2">Parámetros de configuración</th></tr>';

print '<tr><td width="40%">Modo de envío</td><td>';
print '<select name="VERIFACTU_MODE">';
print '<option value="test"' . ($mode == 'test' ? ' selected' : '') . '>Entorno de pruebas (prewww2)</option>';
print '<option value="prod"' . ($mode == 'prod' ? ' selected' : '') . '>Producción (www2)</option>';
print '</select>';
print '</td></tr>';

print '<tr><td>Envío automático a Hacienda</td><td>';
print '<input type="checkbox" name="VERIFACTU_AUTO_SEND" value="1"' . ($auto_send ? ' checked' : '') . '> Activar';
print '</td></tr>';
print '<tr><td>Confirmación de producción</td><td>';
print '<label><input type="checkbox" name="VERIFACTU_PRODUCTION_ACK" value="1"'.($production_ack ? ' checked' : '').'> Confirmo que esta instalación y versión han superado el plan de validación antes de transmitir datos reales</label>';
print '</td></tr>';
print '</table><br>';

// --- Subida ZIP / P12 ---
print '<table class="noborder" width="100%">';
print '<tr class="liste_titre"><th>Certificados</th><th>Acción</th></tr>';

// Método ZIP
print '<tr>';
print '<td>';
print 'Sube un archivo ZIP que contenga <strong>cert.pem</strong>, <strong>key.pem</strong> y <strong>ca-bundle.crt</strong>.<br>';
print 'Si el ZIP está vacío, se eliminarán los certificados existentes.';
print '</td>';
print '<td><input type="file" name="cert_zip" accept=".zip"></td>';
print '</tr>';

// Método P12
print '<tr>';
print '<td>Sube un archivo <strong>.p12</strong> y se convertirá automáticamente a los PEM necesarios.</td>';
print '<td><input type="file" name="cert_p12" accept=".p12"></td>';
print '</tr>';

print '<tr>';
print '<td>Contraseña del archivo P12</td>';
print '<td><input type="password" name="cert_p12_pass" autocomplete="off"></td>';
print '</tr>';

print '</table><br>';

// --- Mostrar estado actual ---
print '<table class="noborder" width="100%">';
print '<tr class="liste_titre"><th>Archivo</th><th>Estado actual</th></tr>';

$expected = ['cert.pem', 'key.pem', 'ca-bundle.crt'];
foreach ($expected as $f) {
	$filepath = $upload_dir . $f;
	print '<tr><td>' . $f . '</td><td>';
	if (file_exists($filepath)) {
		print '<span style="color:green">✔️ ' . dol_escape_htmltag($filepath) . '</span>';
	} else {
		print '<span style="color:#999">— No encontrado —</span>';
	}
	print '</td></tr>';
}
print '</table>';

print '<br><input type="submit" class="button" value="Guardar">';
print '</form>';

llxFooter();
$db->close();
