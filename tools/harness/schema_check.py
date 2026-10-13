"""Minimal JSON Schema subset validator (type, required, properties, items, enum) — stdlib only."""
from __future__ import annotations

from typing import Any, Dict, List

TYPES = {'object': dict, 'array': list, 'string': str, 'boolean': bool, 'null': type(None)}


def _is(value: Any, t: str) -> bool:
    if t == 'integer':
        return isinstance(value, int) and not isinstance(value, bool)
    if t == 'number':
        return isinstance(value, (int, float)) and not isinstance(value, bool)
    return isinstance(value, TYPES[t])


def validate(value: Any, schema: Dict[str, Any], path: str = '$') -> List[str]:
    errors: List[str] = []
    t = schema.get('type')
    if t:
        types = t if isinstance(t, list) else [t]
        if not any(_is(value, x) for x in types):
            return [f'{path}: expected {t}, got {type(value).__name__}']
    if 'enum' in schema and value not in schema['enum']:
        errors.append(f'{path}: {value!r} not in {schema["enum"]}')
    if isinstance(value, dict):
        for key in schema.get('required', []):
            if key not in value:
                errors.append(f'{path}: missing {key}')
        for key, sub in schema.get('properties', {}).items():
            if key in value:
                errors += validate(value[key], sub, f'{path}.{key}')
    if isinstance(value, list) and 'items' in schema:
        for i, item in enumerate(value):
            errors += validate(item, schema['items'], f'{path}[{i}]')
    return errors
