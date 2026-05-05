# Relevamiento Técnico: Plugin XML Enricher

Este documento detalla la arquitectura, componentes y flujo de datos del plugin "XML Enricher" para OJS 3.4. También incluye propuestas de mejora y refactorización para facilitar el mantenimiento futuro.

## 1. Arquitectura General

El plugin sigue una arquitectura modular que separa la integración con OJS (Hooks/Plugin), la lógica de negocio (Service), y la manipulación de datos (Extractors/Builders).

```mermaid
graph TD
    User[Usuario] -->|Edita Publicación| Form[EnrichmentForm (UI)]
    Form -->|Submit| PublicationAPI[OJS Publication API]
    PublicationAPI -->|Hook: Publication::edit| MainPlugin[PluginTemplatePlugin]
    
    subgraph "Core Logic"
        MainPlugin -->|Llama| Service[EnrichmentService]
        Service -->|1. Lee Archivo| FileRepo[File Repository]
        Service -->|2. Extrae Datos| Processor[PluginMetadataProcessor]
        Processor -->|Usa| Extractor[MetadataExtractor]
        Processor -->|Usa| Builder[JATSBuilder]
        Service -->|3. Guarda Nuevo XML| FileRepo
        Service -->|4. Crea Galley| GalleyRepo[Galley Repository]
    end
    
    Extractor -->|Lee| Submission[Submission/Publication Object]
```

## 2. Análisis de Componentes

### A. Integración y Controladores

#### `PluginTemplatePlugin.php` (Main Class)
*   **Responsabilidad:** Punto de entrada. Registra el plugin, gestiona los Hooks de OJS y configura el entorno.
*   **Funciones Clave:**
    *   `register()`: Registra hooks.
    *   `addToPublicationForms()`: Inyecta el formulario en la pestaña de publicación.
    *   `addToSchema()`: Añade campos personalizados (`xmlFileId`, `suffix`, `overwrite`) al esquema de Publicación.
    *   `validatePublicationEdit()`: Valida que se haya seleccionado un archivo.
    *   `handlePublicationEdit()`: Intercepta el guardado de la publicación para ejecutar el enriquecimiento.
*   **Observación:** Actúa como "Controller" principal al interceptar el flujo de guardado de la publicación.

#### `PluginTemplateHandler.php`
*   **Responsabilidad:** Manejador de endpoints AJAX.
*   **Estado Actual:** Contiene métodos `fetchXmlList` y `enrichXml`.
*   **Crítica:** Existe redundancia. La lógica de enriquecimiento (`enrichXml`) está duplicada aquí, pero el flujo principal actual usa el Hook `handlePublicationEdit` en la clase principal. Es posible que este Handler no se esté utilizando para el enriquecimiento en el flujo actual, o que sea un endpoint alternativo no mantenido.

#### `EnrichmentForm.php` & `enricherForm.tpl`
*   **Responsabilidad:** Definición y renderizado del formulario UI.
*   **Estado Actual:** Usa componentes Vue de OJS. El TPL contiene lógica JavaScript (DOM manipulation) para ajustar estilos visuales que OJS no permite configurar nativamente.

### B. Lógica de Negocio y Servicios

#### `EnrichmentService.php`
*   **Responsabilidad:** Centraliza la lógica de negocio. Coordina la lectura de archivos, el llamado al procesador de metadatos, la creación de archivos temporales, la subida de nuevos archivos y la gestión de Galleys.
*   **Calidad:** Buena separación de responsabilidades. Manejo de errores y logging robusto.
*   **Mejora:** La lógica para determinar la ruta física del archivo (`getFilePath`) es compleja y podría ser frágil si OJS cambia su estructura de almacenamiento.

### C. Procesamiento de Metadatos y XML

#### `PluginMetadataProcessor.php`
*   **Responsabilidad:** Orquestador. Recibe XML crudo y objetos OJS, y devuelve XML enriquecido.
*   **Flujo:** `XML String` -> `DOM` -> `MetadataExtractor` -> `JATSBuilder` -> `DOM Manipulation` -> `XML String`.

#### `MetadataExtractor.php`
*   **Responsabilidad:** Extraer datos de los objetos complejos de OJS (`Submission`, `Publication`, `Context`) y convertirlos en un array plano y normalizado.
*   **Ventaja:** Desacopla la estructura interna de OJS de la estructura del XML. Si OJS cambia cómo guarda los autores, solo se toca esta clase.

#### `JATSBuilder.php`
*   **Responsabilidad:** Construir nodos DOM de JATS XML a partir de un array de datos.
*   **Ventaja:** Encapsula la complejidad de la estructura JATS (nodos anidados, atributos).

## 3. Propuestas de Mejora y Refactorización

### 1. Eliminar Redundancia en Selección de Archivos
**Problema:** La lógica para buscar archivos XML "Production Ready" está duplicada en `PluginTemplatePlugin::addToPublicationForms` y `PluginTemplateHandler::fetchXmlList`.
**Solución:** Mover esta lógica a un método estático en `EnrichmentService` o un `Repository` auxiliar del plugin.
```php
// En EnrichmentService
public static function getProductionXmlFiles($submissionId) { ... }
```

### 2. Clarificar Responsabilidad del Handler
**Problema:** `PluginTemplateHandler::enrichXml` duplica la lógica de llamada al servicio que ya existe en `PluginTemplatePlugin::handlePublicationEdit`.
**Solución:** Si el formulario se envía como parte del guardado de la publicación (Hook), el endpoint AJAX `enrichXml` en el Handler es innecesario y debería eliminarse para evitar confusión. Si se planea usar AJAX directo en el futuro, se debe documentar por qué existen ambos caminos.

### 3. Abstracción de Rutas de Archivos
**Problema:** `EnrichmentService::getFilePath` intenta adivinar la ruta física.
**Solución:** Investigar si OJS 3.4 provee un servicio de archivos (`FileService`) más robusto para obtener el stream de datos sin depender de rutas físicas locales, lo cual haría el plugin compatible con almacenamiento en la nube (S3, Azure) si OJS lo soporta.

### 4. Limpieza de Frontend
**Problema:** El script en `enricherForm.tpl` usa `setInterval` y manipulación DOM directa para arreglar estilos.
**Solución:** Aunque es un "mal necesario" en OJS a veces, se podría intentar encapsular este comportamiento en un componente Vue personalizado si se tiene control sobre el build process de JS, o al menos documentar claramente que este bloque es un "UI Patch" temporal.

### 5. Tipado Estricto
**Mejora:** Agregar declaraciones de tipos de retorno (`: void`, `: array`, `: string`) en todos los métodos de las clases `classes/` para mejorar la robustez y el soporte del IDE.

## 4. Guía para Retomar el Trabajo

1.  **Verificar Flujo de Guardado:** Confirmar si se desea mantener el guardado vía Hook (al guardar la publicación) o vía botón independiente (AJAX). Actualmente el sistema híbrido puede ser confuso.
2.  **Refactorizar `fetchXmlList`:** Centralizar la query de archivos.
3.  **Pruebas:** Crear casos de prueba unitarios para `MetadataExtractor` sería muy sencillo y valioso, ya que es una función pura (Input OJS Objects -> Output Array).

---
*Documento generado por Asistente AI - 27 Nov 2025*
