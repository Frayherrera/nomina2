# AUDIT PRO — Auditoría de nómina (Laravel 12)

Plataforma para auditar la nómina de varias empresas: cada empresa tiene sus periodos, sus homologaciones de columnas y su historial de auditorías.

## Funcionalidades

- **Inicio de sesión** con datos aislados por usuario (cada auditor solo ve sus empresas).
- **Panel principal** con indicadores: empresas, auditorías ejecutadas y hallazgos totales.
- **Empresas**: registro con nombre y NIT (opcional).
- **Periodos de nómina** por empresa (mes/año, p. ej. "Enero de 2026").
- **Carga de 3 archivos**: archivo principal (la referencia) y dos archivos de comparación. Uso típico:
  1. Comprobante / liquidación de nómina en Excel (`.xlsm`/`.xlsx`) → principal.
  2. Reporte de SIIGO (Nómina detallada por concepto) → comparación 1.
  3. Planilla PILA en PDF (SuAporte / Enlace Operativo) → comparación 2.

  Lee Excel (`xlsx`, `xlsm`, `xls`), `csv`/`txt` (detecta `,` o `;`), `ods` y PDF de planilla PILA (incluso protegidos con contraseña de propietario; se leen en PHP puro, sin programas externos en el servidor). Otros formatos se adjuntan para revisión manual.
- **Lectura inteligente de hojas de cálculo**: ubica la fila de encabezados aunque arriba haya títulos (empresa, NIT, periodo), completa encabezados de dos filas, descarta filas de totales y firmas, y une todas las hojas con detalle por empleado (p. ej. Nómina + Provisión de prestaciones; o en SIIGO: Neto a pagar + Seguridad social + Prestaciones).
- **Homologación inteligente**: cada columna se asigna a un concepto estándar (Sueldo devengado, Auxilio de transporte, Salud y Pensión del empleado, Préstamos y anticipos, Total devengado, Total deducciones, Neto a pagar, IBC, aportes a seguridad social, Prima, Cesantías, Intereses, Vacaciones…). Reconoce automáticamente los encabezados del comprobante, SIIGO y la PILA, y permite corregir a mano. Columnas asignadas al mismo concepto de "suma" se suman (Préstamo + Anticipo = Préstamos). También admite archivos en formato por filas (Concepto / Valor / Cantidad). La homologación se guarda por empresa.
- **Motor de auditoría** (llave: identificación del empleado + concepto):
  - Registros duplicados en el archivo principal.
  - Empleados que están en un archivo y no en el otro (un hallazgo por empleado).
  - Nombres del empleado que no coinciden entre archivos.
  - Diferencias de valor por encima de la **tolerancia de redondeo** de cada archivo comparativo ($1 por defecto; $100 para la PILA, que redondea aportes a la centena).
  - Solo compara conceptos que existen en ambos archivos; la PILA se compara por equivalencia (IBC salud ↔ Sueldo devengado, Aporte salud ↔ Salud del empleado).
  - Entiende montos en formato colombiano e internacional (`$ 1.250.000`, `1.250.000,50`, `1,250,000.50`).
- **Resultados** con buscador (empleado, identificación o concepto) y filtros (Todos / Con diferencia / Revisión manual).
- **Informe PDF** horizontal con resumen y detalle de cada hallazgo.
- **Historial** de auditorías, para consultarlas sin volver a cargar los archivos.

## Ejecutar con Docker

Requisito: Docker Desktop.

```bash
docker compose up -d --build
```

Abre http://localhost:8000.

El contenedor instala dependencias si faltan, crea `.env` y la clave de la app, ejecuta migraciones y crea el usuario auditor inicial:

| Campo | Valor por defecto (cámbialo en `.env`) |
|---|---|
| Nombre | `AUDITOR_NAME="Jesús Emiro Pérez"` |
| Correo | `AUDITOR_EMAIL=auditor@auditpro.test` |
| Contraseña | `AUDITOR_PASSWORD=AuditPro2026!` |

Después de cambiar esos valores, ejecuta `docker compose exec app php artisan db:seed` para actualizar el usuario.

### Comandos útiles

```bash
docker compose exec app php artisan test          # pruebas automatizadas
docker compose exec app php artisan migrate:fresh --seed   # reinicia la base de datos
docker compose logs -f app                        # ver logs
docker compose down                               # detener
```

## Base de datos

Por defecto usa SQLite (`database/database.sqlite`). Para usar MySQL, cambia las variables `DB_*` en `.env` (la imagen ya incluye `pdo_mysql`).

## Estructura principal

| Ruta | Contenido |
|---|---|
| `app/Services/Audit/PayrollAuditEngine.php` | Reglas de auditoría |
| `app/Services/Audit/ColumnMapper.php` | Campos, alias de encabezados, normalización y lectura de montos |
| `app/Services/Audit/SpreadsheetReader.php` | Lectura de Excel/CSV/ODS (PhpSpreadsheet) |
| `app/Services/Audit/FileWorkspace.php` | Archivos cargados por empresa (sesión + `storage/app/private/uploads`) |
| `app/Http/Controllers/` | Autenticación, panel, empresas, periodos, carga de archivos y auditorías |
| `resources/views/` | Vistas Blade; `audits/pdf.blade.php` es el informe PDF |
| `public/css/app.css` | Estilos (CSS plano, sin compilación) |
| `tests/` | Pruebas del motor y del flujo completo |
