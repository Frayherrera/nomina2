# AUDIT PRO — Auditoría de nómina (Laravel 12)

Plataforma para auditar la nómina de varias empresas: cada empresa tiene sus periodos, sus homologaciones de columnas y su historial de auditorías.

## Funcionalidades

- **Inicio de sesión** con datos aislados por usuario (cada auditor solo ve sus empresas).
- **Panel principal** con indicadores: empresas, auditorías ejecutadas y hallazgos totales.
- **Empresas**: registro con nombre y NIT (opcional).
- **Periodos de nómina** por empresa (mes/año, p. ej. "Enero de 2026").
- **Carga de 3 archivos**: archivo principal (la referencia) y dos archivos de comparación.
  Lee Excel (`xlsx`, `xlsm`, `xls`), `csv`/`txt` (detecta `,` o `;`) y `ods`. Se puede adjuntar cualquier otro formato, pero queda pendiente para revisión manual.
- **Homologación inteligente**: reconoce encabezados comunes (Cédula, Documento, Nombre, Trabajador, Concepto, Descripción, Valor, Importe, Total, Días, Horas…) y permite corregir a mano los que no reconoce. La homologación se guarda por empresa y se reutiliza en los siguientes periodos.
- **Motor de auditoría** (llave: identificación del empleado + concepto):
  - Registros duplicados en el archivo principal.
  - Registros del archivo comparativo que no están en el principal.
  - Registros del principal que faltan en cada archivo comparativo.
  - Diferencias de valor (más de $0,01) y diferencias de cantidad (solo si ambos archivos tienen la columna de cantidad).
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
