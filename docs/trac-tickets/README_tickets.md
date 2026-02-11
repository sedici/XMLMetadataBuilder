# Tickets de Redmine - Plugin XML Enricher

Este conjunto de documentos proporciona una visión completa del plugin XML Enricher para OJS 3.4.

## Tickets Generados

### [Ticket 1: Arquitectura General del Plugin](file:///home/santi/.gemini/antigravity/brain/580d816c-ba39-41b1-a90a-78529fe37fa9/ticket_1_arquitectura_general.md)

**Tipo**: Documentación

**Resumen**: Documenta la arquitectura modular del plugin, componentes principales, integración con OJS mediante hooks, y estructura de directorios.

**Contenido**:
- Propósito y descripción general
- Arquitectura de capas (Integración, Servicios, Procesamiento, Presentación)
- Componentes principales con responsabilidades
- Sistema de hooks de OJS
- Flujo de trabajo completo
- Extensión del schema de publicación

---

### [Ticket 2: Sistema de Enriquecimiento XML y Procesamiento de Metadatos](file:///home/santi/.gemini/antigravity/brain/580d816c-ba39-41b1-a90a-78529fe37fa9/ticket_2_sistema_enriquecimiento.md)

**Tipo**: Documentación Técnica

**Resumen**: Detalla el núcleo funcional del plugin: extracción de metadatos de OJS y construcción de XML JATS conforme al estándar DTD 1.4.

**Contenido**:
- `MetadataExtractor`: Extracción de metadatos desde objetos OJS
  - Metadatos de revista, artículo, autores, secciones, fechas, permisos
- `JATSBuilder`: Construcción de nodos JATS XML
  - `buildFront`, `buildJournalMeta`, `buildArticleMeta`, `buildContribGroup`, etc.
- `PluginMetadataProcessor`: Orquestación del proceso de enriquecimiento
- Conformidad con JATS Publishing DTD 1.4
- Ejemplos de salida XML
- Limitaciones conocidas

---

### [Ticket 3: Interfaz de Usuario y Formularios](file:///home/santi/.gemini/antigravity/brain/580d816c-ba39-41b1-a90a-78529fe37fa9/ticket_3_interfaz_usuario.md)

**Tipo**: Documentación Técnica - Frontend

**Resumen**: Documenta la capa de presentación: formulario Vue, templates Smarty, módulos JavaScript, y funcionalidad de preview.

**Contenido**:
- `EnrichmentForm.php`: Componente de formulario Vue
- `enricherForm.tpl`: Template Smarty
- `enricherForm.js`: Módulo JavaScript con funcionalidades:
  - Inyección de configuración Vue
  - Conversión de campos a fieldsets
  - Setup del botón "Mostrar Front"
  - Manipulación DOM para ajustes visuales
- Funcionalidad de preview AJAX
- Integración con sistema de publicaciones OJS
- Flujo de usuario completo
- Problemas conocidos y soluciones

---

### [Ticket 4: Gestión de Archivos y Galleys](file:///home/santi/.gemini/antigravity/brain/580d816c-ba39-41b1-a90a-78529fe37fa9/ticket_4_gestion_archivos.md)

**Tipo**: Documentación Técnica - Backend

**Resumen**: Documenta el sistema de gestión de archivos: creación, almacenamiento, galleys, y limpieza de dependencias.

**Contenido**:
- `EnrichmentService`: Servicio centralizado de gestión de archivos
  - `getProductionXmlFiles`: Listar archivos XML disponibles
  - `enrich`: Orquestar proceso de enriquecimiento
  - `enrichSingleFile`: Procesar un archivo individual
  - `createEnrichedFile`: Crear nuevo SubmissionFile
  - `createGalley`: Generar galley para publicación
  - `deleteGalleys`: Limpieza de dependencias
  - `getFilePath`: Obtención de rutas físicas
- Sistema de galleys de OJS
- Manejo de sobrescritura
- Limpieza automática via hooks
- Estructura de archivos en OJS
- Manejo de errores y logging

---

### [Ticket 5: Mejoras Propuestas y Refactorización](file:///home/santi/.gemini/antigravity/brain/580d816c-ba39-41b1-a90a-78529fe37fa9/ticket_5_mejoras_propuestas.md)

**Tipo**: Propuesta de Mejora

**Resumen**: Oportunidades de mejora identificadas, organizadas por prioridad y área de impacto, con hoja de ruta de implementación.

**Contenido**:
- **Refactorización de Arquitectura**:
  - Eliminar redundancia en selección de archivos
  - Abstracción de almacenamiento (FileService)
  - Separar responsabilidades de Processor
- **Mejoras Funcionales**:
  - Validación de XML contra DTD JATS
  - Soporte multilingüe completo
  - Versionado de archivos
  - Batch processing
- **Optimización de UI/UX**:
  - Migrar a componente Vue personalizado
  - Preview inline
  - Indicador de progreso
- **Validación y Calidad**:
  - Validación de ORCID
  - Parsing mejorado de páginas
- **Testing**:
  - Tests unitarios para MetadataExtractor
  - Tests de integración
  - Logging estructurado
- **Documentación**:
  - API interna
  - Guía de usuario
  - Guía de desarrollo
- **Rendimiento**:
  - Procesamiento asíncrono
  - Caché de metadatos
- **Seguridad**:
  - Sanitización de inputs
  - Validación de permisos
- **Hoja de Ruta Priorizada** (4 fases, ~240 horas)

---

## Estadísticas

- **Total de tickets**: 5
- **Páginas de documentación**: ~150 páginas equivalentes
- **Diagramas**: 8 diagramas mermaid
- **Ejemplos de código**: ~50 bloques
- **Tablas**: ~15 tablas
- **Referencias**: JATS DTD 1.4, OJS Docs, PHP/Vue best practices

## Uso de los Tickets

Estos tickets pueden ser importados directamente a Redmine o usados como documentación de referencia para:

1. **Onboarding**: Nuevos desarrolladores que trabajen con el plugin
2. **Planificación**: Roadmap de mejoras y refactorizaciones
3. **Debugging**: Referencia técnica durante resolución de problemas
4. **Auditoría**: Revisión de código y arquitectura
5. **Documentación**: Base para documentación de usuario final

## Formato

Todos los tickets están en formato Markdown con:
- ✅ Headers estructurados
- ✅ Diagramas mermaid para visualización
- ✅ Bloques de código con syntax highlighting
- ✅ Tablas comparativas
- ✅ Enlaces a archivos fuente
- ✅ Ejemplos prácticos

---

**Fecha de creación**: 2025-12-04  
**Versión del plugin**: 1.0.0.0  
**OJS Version**: 3.4+
