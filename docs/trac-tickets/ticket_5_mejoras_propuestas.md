# Ticket 5: Mejoras Propuestas y Refactorización

## Descripción

Este ticket documenta oportunidades de mejora identificadas en el código actual del plugin XML Enricher, organizadas por prioridad y área de impacto.

## Categorías de Mejoras

1. **Refactorización de Arquitectura**
2. **Mejoras Funcionales**
3. **Optimización de UI/UX**
4. **Validación y Calidad de Datos**
5. **Testing y Mantenibilidad**
6. **Documentación**
7. **Rendimiento**
8. **Seguridad**

---

## 1. Refactorización de Arquitectura

### 1.1 Eliminar Redundancia en Selección de Archivos

**Prioridad**: 🔴 Alta

**Problema**: La lógica para buscar archivos XML "Production Ready" está duplicada en:
- `PluginTemplatePlugin::addToPublicationForms` (línea ~170)
- `EnrichmentService::getProductionXmlFiles`

**Impacto**: 
- Dificultad para mantener consistencia
- Duplicación de código (~30 líneas)

**Solución Propuesta**:

Centralizar en `EnrichmentService` y eliminar del plugin principal:

```php
// En PluginTemplatePlugin::addToPublicationForms
public function addToPublicationForms($hookName, $params) {
    // ...
    
    // ANTES (duplicado):
    // $xmlFiles = /* lógica compleja aquí */;
    
    // DESPUÉS (delegado):
    $xmlFiles = EnrichmentService::getProductionXmlFiles($submission->getId());
    
    // ...
}
```

**Beneficios**:
- ✅ Única fuente de verdad
- ✅ Más fácil de testear
- ✅ Cambios futuros en un solo lugar

---

### 1.2 Abstracción de Almacenamiento de Archivos

**Prioridad**: 🟡 Media

**Problema**: `EnrichmentService::getFilePath` depende de rutas físicas locales:

```php
$fullPath = $filesDir . '/' . $path;
```

**Limitaciones**:
- ❌ No compatible con S3 / Object Storage
- ❌ No compatible con almacenamiento distribuido
- ❌ Asume sistema de archivos local

**Solución Propuesta**:

Migrar a `FileService` de OJS para abstraer el storage:

```php
// ANTES:
$filePath = self::getFilePath($file);
$xmlContent = file_get_contents($filePath);

// DESPUÉS:
$fileService = Services::get('file');
$stream = $fileService->getFileStream($file->getData('path'));
$xmlContent = stream_get_contents($stream);
```

**Beneficios**:
- ✅ Compatible con cloud storage
- ✅ Sigue patrones de OJS 3.4+
- ✅ Más robusto ante cambios de infraestructura

**Esfuerzo**: ~4 horas (refactorizar 3 métodos)

---

### 1.3 Separar Responsabilidades de PluginMetadataProcessor

**Prioridad**: 🟢 Baja

**Problema**: `PluginMetadataProcessor::enrichFront` hace múltiples cosas:
1. Parsea XML
2. Extrae metadatos
3. Mapea datos al builder
4. Construye front
5. Reemplaza en DOM
6. Serializa

**Solución Propuesta**:

Dividir en métodos más pequeños:

```php
class PluginMetadataProcessor
{
    public static function enrichFront($xml, $submission, $publication, $context) {
        $dom = self::parseXml($xml);
        $metadata = self::extractMetadata($submission, $publication, $context);
        $builderMeta = self::mapToBuilderFormat($metadata);
        $frontNode = self::buildFrontNode($builderMeta);
        $dom = self::replaceFrontInDom($dom, $frontNode);
        return self::serializeDom($dom);
    }
    
    private static function parseXml($xml) { /* ... */ }
    private static function extractMetadata(...) { /* ... */ }
    private static function mapToBuilderFormat(...) { /* ... */ }
    // Etc.
}
```

**Beneficios**:
- ✅ Más testeable (cada método se prueba aisladamente)
- ✅ Más fácil de depurar
- ✅ Mejor documentación con métodos específicos

---

## 2. Mejoras Funcionales

### 2.1 Validación de XML contra DTD JATS

**Prioridad**: 🔴 Alta

**Problema**: El plugin genera XML pero no valida que sea JATS válido.

**Riesgo**: 
- XML mal formado podría pasar desapercibido
- Incompatibilidad con sistemas externos

**Solución Propuesta**:

Añadir validación antes de guardar:

```php
class JATSValidator
{
    public static function validateAgainstDTD(string $xml): array
    {
        $dom = new DOMDocument();
        $dom->loadXML($xml);
        
        // Validar contra DTD JATS 1.4
        $dtdPath = __DIR__ . '/../schemas/JATS-publishing-1.4.dtd';
        
        if (!$dom->validate($dtdPath)) {
            libxml_use_internal_errors(true);
            $errors = libxml_get_errors();
            libxml_clear_errors();
            
            return [
                'valid' => false,
                'errors' => $errors
            ];
        }
        
        return ['valid' => true];
    }
}
```

**Usar en EnrichmentService**:

```php
$enrichedXml = PluginMetadataProcessor::enrichFront(...);

// Validar
$validation = JATSValidator::validateAgainstDTD($enrichedXml);
if (!$validation['valid']) {
    // Opcionalmente: proceder de todas formas con warning
}
```

**Beneficios**:
- ✅ Detecta errores de estructura temprano
- ✅ Garantiza conformidad JATS
- ✅ Facilita debugging

**Esfuerzo**: ~8 horas (incluye descargar DTD, testear, documentar)

---

### 2.2 Soporte Multilingüe Completo

**Prioridad**: 🟡 Media

**Problema**: El sistema solo genera un `<front>` en el idioma principal:

```php
$title = is_array($meta['article']['title']) 
    ? reset($meta['article']['title'])  // Solo toma el primero
    : $meta['article']['title'];
```

**Limitación**: Artículos multilingües pierden metadatos en otros idiomas.

**Solución Propuesta**:

Generar múltiples `<front>` con atributo `xml:lang`:

```xml
<front xml:lang="es">
    <article-title>Título en Español</article-title>
</front>
<front xml:lang="en">
    <article-title>Title in English</article-title>
</front>
```

**Alternativa JATS estándar**:

Usar `<trans-title-group>`:

```xml
<title-group>
    <article-title xml:lang="es">Título en Español</article-title>
    <trans-title-group>
        <trans-title xml:lang="en">Title in English</trans-title>
    </trans-title-group>
</title-group>
```

**Esfuerzo**: ~12 horas

---

### 2.3 Versionado de Archivos Enriquecidos

**Prioridad**: 🟢 Baja

**Problema**: No se mantiene historial de versiones anteriores.

**Escenario**: 
- Usuario enriquece archivo con sufijo `-v1`
- Luego enriquece con `-v2`
- No hay forma de recuperar `-v1` si hubo un error

**Solución Propuesta**:

Añadir metadata de versión en `submission_files`:

```php
$newFile->setData('pluginTemplate::versionOf', $sourceFile->getId());
$newFile->setData('pluginTemplate::version', time());
```

Añadir método para listar versiones:

```php
EnrichmentService::getEnrichedVersions($sourceFileId);
// Retorna array de archivos ordenados por timestamp
```

**Beneficios**:
- ✅ Auditoría de cambios
- ✅ Rollback posible
- ✅ Comparación de versiones

---

### 2.4 Batch Processing (Lote)

**Prioridad**: 🟡 Media

**Problema**: Solo se puede enriquecer un archivo a la vez.

**Caso de uso**: Revista con 50 artículos en un issue que quiere enriquecer todos.

**Solución Propuesta**:

Añadir opción en settings del plugin:

```php
// En settings form
$this->addField(new FieldOptions('batchEnrich', [
    'label' => 'Enriquecer todos los XMLs del issue',
    'type' => 'checkbox'
]));
```

Backend:

```php
class BatchEnrichmentService
{
    public static function enrichIssue($issueId, $suffix, $overwrite)
    {
        $submissions = Repo::submission()
            ->getCollector()
            ->filterByIssueIds([$issueId])
            ->getMany();
        
        foreach ($submissions as $submission) {
            $xmlFiles = EnrichmentService::getProductionXmlFiles($submission->getId());
            
            foreach ($xmlFiles as $file) {
                try {
                    EnrichmentService::enrichSingleFile(...);
                } catch (Exception $e) {
                    error_log('[BatchEnrichment] Error en submission ' . 
                              $submission->getId() . ': ' . $e->getMessage());
                }
            }
        }
    }
}
```

**Beneficios**:
- ✅ Ahorra tiempo en revistas grandes
- ✅ Consistencia en todo el issue

**Esfuerzo**: ~16 horas (UI + backend + testing)

---

## 3. Optimización de UI/UX

### 3.1 Migrar a Componente Vue Personalizado

**Prioridad**: 🟡 Media

**Problema**: Actualmente se usa manipulación DOM con `setInterval`:

```javascript
convertSuffixToFieldset: function() {
    var interval = setInterval(function() {
        var suffixField = document.querySelector('...');
        if (suffixField) {
            // Manipulación DOM manual
        }
    }, 100);
}
```

**Limitaciones**:
- ❌ Frágil (depende de timing)
- ❌ No reactivo
- ❌ Difícil de mantener

**Solución Propuesta**:

Crear componente Vue dedicado:

```vue
<!-- XMLEnricherForm.vue -->
<template>
    <div class="xml-enricher-form">
        <fieldset class="xml-file-selector">
            <legend>Archivo XML</legend>
            <pkp-field-options 
                v-model="selectedFileId"
                :options="xmlFiles"
                type="radio"
            />
            <button @click="showPreview" class="pkpButton">
                Mostrar Front
            </button>
        </fieldset>
        
        <fieldset class="enrichment-options">
            <legend>Opciones</legend>
            <pkp-field-text 
                v-model="suffix"
                label="Sufijo"
            />
            <pkp-field-options
                v-model="overwrite"
                type="checkbox"
                label="Sobrescribir"
            />
        </fieldset>
    </div>
</template>

<script>
export default {
    name: 'XMLEnricherForm',
    data() {
        return {
            selectedFileId: null,
            suffix: '-enriched',
            overwrite: false
        };
    },
    methods: {
        showPreview() {
            // Lógica de preview
        }
    }
};
</script>
```

**Beneficios**:
- ✅ Reactivo (no más `setInterval`)
- ✅ Testeable con Vue Test Utils
- ✅ Mejor integración con OJS

**Esfuerzo**: ~24 horas (incluye setup build, testing)

---

### 3.2 Preview Inline (Sin Modal)

**Prioridad**: 🟢 Baja

**Problema**: El preview abre un modal que interrumpe el flujo.

**Solución Propuesta**:

Mostrar preview en un panel colapsable dentro del formulario:

```vue
<div class="xml-preview-panel" v-if="showPreview">
    <div class="panel-header">
        <h4>Preview del Front</h4>
        <button @click="showPreview = false">×</button>
    </div>
    <pre class="xml-content">{{ frontXml }}</pre>
</div>
```

**Beneficios**:
- ✅ Menos disruptivo
- ✅ Permite comparar con el formulario

---

### 3.3 Indicador de Progreso

**Prioridad**: 🟡 Media

**Problema**: Durante el enriquecimiento, el usuario no ve progreso.

**Solución Propuesta**:

Añadir spinner y mensaje de estado:

```javascript
// Al submit
this.isEnriching = true;
this.enrichmentStatus = 'Leyendo archivo XML...';

// Usar eventos server-sent para actualizaciones:
// 'Extrayendo metadatos...'
// 'Construyendo JATS...'
// 'Guardando archivo...'
// 'Creando galley...'
// 'Completado!'
```

**Beneficios**:
- ✅ Mejor feedback al usuario
- ✅ Reduce ansiedad en operaciones largas

---

## 4. Validación y Calidad de Datos

### 4.1 Validación de ORCID

**Prioridad**: 🟢 Baja

**Problema**: ORCIDs se insertan sin validación de formato.

**Riesgo**: ORCID malformados en XML publicado.

**Solución Propuesta**:

```php
class ORCIDValidator
{
    public static function normalize($orcid)
    {
        // Eliminar espacios y guiones
        $clean = preg_replace('/[\s-]/', '', $orcid);
        
        // Validar formato
        if (!preg_match('/^\d{4}\d{4}\d{4}\d{3}[0-9X]$/', $clean)) {
            return null;
        }
        
        // Formatear con guiones
        return substr($clean, 0, 4) . '-' .
               substr($clean, 4, 4) . '-' .
               substr($clean, 8, 4) . '-' .
               substr($clean, 12);
    }
}
```

**Usar en MetadataExtractor**:

```php
'orcid' => ORCIDValidator::normalize($author->getOrcid())
```

---

### 4.2 Parsing Mejorado de Páginas

**Prioridad**: 🟡 Media

**Problema**: Regex actual solo soporta `"123-145"` o `"123"`.

**Casos no soportados**:
- `"e12345"` (artículos electrónicos)
- `"L123-L145"` (letters)
- `"S10-S20"` (supplements)

**Solución Propuesta**:

```php
class PageParser
{
    public static function parse($pages)
    {
        $pages = trim($pages);
        
        // Formato: "123-145"
        if (preg_match('/^(\d+)\s*[-–—]\s*(\d+)$/', $pages, $m)) {
            return ['first' => $m[1], 'last' => $m[2]];
        }
        
        // Formato: "e12345"
        if (preg_match('/^e(\d+)$/', $pages, $m)) {
            return ['first' => 'e' . $m[1], 'last' => 'e' . $m[1], 'type' => 'elocation'];
        }
        
        // Formato: "L123-L145"
        if (preg_match('/^([A-Z])(\d+)\s*-\s*([A-Z])(\d+)$/', $pages, $m)) {
            return ['first' => $m[1] . $m[2], 'last' => $m[3] . $m[4]];
        }
        
        // Single page
        if (preg_match('/^\d+$/', $pages)) {
            return ['first' => $pages, 'last' => $pages];
        }
        
        return ['first' => null, 'last' => null];
    }
}
```

---

## 5. Testing y Mantenibilidad

### 5.1 Tests Unitarios para MetadataExtractor

**Prioridad**: 🔴 Alta

**Problema**: No hay tests automatizados.

**Beneficio**: `MetadataExtractor` es función pura, ideal para testing.

**Implementación**:

```php
// tests/classes/MetadataExtractorTest.php

use PHPUnit\Framework\TestCase;

class MetadataExtractorTest extends TestCase
{
    public function testExtractJournal()
    {
        $context = $this->createMockContext([
            'name' => 'Test Journal',
            'acronym' => 'TJ',
            'issn' => '1234-5678'
        ]);
        
        $extractor = new MetadataExtractor();
        $result = $extractor->extractJournal($context);
        
        $this->assertEquals('Test Journal', $result['title']);
        $this->assertEquals('1234-5678', $result['issn']);
    }
    
    public function testExtractAuthors()
    {
        $submission = $this->createMockSubmission([
            'authors' => [
                ['given' => 'Juan', 'family' => 'García', 'orcid' => '0000-0001-2345-6789']
            ]
        ]);
        
        $extractor = new MetadataExtractor();
        $result = $extractor->extractAuthors($submission);
        
        $this->assertCount(1, $result);
        $this->assertEquals('Juan', $result[0]['given']);
    }
    
    // Más tests...
}
```

**Esfuerzo**: ~16 horas (setup + tests completos)

**Cobertura objetivo**: >80%

---

### 5.2 Tests de Integración

**Prioridad**: 🟡 Media

**Implementación**:

```php
// tests/integration/EnrichmentFlowTest.php

class EnrichmentFlowTest extends TestCase
{
    public function testCompleteEnrichmentFlow()
    {
        // 1. Setup: crear submission con XML
        $submission = $this->createTestSubmission();
        $xmlFile = $this->uploadTestXML($submission);
        
        // 2. Enriquecer
        EnrichmentService::enrich($xmlFile->getId(), $submission->getCurrentPublication(), [
            'suffix' => '-test',
            'overwrite' => false
        ]);
        
        // 3. Verificar archivo creado
        $enrichedFile = $this->findFileByName('test-article-test.xml');
        $this->assertNotNull($enrichedFile);
        
        // 4. Verificar galley creado
        $galley = $this->findGalleyByFileId($enrichedFile->getId());
        $this->assertNotNull($galley);
        $this->assertEquals('XML test', $galley->getData('label'));
        
        // 5. Verificar contenido XML
        $xmlContent = file_get_contents($this->getFilePath($enrichedFile));
        $this->assertStringContainsString('<front>', $xmlContent);
    }
}
```

---

### 5.3 Logging Estructurado

**Prioridad**: 🟢 Baja

**Problema**: Logs actuales son strings simples, difíciles de parsear.

**Solución Propuesta**:

```php
class PluginLogger
{
    public static function log($level, $message, $context = [])
    {
        $logEntry = json_encode([
            'timestamp' => time(),
            'level' => $level,
            'plugin' => 'XMLEnricher',
            'message' => $message,
            'context' => $context
        ]);
        
    }
}

// Uso:
PluginLogger::log('INFO', 'Starting enrichment', [
    'fileId' => $fileId,
    'suffix' => $suffix
]);
```

**Beneficios**:
- ✅ Parseable con herramientas (Splunk, ELK)
- ✅ Facilita debugging

---

## 6. Documentación

### 6.1 Documentación de API Interna

**Prioridad**: 🟡 Media

**Acción**: Añadir PHPDoc completo:

```php
/**
 * Enrich an XML file with metadata from OJS.
 * 
 * This method reads the source file, extracts metadata from the submission
 * and publication objects, enriches the XML's <front> element using JATS
 * standards, creates a new file with the specified suffix, and optionally
 * creates a galley for publication.
 * 
 * @param int|array $fileId Single file ID or array of file IDs for multilingual content
 * @param Publication $publication The publication containing metadata
 * @param array $options Configuration options:
 *   - 'suffix' (string): Suffix for enriched filename (default: '-enriched')
 *   - 'overwrite' (bool): Whether to overwrite existing files (default: false)
 *   - 'createGalley' (bool): Whether to create galley automatically (default: true)
 * 
 * @return void
 * 
 * @throws Exception If file not found or enrichment fails
 * 
 * @example
 * EnrichmentService::enrich(123, $publication, [
 *     'suffix' => '-completo',
 *     'overwrite' => true
 * ]);
 */
public static function enrich($fileId, $publication, array $options = [])
{
    // ...
}
```

---

### 6.2 Guía de Usuario

**Prioridad**: 🟡 Media

**Crear**: `docs/USER_GUIDE.md`

Contenido:
- Cómo instalar el plugin
- Cómo configurar el plugin
- Paso a paso para enriquecer un XML
- Resolución de problemas comunes
- FAQ

---

### 6.3 Guía de Desarrollo

**Prioridad**: 🟢 Baja

**Crear**: `docs/DEVELOPMENT.md`

Contenido:
- Arquitectura técnica
- Cómo extender el plugin
- Cómo añadir nuevos campos JATS
- Cómo contribuir

---

## 7. Rendimiento

### 7.1 Procesamiento Asíncrono

**Prioridad**: 🟡 Media

**Problema**: Enriquecimiento bloquea la UI durante segundos.

**Solución Propuesta**:

Usar sistema de jobs de OJS:

```php
use PKP\jobs\BaseJob;

class EnrichXMLJob extends BaseJob
{
    protected $fileId;
    protected $publicationId;
    protected $options;
    
    public function __construct($fileId, $publicationId, $options)
    {
        $this->fileId = $fileId;
        $this->publicationId = $publicationId;
        $this->options = $options;
    }
    
    public function handle()
    {
        $publication = Repo::publication()->get($this->publicationId);
        EnrichmentService::enrich($this->fileId, $publication, $this->options);
    }
}

// Uso:
EnrichXMLJob::dispatch($fileId, $publicationId, $options);
```

**Beneficios**:
- ✅ No bloquea UI
- ✅ Escalable para batch processing

---

### 7.2 Caché de Metadatos Extraídos

**Prioridad**: 🟢 Baja

**Problema**: Si se enriquecen múltiples archivos del mismo submission, se extrae metadatos repetidamente.

**Solución**:

```php
private static $metadataCache = [];

public static function enrich($fileId, $publication, $options = [])
{
    $cacheKey = 'submission_' . $publication->getData('submissionId');
    
    if (!isset(self::$metadataCache[$cacheKey])) {
        $extractor = new MetadataExtractor();
        self::$metadataCache[$cacheKey] = $extractor->extract(...);
    }
    
    $metadata = self::$metadataCache[$cacheKey];
    // Usar metadata cacheada
}
```

---

## 8. Seguridad

### 8.1 Sanitización de Inputs

**Prioridad**: 🔴 Alta

**Problema**: Sufijo personalizado no se sanitiza.

**Riesgo**: Path traversal si usuario ingresa `../../malicious`.

**Solución**:

```php
public static function sanitizeSuffix($suffix)
{
    // Eliminar caracteres peligrosos
    $suffix = preg_replace('/[^a-zA-Z0-9_-]/', '', $suffix);
    
    // Limitar longitud
    $suffix = substr($suffix, 0, 50);
    
    // Asegurar que empiece con guión
    if (!empty($suffix) && $suffix[0] !== '-') {
        $suffix = '-' . $suffix;
    }
    
    return $suffix ?: '-enriched';
}
```

---

### 8.2 Validación de Permisos

**Prioridad**: 🔴 Alta

**Problema**: No se valida que el usuario tenga permisos para modificar la publicación.

**Solución**:

```php
public function handlePublicationEdit($hookName, $args)
{
    $request = Application::get()->getRequest();
    $user = $request->getUser();
    $publication = $args[0];
    
    // Validar permisos
    if (!$this->userCanEdit($user, $publication)) {
        return false;
    }
    
    // Continuar...
}

private function userCanEdit($user, $publication)
{
    $submission = Repo::submission()->get($publication->getData('submissionId'));
    $context = /* ... */;
    
    return $user->hasRole([ROLE_ID_MANAGER, ROLE_ID_SITE_ADMIN], $context->getId())
        || $submission->getData('submissionProgress') == 0; // Solo si está publicado
}
```

---

## Hoja de Ruta Priorizada

### Fase 1: Estabilidad y Calidad (Q1 2026)
1. ✅ Validación de XML contra DTD JATS
2. ✅ Sanitización de inputs
3. ✅ Validación de permisos
4. ✅ Tests unitarios para MetadataExtractor
5. ✅ Eliminar redundancia en selección de archivos

**Esfuerzo**: ~64 horas (~2 semanas)

### Fase 2: Mejoras de Arquitectura (Q2 2026)
6. ✅ Abstracción de almacenamiento (FileService)
7. ✅ Separar responsabilidades de Processor
8. ✅ Logging estructurado
9. ✅ Documentación de API

**Esfuerzo**: ~40 horas (~1 semana)

### Fase 3: Nuevas Funcionalidades (Q3 2026)
10. ✅ Soporte multilingüe completo
11. ✅ Batch processing
12. ✅ Procesamiento asíncrono
13. ✅ Indicador de progreso

**Esfuerzo**: ~80 horas (~2.5 semanas)

### Fase 4: UI y UX (Q4 2026)
14. ✅ Migrar a componente Vue personalizado
15. ✅ Preview inline
16. ✅ Diff viewer
17. ✅ Guía de usuario

**Esfuerzo**: ~56 horas (~1.5 semanas)

---

## Métricas de Éxito

### Pre-mejoras (Baseline)
- **Cobertura de tests**: 0%
- **Tiempo de enriquecimiento**: ~3-5 segundos (bloquea UI)
- **Bugs conocidos**: 3 (path traversal, permisos, ORCID inválidos)
- **Compatibilidad storage**: Solo local

### Post-mejoras (Objetivo)
- **Cobertura de tests**: >80%
- **Tiempo de enriquecimiento**: <1 segundo (asíncrono)
- **Bugs conocidos**: 0
- **Compatibilidad storage**: Local + S3 + Azure

---

## Referencias

- [JATS Best Practices](https://jats4r.org/)
- [OJS Plugin Testing Guide](https://docs.pkp.sfu.ca/dev/testing/)
- [PHP Standards Recommendations (PSR)](https://www.php-fig.org/psr/)
