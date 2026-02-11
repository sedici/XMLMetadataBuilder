# Ticket Redmine: Componentes Principales del Plugin XMLMetadataBuilder

## Asunto
Descripción general de las clases principales y sus responsabilidades en el plugin XMLMetadataBuilder

---

## Descripción

El plugin **XMLMetadataBuilder** enriquece archivos XML JATS con metadatos de OJS. Está compuesto por tres clases principales que trabajan en conjunto siguiendo un patrón de capas:

---

## 1. MetadataExtractor (Extractor de Metadatos)

**Ubicación:** `classes/MetadataExtractor.php`

### ¿De qué se encarga?
Extrae todos los metadatos necesarios desde OJS (submission, autores, revista, fechas, permisos) y los normaliza en una estructura de datos que puede ser usada para construir XML.

### Responsabilidad principal
**"Leer datos de OJS y organizarlos"** - Actúa como puente entre la base de datos de OJS y el generador de XML.

### Métodos principales (ejemplos):

- **`extract()`** - Punto de entrada que coordina toda la extracción y retorna un array con todos los metadatos
- **`extractJournal()`** - Obtiene datos de la revista (título, ISSN, publisher)
- **`extractArticle()`** - Obtiene datos del artículo (título, abstract, DOI, volumen, páginas)
- **`extractAuthors()`** - Obtiene lista de autores con sus datos (nombre, email, ORCID, afiliación)
- **`extractPublicationDates()`** - Obtiene fechas importantes (envío, aceptación, publicación)
- **`extractPermissions()`** - Obtiene información de licencia y copyright

### Ejemplo de salida:
```php
[
    'journal' => ['title' => 'Rev. Example', 'issn' => '1234-5678', ...],
    'article' => ['title' => 'Article Title', 'doi' => '10.1234/...', ...],
    'authors' => [['surname' => 'Pérez', 'given' => 'Juan', ...], ...],
    'pubDates' => ['submitted' => '2024-01-15', 'accepted' => '2024-03-20', ...],
    ...
]
```

---

## 2. JATSBuilder (Constructor de XML JATS)

**Ubicación:** `classes/JATSBuilder.php`

### ¿De qué se encarga?
Construye nodos XML del estándar JATS a partir de los metadatos extraídos, asegurando que el formato cumpla con JATS Publishing 1.1 y SciELO Publishing Schema (SPS) 1.9.

### Responsabilidad principal
**"Convertir datos en XML JATS válido"** - Genera la estructura XML correcta siguiendo el estándar, con el orden y atributos requeridos.

### Métodos principales (ejemplos):

- **`buildFront()`** - Construye el elemento `<front>` completo del JATS
- **`buildJournalMeta()`** - Genera el nodo `<journal-meta>` con datos de la revista
- **`buildArticleMeta()`** - Genera el nodo `<article-meta>` con todos los metadatos del artículo
- **`buildContribGroup()`** - Genera el grupo de autores `<contrib-group>`
- **`buildPubDate()`** - Genera nodos de fecha de publicación `<pub-date>`
- **`buildHistory()`** - Genera el nodo `<history>` con fechas editoriales
- **`buildPermissions()`** - Genera el nodo `<permissions>` con licencia y copyright

### Ejemplo de salida:
```xml
<front>
    <journal-meta>
        <journal-id journal-id-type="publisher-id">revista</journal-id>
        <journal-title-group>
            <journal-title>Revista Ejemplo</journal-title>
        </journal-title-group>
        <issn>1234-5678</issn>
        ...
    </journal-meta>
    <article-meta>
        <article-id pub-id-type="doi">10.1234/example</article-id>
        <title-group>
            <article-title>Título del Artículo</article-title>
        </title-group>
        <contrib-group>
            <contrib contrib-type="author">
                <name>
                    <surname>Pérez</surname>
                    <given-names>Juan</given-names>
                </name>
            </contrib>
        </contrib-group>
        ...
    </article-meta>
</front>
```

---

## 3. XMLMetadataProcessor (Procesador y Orquestador)

**Ubicación:** `classes/XMLMetadataProcessor.php`

### ¿De qué se encarga?
Coordina todo el proceso de enriquecimiento: toma el XML original, usa el Extractor para obtener datos de OJS, usa el Builder para construir el nuevo `<front>`, y reemplaza la sección `<front>` del XML original con la versión enriquecida.

### Responsabilidad principal
**"Orquestar el flujo completo"** - Conecta las otras dos clases y maneja el XML como documento DOM para hacer el reemplazo.

### Método principal:

- **`enrichFront()`** - Método estático que realiza todo el proceso de enriquecimiento

### Flujo interno:
```
1. Parsea el XML original
2. Llama a MetadataExtractor::extract() → obtiene datos
3. Mapea los datos al formato de JATSBuilder
4. Llama a JATSBuilder::buildFront() → genera nuevo <front>
5. Reemplaza el <front> antiguo con el nuevo en el XML
6. Retorna el XML completo enriquecido
```

---

## 4. EnrichmentService (Servicio de Gestión)

**Ubicación:** `classes/services/EnrichmentService.php`

### ¿De qué se encarga?
Maneja toda la lógica relacionada con archivos: leer archivos XML de OJS, llamar al procesador para enriquecerlos, guardar los archivos enriquecidos, y crear las galeradas (galleys) para publicación.

### Responsabilidad principal
**"Gestionar archivos y galeradas"** - Se encarga de la parte de almacenamiento y publicación de los XMLs enriquecidos.

### Métodos principales (ejemplos):

- **`enrich()`** - Punto de entrada para enriquecer archivos
- **`enrichSingleFile()`** - Procesa un archivo individual (lee → enriquece → guarda)
- **`createGalley()`** - Crea una galerada vinculada al archivo enriquecido
- **`getProductionXmlFiles()`** - Obtiene lista de archivos XML en producción
- **`extractFrontElement()`** - Extrae solo el `<front>` original (para preview)

---

## Flujo de Trabajo General

```
Usuario solicita enriquecimiento
        ↓
EnrichmentService lee el archivo XML original
        ↓
XMLMetadataProcessor::enrichFront() coordina:
    1. MetadataExtractor extrae datos de OJS
    2. JATSBuilder construye nuevo <front> en XML
    3. Reemplaza <front> en el XML original
        ↓
EnrichmentService guarda el XML enriquecido
        ↓
EnrichmentService crea galley para publicación
        ↓
Usuario puede publicar el artículo con XML enriquecido
```

---

## Separación de Responsabilidades

| Clase | Entrada | Salida | Responsabilidad |
|-------|---------|--------|-----------------|
| **MetadataExtractor** | Submission, Context de OJS | Array de metadatos | Extraer y normalizar datos |
| **JATSBuilder** | Array de metadatos | DOMElement `<front>` | Construir XML JATS válido |
| **XMLMetadataProcessor** | XML original + Submission | XML completo enriquecido | Orquestar proceso completo |
| **EnrichmentService** | FileId, Publication | Archivos guardados + Galleys | Gestionar archivos y publicación |

---

## Beneficios de esta Arquitectura

1. **Separación clara de responsabilidades** - Cada clase hace una cosa específica
2. **Fácil mantenimiento** - Cambios en extracción no afectan construcción XML
3. **Reutilizable** - JATSBuilder puede usarse independientemente para generar JATS desde cualquier fuente
4. **Testeable** - Cada componente puede probarse por separado
5. **Cumplimiento de estándares** - JATSBuilder centraliza toda la lógica de cumplimiento JATS/SPS

---

## Resumen Ejecutivo

- **MetadataExtractor** = "¿Qué datos tengo en OJS?"
- **JATSBuilder** = "¿Cómo los convierto a XML JATS?"
- **XMLMetadataProcessor** = "¿Cómo junto todo?"
- **EnrichmentService** = "¿Cómo lo guardo y publico?"

Juntas, estas clases permiten transformar automáticamente los metadatos de OJS en un XML JATS completamente válido y compatible con repositorios como SciELO.
