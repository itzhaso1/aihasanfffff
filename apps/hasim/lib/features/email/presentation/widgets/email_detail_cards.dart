import 'package:flutter/material.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/features/email/presentation/widgets/compose_fields.dart';
import 'package:url_launcher/url_launcher.dart';

class MessageInfoCard extends StatelessWidget {
  const MessageInfoCard({
    super.key,
    required this.subject,
    required this.sender,
    required this.recipient,
    this.accountLabel,
    this.timeLabel,
  });

  final String subject;
  final String sender;
  final String recipient;
  final String? accountLabel;
  final String? timeLabel;

  @override
  Widget build(BuildContext context) {
    return ComposeCard(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 18, 16, 16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              subject,
              textAlign: TextAlign.start,
              style: Theme.of(context).textTheme.titleLarge?.copyWith(
                    fontWeight: FontWeight.w800,
                    color: HasimTokens.inkColor(context),
                    height: 1.3,
                  ),
            ),
            const SizedBox(height: 16),
            MessageMetaRow(icon: Icons.mail_outline_rounded, label: 'من', value: sender, ltrValue: true),
            const SizedBox(height: 12),
            MessageMetaRow(icon: Icons.person_outline_rounded, label: 'إلى', value: recipient, ltrValue: true),
            if (accountLabel != null && accountLabel!.isNotEmpty) ...[
              const SizedBox(height: 12),
              MessageMetaRow(icon: Icons.layers_outlined, label: 'حساب', value: accountLabel!),
            ],
            if (timeLabel != null && timeLabel!.isNotEmpty) ...[
              const SizedBox(height: 14),
              Row(
                children: [
                  Icon(Icons.schedule_rounded, size: 16, color: HasimTokens.mutedColor(context)),
                  const SizedBox(width: 6),
                  Flexible(
                    child: Text(
                      timeLabel!,
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                            color: HasimTokens.mutedColor(context),
                            fontWeight: FontWeight.w600,
                          ),
                    ),
                  ),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class MessageMetaRow extends StatelessWidget {
  const MessageMetaRow({
    super.key,
    required this.icon,
    required this.label,
    required this.value,
    this.ltrValue = false,
  });

  final IconData icon;
  final String label;
  final String value;
  final bool ltrValue;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Container(
          width: 32,
          height: 32,
          decoration: BoxDecoration(
            color: HasimTokens.mintFill(context),
            shape: BoxShape.circle,
          ),
          child: Icon(icon, size: 16, color: AppTheme.brand),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                '$label:',
                textAlign: TextAlign.start,
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: HasimTokens.mutedColor(context),
                      fontWeight: FontWeight.w600,
                    ),
              ),
              const SizedBox(height: 2),
              Text(
                value,
                textAlign: TextAlign.start,
                textDirection: ltrValue ? TextDirection.ltr : TextDirection.rtl,
                style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                      color: HasimTokens.inkColor(context),
                      fontWeight: FontWeight.w700,
                      height: 1.35,
                    ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class MessageBodyCard extends StatelessWidget {
  const MessageBodyCard({super.key, required this.body});

  final String body;

  @override
  Widget build(BuildContext context) {
    return ComposeCard(
      minHeight: 160,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(18, 18, 18, 20),
        child: Align(
          alignment: AlignmentDirectional.topStart,
          child: _MessageBodyText(body: body),
        ),
      ),
    );
  }
}

class _MessageBodyText extends StatelessWidget {
  const _MessageBodyText({required this.body});

  final String body;

  @override
  Widget build(BuildContext context) {
    final style = Theme.of(context).textTheme.bodyLarge?.copyWith(
          color: HasimTokens.inkColor(context),
          height: 1.7,
        );
    return SelectableText.rich(
      TextSpan(children: _linkSpans(context, body, style)),
      textAlign: TextAlign.start,
    );
  }
}

List<InlineSpan> _linkSpans(BuildContext context, String text, TextStyle? style) {
  final pattern = RegExp(r'(https?:\/\/[^\s]+)', caseSensitive: false);
  final matches = pattern.allMatches(text).toList();
  if (matches.isEmpty) {
    return [TextSpan(text: text, style: style)];
  }
  final spans = <InlineSpan>[];
  var cursor = 0;
  for (final match in matches) {
    if (match.start > cursor) {
      spans.add(TextSpan(text: text.substring(cursor, match.start), style: style));
    }
    final url = match.group(0)!;
    spans.add(
      WidgetSpan(
        alignment: PlaceholderAlignment.baseline,
        baseline: TextBaseline.alphabetic,
        child: GestureDetector(
          onTap: () {
            final uri = Uri.tryParse(url);
            if (uri != null) {
              launchUrl(uri, mode: LaunchMode.externalApplication);
            }
          },
          child: Text(
            url,
            style: style?.copyWith(
              color: AppTheme.brand,
              fontWeight: FontWeight.w700,
              decoration: TextDecoration.underline,
            ),
          ),
        ),
      ),
    );
    cursor = match.end;
  }
  if (cursor < text.length) {
    spans.add(TextSpan(text: text.substring(cursor), style: style));
  }
  return spans;
}
