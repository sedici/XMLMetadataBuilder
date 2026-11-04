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
| Se programa o publica | `Publication::publish` | Vuelve a enriquecer con los metadatos definitivos |
| Se crea una versión nueva | `SubmissionFile::add` + `Publication::version` | Core copia el XML (se enriquece por el primer hook); el plugin copia las imágenes, que core no copia, y enriquece de nuevo |

Los hooks de edición corren antes de que OJS guarde los cambios (y las afiliaciones se guardan dentro del update del autor), por eso el enriquecimiento se hace al final de la petición. Varios cambios en una misma petición producen un solo enriquecimiento. Reordenar autores no dispara ningún hook de OJS: el orden se actualiza en la próxima edición o al publicar.

El panel JATS carga el contenido al abrir la sección: después de editar metadatos hay que volver a entrar a la sección para ver el cambio.

- Si el contenido no cambia, no se crea una revisión nueva.
- Si el enriquecimiento falla (por ejemplo, XML mal formado), se registra en el log de PHP y el archivo queda como estaba. Nunca bloquea la subida ni la publicación.
- Cada enriquecimiento deja una entrada en el historial de actividad del envío.

## Componentes

```
XMLMetadataBuilderPlugin.php        Registro de hooks
classes/JatsEnrichmentService.php   Localiza el JATS de la publicación, lo reescribe y copia dependientes
classes/XMLMetadataProcessor.php    Reemplaza el <front> del XML
classes/MetadataExtractor.php       Lee los metadatos de OJS de la publicación
classes/JATSBuilder.php             Construye el <front> (perfil SciELO/SPS)
classes/CountryMapper/              Códigos ISO 3166 de país
```

## Pendiente

- XML con traducciones (`sub-article`): el `<front>` se arma siempre en el idioma de la publicación.
- Acción manual "Usar como JATS de la publicación" desde los archivos listos para producción, para llevar el XML de Texture con sus imágenes al panel JATS.
- ROR de las afiliaciones: se extrae pero todavía no se escribe en el XML.

## Instalación

Copiar en `plugins/generic/XMLMetadataBuilder` y habilitarlo en *Ajustes → Sitio web → Módulos*.
