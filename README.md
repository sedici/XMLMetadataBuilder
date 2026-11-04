# XML Metadata Builder para OJS 3.5

Plugin genérico que enriquece **automáticamente** el JATS de cada publicación con los metadatos de OJS, siguiendo el perfil SciELO/SPS. No tiene interfaz de usuario.

## Qué XML enriquece

Solo el **JATS de la publicación** de OJS 3.5: el archivo que se sube en *Publicación → JATS* (`SubmissionFile` con `fileStage = SUBMISSION_FILE_JATS`, asociado a la publicación).

Si la publicación no tiene archivo subido, OJS muestra un JATS generado al vuelo por `jatsTemplate`; ese no se toca.

## Cuándo se enriquece

| Momento | Hook | Qué hace |
|---|---|---|
| Se sube el JATS de la publicación | `SubmissionFile::add` | Reemplaza el `<front>` y guarda el resultado como nueva revisión del mismo archivo |
| Se editan metadatos o autores (antes de publicar) | `Publication::edit`, `Author::add/edit/delete` | Vuelve a enriquecer al final de la petición, cuando los cambios ya están guardados |
| Se programa o publica | `Publication::publish` | Vuelve a enriquecer con los metadatos definitivos y genera o actualiza la galerada XML |
| Se crea una versión nueva | `SubmissionFile::add` + `Publication::version` | Core copia el XML (se enriquece por el primer hook); el plugin copia las imágenes, que core no copia, y enriquece de nuevo |

Los hooks de edición corren antes de que OJS guarde los cambios (y las afiliaciones se guardan dentro del update del autor), por eso el enriquecimiento se hace al final de la petición. Varios cambios en una misma petición producen un solo enriquecimiento. Reordenar autores no dispara ningún hook de OJS: el orden se actualiza en la próxima edición o al publicar.

El panel JATS carga el contenido al abrir la sección: después de editar metadatos hay que volver a entrar a la sección para ver el cambio.

- Si el contenido no cambia, no se crea una revisión nueva.
- Si el enriquecimiento falla (por ejemplo, XML mal formado), se registra en el log de PHP y el archivo queda como estaba. Nunca bloquea la subida ni la publicación.
- Cada enriquecimiento deja una entrada en el historial de actividad del envío.

## Galerada XML

Como en OJS 3.3 y 3.4, el plugin puede publicar el XML como galerada (etiqueta "XML", visible con Lens). Se activa por revista en *Ajustes → Sitio web → Módulos → XML Metadata Builder → Ajustes* y viene **activado por defecto**.

- Se genera al programar o publicar, a partir del JATS enriquecido de la publicación, con sus imágenes como archivos dependientes.
- En la galerada, los `xlink:href` con espacios se pasan a `%20` para que Lens encuentre las imágenes; el JATS de la publicación conserva los nombres originales.
- La galerada del plugin se reconoce porque su archivo tiene como origen (`sourceSubmissionFileId`) el JATS de la publicación. Las galeradas que se suben a mano no se tocan.
- Al crear una versión, OJS clona las galeradas apuntando al archivo de la versión anterior. Al publicar la versión nueva, el plugin le asigna un archivo propio y la versión anterior no cambia.
- Si se desactiva el ajuste, las galeradas ya generadas se mantienen.

## Componentes

```
XMLMetadataBuilderPlugin.php        Registro de hooks
classes/JatsEnrichmentService.php   Localiza el JATS de la publicación, lo reescribe y copia dependientes
classes/XMLMetadataProcessor.php    Reemplaza el <front> del XML
classes/MetadataExtractor.php       Lee los metadatos de OJS de la publicación
classes/XmlGalleyService.php        Genera y mantiene la galerada XML a partir del JATS
classes/JATSBuilder.php             Construye el <front> (perfil SciELO/SPS)
XMLMetadataBuilderSettingsForm.php  Ajustes por revista (galerada XML)
classes/CountryMapper/              Códigos ISO 3166 de país
```

## Pendiente

- XML con traducciones (`sub-article`): el `<front>` se arma siempre en el idioma de la publicación.
- Acción manual "Usar como JATS de la publicación" desde los archivos listos para producción, para llevar el XML de Texture con sus imágenes al panel JATS.
- ROR de las afiliaciones: se extrae pero todavía no se escribe en el XML.

## Instalación

Copiar en `plugins/generic/XMLMetadataBuilder` y habilitarlo en *Ajustes → Sitio web → Módulos*.
