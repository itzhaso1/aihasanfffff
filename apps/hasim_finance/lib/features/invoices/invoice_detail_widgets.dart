import 'package:flutter/material.dart';
import 'package:hasim_finance/core/layout/finance_chrome.dart';
import 'package:hasim_finance/core/models/models.dart';
import 'package:hasim_finance/core/theme/finance_tokens.dart';
import 'package:hasim_finance/core/widgets/widgets.dart';
import 'package:hasim_finance/l10n/app_localizations.dart';

String invoiceCurrency(InvoiceRecord invoice) {
  final code = invoice.currency.trim();
  if (code.isEmpty || code.toUpperCase() == 'SAR') return 'ر.س';
  return code;
}

String? firstNonEmpty(Iterable<String?> values) {
  for (final value in values) {
    final text = value?.trim() ?? '';
    if (text.isNotEmpty) return text;
  }
  return null;
}

String? snapshotText(Map<String, dynamic>? snap, List<String> keys) {
  if (snap == null) return null;
  for (final key in keys) {
    final value = snap[key];
    if (value == null) continue;
    final text = value.toString().trim();
    if (text.isNotEmpty) return text;
  }
  return null;
}

String? snapshotAddress(Map<String, dynamic>? snap) {
  if (snap == null) return null;
  final address = snap['address'];
  if (address is String && address.trim().isNotEmpty) return address.trim();
  final parts = <String>[];
  void add(Object? value) {
    final text = value?.toString().trim() ?? '';
    if (text.isNotEmpty && !parts.contains(text)) parts.add(text);
  }

  if (address is Map) {
    add(address['line'] ?? address['address_line']);
    add(address['building_number']);
    add(address['street']);
    add(address['district']);
    add(address['city']);
    add(address['postal_code']);
    add(address['country_code'] ?? address['country']);
  }
  add(snap['address_line']);
  add(snap['building_number']);
  add(snap['street']);
  add(snap['district']);
  add(snap['city']);
  add(snap['postal_code']);
  add(snap['country_code'] ?? snap['country']);
  if (parts.isEmpty) return null;
  return parts.join('، ');
}

String documentStatusLabel(String? status, AppLocalizations l) {
  return switch (status) {
    'draft' => l.draftStatus,
    'issued' => l.issued,
    'cancelled' => l.cancelled,
    'sent' => l.lifecycleSent,
    _ => status ?? '',
  };
}

String paymentStatusLabel(String? status, AppLocalizations l) {
  return switch (status) {
    'paid' => l.paidInFull,
    'unpaid' => l.unpaid,
    'partial' => l.partial,
    'overdue' => l.overdue,
    _ => status ?? '',
  };
}

String paymentMethodLabel(String? method, AppLocalizations l) {
  return switch (method) {
    'cash' => l.methodCash,
    'bank_transfer' => l.methodBank,
    'card' => l.methodCard,
    'other' => l.methodOther,
    _ => method ?? '—',
  };
}

String auditActionLabel(String? action, AppLocalizations l) {
  return switch (action) {
    'invoice_created' => l.invoiceCreatedEvent,
    'invoice_issued' => l.invoiceIssuedEvent,
    'invoice_cancelled' => l.invoiceCancelledEvent,
    'invoice_sent' => l.invoiceSentEvent,
    'invoice_updated' => l.invoiceUpdatedEvent,
    'invoice_reminder_sent' => l.invoiceReminderSentEvent,
    _ => action ?? '',
  };
}

Color _badgeForeground(BuildContext context, String? status) {
  final p = context.hs;
  return switch (status) {
    'cancelled' => p.pink,
    'overdue' || 'failed' || 'voided' => p.danger,
    'unpaid' || 'expired' => p.orange,
    'paid' || 'posted' || 'issued' || 'sent' => p.success,
    'partial' || 'draft' || 'pending' => p.info,
    _ => p.textMuted,
  };
}

Color _badgeBackground(BuildContext context, String? status) {
  final p = context.hs;
  return switch (status) {
    'cancelled' => p.pinkSoft,
    'overdue' || 'failed' || 'voided' => p.dangerSoft,
    'unpaid' || 'expired' => p.orangeSoft,
    'paid' || 'posted' || 'issued' || 'sent' => p.successSoft,
    'partial' || 'draft' || 'pending' => p.infoSoft,
    _ => p.canvasAlt,
  };
}

class InvoiceStatusBadges extends StatelessWidget {
  const InvoiceStatusBadges({super.key, required this.invoice});

  final InvoiceRecord invoice;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        if ((invoice.documentStatus ?? '').isNotEmpty)
          _InvoicePill(
            label: '${l.documentStatus}: ${documentStatusLabel(invoice.documentStatus, l)}',
            foreground: _badgeForeground(context, invoice.documentStatus),
            background: _badgeBackground(context, invoice.documentStatus),
          ),
        if ((invoice.paymentStatus ?? '').isNotEmpty)
          _InvoicePill(
            label: '${paymentStatusLabel(invoice.paymentStatus, l)} (${invoice.paymentStatus})',
            foreground: _badgeForeground(context, invoice.paymentStatus),
            background: _badgeBackground(context, invoice.paymentStatus),
            icon: invoice.paymentStatus == 'unpaid' || invoice.paymentStatus == 'overdue'
                ? Icons.access_time_rounded
                : invoice.paymentStatus == 'paid'
                    ? Icons.check_circle_outline_rounded
                    : null,
          ),
      ],
    );
  }
}

class _InvoicePill extends StatelessWidget {
  const _InvoicePill({
    required this.label,
    required this.foreground,
    required this.background,
    this.icon,
  });

  final String label;
  final Color foreground;
  final Color background;
  final IconData? icon;

  @override
  Widget build(BuildContext context) {
    return ConstrainedBox(
      constraints: const BoxConstraints(maxWidth: 280),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
        decoration: BoxDecoration(color: background, borderRadius: BorderRadius.circular(999)),
        child: Text(
          icon == null ? label : '● $label',
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
          style: TextStyle(color: foreground, fontWeight: FontWeight.w800, fontSize: 12),
        ),
      ),
    );
  }
}

class InvoiceSectionCard extends StatelessWidget {
  const InvoiceSectionCard({
    super.key,
    required this.title,
    required this.child,
    this.icon,
    this.tone = FinanceIconTone.teal,
    this.trailing,
    this.padding = const EdgeInsets.fromLTRB(16, 12, 16, 14),
  });

  final String title;
  final Widget child;
  final IconData? icon;
  final FinanceIconTone tone;
  final Widget? trailing;
  final EdgeInsets padding;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: context.hs.card,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(FinanceTokens.radiusLg),
        side: BorderSide(color: context.hs.border),
      ),
      child: Padding(
        padding: padding,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                if (icon != null) ...[
                  FinanceIconBadge(icon: icon!, tone: tone, size: 32),
                  const SizedBox(width: 8),
                ],
                Expanded(
                  child: Text(
                    title,
                    style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w800),
                  ),
                ),
                ?trailing,
              ],
            ),
            const SizedBox(height: 12),
            child,
          ],
        ),
      ),
    );
  }
}

class InvoiceMetaRow extends StatelessWidget {
  const InvoiceMetaRow({super.key, required this.label, this.value, this.emphasized = false});

  final String label;
  final String? value;
  final bool emphasized;

  @override
  Widget build(BuildContext context) {
    final text = value?.trim() ?? '';
    if (text.isEmpty) return const SizedBox.shrink();
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 108,
            child: Text(
              label,
              style: theme.textTheme.bodySmall?.copyWith(color: context.hs.textMuted, fontWeight: FontWeight.w700),
            ),
          ),
          Expanded(
            child: Text(
              text,
              style: theme.textTheme.bodyMedium?.copyWith(
                fontWeight: emphasized ? FontWeight.w800 : FontWeight.w600,
                height: 1.35,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class InvoiceMoneyRow extends StatelessWidget {
  const InvoiceMoneyRow({
    super.key,
    required this.label,
    required this.value,
    required this.currency,
    this.hint,
    this.emphasis = false,
    this.highlight = false,
    this.valueColor,
  });

  final String label;
  final String value;
  final String currency;
  final String? hint;
  final bool emphasis;
  final bool highlight;
  final Color? valueColor;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final row = Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        children: [
          Expanded(
            child: Text.rich(
              TextSpan(
                text: label,
                style: theme.textTheme.bodySmall?.copyWith(
                  color: emphasis ? context.hs.text : context.hs.textMuted,
                  fontWeight: emphasis ? FontWeight.w800 : FontWeight.w600,
                ),
                children: [
                  if (hint != null && hint!.trim().isNotEmpty)
                    TextSpan(
                      text: '  $hint',
                      style: theme.textTheme.bodySmall?.copyWith(color: context.hs.textFaint, fontWeight: FontWeight.w600),
                    ),
                ],
              ),
            ),
          ),
          Text(
            '$value $currency',
            style: theme.textTheme.bodyMedium?.copyWith(
              fontWeight: FontWeight.w800,
              color: valueColor ?? (emphasis ? context.hs.brandAccent : context.hs.text),
              fontSize: emphasis ? 16 : 13.5,
            ),
          ),
        ],
      ),
    );
    if (!highlight) return row;
    return Container(
      margin: const EdgeInsets.only(top: 6),
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
      decoration: BoxDecoration(
        color: context.hs.brandSoft,
        borderRadius: BorderRadius.circular(10),
      ),
      child: row,
    );
  }
}

class InvoiceHeader extends StatelessWidget {
  const InvoiceHeader({
    super.key,
    required this.invoice,
    required this.actions,
  });

  final InvoiceRecord invoice;
  final Widget actions;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final theme = Theme.of(context);
    final stacked = MediaQuery.sizeOf(context).width < 980;
    final identity = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          l.taxInvoice,
          style: theme.textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w800, fontSize: 26, height: 1.1),
        ),
        const SizedBox(height: 4),
        Text(
          invoice.invoiceNumber ?? '#${invoice.id}',
          style: theme.textTheme.titleMedium?.copyWith(color: context.hs.textMuted, fontWeight: FontWeight.w700),
        ),
        const SizedBox(height: 10),
        InvoiceStatusBadges(invoice: invoice),
        const SizedBox(height: 12),
        Wrap(
          spacing: 18,
          runSpacing: 8,
          children: [
            _DateMeta(icon: Icons.event_outlined, label: l.issueDate, value: invoice.issueDate),
            _DateMeta(icon: Icons.event_available_outlined, label: l.dueDate, value: invoice.dueDate),
            _DateMeta(icon: Icons.local_shipping_outlined, label: l.supplyDate, value: invoice.supplyDate),
            _DateMeta(icon: Icons.schedule_outlined, label: l.issuedAt, value: invoice.issuedAt),
          ],
        ),
      ],
    );
    if (stacked) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          identity,
          const SizedBox(height: 14),
          actions,
        ],
      );
    }
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(child: identity),
        const SizedBox(width: 16),
        // Bounded so a long action toolbar wraps instead of overflowing.
        Flexible(child: actions),
      ],
    );
  }
}

class _DateMeta extends StatelessWidget {
  const _DateMeta({required this.icon, required this.label, this.value});

  final IconData icon;
  final String label;
  final String? value;

  @override
  Widget build(BuildContext context) {
    final text = value?.trim() ?? '';
    if (text.isEmpty) return const SizedBox.shrink();
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, size: 15, color: context.hs.textFaint),
        const SizedBox(width: 6),
        Text('$label  ', style: Theme.of(context).textTheme.bodySmall?.copyWith(color: context.hs.textMuted)),
        Text(text, style: Theme.of(context).textTheme.bodySmall?.copyWith(fontWeight: FontWeight.w800)),
      ],
    );
  }
}

class InvoiceActionButton extends StatelessWidget {
  const InvoiceActionButton({
    super.key,
    required this.label,
    required this.icon,
    required this.onPressed,
    this.filled = false,
  });

  final String label;
  final IconData icon;
  final VoidCallback onPressed;
  final bool filled;

  @override
  Widget build(BuildContext context) {
    final child = Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, size: 16),
        const SizedBox(width: 6),
        Text(label),
      ],
    );
    final style = ButtonStyle(
      minimumSize: const WidgetStatePropertyAll(Size(40, 38)),
      padding: const WidgetStatePropertyAll(EdgeInsets.symmetric(horizontal: 12, vertical: 8)),
      visualDensity: VisualDensity.compact,
    );
    if (filled) {
      return FilledButton(onPressed: onPressed, style: style, child: child);
    }
    return OutlinedButton(onPressed: onPressed, style: style, child: child);
  }
}

class CustomerInfoCard extends StatelessWidget {
  const CustomerInfoCard({super.key, required this.invoice, this.onOpenCustomer});

  final InvoiceRecord invoice;
  final VoidCallback? onOpenCustomer;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final snap = invoice.recipientSnapshot;
    final name = firstNonEmpty([
      snapshotText(snap, const ['name']),
      invoice.customerName,
      invoice.supplierName,
    ]);
    return InvoiceSectionCard(
      title: l.customerInfo,
      icon: Icons.person_outline_rounded,
      tone: FinanceIconTone.teal,
      child: Column(
        children: [
          InvoiceMetaRow(label: l.customer, value: name, emphasized: true),
          InvoiceMetaRow(label: l.vatNumber, value: snapshotText(snap, const ['vat_number', 'vat'])),
          InvoiceMetaRow(label: l.crNumber, value: snapshotText(snap, const ['commercial_registration', 'cr_number'])),
          InvoiceMetaRow(label: l.telephone, value: snapshotText(snap, const ['phone', 'mobile', 'whatsapp'])),
          InvoiceMetaRow(label: l.email, value: snapshotText(snap, const ['email'])),
          InvoiceMetaRow(label: l.address, value: snapshotAddress(snap)),
          InvoiceMetaRow(label: l.contract, value: firstNonEmpty([invoice.contractNumber, invoice.contractTitle])),
          InvoiceMetaRow(label: l.project, value: invoice.projectName),
          if (onOpenCustomer != null && invoice.customerId != null)
            Align(
              alignment: AlignmentDirectional.centerStart,
              child: TextButton(
                onPressed: onOpenCustomer,
                child: Text(l.customer),
              ),
            ),
        ],
      ),
    );
  }
}

class InvoiceSummaryCard extends StatelessWidget {
  const InvoiceSummaryCard({super.key, required this.invoice});

  final InvoiceRecord invoice;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final currency = invoiceCurrency(invoice);
    final taxHint = (invoice.taxRate ?? '').trim().isEmpty ? null : '(${invoice.taxRate}%)';
    return InvoiceSectionCard(
      title: l.invoiceSummary,
      icon: Icons.receipt_long_outlined,
      tone: FinanceIconTone.teal,
      child: Column(
        children: [
          InvoiceMoneyRow(label: l.subtotal, value: invoice.subtotal, currency: currency),
          InvoiceMoneyRow(label: l.discount, value: invoice.discount, currency: currency),
          InvoiceMoneyRow(label: l.taxableAmount, value: invoice.taxableAmount, currency: currency),
          InvoiceMoneyRow(label: l.tax, value: invoice.taxAmount, currency: currency, hint: taxHint),
          InvoiceMoneyRow(
            label: l.total,
            value: invoice.total,
            currency: currency,
            emphasis: true,
            highlight: true,
          ),
          if ((invoice.zatcaRequirement ?? '').isNotEmpty ||
              invoice.hasZatcaQr ||
              invoice.zatcaXmlAvailable ||
              (invoice.zatcaSubtype ?? '').isNotEmpty) ...[
            const SizedBox(height: 8),
            const Divider(height: 1),
            const SizedBox(height: 8),
            InvoiceMetaRow(label: l.zatcaInfo, value: invoice.zatcaRequirement),
            InvoiceMetaRow(label: l.taxDocumentSubtype, value: invoice.zatcaSubtype),
            InvoiceMetaRow(label: l.xmlAvailable, value: invoice.zatcaXmlAvailable ? l.xmlAvailable : l.xmlUnavailable),
            InvoiceMetaRow(label: l.qrAvailable, value: invoice.zatcaQrAvailable ? l.qrAvailable : l.qrUnavailable),
            InvoiceMetaRow(label: l.zatcaFoundation, value: invoice.zatcaIntegration ?? 'foundation'),
            InvoiceMetaRow(label: l.taxProfile, value: invoice.taxProfileType),
          ],
          if (invoice.taxBreakdown.isNotEmpty) ...[
            const SizedBox(height: 8),
            const Divider(height: 1),
            const SizedBox(height: 8),
            for (final bucket in invoice.taxBreakdown)
              InvoiceMoneyRow(
                label: '${bucket['code'] ?? bucket['type'] ?? l.tax}',
                value: '${bucket['tax_amount'] ?? bucket['amount'] ?? ''}',
                currency: currency,
              ),
          ],
        ],
      ),
    );
  }
}

class InvoiceItemsTable extends StatelessWidget {
  const InvoiceItemsTable({super.key, required this.invoice});

  final InvoiceRecord invoice;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final theme = Theme.of(context);
    return InvoiceSectionCard(
      title: l.invoiceItems,
      icon: Icons.inventory_2_outlined,
      tone: FinanceIconTone.teal,
      padding: const EdgeInsets.fromLTRB(8, 12, 8, 8),
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: ConstrainedBox(
          constraints: BoxConstraints(minWidth: MediaQuery.sizeOf(context).width < 720 ? 780 : 1080),
          child: Table(
            defaultVerticalAlignment: TableCellVerticalAlignment.middle,
            columnWidths: const {
              0: FixedColumnWidth(44),
              1: FlexColumnWidth(2.4),
              2: FlexColumnWidth(0.85),
              3: FlexColumnWidth(0.85),
              4: FlexColumnWidth(1),
              5: FlexColumnWidth(0.85),
              6: FlexColumnWidth(0.85),
              7: FlexColumnWidth(0.9),
              8: FlexColumnWidth(1),
            },
            children: [
              TableRow(
                decoration: BoxDecoration(color: context.hs.canvasAlt),
                children: [
                  _head(context, l.lineNumber),
                  _head(context, l.description),
                  _head(context, l.quantity, numeric: true),
                  _head(context, l.unit),
                  _head(context, l.price, numeric: true),
                  _head(context, l.discount, numeric: true),
                  _head(context, l.taxRate, numeric: true),
                  _head(context, l.tax, numeric: true),
                  _head(context, l.total, numeric: true),
                ],
              ),
              for (var i = 0; i < invoice.lines.length; i++)
                TableRow(
                  children: [
                    _cell(context, '${i + 1}', muted: true),
                    _desc(context, invoice.lines[i]),
                    _cell(context, invoice.lines[i].quantity, numeric: true),
                    _cell(context, invoice.lines[i].unit),
                    _cell(context, invoice.lines[i].unitPrice, numeric: true),
                    _cell(context, invoice.lines[i].discount, numeric: true),
                    _cell(context, invoice.lines[i].taxRate, numeric: true),
                    _cell(context, invoice.lines[i].taxAmount, numeric: true),
                    _cell(context, invoice.lines[i].total, numeric: true, bold: true),
                  ],
                ),
              TableRow(
                decoration: BoxDecoration(color: context.hs.canvasAlt),
                children: [
                  const SizedBox.shrink(),
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 10),
                    child: Text(l.tableTotal, style: theme.textTheme.bodySmall?.copyWith(fontWeight: FontWeight.w800)),
                  ),
                  const SizedBox.shrink(),
                  const SizedBox.shrink(),
                  _cell(context, invoice.subtotal, numeric: true, bold: true),
                  _cell(context, invoice.discount, numeric: true, bold: true),
                  _cell(context, invoice.taxRate, numeric: true, bold: true),
                  _cell(context, invoice.taxAmount, numeric: true, bold: true),
                  _cell(context, invoice.total, numeric: true, bold: true),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _head(BuildContext context, String label, {bool numeric = false}) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 10),
      child: Text(
        label,
        textAlign: numeric ? TextAlign.end : TextAlign.start,
        style: Theme.of(context).textTheme.bodySmall?.copyWith(fontWeight: FontWeight.w800, color: context.hs.textMuted),
      ),
    );
  }

  Widget _cell(BuildContext context, String? value, {bool numeric = false, bool bold = false, bool muted = false}) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 10),
      child: Text(
        value?.trim().isNotEmpty == true ? value!.trim() : '—',
        textAlign: numeric ? TextAlign.end : TextAlign.start,
        style: Theme.of(context).textTheme.bodySmall?.copyWith(
              fontWeight: bold ? FontWeight.w800 : FontWeight.w600,
              color: muted ? context.hs.textMuted : context.hs.text,
            ),
      ),
    );
  }

  Widget _desc(BuildContext context, LineItem line) {
    final title = firstNonEmpty([line.productName, line.description]) ?? '—';
    final subtitle = (line.productName != null &&
            line.description != null &&
            line.productName!.trim().isNotEmpty &&
            line.description!.trim().isNotEmpty &&
            line.productName!.trim() != line.description!.trim())
        ? line.description!.trim()
        : null;
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(title, style: Theme.of(context).textTheme.bodySmall?.copyWith(fontWeight: FontWeight.w800)),
          if (subtitle != null)
            Text(subtitle, style: Theme.of(context).textTheme.bodySmall?.copyWith(color: context.hs.textMuted, fontSize: 11)),
          if ((line.exemptionReason ?? '').trim().isNotEmpty || (line.exemptionCode ?? '').trim().isNotEmpty)
            Text(
              [line.exemptionCode, line.exemptionReason].where((v) => (v ?? '').trim().isNotEmpty).join(' · '),
              style: Theme.of(context).textTheme.bodySmall?.copyWith(color: context.hs.textMuted, fontSize: 11),
            ),
        ],
      ),
    );
  }
}

class InvoiceTotalsCard extends StatelessWidget {
  const InvoiceTotalsCard({super.key, required this.invoice});

  final InvoiceRecord invoice;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final currency = invoiceCurrency(invoice);
    final taxHint = (invoice.taxRate ?? '').trim().isEmpty ? null : '(${invoice.taxRate}%)';
    return InvoiceSectionCard(
      title: l.invoiceGrandTotal,
      icon: Icons.payments_outlined,
      tone: FinanceIconTone.teal,
      child: Column(
        children: [
          InvoiceMoneyRow(label: l.subtotal, value: invoice.subtotal, currency: currency),
          InvoiceMoneyRow(label: l.discount, value: invoice.discount, currency: currency),
          InvoiceMoneyRow(label: l.taxableAmount, value: invoice.taxableAmount, currency: currency),
          InvoiceMoneyRow(label: l.tax, value: invoice.taxAmount, currency: currency, hint: taxHint),
          InvoiceMoneyRow(label: l.invoiceGrandTotal, value: invoice.total, currency: currency, emphasis: true, highlight: true),
          InvoiceMoneyRow(label: l.paid, value: invoice.amountPaid, currency: currency, valueColor: context.hs.success),
          InvoiceMoneyRow(label: l.remainingAmount, value: invoice.amountDue, currency: currency, valueColor: context.hs.danger),
          if (invoice.amountCredited != '0.00') InvoiceMoneyRow(label: l.amountCredited, value: invoice.amountCredited, currency: currency),
          if (invoice.amountDebited != '0.00') InvoiceMoneyRow(label: l.amountDebited, value: invoice.amountDebited, currency: currency),
        ],
      ),
    );
  }
}

class PaymentStatusCard extends StatelessWidget {
  const PaymentStatusCard({
    super.key,
    required this.invoice,
    this.onRecordPayment,
    this.onOpenPayment,
    this.onReversePayment,
  });

  final InvoiceRecord invoice;
  final VoidCallback? onRecordPayment;
  final ValueChanged<PaymentRecord>? onOpenPayment;
  final ValueChanged<PaymentRecord>? onReversePayment;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final currency = invoiceCurrency(invoice);
    final methods = invoice.payments.map((row) => row.method).whereType<String>().where((row) => row.trim().isNotEmpty).toSet();
    return InvoiceSectionCard(
      title: l.paymentStatus,
      icon: Icons.account_balance_wallet_outlined,
      tone: FinanceIconTone.teal,
      trailing: onRecordPayment == null
          ? null
          : IconButton(
              tooltip: l.recordPayment,
              onPressed: onRecordPayment,
              icon: const Icon(Icons.add_card_outlined, size: 16),
            ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          InvoiceMoneyRow(label: l.invoiceTotalAmount, value: invoice.total, currency: currency),
          InvoiceMoneyRow(label: l.paid, value: invoice.amountPaid, currency: currency, valueColor: context.hs.success),
          InvoiceMoneyRow(
            label: l.remainingAmount,
            value: invoice.amountDue,
            currency: currency,
            valueColor: context.hs.danger,
            emphasis: true,
          ),
          InvoiceMetaRow(
            label: l.paymentMethod,
            value: methods.isEmpty ? '—' : methods.map((method) => paymentMethodLabel(method, l)).join(' · '),
          ),
          if (invoice.payments.isNotEmpty) ...[
            const SizedBox(height: 4),
            Text(l.payments, style: Theme.of(context).textTheme.bodySmall?.copyWith(fontWeight: FontWeight.w800)),
            const SizedBox(height: 6),
            for (final payment in invoice.payments)
              ListTile(
                dense: true,
                contentPadding: EdgeInsets.zero,
                title: Text('${payment.amount} $currency · ${paymentMethodLabel(payment.method, l)}'),
                subtitle: Text([
                  payment.reference,
                  payment.receiptNumber,
                  payment.paymentDate,
                  payment.status,
                ].whereType<String>().where((row) => row.trim().isNotEmpty).join(' · ')),
                trailing: payment.status == 'posted' && onReversePayment != null
                    ? TextButton(onPressed: () => onReversePayment!(payment), child: Text(l.reversePayment))
                    : StatusChip(label: payment.status ?? '', tone: toneFor(payment.status)),
                onTap: onOpenPayment == null ? null : () => onOpenPayment!(payment),
              ),
          ],
          Text(l.neverMarkPaidLocally, style: Theme.of(context).textTheme.bodySmall?.copyWith(color: context.hs.textFaint, fontSize: 11)),
        ],
      ),
    );
  }
}

class NotesTermsCard extends StatelessWidget {
  const NotesTermsCard({super.key, required this.invoice});

  final InvoiceRecord invoice;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final notes = invoice.notes?.trim() ?? '';
    final terms = invoice.paymentTerms?.trim() ?? '';
    return InvoiceSectionCard(
      title: l.notesAndTerms,
      icon: Icons.notes_rounded,
      tone: FinanceIconTone.teal,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(l.notesField, style: Theme.of(context).textTheme.bodySmall?.copyWith(fontWeight: FontWeight.w800)),
          const SizedBox(height: 4),
          Text(
            notes.isEmpty ? l.noNotes : notes,
            style: Theme.of(context).textTheme.bodySmall?.copyWith(color: notes.isEmpty ? context.hs.textFaint : context.hs.text, height: 1.45),
          ),
          const SizedBox(height: 12),
          Text(l.termsAndConditions, style: Theme.of(context).textTheme.bodySmall?.copyWith(fontWeight: FontWeight.w800)),
          const SizedBox(height: 4),
          Text(
            terms.isEmpty ? l.noTerms : terms,
            style: Theme.of(context).textTheme.bodySmall?.copyWith(color: terms.isEmpty ? context.hs.textFaint : context.hs.text, height: 1.45),
          ),
        ],
      ),
    );
  }
}

class AttachmentsCard extends StatelessWidget {
  const AttachmentsCard({
    super.key,
    required this.invoice,
    this.onUpload,
    this.onDownload,
    this.onDelete,
  });

  final InvoiceRecord invoice;
  final VoidCallback? onUpload;
  final ValueChanged<Map<String, dynamic>>? onDownload;
  final ValueChanged<Map<String, dynamic>>? onDelete;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    return InvoiceSectionCard(
      title: '${l.attachmentsTitle} (${invoice.attachments.length})',
      icon: Icons.attach_file_rounded,
      tone: FinanceIconTone.teal,
      child: invoice.attachments.isEmpty
          ? Container(
              padding: const EdgeInsets.symmetric(vertical: 18, horizontal: 12),
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: context.hs.border, style: BorderStyle.solid),
              ),
              child: Column(
                children: [
                  Icon(Icons.cloud_upload_outlined, color: context.hs.textFaint, size: 28),
                  const SizedBox(height: 8),
                  Text(l.noAttachments, style: Theme.of(context).textTheme.bodySmall?.copyWith(fontWeight: FontWeight.w800)),
                  const SizedBox(height: 4),
                  Text(l.uploadInvoiceAttachmentsHint, textAlign: TextAlign.center, style: Theme.of(context).textTheme.bodySmall?.copyWith(color: context.hs.textFaint)),
                  if (onUpload != null) ...[
                    const SizedBox(height: 10),
                    OutlinedButton.icon(onPressed: onUpload, icon: const Icon(Icons.upload_file_outlined, size: 16), label: Text(l.attachment)),
                  ],
                ],
              ),
            )
          : Column(
              children: [
                for (final row in invoice.attachments)
                  ListTile(
                    dense: true,
                    contentPadding: EdgeInsets.zero,
                    leading: const Icon(Icons.insert_drive_file_outlined, size: 18),
                    title: Text('${row['file_name'] ?? l.attachment}'),
                    subtitle: Text([
                      row['file_type'] ?? row['mime_type'] ?? row['content_type'],
                      row['created_at'] ?? row['uploaded_at'] ?? row['date'],
                    ].where((value) => value != null && value.toString().trim().isNotEmpty).join(' · ')),
                    trailing: Wrap(
                      spacing: 4,
                      children: [
                        if (onDownload != null)
                          IconButton(tooltip: l.download, icon: const Icon(Icons.download_outlined, size: 18), onPressed: () => onDownload!(row)),
                        if (onDelete != null)
                          IconButton(tooltip: l.delete, icon: const Icon(Icons.delete_outline, size: 18), onPressed: () => onDelete!(row)),
                      ],
                    ),
                  ),
                if (onUpload != null)
                  Align(
                    alignment: AlignmentDirectional.centerStart,
                    child: OutlinedButton.icon(onPressed: onUpload, icon: const Icon(Icons.upload_file_outlined, size: 16), label: Text(l.attachment)),
                  ),
              ],
            ),
    );
  }
}

class AuditLogCard extends StatelessWidget {
  const AuditLogCard({super.key, required this.invoice, this.initiallyExpanded = false});

  final InvoiceRecord invoice;
  final bool initiallyExpanded;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    if (invoice.audit.isEmpty) return const SizedBox.shrink();
    final theme = Theme.of(context);
    return Material(
      color: context.hs.card,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(FinanceTokens.radiusLg),
        side: BorderSide(color: context.hs.border),
      ),
      child: Theme(
        data: theme.copyWith(dividerColor: Colors.transparent),
        child: ExpansionTile(
          initiallyExpanded: initiallyExpanded,
          tilePadding: const EdgeInsets.symmetric(horizontal: 16),
          childrenPadding: const EdgeInsets.fromLTRB(8, 0, 8, 12),
          leading: FinanceIconBadge(icon: Icons.history_rounded, tone: FinanceIconTone.teal, size: 32),
          title: Text(l.auditTrail, style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w800)),
          children: [
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: ConstrainedBox(
                constraints: const BoxConstraints(minWidth: 720),
                child: DataTable(
                  headingRowHeight: 36,
                  dataRowMinHeight: 36,
                  dataRowMaxHeight: 44,
                  headingRowColor: WidgetStatePropertyAll(context.hs.surface),
                  columns: [
                    DataColumn(label: Text(l.lineNumber)),
                    DataColumn(label: Text(l.auditEvent)),
                    DataColumn(label: Text(l.auditDescription)),
                    DataColumn(label: Text(l.auditUser)),
                    DataColumn(label: Text(l.auditDate)),
                  ],
                  rows: [
                    for (var i = 0; i < invoice.audit.length; i++)
                      DataRow(
                        cells: [
                          DataCell(Text('${i + 1}')),
                          DataCell(Text('${invoice.audit[i]['action'] ?? ''}')),
                          DataCell(Text(auditActionLabel(invoice.audit[i]['action']?.toString(), l))),
                          DataCell(Text('${invoice.audit[i]['actor_name'] ?? '—'}')),
                          DataCell(Text('${invoice.audit[i]['created_at'] ?? ''}')),
                        ],
                      ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class JournalEntriesCard extends StatelessWidget {
  const JournalEntriesCard({super.key, required this.invoice});

  final InvoiceRecord invoice;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    if (invoice.journalEntries.isEmpty) return const SizedBox.shrink();
    return InvoiceSectionCard(
      title: l.journalEntries,
      icon: Icons.menu_book_outlined,
      tone: FinanceIconTone.indigo,
      child: Column(
        children: [
          for (final entry in invoice.journalEntries)
            ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              title: Text('${entry['entry_number'] ?? ''} · ${entry['type'] ?? ''}'),
              subtitle: Text('${entry['description'] ?? ''} · ${entry['status'] ?? ''}'),
              trailing: Text(_journalTotals(entry), style: const TextStyle(fontWeight: FontWeight.w800)),
            ),
        ],
      ),
    );
  }

  String _journalTotals(Map<String, dynamic> entry) {
    final lines = entry['lines'];
    if (lines is! List) return '';
    var debit = 0.0;
    var credit = 0.0;
    for (final line in lines) {
      if (line is! Map) continue;
      debit += double.tryParse('${line['debit'] ?? 0}') ?? 0;
      credit += double.tryParse('${line['credit'] ?? 0}') ?? 0;
    }
    return '${debit.toStringAsFixed(2)} / ${credit.toStringAsFixed(2)}';
  }
}

class InvoiceTwoColumn extends StatelessWidget {
  const InvoiceTwoColumn({super.key, required this.leading, required this.trailing, this.breakpoint = 1100});

  final Widget leading;
  final Widget trailing;
  final double breakpoint;

  @override
  Widget build(BuildContext context) {
    if (MediaQuery.sizeOf(context).width < breakpoint) {
      return Column(
        children: [
          leading,
          const SizedBox(height: 12),
          trailing,
        ],
      );
    }
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(child: leading),
        const SizedBox(width: 12),
        Expanded(child: trailing),
      ],
    );
  }
}
