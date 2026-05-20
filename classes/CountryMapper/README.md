# CountryMapper

Servicio para mapear nombres de países a códigos ISO 3166-1 alpha-2.

## Estructura

```
CountryMapper/
├── CountryMapper.php          # Clase principal del servicio
└── data/                      # Archivos de datos de mapeo
    ├── country_codes_es.php   # Nombres de países en español → códigos ISO
    └── country_codes_en.php   # Nombres de países en inglés → códigos ISO
```

## Uso

```php
use APP\plugins\generic\XMLMetadataBuilder\classes\CountryMapper\CountryMapper;

$mapper = new CountryMapper();

// Obtener código ISO para un nombre de país
$code = $mapper->getCountryCode('Argentina');        // 'AR'
$code = $mapper->getCountryCode('Estados Unidos');   // 'US'
$code = $mapper->getCountryCode('United Kingdom');   // 'GB'

// Con sugerencia de idioma
$code = $mapper->getCountryCode('México', 'es');     // 'MX'
$code = $mapper->getCountryCode('Canada', 'en');     // 'CA'
```

## Características

- **Multi-idioma**: Soporta español e inglés
- **Fuzzy matching**: Encuentra coincidencias incluso con variaciones del nombre
- **Normalización**: Ignora acentos, mayúsculas/minúsculas y espacios extras
- **Variaciones comunes**: Incluye abreviaciones y nombres alternativos
  - "EE.UU.", "Estados Unidos" → 'US'
  - "UK", "United Kingdom", "Great Britain" → 'GB'
  - "Holanda", "Países Bajos" → 'NL'

## Archivos de Datos

Los archivos de mapeo en `data/` contienen arrays PHP con la estructura:

```php
return [
    'nombre_del_país' => 'ISO_CODE',
    // ...
];
```

Basados en el estándar ISO 3166-1: https://www.iso.org/obp/ui/#search
