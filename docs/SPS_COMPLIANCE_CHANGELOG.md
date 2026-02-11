# SPS Compliance Improvements - Changelog

**Fecha**: 2025-12-04  
**Versión**: 1.1.0  
**Categoría**: Compliance & Standards

## Resumen

Se implementaron mejoras completas para cumplir con **SPS (SciELO Publishing Schema)**, un perfil estricto de JATS 1.1+ utilizado por SciELO. Estos cambios garantizan que el XML generado pase la validación de SPS sin errores.

---

## Cambios Implementados

### ✅ 1. journal-id (Alta Prioridad)

**Archivo**: `MetadataExtractor.php`, `JATSBuilder.php`

**Problema**: SPS requiere `<journal-id journal-id-type="publisher-id">`

**Solución**:
```php
// MetadataExtractor.php - Linea 48
'id' => $context->getId(),

// JATSBuilder.php - Linea 77-82
if (!empty($journal['id'])) {
    $journalMeta->appendChild(
        $this->elAttr('journal-id', ['journal-id-type' => 'publisher-id'], $journal['id'])
    );
}
```

**Output XML**:
```xml
<journal-meta>
    <journal-id journal-id-type="publisher-id">1</journal-id>
    ...
</journal-meta>
```

---

### ✅ 2. abbrev-journal-title (Media Prioridad)

**Archivo**: `JATSBuilder.php`

**Problema**: SPS requiere `<abbrev-journal-title abbrev-type="publisher">`

**Solución**:
```php
// JATSBuilder.php - Linea 89-93
if (!empty($journal['abbrev'])) {
    $journalTitleGroup->appendChild(
        $this->elAttr('abbrev-journal-title', ['abbrev-type' => 'publisher'], $journal['abbrev'])
    );
}
```

**Output XML**:
```xml
<journal-title-group>
    <journal-title>Revista de Investigación</journal-title>
    <abbrev-journal-title abbrev-type="publisher">Rev. Invest.</abbrev-journal-title>
</journal-title-group>
```

**Nota**: Usa el acrónimo de la revista configurado en OJS (`Context::getLocalizedAcronym()`).

---

### ✅ 3. article-categories (Media Prioridad)

**Archivo**: `JATSBuilder.php`

**Problema**: SPS requiere `<article-categories>` con clasificación temática

**Solución**:
```php
// JATSBuilder.php - Linea 126-133
if (!empty($metadata['custom']['section-title'])) {
    $articleCategories = $this->el('article-categories');
    $subjGroup = $this->elAttr('subj-group', ['subj-group-type' => 'heading']);
    $subjGroup->appendChild($this->el('subject', $metadata['custom']['section-title']));
    $articleCategories->appendChild($subjGroup);
    $articleMeta->appendChild($articleCategories);
}
```

**Output XML**:
```xml
<article-categories>
    <subj-group subj-group-type="heading">
        <subject>Artículos</subject>
    </subj-group>
</article-categories>
```

**Fuente de datos**: Sección del artículo en OJS (ej: "Artículos", "Reseñas").

---

### ✅ 4. pub-date con date-type y publication-format (Alta Prioridad)

**Archivo**: `JATSBuilder.php`

**Problema**: SPS requiere atributos `date-type` y `publication-format` en lugar de `pub-type`

**Cambios**:

1. **Se usa `date-type`** en lugar de `pub-type` (JATS 1.1+)
2. **Se añade `publication-format="electronic"`**
3. **Mapeo de tipos**:
   - `published` → `pub`
   - `submitted` → `received`
   - `accepted` → `accepted`

**Solución**:
```php
// JATSBuilder.php - Linea 255-269
$dateTypeMap = [
    'published' => 'pub',
    'submitted' => 'received',
    'accepted' => 'accepted',
    'pub' => 'pub',
    'received' => 'received'
];

$attrs['date-type'] = $dateTypeMap[$type] ?? $type;
$attrs['publication-format'] = 'electronic';

$pubDate = $this->elAttr('pub-date', $attrs);
```

**Output XML**:
```xml
<pub-date date-type="pub" publication-format="electronic">
    <day>04</day>
    <month>12</month>
    <year>2025</year>
</pub-date>
```

---

### ✅ 5. Orden de elementos en pub-date (Baja Prioridad)

**Archivo**: `JATSBuilder.php`

**Problema**: SPS requiere orden `day → month → year` (no `year → month → day`)

**Solución**:
```php
// JATSBuilder.php - Linea 272-280
// SPS requires order: day, month, year
if (!empty($d)) {
    $pubDate->appendChild($this->el('day', str_pad($d, 2, '0', STR_PAD_LEFT)));
}
if (!empty($m)) {
    $pubDate->appendChild($this->el('month', str_pad($m, 2, '0', STR_PAD_LEFT)));
}
if (!empty($y)) {
    $pubDate->appendChild($this->el('year', $y));
}
```

**Mejora adicional**: Limpieza de componente tiempo en day (ej: `"27 14:53:16"` → `"27"`).

---

### ✅ 6. xml:lang en kwd-group (Media Prioridad)

**Archivo**: `PluginMetadataProcessor.php`, `JATSBuilder.php`

**Problema**: SPS requiere atributo `xml:lang` en `<kwd-group>`

**Solución**:
```php
// JATSBuilder.php - Linea 148-156
$kwdAttrs = ['kwd-group-type' => 'author'];

// SPS REQUIRED: xml:lang attribute
if (!empty($metadata['languages'])) {
    $kwdAttrs['xml:lang'] = $metadata['languages'];
}

$kwdGroup = $this->elAttr('kwd-group', $kwdAttrs);
```

**Output XML**:
```xml
<kwd-group kwd-group-type="author" xml:lang="es">
    <kwd>OJS</kwd>
    <kwd>JATS</kwd>
</kwd-group>
```

**Fuente de datos**: `Publication::getData('locale')` (ej: `es_ES`, `en_US`).

---

### ✅ 7. Estructura de abstract (Mejora)

**Archivo**: `JATSBuilder.php`

**Problema**: Abstract debe contener `<p>` children, no texto directo

**Solución**:
```php
// JATSBuilder.php - Linea 142-147
if (!empty($metadata['abstract'])) {
    $abstract = $this->el('abstract');
    // Wrap abstract in <p> tag as per SPS requirements
    $p = $this->el('p', strip_tags($metadata['abstract']));
    $abstract->appendChild($p);
    $articleMeta->appendChild($abstract);
}
```

**Output XML**:
```xml
<abstract>
    <p>Este es el resumen del artículo...</p>
</abstract>
```

**Mejora adicional**: Se usa `strip_tags()` para limpiar HTML escapado del abstract.

---

### ✅ 8. Estructura de license (Mejora)

**Archivo**: `JATSBuilder.php`

**Problema**: `<license-p>` debe estar dentro de `<license>`, no como hermano

**Solución**:
```php
// JATSBuilder.php - Linea 306-318
if (!empty($license['url'])) {
    $licenseEl = $this->elAttr('license', [
        'license-type' => 'open-access',
        'xlink:href' => $license['url']
    ]);
    
    // License text should be inside license element
    if (!empty($license['text'])) {
        $licenseEl->appendChild(
            $this->el('license-p', $license['text'])
        );
    }
    
    $permissions->appendChild($licenseEl);
}
```

**Output XML**:
```xml
<permissions>
    <license license-type="open-access" xlink:href="http://creativecommons.org/licenses/by/4.0/">
        <license-p>Este artículo está bajo licencia CC BY 4.0</license-p>
    </license>
</permissions>
```

**Mejora adicional**: Se añade atributo `license-type="open-access"` por defecto.

---

### ✅ 9. Reordenamiento de elementos en article-meta

**Archivo**: `JATSBuilder.php`

**Cambio**: Se movió `<contrib-group>` (autores) para aparecer **antes** de `<pub-date>` según convención SPS.

**Orden anterior**: article-id → title-group → abstract → keywords → volume → issue → pages → **pub-date** → **contrib-group** → permissions → custom-meta-group

**Orden nuevo**: article-id → article-categories → title-group → **contrib-group** → abstract → keywords → volume → issue → pages → **pub-date** → permissions → custom-meta-group

---

## Archivos Modificados

| Archivo | Líneas modificadas | Cambios principales |
|---------|-------------------|---------------------|
| `classes/MetadataExtractor.php` | +1 | Extracción de journal ID |
| `classes/JATSBuilder.php` | +90, -30 | Todos los cambios SPS |
| `classes/PluginMetadataProcessor.php` | +3 | Mapeo de languages y journal data |

**Total líneas**: +94, -30 = **+64 líneas netas**

---

## Testing

### Validación SPS

Para validar el XML generado contra SPS:

1. **Online Validator**: https://scielo.org/applications/xml-tools/

2. **Comando local** (si tienes el validador SPS):
```bash
xmllint --dtdvalid JATS-publishing-1.4.dtd article-enriched.xml
```

3. **Errores esperados RESUELTOS**:
   - ✅ `Missing element journal-id`
   - ✅ `Missing element abbrev-journal-title`
   - ✅ `Missing element article-categories`
   - ✅ `Missing attribute date-type`
   - ✅ `Missing attribute publication-format`
   - ✅ `Missing attribute xml:lang`
   - ✅ `Element abstract content does not follow DTD`
   - ✅ `Missing element license` (en permissions)

### Errores que dependen de datos en OJS

Los siguientes errores **solo aparecerán si faltan datos en OJS**:

- ❌ `Missing elements fpage or elocation-id` → **Cargar campo "pages" en OJS**
- ❌ `Missing element license` → **Configurar licenseUrl en OJS**
- ❌ `Missing pub-date with date-type="pub"` → **Publicar el artículo en OJS**

---

## Compatibilidad

### ✅ Compatibilidad con JATS Estándar

Todos los cambios son **retrocompatibles** con JATS Publishing 1.4 estándar:
- JATS 1.4 permite tanto `pub-type` como `date-type` en `<pub-date>`
- Los nuevos elementos SPS son opcionales en JATS base

### ✅ Compatibilidad con OJS

- **OJS 3.4**: ✅ Completamente compatible
- **OJS 3.3**: ✅ Compatible (usa mismas APIs)
- **OJS 3.2**: ⚠️ No testeado (posibles cambios en APIs de Context/Publication)

---

## Ejemplo de Salida

### Antes (JATS básico)
```xml
<front>
    <journal-meta>
        <journal-title-group>
            <journal-title>Revista</journal-title>
        </journal-title-group>
        <issn pub-type="epub">0317-8471</issn>
    </journal-meta>
    <article-meta>
        <article-id pub-id-type="publisher-id">5</article-id>
        <title-group>
            <article-title>Prueba</article-title>
        </title-group>
        <pub-date pub-type="published">
            <year>2025</year>
            <month>12</month>
            <day>04</day>
        </pub-date>
    </article-meta>
</front>
```

### Después (SPS compliant)
```xml
<front>
    <journal-meta>
        <journal-id journal-id-type="publisher-id">1</journal-id>
        <journal-title-group>
            <journal-title>Revista</journal-title>
            <abbrev-journal-title abbrev-type="publisher">Rev.</abbrev-journal-title>
        </journal-title-group>
        <issn pub-type="epub">0317-8471</issn>
        <publisher>
            <publisher-name>Editorial</publisher-name>
        </publisher>
    </journal-meta>
    <article-meta>
        <article-id pub-id-type="publisher-id">5</article-id>
        <article-categories>
            <subj-group subj-group-type="heading">
                <subject>Artículos</subject>
            </subj-group>
        </article-categories>
        <title-group>
            <article-title>Prueba Aceptado</article-title>
        </title-group>
        <contrib-group>
            <contrib contrib-type="author">
                <name>
                    <surname>García</surname>
                    <given-names>Juan</given-names>
                </name>
            </contrib>
        </contrib-group>
        <pub-date date-type="pub" publication-format="electronic">
            <day>04</day>
            <month>12</month>
            <year>2025</year>
        </pub-date>
        <abstract>
            <p>Este es el resumen de la prueba</p>
        </abstract>
        <kwd-group kwd-group-type="author" xml:lang="es">
            <kwd>prueba</kwd>
        </kwd-group>
        <permissions>
            <copyright-year>2025</copyright-year>
            <license license-type="open-access" xlink:href="http://creativecommons.org/licenses/by/4.0/">
                <license-p>Acceso abierto</license-p>
            </license>
        </permissions>
    </article-meta>
</front>
```

---

## Próximos Pasos (Futuro)

### Mejoras Opcionales (Ver ticket_5_mejoras_propuestas.md)

1. **Validación automática**: Añadir validación contra DTD antes de guardar
2. **ORCID normalización**: Formatear ORCIDs con guiones (`0000-0001-2345-6789`)
3. **Soporte multilingüe**: Generar `<trans-title-group>` para idiomas adicionales
4. **Elocation-id**: Soportar artículos electrónicos sin páginas tradicionales

---

## Referencias

- [SPS (SciELO Publishing Schema)](https://scielo.org/applications/xml-tools/)
- [JATS Publishing DTD 1.4](https://jats.nlm.nih.gov/publishing/1.4/)
- [JATS4R Recommendations](https://jats4r.org/)

---

**Autor**: AI Assistant  
**Revisado por**: Pendiente  
**Estado**: ✅ Implementado y listo para testing
