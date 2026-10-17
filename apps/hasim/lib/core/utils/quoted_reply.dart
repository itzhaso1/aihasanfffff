/// رد مقتبس بصيغة واتساب: سطر أول يبدأ بـ `> ` ثم نص الرد.
///
/// الـ API لا يحمل معرّف الرسالة المقتبسة، لذلك يُرسل الاقتباس داخل النص نفسه.
const int quotedReplyMaxLength = 120;

({String? quote, String body}) splitQuotedReply(String content) {
  if (!content.startsWith('> ')) {
    return (quote: null, body: content);
  }
  final newline = content.indexOf('\n');
  if (newline < 0) {
    return (quote: null, body: content);
  }
  final quote = content.substring(2, newline).trim();
  final body = content.substring(newline + 1).trim();
  if (quote.isEmpty || body.isEmpty) {
    return (quote: null, body: content);
  }
  return (quote: quote, body: body);
}

String composeQuotedReply({required String quoted, required String reply}) {
  final body = reply.trim();
  final line = splitQuotedReply(
    quoted.trim(),
  ).body.replaceAll(RegExp(r'\s+'), ' ').trim();
  if (line.isEmpty || body.isEmpty) {
    return body;
  }
  final clipped = line.length > quotedReplyMaxLength
      ? '${line.substring(0, quotedReplyMaxLength)}…'
      : line;
  return '> $clipped\n$body';
}
