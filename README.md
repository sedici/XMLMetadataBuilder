# XMLMetadataBuilder Plugin para OJS 3.3

![PHP](https://img.shields.io/badge/PHP-%3E%3D7.4-8892BF?logo=php&logoColor=white)
![OJS](https://img.shields.io/badge/OJS-3.3-blue)
![License](https://img.shields.io/badge/license-GPLv3-lightgrey)

Este plugin para **Open Journal Systems (OJS)** automatiza la construcción y enriquecimiento de archivos XML JATS. Su función principal es extraer metadatos cargados en OJS para generar o corregir la sección `<front>` de los archivos XML, asegurando el cumplimiento de estándares internacionales de publicación y preservación.

1. **Copiar Archivos:** 
   Copiar la carpeta del plugin en el directorio plugins/generic/ de la instalación de OJS utilizando el siguiente comando: `git clone https://github.com/sedici/OJS-JATS-Completer/tree/stable-3_3 XMLMetadataBuilder`

2. Habilitar Plugin:
    * Dentro de OJS, dirigirse a la sección de plugins instalados (Ajustes > Sitio web > Módulos > Módulos instalados).
    * Busque "XMLMetadataBuilder" en la lista de plugins genéricos.
    * Marque la casilla para habilitarlo.
    * En la sección Publicación correspondiente a cada envío aparecerá una nueva pestaña llamada “XMLMetadataBuilder”, donde se podrán visualizar los archivos XML JATS cargados en la etapa de Producción, junto con algunas opciones específicas del plugin y dos botones: "Descargar" y "Completar XML"

## Arquitectura y Propósito

El plugin nace de la necesidad de separar la lógica de enriquecimiento de metadatos de la lógica de transformación visual. Se enfoca exclusivamente en garantizar que el XML sea un reflejo fiel de los metadatos registrados en el flujo editorial de OJS, facilitando la interoperabilidad con sistemas como SciELO, Redalyc, Latindex y Crossref.

### Componentes Principales

La solución sigue un patrón de capas para garantizar la mantenibilidad y el desacoplamiento:

| Clase | Ubicación | Responsabilidad |
| :--- | :--- | :--- |
| **MetadataExtractor** | `classes/MetadataExtractor.php` | Extrae y normaliza datos de OJS (Journal, Submission, Autores, Fechas) en un array estructurado. |
| **JATSBuilder** | `classes/JATSBuilder.php` | Construye nodos XML (front, journal-meta, article-meta) siguiendo el estándar JATS Publishing 1.4. |
| **XMLMetadataProcessor** | `classes/XMLMetadataProcessor.php` | Orquestador que reemplaza el `<front>` antiguo por el nuevo generado dentro del DOM del XML. |
| **EnrichmentService** | `classes/services/EnrichmentService.php` | Gestiona la persistencia, lectura de archivos, creación de galeradas y empaquetado ZIP. |

---

## Funcionalidades Clave

### 1. Enriquecimiento de Metadatos JATS
El plugin automatiza la creación de una sección `<front>` exhaustiva y normalizada, resolviendo problemas de validación y falta de información en los archivos originales:

* **Construcción Estructurada:** Genera y organiza los metadatos esenciales en los nodos estándar:
    * `<journal-meta>`: Identificación de la revista (título, ISSN, editores).
    * `<article-meta>`: Datos del artículo (DOI, títulos, resúmenes, palabras clave).
    * `<contrib-group>`: Información detallada de los autores.
* **Normalización de Autoría:** Integra nombres, apellidos, identificadores ORCID, correos electrónicos y afiliaciones institucionales de forma jerárquica.
* **Historial Editorial y Permisos:** Recupera automáticamente las fechas clave del flujo editorial (envío, aceptación, publicación) e inserta las declaraciones de licencia y copyright configuradas en OJS.

### 2. Gestión y Exportación de Archivos

El plugin ofrece dos acciones principales adaptadas al ciclo de vida del artículo:

#### A. Acción: Completar XML
Diseñada para el proceso de edición y pre-publicación.
* **Procesamiento Automático:** Al ejecutar esta acción, el plugin procesa el archivo, inyecta los metadatos y lo envía automáticamente a la etapa de **Producción**.
* **Generación de Galeradas:** Incluye una opción (checkbox) para enviar el XML resultante directamente a la sección de **Galeradas**, dejándolo listo para la vista pública.
* **Restricción de Estado:** Esta función solo está disponible para artículos no publicados. Si se intenta ejecutar en un artículo ya publicado, el sistema notificará un error para proteger la integridad de la publicación final.

#### B. Acción: Descargar Paquete (ZIP)
Diseñada para obtener el XML JATS completo, especialmente en escenarios donde el artículo ya se encuentra publicado y las restricciones de OJS impiden el uso de la función "Completar XML".
* **Empaquetado Integral:** Genera un archivo **ZIP** que contiene el XML JATS enriquecido junto con todas sus dependencias (imágenes, tablas y otros recursos asociados), garantizando la integridad de los datos para su distribución.
* **Compatibilidad con Artículos Publicados:** Dado que OJS restringe la generación o modificación de archivos una vez que el artículo ha sido publicado, la función de descarga actúa como una alternativa permanente. Esto permite extraer el paquete completo con los metadatos actualizados en cualquier etapa del ciclo de vida del documento, sin afectar el estado de la publicación.

### 3. Integración con el Flujo de Producción
* **Generación de Galeradas:** El plugin permite crear galeradas (galleys) directamente desde el archivo XML JATS completo para su publicación inmediata.
* **Soporte Multi-formato:** Prepara el terreno para que otros plugins (como JATSParser o scieloAdapter) reciban un XML completo.

---

## Flujo de Trabajo Interno

1. **Solicitud:** El usuario o un proceso automático solicita el enriquecimiento.
2. **Extracción:** `MetadataExtractor` obtiene los metadatos cargados en OJS.
3. **Construcción:** `JATSBuilder` crea el nuevo bloque `<front>`.
4. **Procesamiento:** `XMLMetadataProcessor` inyecta el nuevo bloque en el archivo original.
5. **Finalización:** `EnrichmentService` guarda el resultado, vincula archivos dependientes y genera el ZIP de descarga o la galerada.

---
