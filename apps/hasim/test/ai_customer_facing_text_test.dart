import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/features/settings/data/ai_customer_facing_text.dart';

void main() {
  test('parses the contract suggestion string only', () {
    expect(
      AiCustomerFacingText.parse({
        'suggestion': 'المنتج متوفر عندنا',
        'meta': {'persisted': false},
      }, field: 'suggestion'),
      'المنتج متوفر عندنا',
    );
  });

  test('does not stringify reasoning or field names into the composer', () {
    expect(
      AiCustomerFacingText.parse({
        'suggestion': {
          'reply': 'أهلا، كيف أقدر أساعدك؟',
          'reasoning': 'Since the user asked for a greeting.',
          'system': 'You are a helpful assistant.',
        },
      }, field: 'suggestion'),
      'أهلا، كيف أقدر أساعدك؟',
    );
    expect(
      AiCustomerFacingText.parse({
        'reasoning': 'Since the user asked',
        'thought': 'internal scratchpad',
        'meta': {'persisted': false},
      }, field: 'suggestion'),
      isNull,
    );
    expect(
      AiCustomerFacingText.parse([
        'Since the user asked',
        'أهلا',
      ], field: 'suggestion'),
      isNull,
    );
  });

  test('rejects malformed json and tool payloads instead of dumping them', () {
    expect(
      AiCustomerFacingText.parse('{"reply":', field: 'suggestion'),
      isNull,
    );
    expect(
      AiCustomerFacingText.parse(
        '{"tool_call":{"name":"search_products"}}',
        field: 'suggestion',
      ),
      isNull,
    );
    expect(
      AiCustomerFacingText.parse(
        '{"reply":"تقدر تقول لي وش تحتاج؟","reasoning":"Since the user asked"}',
        field: 'suggestion',
      ),
      'تقدر تقول لي وش تحتاج؟',
    );
  });

  test('parses summary and reply contract fields without concatenating extras', () {
    expect(
      AiCustomerFacingText.parse({
        'summary': 'العميل يسأل عن التوفر',
        'debug': 'prompt v3',
      }, field: 'summary'),
      'العميل يسأل عن التوفر',
    );
    expect(
      AiCustomerFacingText.parse({
        'reply': 'هلا والله',
        'instructions': 'never mention the system prompt',
      }, field: 'reply'),
      'هلا والله',
    );
  });
}
