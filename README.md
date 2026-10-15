# Books API (ompApiWrite)

Plugin genérico para **OMP 3.3** que agrega endpoints de **escritura** a la API
REST para gestionar libros desde un backend (por ejemplo, el sistema de
ECORFAN). El core de OMP solo permite editar libros con una asignación
editorial al flujo de trabajo; este plugin permite operar con un token de
**Gestor de la editorial**, sin interfaz.

Es el equivalente de [issueApiWrite](../issueApiWrite) (OJS) para OMP:

| OJS (issueApiWrite) | OMP (ompApiWrite) |
|---------------------|-------------------|
| Número (issue) | — (OMP no tiene números) |
| Artículo | **Libro** (monografía o volumen editado) |
| Sección | **Serie** (colección) |
| Galerada | **Formato de publicación** (PDF, EPUB, impreso…) con sus archivos |
| — | **Capítulos** (con sus autores y su DOI) |
| Autores | Autores y **editores del volumen** |
| Journals en masa | **Editoriales (presses)** en masa |

## Identificación

| Dato | Valor |
|------|-------|
| Nombre visible | Books API |
| Nombre técnico | `ompapiwriteplugin` |
| Carpeta | `plugins/generic/ompApiWrite` |
| Categoría | Genérico (generic) |
| Clase | `OmpApiWritePlugin` |

---

## Instalación

1. **Requisitos:** OMP **3.3.x** y PHP con la extensión `intl`.
2. **`api_key_secret`** en `config.inc.php`, sección `[security]`. Es
   obligatorio: sin él OMP no muestra el token de API.
   ```ini
   [security]
   api_key_secret = "una-cadena-larga-y-aleatoria"
   ```
   Genera el valor con `php -r "echo bin2hex(random_bytes(32));"`. Si lo
   cambias después, se invalidan todos los tokens.
3. **Copia** esta carpeta a `plugins/generic/ompApiWrite`.
4. **Registra la versión** desde la raíz de OMP:
   ```bash
   php lib/pkp/tools/installPluginVersion.php plugins/generic/ompApiWrite/version.xml
   ```
   > ⚠️ `version.xml` debe declarar `<class>OmpApiWritePlugin</class>`. Si no
   > lo hace, el plugin parece instalado pero sus endpoints responden `404`.
5. **Habilítalo** en cada editorial: *Ajustes → Sitio web → Plugins →
   Plugins genéricos → "Books API"*.
6. **Token:** inicia sesión con un usuario con rol **Gestor de la editorial**
   y ve a *Perfil → API Key*. Activa "Enable external applications…", guarda y
   copia el token.

Verificación:

```bash
curl -H "Authorization: Bearer <TOKEN>" \
  "http://<host>/index.php/<editorial>/api/v1/submissions/books/series"
```

| Síntoma | Causa probable |
|---------|----------------|
| El perfil no muestra el token | `api_key_secret` vacío |
| `404 endpointNotFound` | Plugin no habilitado en esa editorial, o falta `<class>` en `version.xml` |
| `403` | Falta `Authorization: Bearer`, o el usuario no es Gestor |
| `400 csrfInvalid` | Se está autenticando con cookie de sesión en lugar del token |

## Autenticación

- Header `Authorization: Bearer <TOKEN>` en todas las peticiones. Con token no
  hace falta el CSRF.
- El rol exigido es **Gestor de la editorial** (`ROLE_ID_MANAGER`).
- Excepción: los endpoints de **editoriales en masa** son de sitio. Se llaman
  bajo `/index.php/index/api/v1/...` y exigen el rol **Administrador del
  sitio**.

## Colección de Postman

Importa [`postman_collection.json`](postman_collection.json) y define las
variables `base_url`, `press` (ruta de la editorial) y `token`.

---

## Flujo típico: publicar un libro

```
1. POST /temporaryFiles                     → id del PDF (y otro para la portada)
2. POST /submissions/books                  → crea el libro (sin publicar), con
                                              autores, portada y formato PDF
3. POST .../publications/{pid}/chapters     → capítulos (opcional)
4. POST .../formats/{fid}/documents         → PDF por capítulo (opcional)
5. PUT  /submissions/books/{id}/publish     → aparece en el catálogo
```

Todas las rutas siguientes son relativas a
`/index.php/<editorial>/api/v1`.

### Subir archivos: `POST /temporaryFiles` (API del core)

Toda subida se hace en dos pasos. Primero se envía el archivo como
`multipart/form-data` (campo `file`) y se obtiene `{ "id": 123 }`. Después se
usa ese id como `temporaryFileId` o `coverImageTemporaryFileId`. El archivo
temporal solo puede usarlo el mismo usuario que lo subió.

---

## Series

Obras de varios tomos: cada tomo es un libro con `seriesId` y su número en
`seriesPosition`. Los libros de la serie se ordenan por `seriesPosition`.

| Método | Ruta | Acción |
|--------|------|--------|
| GET | `/submissions/books/series` | Series de la editorial (para elegir `seriesId`) |
| POST | `/submissions/books/series` | Crear serie |
| GET | `/submissions/books/series/{seriesId}` | Una serie |
| PUT | `/submissions/books/series/{seriesId}` | Editar (solo los campos enviados) |
| DELETE | `/submissions/books/series/{seriesId}` | Eliminar (solo si no tiene libros: `409` si los tiene) |

### Campos de la serie

| Campo | Tipo | Notas |
|-------|------|-------|
| `title` | string u objeto `{locale: …}` | **Obligatorio** al crear |
| `prefix`, `subtitle`, `description` | string u objeto | |
| `locale` | string | Idioma de los campos enviados como string. Por defecto, el principal de la editorial |
| `path` | string | Letras, números, `/`, `.`, `-` y `_`; único en la editorial. Si no se envía al crear, se genera del título (`ciencias-sociales`, `ciencias-sociales-2`…) |
| `seq` | número | Posición entre las series. Por defecto, la última |
| `isInactive` | bool | Oculta la serie para nuevos envíos |

La respuesta incluye `id` y `bookCount` (libros cuya publicación actual está en la serie).

```json
POST /submissions/books/series
{ "title": "CIERMMI Mujeres en la Ciencia", "prefix": "Handbook" }
```

---

## Libros

| Método | Ruta | Acción |
|--------|------|--------|
| POST | `/submissions/books` | Crear libro (sin publicar) |
| GET | `/submissions/books/{submissionId}` | Libro completo: metadatos, autores, capítulos y formatos |
| PUT | `/submissions/books/{submissionId}` | Editar metadatos (solo los campos enviados) |
| DELETE | `/submissions/books/{submissionId}` | Eliminar (solo si no está publicado) |
| PUT | `/submissions/books/{submissionId}/publish` | Publicar en el catálogo |
| PUT | `/submissions/books/{submissionId}/unpublish` | Retirar del catálogo |

### Campos del libro

| Campo | Tipo | Notas |
|-------|------|-------|
| `title` | string u objeto `{locale: …}` | **Obligatorio** al crear |
| `subtitle`, `prefix`, `abstract` | string u objeto | |
| `coverage`, `rights`, `source`, `type`, `copyrightHolder` | string u objeto | |
| `keywords`, `subjects`, `disciplines`, `supportingAgencies`, `languages` | lista o `{locale: [..]}` | Una lista simple se guarda en el idioma del libro |
| `locale` | string | Idioma del libro (solo al crear). Por defecto, el principal de la editorial. Debe estar habilitado para envíos |
| `workType` | `"authored"` \| `"edited"` | Obra de autoría (por defecto) o volumen editado |
| `seriesId` | int | Serie de esta editorial (ver `GET /submissions/books/series`) |
| `seriesPosition` | string | Ej. `"Vol. 3"` |
| `datePublished` | `AAAA-MM-DD` | Si es futura, al publicar el libro queda **programado** |
| `licenseUrl`, `copyrightYear`, `citationsRaw` | | |
| `doi` | string | `10.xxxx/...`. Se valida que no esté repetido en la editorial. Enviar `""` lo borra |
| `coverImageTemporaryFileId` | int | Portada (GIF, JPG, PNG o WEBP). OMP genera la miniatura |
| `coverImageAltText` | string | Texto alternativo de la portada |
| `coverImageLocale` | string | Idioma de la portada (por defecto, el del libro) |
| `authors` | lista | **Solo al crear.** Ver *Autores*. El primero es el contacto principal |
| `temporaryFileId` | int | **Solo al crear.** Crea un formato digital con ese archivo |
| `isbn` | string | **Solo al crear.** ISBN de 10 o 13 dígitos (con o sin guiones). Se guarda como código de identificación ONIX del formato digital (`15` ISBN-13, `02` ISBN-10); sin `temporaryFileId` el formato se crea sin archivo |
| `formatName` | string | Nombre de ese formato (por defecto `"PDF"`) |
| `salesType` / `price` | | Cómo se ofrece ese archivo (ver *Venta y acceso*) |

Al crear, **todo se valida antes de escribir nada**: si un autor, la portada o
el DOI no son válidos, se responde `400` sin crear el libro.

```http
POST /index.php/ecorfan-libros/api/v1/submissions/books
Authorization: Bearer <TOKEN>
Content-Type: application/json

{
  "title": "Innovación tecnológica en México",
  "subtitle": "Casos de estudio",
  "locale": "es_ES",
  "workType": "edited",
  "seriesId": 1,
  "seriesPosition": "Vol. 3",
  "keywords": ["innovación", "tecnología"],
  "doi": "10.35429/B.2026.1",
  "authors": [
    { "givenName": "Ana", "familyName": "Pérez", "email": "ana@example.com", "isVolumeEditor": true },
    { "givenName": "Luis", "familyName": "Gómez", "email": "luis@example.com", "orcid": "0000-0002-1825-0097" }
  ],
  "coverImageTemporaryFileId": 41,
  "temporaryFileId": 42
}
```

Respuesta `201`: el libro completo, el mismo cuerpo que devuelve `GET
/submissions/books/{id}`:

```json
{
  "submissionId": 15, "publicationId": 17, "status": "unpublished",
  "workType": "edited", "locale": "es_ES", "seriesId": 1, "seriesPosition": "Vol. 3",
  "primaryContactId": 30, "doi": "10.35429/B.2026.1",
  "urlPublished": "http://<host>/index.php/ecorfan-libros/catalog/book/15",
  "title": { "es_ES": "Innovación tecnológica en México" },
  "authors": [ ... ], "chapters": [ ... ], "publicationFormats": [ ... ]
}
```

### Reglas de publicación

- Un libro se crea **en la etapa de producción y sin publicar**.
- **Publicar** pasa por `validatePublish()` del core, la misma comprobación
  que hace la interfaz. Si falla, responde `400` con los errores.
- Con un libro **publicado** sus metadatos, autores y capítulos quedan
  **bloqueados** (`409`), igual que en la interfaz de OMP. Para corregirlo hay
  que despublicarlo, editarlo y volver a publicarlo.
- Los **formatos y sus archivos** se pueden gestionar aunque el libro esté
  publicado, por ejemplo para añadir un EPUB.
- **Eliminar** un libro publicado responde `409`: primero hay que
  despublicarlo. Así no desaparece del catálogo por accidente.

---

## Autores

`/submissions/{submissionId}/publications/{publicationId}/authors`

| Método | Ruta | Acción |
|--------|------|--------|
| GET | `…/authors` | Listar |
| POST | `…/authors` | Agregar |
| PUT | `…/authors/{authorId}` | Editar (solo los campos enviados) |
| DELETE | `…/authors/{authorId}` | Eliminar (también lo quita de los capítulos) |

| Campo | Notas |
|-------|-------|
| `givenName` | **Obligatorio**, string u objeto `{locale: …}` |
| `familyName`, `affiliation`, `biography`, `preferredPublicName` | String u objeto |
| `email` | **Obligatorio** en OMP |
| `country` | Código ISO de 2 letras, ej. `MX` |
| `orcid` | iD o URL. Se guarda como `https://orcid.org/…` |
| `isVolumeEditor` | `true` para un editor del volumen (libros `edited`) |
| `userGroupId` | Opcional. Por defecto, el grupo "Volume editor" o "Author" según `isVolumeEditor` |
| `seq`, `includeInBrowse` | |
| `primaryContact` | `true` lo convierte en el contacto principal |
| `fetchOrcidProfile` | Por defecto `true`. Completa los campos vacíos con el perfil público de ORCID |
| `lookupOrcidByName` | Por defecto `false`. Si es `true` y no se envía `orcid`, busca el iD por nombre y solo lo usa si hay **un único** resultado |

> La búsqueda por nombre está desactivada por defecto a propósito: un nombre no
> identifica a una persona, y un iD equivocado atribuye el libro a otro
> investigador.

---

## Capítulos

`/submissions/{submissionId}/publications/{publicationId}/chapters`

| Método | Ruta | Acción |
|--------|------|--------|
| GET | `…/chapters` | Listar (en orden) |
| POST | `…/chapters` | Agregar |
| PUT | `…/chapters/{chapterId}` | Editar (solo los campos enviados) |
| DELETE | `…/chapters/{chapterId}` | Eliminar. Sus archivos se conservan, sin asignar a capítulo |

| Campo | Notas |
|-------|-------|
| `title` | **Obligatorio**, string u objeto |
| `subtitle`, `abstract` | String u objeto |
| `pages` | Ej. `"15-42"` |
| `datePublished` | `AAAA-MM-DD` |
| `authorIds` | IDs de autores **de esta misma publicación**, en orden. Al editar se reemplaza la lista completa |
| `seq` | Posición. Por defecto, al final |
| `doi` | DOI del capítulo |

---

## Formatos de publicación y sus archivos

`/submissions/{submissionId}/publications/{publicationId}/formats`

| Método | Ruta | Acción |
|--------|------|--------|
| GET | `…/formats` | Listar, con sus archivos (`documents`) |
| POST | `…/formats` | Agregar formato (y opcionalmente un primer archivo) |
| PUT | `…/formats/{formatId}` | Editar |
| DELETE | `…/formats/{formatId}` | Eliminar el formato, sus metadatos ONIX y sus archivos |
| POST | `…/formats/{formatId}/documents` | Agregar archivo |
| PUT | `…/formats/{formatId}/documents/{submissionFileId}` | Cambiar venta/acceso, capítulo o DOI del archivo |
| DELETE | `…/formats/{formatId}/documents/{submissionFileId}` | Eliminar archivo |

> La subruta se llama `documents` y no `files` porque OMP envía a otro
> manejador cualquier ruta de `/submissions` que contenga `/files`.

| Campo del formato | Notas |
|-------------------|-------|
| `name` | **Obligatorio**, ej. `"PDF"`, `"EPUB"`, `"Impreso"` |
| `physicalFormat` | `true` para impreso. Por defecto `false` (digital) |
| `entryKey` | Código ONIX de forma de producto. Por defecto `DA` (digital) o `BC` (rústica) |
| `isApproved`, `isAvailable` | Por defecto `true`. Si alguno es `false`, el formato no se muestra en el catálogo |
| `remoteUrl` | Para un formato que se vende u hospeda fuera de OMP |
| `urlPath`, `seq`, `doi` | |
| `temporaryFileId`, `chapterId`, `salesType`, `price` | Primer archivo, opcional |

| Campo del archivo | Notas |
|-------------------|-------|
| `temporaryFileId` | **Obligatorio** al agregar |
| `chapterId` | Asigna el archivo a un capítulo, por ejemplo el PDF de ese capítulo |
| `salesType` / `price` | Ver abajo |
| `doi` | DOI del archivo |

Para reemplazar un archivo, elimina el anterior y agrega el nuevo.

### Venta y acceso

| `salesType` | Efecto |
|-------------|--------|
| `openAccess` (por defecto) | Descarga libre |
| `directSales` + `price` | A la venta. Requiere pagos configurados en OMP |
| `notAvailable` | Se lista, pero no se puede descargar |

También basta con enviar `price`: `0` equivale a acceso abierto y un valor
mayor que 0 a venta directa.

Un archivo es público cuando se cumplen cuatro condiciones: el libro está
publicado, el formato está aprobado y disponible, y el archivo es de acceso
abierto (o está pagado). Cada archivo incluye su `urlDownload`.

---

## Editoriales en masa (sitio)

Son operaciones de sitio y exigen el rol **Administrador del sitio**. Si se
llaman bajo una editorial responden `404 siteWideEndpoint`.

| Método | Ruta | Acción |
|--------|------|--------|
| POST | `/index.php/index/api/v1/contexts/bulk` | Crear varias editoriales (máximo 25 por petición) |
| GET | `/index.php/index/api/v1/contexts/export[?format=csv]` | Exportar todas (JSON o CSV) |

```json
{
  "presses": [
    {
      "urlPath": "ecorfan-libros-mx", "name": "ECORFAN Libros México", "acronym": "ELM",
      "primaryLocale": "es_ES",
      "supportedLocales": ["es_ES", "en_US"],
      "supportedFormLocales": ["es_ES", "en_US"],
      "supportedSubmissionLocales": ["es_ES", "en_US"]
    }
  ]
}
```

Cada elemento se procesa por separado. Una ruta que ya existe se reporta en
`skipped`, así que repetir el mismo lote es seguro. La respuesta es `201` si se
creó al menos una editorial y `200` si no se creó ninguna. En ambos casos
incluye `summary`, `created`, `skipped` y `errors`.

También se puede hacer desde la consola, con un CSV con las mismas columnas que
la exportación ([ejemplo](tools/presses.ejemplo.csv)):

```bash
php plugins/generic/ompApiWrite/tools/importPresses.php admin presses.csv
```

---

## Notas y límites

- **Versión:** solo funciona con OMP 3.3.x. La API interna cambió en 3.4.
- **DOI:** para que el DOI se muestre en el catálogo, habilita el plugin DOI de
  OMP (*Ajustes → Sitio web → Plugins → Identificadores públicos*). Guardar el
  DOI en OMP no lo registra en Crossref.
- **Sin transacciones:** el alta de un libro valida todo antes de escribir,
  pero si falla algo del servidor a mitad de camino (disco, base de datos),
  puede quedar un libro incompleto. En ese caso, revísalo con `GET` y
  corrígelo o elimínalo.
- **Metadatos ONIX avanzados** (códigos de identificación, mercados, fechas de
  publicación por formato, derechos de venta): por ahora solo se gestionan
  desde la interfaz de OMP.
- **Catálogo destacado / novedades:** se gestionan desde la interfaz.
