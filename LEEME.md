# MiSalaUCR — Sistema de reservación de salas de estudio

Aplicación multi-organización para que asociaciones estudiantiles gestionen la
reservación de sus salas de estudio. Construida en PHP (compatible con cualquier
plan de Hostinger) con base de datos SQLite (local) o MySQL (producción).

## Configuración (antes de arrancar)

Los datos sensibles no van en el repositorio. Copie la plantilla y complete sus valores:

```
cp config.example.php config.local.php
```

En `config.local.php` defina el API key de Resend, las credenciales de MySQL
(en producción) y las **contraseñas iniciales** del super-admin y del admin
(`seed`). Este archivo está en `.gitignore` y nunca se sube.

## Cuentas iniciales

Se crean al generar la base de datos por primera vez, con las contraseñas que
usted ponga en `config.local.php` → `seed`:

| Rol | Usuario (por defecto) | Contraseña |
|---|---|---|
| Super-admin (plataforma) | `castroramirez702@gmail.com` | la que defina en `seed.super_pass` |
| Admin de la asociación | `admin.civil@misalaucr.test` | la que defina en `seed.admin_pass` (placeholder: cámbielo desde el panel de super-admin) |

Si deja alguna contraseña vacía, se genera una segura al azar y se guarda una
única vez en `data/credenciales_iniciales.txt`. Los estudiantes no se
auto-registran: el admin los crea (uno por uno o por CSV) y entran con
**carné + contraseña temporal**, que deben cambiar al primer ingreso.

## Probar en su computadora (opción rápida)

1. Instale PHP para Windows (o XAMPP: https://www.apachefriends.org).
2. Abra una terminal en esta carpeta y ejecute:
   ```
   php -S localhost:8000
   ```
3. Abra http://localhost:8000 en el navegador. La base de datos se crea sola
   (archivo `data/misalaucr.sqlite`) con la organización de Ingeniería Civil,
   sus 3 salas y las cuentas iniciales.

Con XAMPP: copie esta carpeta a `C:\xampp\htdocs\misalaucr`, inicie Apache y
abra http://localhost/misalaucr.

## Publicar en Hostinger

1. **Base de datos**: en hPanel → Bases de datos → MySQL, cree una base y un
   usuario. Anote nombre, usuario y contraseña.
2. **Configuración**: en `config.local.php` (copiado de `config.example.php`) ponga:
   ```php
   'db' => [
       'driver' => 'mysql',
       'mysql'  => ['host' => 'localhost', 'dbname' => 'SU_BD', 'user' => 'SU_USUARIO', 'pass' => 'SU_CLAVE'],
   ],
   'base_url' => 'https://SU_DOMINIO',
   ```
3. **Subir archivos**: suba TODO el contenido de esta carpeta a `public_html`
   (por el Administrador de archivos de hPanel o FTP). Las tablas y datos
   iniciales se crean solos en la primera visita.
4. **Cron (recomendado)**: en hPanel → Avanzado → Cron Jobs agregue cada 5 min:
   ```
   php /home/SU_USUARIO/public_html/cron.php
   ```
   Esto procesa no-shows y recordatorios con puntualidad incluso sin visitas.
   (La app también los procesa sola en cada visita, así que funciona sin cron.)
5. **HTTPS**: active el certificado SSL gratuito en hPanel → Seguridad.

## Activar correos (cuando decida hacerlo)

1. Cree cuenta gratuita en https://resend.com (3,000 correos/mes gratis).
2. Verifique su dominio y cree un API key.
3. En `config.local.php` pegue el key en `'resend_api_key'` y ajuste `'mail_from'`.

Mientras no haya API key, la app funciona igual: los correos de confirmación,
recordatorio y bloqueo quedan registrados en el panel Admin → Correos.

## Carga masiva de estudiantes (CSV)

Un estudiante por línea, con este orden de columnas (vea `ejemplo_estudiantes.csv`):

```
nombre,carné,correo,teléfono,contraseña
Ana Mora Pérez,C23451,ana.mora@ucr.ac.cr,8888-1111,
José Solano Li,C23452,jose.solano@ucr.ac.cr,,ClaveTemp1
```

Correo, teléfono y contraseña son opcionales; si falta la contraseña se genera
una automática y se muestra **una sola vez** tras la importación para
entregarla al estudiante.

## Reglas (configurables por organización, panel Admin → Configuración)

- Horario operativo (por defecto L–V, 7:00–22:00, bloques de 1 hora).
- Solo se reserva el mismo día; los bloques se abren a la hora de apertura.
- Máximo de horas por sesión (2) y por semana (4, ciclo lunes a domingo, ajustable).
- Cancelación del estudiante: hasta 10 minutos antes del inicio del bloque.
- Check-in de 10 minutos al iniciar el bloque; sin confirmación → no-show y el
  espacio se libera (si hay fila de espera, se asigna automáticamente al primero).
- 3 no-shows → bloqueo de 1 semana (el admin puede levantarlo manualmente).
- Fila de espera: si un bloque está ocupado, el estudiante puede inscribirse;
  al liberarse, la reserva se asigna en orden de llegada y se avisa por correo.

## Otras funciones

- Admin: renombrar salas (pestaña Salas) y teléfono de estudiantes.
- Super-admin: dashboard (resumen, ranking de uso, actividad reciente),
  crear/mover estudiantes entre asociaciones, y congelar asociaciones por
  impago con motivo visible (los usuarios pierden acceso de inmediato y ven
  la pantalla de "asociación suspendida").

## Estructura

```
config.php        configuración (BD, correo, zona horaria)
index.php         redirige según rol
login.php         ingreso con carné o correo
password.php      cambio de contraseña (forzado al primer ingreso)
student.php       vista del estudiante (reservar, cancelar, check-in)
admin.php         panel de la asociación (reservas, estudiantes, salas, reportes, config, correos)
superadmin.php    panel de la plataforma (organizaciones y sus admins)
cron.php          tareas automáticas (no-shows, recordatorios)
lib/              conexión BD, autenticación, reglas de negocio, correo, plantilla
assets/style.css  estilos (mobile-first)
data/             base SQLite local (protegida por .htaccess)
```
