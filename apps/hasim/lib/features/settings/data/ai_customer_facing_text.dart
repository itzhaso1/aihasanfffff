import 'dart:convert';

/// Extracts the customer-facing AI string from an API payload.
///
/// The mobile contract is a single string field (`suggestion`, `reply`, or
/// `summary`). Maps, lists, reasoning/tool/debug keys, and malformed JSON must
/// not be stringified into the composer or a message bubble.
class AiCustomerFacingText {
  static const _userKeys = ['suggestion', 'reply', 'summary', 'content', 'text'];
  static const _internalKeys = {
    'reasoning',
    'thought',
    'thoughts',
    'thinking',
    'system',
    'instructions',
    'developer',
    'tool',
    'tools',
    'tool_call',
    'tool_calls',
    'functionCall',
    'function_call',
    'metadata',
    'meta',
    'debug',
    'prompt',
    'raw',
    'persisted',
  };

  static String? parse(dynamic raw, {required String field}) {
    if (raw == null) {
      return null;
    }
    if (raw is String) {
      return _fromString(raw, field: field);
    }
    if (raw is Map) {
      return _fromMap(Map<String, dynamic>.from(raw), field: field);
    }
    return null;
  }

  static String? _fromString(String raw, {required String field}) {
    final trimmed = raw.trim();
    if (trimmed.isEmpty) {
      return null;
    }
    if (trimmed.startsWith('{') || trimmed.startsWith('[')) {
      try {
        final decoded = jsonDecode(trimmed);
        if (decoded is Map) {
          return _fromMap(Map<String, dynamic>.from(decoded), field: field);
        }
        return null;
      } on FormatException {
        return null;
      }
    }
    return trimmed;
  }

  static String? _fromMap(Map<String, dynamic> map, {required String field}) {
    final preferred = _value(map[field], field: field);
    if (preferred != null) {
      return preferred;
    }
    final nested = map['data'];
    if (nested is Map) {
      final fromData = _fromMap(Map<String, dynamic>.from(nested), field: field);
      if (fromData != null) {
        return fromData;
      }
    }
    for (final key in _userKeys) {
      if (key == field || _internalKeys.contains(key)) {
        continue;
      }
      final value = _value(map[key], field: key);
      if (value != null) {
        return value;
      }
    }
    return null;
  }

  static String? _value(dynamic raw, {required String field}) {
    if (raw is String) {
      return _fromString(raw, field: field);
    }
    if (raw is Map) {
      return _fromMap(Map<String, dynamic>.from(raw), field: field);
    }
    return null;
  }
}
