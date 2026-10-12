import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/features/conversations/presentation/ai_suggestion_apply.dart';

void main() {
  test('applies the latest suggestion for the same untouched composer', () {
    expect(
      shouldApplyAiSuggestion(
        requestConversationId: 4,
        currentConversationId: 4,
        requestGeneration: 2,
        currentGeneration: 2,
        composerAtRequest: '',
        composerNow: '',
        suggestion: 'المنتج متوفر عندنا',
      ),
      isTrue,
    );
  });

  test('does not replace composer text the user changed while waiting', () {
    expect(
      shouldApplyAiSuggestion(
        requestConversationId: 4,
        currentConversationId: 4,
        requestGeneration: 2,
        currentGeneration: 2,
        composerAtRequest: '',
        composerNow: 'مسودة الموظف',
        suggestion: 'اقتراح',
      ),
      isFalse,
    );
  });

  test('drops a stale suggestion and an empty or foreign response', () {
    expect(
      shouldApplyAiSuggestion(
        requestConversationId: 4,
        currentConversationId: 4,
        requestGeneration: 1,
        currentGeneration: 2,
        composerAtRequest: '',
        composerNow: '',
        suggestion: 'رد قديم',
      ),
      isFalse,
    );
    expect(
      shouldApplyAiSuggestion(
        requestConversationId: 4,
        currentConversationId: 9,
        requestGeneration: 2,
        currentGeneration: 2,
        composerAtRequest: '',
        composerNow: '',
        suggestion: 'رد محادثة أخرى',
      ),
      isFalse,
    );
    expect(
      shouldApplyAiSuggestion(
        requestConversationId: 4,
        currentConversationId: 4,
        requestGeneration: 2,
        currentGeneration: 2,
        composerAtRequest: 'مسودة',
        composerNow: 'مسودة',
        suggestion: '   ',
      ),
      isFalse,
    );
  });
}
