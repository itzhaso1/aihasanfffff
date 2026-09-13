import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim_finance/core/layout/finance_layout.dart';
import 'package:hasim_finance/core/auth/auth_controller.dart';
import 'package:hasim_finance/core/models/models.dart';
import 'package:hasim_finance/core/network/api_exception.dart';
import 'package:hasim_finance/core/widgets/widgets.dart';
import 'package:hasim_finance/features/shared/paged.dart';
import 'package:hasim_finance/l10n/app_localizations.dart';

class CustomersScreen extends ConsumerWidget {
  const CustomersScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l = AppLocalizations.of(context);
    final auth = ref.watch(authControllerProvider);
    return PagedListScreen<CustomerRecord>(
      title: l.customers,
      allowed: auth.permissions.customersView,
      onCreate: auth.permissions.customersCreate ? () => context.push('/customers/new') : null,
      loader: (api, search, page) => api.customers(search: search, page: page),
      itemBuilder: (context, customer) => Card(
        child: ListTile(
          title: Text(customer.name),
          subtitle: Text('${customer.partyType ?? ''} · ${customer.email ?? customer.phone ?? ''}'),
          trailing: MoneyText(customer.outstandingBalance, style: const TextStyle(fontWeight: FontWeight.w700)),
          onTap: () => context.push('/customers/${customer.id}'),
        ),
      ),
    );
  }
}

class CustomerDetailScreen extends ConsumerStatefulWidget {
  const CustomerDetailScreen({super.key, required this.id});
  final int id;

  @override
  ConsumerState<CustomerDetailScreen> createState() => _CustomerDetailScreenState();
}

class _CustomerDetailScreenState extends ConsumerState<CustomerDetailScreen> {
  CustomerRecord? _data;
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final data = await ref.read(financeApiProvider).customer(widget.id);
      if (!mounted) return;
      setState(() {
        _data = data;
        _loading = false;
      });
    } on ApiException catch (e) {
      setState(() {
        _error = e.message;
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final customer = _data;
    return DetailScaffold(
      title: customer?.name ?? l.customers,
      loading: _loading,
      error: _error,
      onRetry: _load,
      actions: [
        IconButton(onPressed: () => context.push('/customers/${widget.id}/edit'), tooltip: l.edit, icon: const Icon(Icons.edit)),
      ],
      child: customer == null
          ? const SizedBox.shrink()
          : DefaultTabController(
              length: 6,
              child: Column(
                children: [
                  Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('${l.vatNumber}: ${customer.vatNumber ?? '-'}'),
                        Text('${l.crNumber}: ${customer.commercialRegistration ?? '-'}'),
                        Text('${l.email}: ${customer.email ?? '-'}'),
                        Text('${l.phone}: ${customer.phone ?? '-'}'),
                        if ((customer.whatsapp ?? '').isNotEmpty) Text('${l.whatsapp}: ${customer.whatsapp}'),
                        const SizedBox(height: 8),
                        MoneyText(customer.outstandingBalance),
                      ],
                    ),
                  ),
                  TabBar(isScrollable: true, tabs: [
                    Tab(text: l.overview),
                    Tab(text: l.invoices),
                    Tab(text: l.quotes),
                    Tab(text: l.payments),
                    Tab(text: l.receipts),
                    Tab(text: l.contracts),
                  ]),
                  Expanded(
                    child: TabBarView(children: [
                      ListView(padding: const EdgeInsets.all(16), children: [
                        InfoRow(label: l.street, value: customer.street),
                        InfoRow(label: l.buildingNumber, value: customer.buildingNumber),
                        InfoRow(label: l.district, value: customer.district),
                        InfoRow(label: l.city, value: customer.city),
                        InfoRow(label: l.postalCode, value: customer.postalCode),
                        InfoRow(label: l.country, value: customer.countryCode),
                        InfoRow(label: l.additionalNumber, value: customer.additionalNumber),
                        InfoRow(label: l.paymentTerms, value: customer.paymentTerms),
                        InfoRow(label: l.notesField, value: customer.notes),
                        Text(customer.address ?? ''),
                        TextButton(onPressed: () => context.push('/statements?customer_id=${customer.id}'), child: Text(l.statements)),
                      ]),
                      _miniList(l, customer.invoices.map((i) => ListTile(title: Text(i.invoiceNumber ?? ''), trailing: Text(i.total), onTap: () => context.push('/invoices/${i.id}'))).toList()),
                      _miniList(l, customer.quotes.map((q) => ListTile(title: Text(q.quoteNumber ?? ''), trailing: Text(q.total), onTap: () => context.push('/quotes/${q.id}'))).toList()),
                      _miniList(l, customer.payments.map((p) => ListTile(title: Text(p.invoiceNumber ?? ''), trailing: Text(p.amount), onTap: () => context.push('/payments/${p.id}'))).toList()),
                      _miniList(l, customer.receipts.map((r) => ListTile(title: Text(r.receiptNumber ?? ''), trailing: Text(r.amount), onTap: () => context.push('/receipts/${r.id}'))).toList()),
                      _miniList(l, customer.contracts.map((c) => ListTile(title: Text(c.title ?? c.contractNumber ?? ''), onTap: () => context.push('/contracts/${c.id}'))).toList()),
                    ]),
                  ),
                ],
              ),
            ),
    );
  }

  Widget _miniList(AppLocalizations l, List<Widget> children) {
    if (children.isEmpty) return EmptyState(title: l.empty);
    return ListView(children: children);
  }
}

class CustomerFormScreen extends ConsumerStatefulWidget {
  const CustomerFormScreen({super.key, this.id});
  final int? id;

  @override
  ConsumerState<CustomerFormScreen> createState() => _CustomerFormScreenState();
}

class _CustomerFormScreenState extends ConsumerState<CustomerFormScreen> {
  final _name = TextEditingController();
  final _phone = TextEditingController();
  final _email = TextEditingController();
  final _vat = TextEditingController();
  final _cr = TextEditingController();
  final _address = TextEditingController();
  final _whatsapp = TextEditingController();
  final _street = TextEditingController();
  final _building = TextEditingController();
  final _district = TextEditingController();
  final _city = TextEditingController();
  final _postal = TextEditingController();
  final _country = TextEditingController(text: 'SA');
  final _additional = TextEditingController();
  final _paymentTerms = TextEditingController();
  final _notes = TextEditingController();
  String _type = 'individual';
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    if (widget.id != null) {
      ref.read(financeApiProvider).customer(widget.id!).then((c) {
        _name.text = c.name;
        _phone.text = c.phone ?? '';
        _email.text = c.email ?? '';
        _vat.text = c.vatNumber ?? '';
        _cr.text = c.commercialRegistration ?? '';
        _address.text = c.address ?? '';
        _whatsapp.text = c.whatsapp ?? '';
        _street.text = c.street ?? '';
        _building.text = c.buildingNumber ?? '';
        _district.text = c.district ?? '';
        _city.text = c.city ?? '';
        _postal.text = c.postalCode ?? '';
        _country.text = c.countryCode ?? 'SA';
        _additional.text = c.additionalNumber ?? '';
        _paymentTerms.text = c.paymentTerms ?? '';
        _notes.text = c.notes ?? '';
        _type = c.partyType ?? 'individual';
        if (mounted) setState(() {});
      }).catchError((Object error) {
        if (mounted) showApiError(context, error);
      });
    }
  }

  @override
  void dispose() {
    _name.dispose();
    _phone.dispose();
    _email.dispose();
    _vat.dispose();
    _cr.dispose();
    _address.dispose();
    _whatsapp.dispose();
    _street.dispose();
    _building.dispose();
    _district.dispose();
    _city.dispose();
    _postal.dispose();
    _country.dispose();
    _additional.dispose();
    _paymentTerms.dispose();
    _notes.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    setState(() => _busy = true);
    try {
      final saved = await ref.read(financeApiProvider).saveCustomer({
        'name': _name.text.trim(),
        'phone': _phone.text.trim(),
        'email': _email.text.trim(),
        'vat_number': _vat.text.trim(),
        'commercial_registration': _cr.text.trim(),
        'address': _address.text.trim(),
        'whatsapp': _whatsapp.text.trim(),
        'street': _street.text.trim(),
        'building_number': _building.text.trim(),
        'district': _district.text.trim(),
        'city': _city.text.trim(),
        'postal_code': _postal.text.trim(),
        'country_code': _country.text.trim(),
        'additional_number': _additional.text.trim(),
        'payment_terms': _paymentTerms.text.trim(),
        'notes': _notes.text.trim(),
        'party_type': _type,
      }, id: widget.id);
      if (!mounted) return;
      context.go('/customers/${saved.id}');
    } catch (e) {
      if (mounted) showApiError(context, e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    return FinanceScaffold(
        title: widget.id == null ? l.create : l.edit,
        showBack: true,
        body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          FormSection(
            title: l.headerSection,
            child: FormGrid(children: [
              DropdownButtonFormField(
                isExpanded: true,
                // ignore: deprecated_member_use
                value: _type,
                items: [
                  DropdownMenuItem(value: 'individual', child: Text(l.individual)),
                  DropdownMenuItem(value: 'company', child: Text(l.company)),
                ],
                onChanged: (v) => setState(() => _type = v ?? 'individual'),
              ),
              TextField(controller: _name, decoration: InputDecoration(labelText: l.customer)),
              TextField(controller: _phone, decoration: InputDecoration(labelText: l.phone)),
              TextField(controller: _email, decoration: InputDecoration(labelText: l.email)),
              TextField(controller: _whatsapp, decoration: InputDecoration(labelText: l.whatsapp)),
              TextField(controller: _vat, decoration: InputDecoration(labelText: l.vatNumber)),
              TextField(controller: _cr, decoration: InputDecoration(labelText: l.crNumber)),
              TextField(controller: _paymentTerms, decoration: InputDecoration(labelText: l.paymentTerms)),
            ]),
          ),
          FormSection(
            title: l.datesSection,
            child: FormGrid(children: [
              TextField(controller: _street, decoration: InputDecoration(labelText: l.street)),
              TextField(controller: _building, decoration: InputDecoration(labelText: l.buildingNumber)),
              TextField(controller: _district, decoration: InputDecoration(labelText: l.district)),
              TextField(controller: _city, decoration: InputDecoration(labelText: l.city)),
              TextField(controller: _postal, decoration: InputDecoration(labelText: l.postalCode)),
              TextField(controller: _country, decoration: InputDecoration(labelText: l.country)),
              TextField(controller: _additional, decoration: InputDecoration(labelText: l.additionalNumber)),
            ]),
          ),
          TextField(controller: _address, decoration: InputDecoration(labelText: l.addressLine)),
          TextField(controller: _notes, decoration: InputDecoration(labelText: l.notesField), maxLines: 3),
          const SizedBox(height: 16),
          FilledButton(onPressed: _busy ? null : _save, child: Text(l.save)),
        ],
      ),
    );
  }
}
