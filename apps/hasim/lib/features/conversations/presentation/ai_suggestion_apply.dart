/// Decides whether an AI suggestion may replace the composer text.
///
/// A late response from another conversation, an older request, an empty
/// suggestion, or a composer the user already changed must leave the field
/// untouched.
bool shouldApplyAiSuggestion({
  required int requestConversationId,
  required int currentConversationId,
  required int requestGeneration,
  required int currentGeneration,
  required String composerAtRequest,
  required String composerNow,
  required String? suggestion,
}) {
  final text = suggestion?.trim() ?? '';
  if (text.isEmpty) {
    return false;
  }
  if (requestConversationId != currentConversationId) {
    return false;
  }
  if (requestGeneration != currentGeneration) {
    return false;
  }
  if (composerNow != composerAtRequest) {
    return false;
  }
  return true;
}
