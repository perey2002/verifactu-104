# Módulo Dolibarr — Verifactu 104 (RSIF para S.L.)

### Cumplimiento con Ley antifraude. Reglamento RSIF — Compatible con VeriFactu.


#### ⚠️  Quien instale y o utilice este módulo en producción debe verificar y hacerse responsable del cumplimiento vía certificado accesible desde el software.. ####

#### 📌 Hasta ahora ningún desarrollador de módulo veri*factu para Dolybarr se hace responsable del cumplimiento de la normativa vía certificado del desarrollador del cumplimiento. Punto obligatorio para vender software veri*factu.
#### ⚠️  Si en tus pruebas ves un error 1208 es porque para probar este software y usarlo como empresa debes obtener tu "firma de sello" (un archivo .p12 concreto que se llama "de sello". La forma de obtenerlo es a través de agencias autorizadas y el precio mínimo son 240 €/año si es a través de la FNMT. Nosotros lo estamos probando con nuestroa propia firma de sello


---

# 📌 Descripción general

**Verifactu 104** es un módulo desarrollado por **104 CUBES.** para **Dolibarr ERP/CRM**, que implementa las funcionalidades para cumplir los requisitos técnicos del **RSIF** derivados del Real Decreto 1007/2023 con compatibilidad con exigencias de VERI*FACTU.

Este módulo tiene las funcionallidades para cumplircon la **integridad, trazabilidad e inalterabilidad** de cada registro mediante:

- Hash encadenado  
- Código QR regulado  
- XML conforme al esquema RSIF (AEAT) * **Probando**
- Registro de acciones  
- Bloqueo y control de modificaciones  
- Página adicional en el PDF  
- Conservación de toda la evidencia digital en el directorio de cada factura  


---

# 📘 Cumplimiento legal

Este módulo permite al usuario cumplir:

- Registro encadenado  
- XML RSIF  * **En prubas**
- Hash y QR  
- Inalterabilidad  
- Evidencia y trazabilidad  

#### ⚠️  Quien instale y o utilice este módulo en producción debe verificar y hacerse responsable de este cumplimiento mediante certificación de cumplimiento ####

---

# 📋 Funcionalidades incluidas

### ✔ 1. Hash encadenado automático
Cada factura validada genera un hash SHA256 encadenado con la factura anterior, asegurando la trazabilidad cronológica requerida por RSIF.

### ✔ 2. Generación de Código QR oficial
El módulo genera el QR con la estructura establecida por la AEAT y lo guarda junto a la factura.

### ✔ 3. Página certificada en el PDF
Sin modificar ninguna plantilla PDF de Dolibarr, añade automáticamente una página final con:

- QR de la factura  
- Hash actual  
- Identificación del emisor  
- Resumen esencial de la factura  
- Evidencia de integridad criptográfica  

Compatible con cualquier modelo de factura de dolibarr, incluso personalizados.

### ✔ 4. Generación del XML RSIF completo 
* **Probando**
Cada factura genera un XML conforme al esquema oficial (`SuministroLR.xsd`).  
Se guarda en el directorio de documentos de la factura junto al PDF y el QR.

XML es **válido para VeriFactu**

### ✔ 5. Registro de acciones RSIF
El módulo documenta eventos internos asociados a:

- Validación  
- Cálculo de hash  
- Generación de QR  
- Generación de XML * **Probando**
- Registro de altas de factura, subsanación, anulación, rectificativas, reintentos.
- Cualquier operación crítica RSIF  

### ✔ 6. Control de integridad y bloqueo
Una vez generada la evidencia RSIF:

- No se permite volver la factura a borrador si ya ha sido enviada (cuando se conecte con AEAT opcionalmente).  
- No se permite modificar una factura fuera del orden cronológico. Es decir, sólo la última factura se puede modificar (Dolibarr nativo permite modificaciones).

Esto evita romper la cadena de trazabilidad.

---

# 🔌 7 Envío a la AEAT (VeriFactu)


Este módulo incluye un panel de configuración donde el usuario puede activar o desactivar la funcionalidad relacionada con el modo VeriFactu. 

El backend permite activar Verifactu en modo pruebas o producción. Para hacerlo solicta certificado .p12 y la contraseña (no se guarda, sólo e usa para extraer lo archivos .key y .pem.
O añadir en un zip cerificados .key y .pem ya extraídos.

Deben ser de un certificado de sello digital.

  
**podemos ayudarte a completar este módulo con dicha funcionalidad (sin cuotas mensuales o anuales)**.  

---

# 🔧 Requisitos del sistema

| Componente | Versión | Comentario |
|-----------|---------|------------|
| Dolibarr ERP/CRM | 16.0 – 22.x | Probado en 20 y 22 |
| PHP | 7.4+ | Recomendado 7.4 o superior |
| Extensiones PHP | `openssl`, `gd` | Para hash y QR |
| Servidor | Linux recomendado | Compatible con Apache/PHP/SQL |

---

# 🚀 Instalación

1. Descargar el ZIP del módulo desde GitHub.  
2. Descomprimir y subir la carpeta a `/custom/`.  
3. Renombrar la carpeta a: `verifactu104`  
4. Activar el módulo desde:  
   Inicio → Configuración → Módulos/Aplicaciones  

---


#### ⚠️  Recuerda que es de obligado certificar el cumplimiento de la normativa so pena de grandes multas según artículo 201. bis de la Ley Antifraude. ####
#### ⚠️  Quien instale y o utilice este módulo en producción debe verificar, certificar y hacerse responsable del cumplimiento de la normativa RSIF. ####
---

# 📣 Comentarios y soporte

Puedes dejar tus dudas en el espacio de discusion
