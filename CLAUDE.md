# MiSalaUCR — Instrucciones para la IA (LEER ANTES DE CUALQUIER CAMBIO)

> Este archivo se lee automáticamente al inicio de cada sesión.
> **Obligatorio** revisarlo antes de editar código, tocar configuración,
> hacer commit/push o dar instrucciones de despliegue.

MiSalaUCR es un sistema **en producción y con usuarios reales**.
El sitio está publicado en **https://www.misalaucr.com** (Hostinger, PHP + MySQL).
Un cambio descuidado puede dejar el sitio caído o a la gente sin poder entrar.
Trabaja con esa mentalidad: **primero no romper**.

---

## 🔴 Reglas críticas (NUNCA sin confirmación explícita del usuario)

1. **No modificar la forma en que se cargan la configuración y los secretos.**
   Antes de tocar `config.php`, `config.local.php`, `.gitignore`, o el `seed`
   de `lib/db.php`, **detente y pregunta**. Un cambio aquí ya rompió el login en
   producción una vez (ver "Incidente" abajo).
2. **Nunca poner secretos en archivos versionados.** Contraseñas, API keys y
   credenciales de base de datos van **solo** en `config.local.php`
   (que está en `.gitignore`). Jamás en `config.php`, `LEEME.md`, `db.php`,
   `CLAUDE.md` ni en mensajes de commit.
3. **Nunca commitear ni subir a GitHub** `config.local.php` ni la base de datos
   (`data/*.sqlite`, dumps `.sql`).
4. **Cada `git push` a `main` publica en producción al instante** (Hostinger
   tiene auto-deploy por git). No hay entorno de pruebas: lo que subes lo ven
   los usuarios reales. Confirma y verifica antes de pushear cambios de
   lógica/config/BD. `config.local.php` **no** viaja por git a propósito (está
   en `.gitignore`), así que el despliegue no lo toca; si alguna vez falta en el
   servidor, se sube/edita **a mano** (hPanel o FTP). Ver "Auto-deploy" abajo.
5. **No tocar la base de datos de producción** (borrar, re-seed, migraciones
   destructivas, cambiar contraseñas) sin pedir confirmación y explicar el riesgo.
6. **No sobrescribir el `config.php` del servidor** dando por hecho su contenido.
   El servidor puede tener valores propios; pregunta antes.
7. **Cambios visuales ≠ cambios de lógica.** Un rediseño toca `assets/style.css`
   y a lo sumo `lib/layout.php`. No aproveches para "mejorar" configuración,
   autenticación o base de datos de paso.

---

## 🗂️ Archivos esenciales / delicados

| Archivo | Por qué es delicado |
|---|---|
| `config.php` | Estructura de configuración. Cambiarla afecta la conexión a la BD. |
| `config.local.php` | **Secretos reales** (MySQL, Resend). Ignorado por git. Existe en local y en el servidor por separado. |
| `lib/db.php` | Conexión, esquema y `seed`. El seed crea las cuentas iniciales. |
| `lib/auth.php` | Login, sesiones, CSRF, roles. |
| `lib/rules.php` | Reglas de negocio (reservas, no-shows, fila de espera). |
| `.gitignore` | Lo que protege que los secretos no se suban. |
| Base de datos MySQL | Datos reales de usuarios. Intocable sin confirmación. |

Cambios seguros de bajo riesgo: `assets/style.css`, textos, `LEEME.md`.

---

## ⚙️ Cómo funciona la configuración (no romper este contrato)

- `config.php` está **versionado** y **no contiene secretos**. Define valores por
  defecto y al final fusiona lo que haya en `config.local.php`:
  ```php
  return array_replace_recursive($defaults, $local);
  ```
- `config.local.php` está en `.gitignore`. Contiene los valores reales
  (driver `mysql`, credenciales de la BD, `resend_api_key`, `base_url`).
- **En el servidor**, `config.local.php` debe existir en `public_html` junto a
  `config.php`. Si falta, la app cae a SQLite por defecto y **el login deja de
  funcionar** aunque las contraseñas sean correctas.
- Plantilla de referencia (sin secretos): `config.example.php`.

## 🌐 Producción (Hostinger)

- Dominio: **https://www.misalaucr.com**  ·  Ruta: `public_html/`
- Base de datos: **MySQL** (el nombre real está en el `config.local.php` del
  servidor y en el dump que descarga el usuario desde hPanel).
- Correos: **Resend** (API key en `config.local.php`).
- Repositorio: `https://github.com/adrianix360/misalaucr` (**público** → no subir
  secretos jamás).

### 🚀 Auto-deploy por git (¡CADA PUSH PUBLICA EN PRODUCCIÓN!)
- Hostinger tiene **recarga automática por git** configurada: cada `git push`
  a `main` **se despliega solo y al instante** en www.misalaucr.com. No hay
  paso manual de subida.
- **Consecuencia crítica:** un push = publicar en vivo para usuarios reales.
  No existe "staging". Antes de hacer push de algo que toque lógica, base de
  datos o configuración, **confirma con el usuario y verifica** (sintaxis con
  `php -l`, sin secretos en el diff). Los cambios solo visuales son de bajo
  riesgo, pero igual van directo a producción.
- `config.local.php` está en `.gitignore`, así que el auto-deploy **nunca lo
  pisa**: las credenciales del servidor quedan intactas en cada despliegue.
- Verificar que un cambio ya está en vivo: `curl -s https://www.misalaucr.com/…`
  y buscar el marcador esperado (así se confirmó el despliegue el 2026-07-28).

## ✅ Flujo seguro antes de subir cambios o desplegar

1. Leer este archivo.
2. Identificar si el cambio toca algo de la lista delicada. Si sí → **confirmar
   con el usuario** antes de proceder.
3. Verificar sintaxis PHP: `php -l <archivo>`.
4. Revisar que no haya secretos en lo que se va a commitear
   (`git status`, `git diff --cached`).
5. Al desplegar, recordar que `config.local.php` se sube **manualmente** al
   servidor y **no** por git.

---

## 📌 Incidente que originó estas reglas (2026-07-28)

Se reestructuró `config.php` para sacar los secretos a `config.local.php` (bien),
pero el servidor **no tenía** `config.local.php`, así que al actualizar el código
la app perdió los datos de conexión MySQL y **nadie podía iniciar sesión** —aunque
las contraseñas en la BD estaban intactas—. Se resolvió subiendo `config.local.php`
con las credenciales MySQL al servidor. **Lección:** cualquier cambio en la carga
de configuración o en `.gitignore` debe considerar qué existe (o falta) en
producción, y confirmarse antes.

## 🗣️ Preferencias del usuario (Diana)

- Respuestas **breves y directas, en español**.
- Preguntar lo necesario **antes** de ejecutar cambios que puedan afectar el sitio.
- Priorizar no romper producción por encima de "mejoras" espontáneas.
