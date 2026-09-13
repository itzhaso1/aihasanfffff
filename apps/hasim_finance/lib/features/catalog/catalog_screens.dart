import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim_finance/core/auth/auth_controller.dart';
import 'package:hasim_finance/core/layout/finance_layout.dart';
import 'package:hasim_finance/core/models/models.dart';
import 'package:hasim_finance/core/network/api_exception.dart';
import 'package:hasim_finance/core/providers/catalog_provider.dart';
import 'package:hasim_finance/features/shared/customer_select.dart';
import 'package:hasim_finance/features/shared/document_lines_editor.dart';
import 'package:hasim_finance/features/shared/paged.dart';
import 'package:hasim_finance/features/shared/supplier_select.dart';
import 'package:hasim_finance/l10n/app_localizations.dart';

class ProductsScreen extends ConsumerWidget {
  const ProductsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l = AppLocalizations.of(context);
    return PagedListScreen<ProductRecord>(
      title: l.products,
      allowed: ref.watch(authControllerProvider).permissions.financeView,
      loader: (api, search, page) => api.products(search: search, page: page),
      itemBuilder: (context, product) => Card(
        child: ListTile(
          title: Text(product.name),
          subtitle: Text('${product.sku ?? ''} · ${product.categoryName ?? ''} · ${l.stock} ${product.stock ?? 0}'),
          trailing: Text(product.soldTotal ?? product.price),
          onTap: () => context.push('/products/${product.id}'),
        ),
      ),
    );
  }
}

class ProductDetailScreen extends ConsumerStatefulWidget {
  const ProductDetailScreen({super.key, required this.id});
  final int id;
  @override
  ConsumerState<ProductDetailScreen> createState() => _ProductDetailScreenState();
}

class _ProductDetailScreenState extends ConsumerState<ProductDetailScreen> {
  ProductRecord? _data;
  bool _loading = true;
  String? _error;

  Future<void> _load() async {
    try {
      final data = await ref.read(financeApiProvider).product(widget.id);
      if (!mounted) return;
      setState(() {
        _data = data;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loading = false;
      });
    }
  }

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final p = _data;
    return DetailScaffold(
      title: p?.name ?? l.products,
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: p == null
          ? const SizedBox.shrink()
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                InfoRow(label: l.sku, value: p.sku),
                InfoRow(label: l.price, value: p.price),
                InfoRow(label: l.stock, value: '${p.stock ?? 0}'),
                InfoRow(label: l.taxRate, value: p.vatRate),
                InfoRow(label: l.category, value: p.categoryName),
                InfoRow(label: l.status, value: p.status),
                InfoRow(label: l.soldTotal, value: p.soldTotal),
                InfoRow(label: l.description, value: p.description),
              ],
            ),
    );
  }
}

class InventoryScreen extends ConsumerWidget {
  const InventoryScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l = AppLocalizations.of(context);
    return PagedListScreen<InventoryMovementRecord>(
      title: l.inventory,
      allowed: ref.watch(authControllerProvider).permissions.financeView,
      loader: (api, search, page) => api.inventory(search: search, page: page),
      itemBuilder: (context, row) => Card(
        child: ListTile(
          title: Text(row.productName ?? '#${row.productId}'),
          subtitle: Text('${row.type ?? ''} · ${row.createdAt ?? ''}'),
          trailing: Text('${row.quantity ?? ''} → ${row.afterQuantity ?? ''}'),
          onTap: row.productId == null ? null : () => context.push('/products/${row.productId}'),
        ),
      ),
    );
  }
}

class ProjectsScreen extends ConsumerWidget {
  const ProjectsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l = AppLocalizations.of(context);
    final p = ref.watch(authControllerProvider).permissions;
    return PagedListScreen<ProjectRecord>(
      title: l.projects,
      allowed: p.financeView,
      onCreate: p.financeManage ? () => context.push('/projects/new') : null,
      loader: (api, search, page) => api.projects(search: search, page: page),
      itemBuilder: (context, project) => Card(
        child: ListTile(
          title: Text(project.name),
          subtitle: Text('${project.customerName ?? ''} · ${project.status ?? ''}'),
          trailing: Text(project.profit),
          onTap: () => context.push('/projects/${project.id}'),
        ),
      ),
    );
  }
}

class ProjectDetailScreen extends ConsumerStatefulWidget {
  const ProjectDetailScreen({super.key, required this.id});
  final int id;
  @override
  ConsumerState<ProjectDetailScreen> createState() => _ProjectDetailScreenState();
}

class _ProjectDetailScreenState extends ConsumerState<ProjectDetailScreen> {
  ProjectRecord? _data;
  bool _loading = true;
  String? _error;

  Future<void> _load() async {
    try {
      final data = await ref.read(financeApiProvider).project(widget.id);
      if (!mounted) return;
      setState(() {
        _data = data;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loading = false;
      });
    }
  }

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final p = _data;
    return DetailScaffold(
      title: p?.name ?? l.projects,
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: p == null
          ? const SizedBox.shrink()
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                MetricGrid(metrics: [
                  (l.budget, p.budget),
                  (l.revenue, p.revenue),
                  (l.costs, p.costs),
                  (l.profit, p.profit),
                ]),
                InfoRow(label: l.customer, value: p.customerName),
                InfoRow(label: l.status, value: p.status),
                InfoRow(label: l.from, value: p.startsOn),
                InfoRow(label: l.to, value: p.endsOn),
                InfoRow(label: l.notesField, value: p.notes),
              ],
            ),
    );
  }
}

class ProjectFormScreen extends ConsumerStatefulWidget {
  const ProjectFormScreen({super.key});
  @override
  ConsumerState<ProjectFormScreen> createState() => _ProjectFormScreenState();
}

class _ProjectFormScreenState extends ConsumerState<ProjectFormScreen> {
  final _name = TextEditingController();
  final _budget = TextEditingController();
  final _notes = TextEditingController();
  int? _customerId;
  bool _busy = false;

  @override
  void dispose() {
    _name.dispose();
    _budget.dispose();
    _notes.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    return FinanceScaffold(
        title: l.projects,
        showBack: true,
        body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          FormSection(
            title: l.headerSection,
            child: FormGrid(children: [
              TextField(controller: _name, decoration: InputDecoration(labelText: l.fieldName)),
              TextField(controller: _budget, decoration: InputDecoration(labelText: l.budget)),
            ]),
          ),
          CustomerSelectField(selectedId: _customerId, onSelected: (id) => setState(() => _customerId = id)),
          TextField(controller: _notes, decoration: InputDecoration(labelText: l.notesField), maxLines: 3),
          FilledButton(
            onPressed: _busy
                ? null
                : () async {
                    setState(() => _busy = true);
                    try {
                      final saved = await ref.read(financeApiProvider).saveProject({
                        'name': _name.text.trim(),
                        'budget': _budget.text.trim(),
                        'notes': _notes.text.trim(),
                        if (_customerId != null) 'customer_id': _customerId,
                      });
                      if (context.mounted) context.go('/projects/${saved.id}');
                    } catch (e) {
                      if (context.mounted) showFormError(context, e);
                    } finally {
                      if (mounted) setState(() => _busy = false);
                    }
                  },
            child: Text(l.save),
          ),
        ],
      ),
    );
  }
}

class PriceListsScreen extends ConsumerWidget {
  const PriceListsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l = AppLocalizations.of(context);
    final p = ref.watch(authControllerProvider).permissions;
    return PagedListScreen<PriceListRecord>(
      title: l.priceLists,
      allowed: p.priceListsView || p.financeView,
      onCreate: p.priceListsManage ? () => context.push('/price-lists/new') : null,
      loader: (api, search, page) => api.priceLists(search: search, page: page),
      itemBuilder: (context, list) => Card(
        child: ListTile(
          title: Text(list.name),
          subtitle: Text('${list.code ?? ''} · ${list.currency} · ${list.status}'),
          trailing: Text('${list.itemsCount}'),
          onTap: () => context.push('/price-lists/${list.id}'),
        ),
      ),
    );
  }
}

class PriceListFormScreen extends ConsumerStatefulWidget {
  const PriceListFormScreen({super.key});
  @override
  ConsumerState<PriceListFormScreen> createState() => _PriceListFormScreenState();
}

class _PriceListFormScreenState extends ConsumerState<PriceListFormScreen> {
  final _name = TextEditingController();
  final _code = TextEditingController();
  final _currency = TextEditingController(text: 'SAR');
  bool _busy = false;

  @override
  void dispose() {
    _name.dispose();
    _code.dispose();
    _currency.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    return FinanceScaffold(
        title: l.priceLists,
        showBack: true,
        body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          FormSection(
            title: l.headerSection,
            child: FormGrid(children: [
              TextField(controller: _name, decoration: InputDecoration(labelText: l.fieldName)),
              TextField(controller: _code, decoration: InputDecoration(labelText: l.sku)),
              TextField(controller: _currency, decoration: InputDecoration(labelText: l.currency)),
            ]),
          ),
          FilledButton(
            onPressed: _busy
                ? null
                : () async {
                    setState(() => _busy = true);
                    try {
                      final saved = await ref.read(financeApiProvider).savePriceList({
                        'name': _name.text.trim(),
                        'code': _code.text.trim(),
                        'currency': _currency.text.trim(),
                      });
                      if (context.mounted) context.go('/price-lists/${saved.id}');
                    } catch (e) {
                      if (context.mounted) showFormError(context, e);
                    } finally {
                      if (mounted) setState(() => _busy = false);
                    }
                  },
            child: Text(l.save),
          ),
        ],
      ),
    );
  }
}

class PriceListDetailScreen extends ConsumerStatefulWidget {
  const PriceListDetailScreen({super.key, required this.id});
  final int id;
  @override
  ConsumerState<PriceListDetailScreen> createState() => _PriceListDetailScreenState();
}

class _PriceListDetailScreenState extends ConsumerState<PriceListDetailScreen> {
  PriceListRecord? _data;
  bool _loading = true;
  String? _error;
  final _itemName = TextEditingController();
  final _itemPrice = TextEditingController();
  final _itemTax = TextEditingController(text: '15');

  Future<void> _load() async {
    try {
      final data = await ref.read(financeApiProvider).priceList(widget.id);
      if (!mounted) return;
      setState(() {
        _data = data;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loading = false;
      });
    }
  }

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _itemName.dispose();
    _itemPrice.dispose();
    _itemTax.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final list = _data;
    final manage = ref.watch(authControllerProvider).permissions.priceListsManage;
    return DetailScaffold(
      title: list?.name ?? l.priceLists,
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: list == null
          ? const SizedBox.shrink()
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                InfoRow(label: l.status, value: list.status),
                InfoRow(label: l.currency, value: list.currency),
                for (final item in list.items)
                  ListTile(
                    title: Text(item.productName ?? ''),
                    subtitle: Text('${item.sku ?? ''} · ${item.minQuantity ?? '1'}'),
                    trailing: Text(item.price),
                  ),
                if (manage) ...[
                  FormSection(
                    title: l.addItem,
                    child: FormGrid(children: [
                      TextField(controller: _itemName, decoration: InputDecoration(labelText: l.fieldName)),
                      TextField(controller: _itemPrice, decoration: InputDecoration(labelText: l.price)),
                      TextField(controller: _itemTax, decoration: InputDecoration(labelText: l.taxRate)),
                    ]),
                  ),
                  FilledButton.tonal(
                    onPressed: () async {
                      await ref.read(financeApiProvider).addPriceListItem(list.id, {
                        'product_name': _itemName.text.trim(),
                        'price': _itemPrice.text.trim(),
                        'tax_rate': _itemTax.text.trim(),
                      });
                      await _load();
                    },
                    child: Text(l.addItem),
                  ),
                  Wrap(spacing: 8, children: [
                    FilledButton(
                      onPressed: () async {
                        await ref.read(financeApiProvider).priceListAction(list.id, 'approve');
                        await _load();
                      },
                      child: Text(l.approve),
                    ),
                    OutlinedButton(
                      onPressed: () async {
                        await ref.read(financeApiProvider).priceListAction(list.id, 'mark-draft');
                        await _load();
                      },
                      child: Text(l.markDraft),
                    ),
                    OutlinedButton(
                      onPressed: () async {
                        await ref.read(financeApiProvider).priceListAction(list.id, 'cancel');
                        await _load();
                      },
                      child: Text(l.cancel),
                    ),
                  ]),
                ],
              ],
            ),
    );
  }
}

class PurchaseOrdersScreen extends ConsumerWidget {
  const PurchaseOrdersScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l = AppLocalizations.of(context);
    final p = ref.watch(authControllerProvider).permissions;
    return PagedListScreen<PurchaseOrderRecord>(
      title: l.purchaseOrders,
      allowed: p.purchasesView || p.financeView,
      onCreate: p.financeManage || p.purchasesManage ? () => context.push('/purchase-orders/new') : null,
      loader: (api, search, page) => api.purchaseOrders(search: search, page: page),
      itemBuilder: (context, order) => Card(
        child: ListTile(
          title: Text(order.poNumber ?? '#${order.id}'),
          subtitle: Text('${order.supplierName ?? ''} · ${order.status}'),
          trailing: Text(order.total),
          onTap: () => context.push('/purchase-orders/${order.id}'),
        ),
      ),
    );
  }
}

class PurchaseOrderDetailScreen extends ConsumerStatefulWidget {
  const PurchaseOrderDetailScreen({super.key, required this.id});
  final int id;
  @override
  ConsumerState<PurchaseOrderDetailScreen> createState() => _PurchaseOrderDetailScreenState();
}

class _PurchaseOrderDetailScreenState extends ConsumerState<PurchaseOrderDetailScreen> {
  PurchaseOrderRecord? _data;
  bool _loading = true;
  String? _error;

  Future<void> _load() async {
    try {
      final data = await ref.read(financeApiProvider).purchaseOrder(widget.id);
      if (!mounted) return;
      setState(() {
        _data = data;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loading = false;
      });
    }
  }

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final order = _data;
    final manage = ref.watch(authControllerProvider).permissions.financeManage ||
        ref.watch(authControllerProvider).permissions.purchasesManage;
    return DetailScaffold(
      title: order?.poNumber ?? l.purchaseOrders,
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: order == null
          ? const SizedBox.shrink()
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                DocumentHeader(number: order.poNumber ?? '#${order.id}', documentStatus: order.status, customer: order.supplierName),
                TotalsCard(subtotal: order.subtotal, tax: order.taxAmount, total: order.total),
                LineTable(lines: order.items),
                Wrap(spacing: 8, children: [
                  if (manage && order.status == 'draft')
                    FilledButton(
                      onPressed: () async {
                        await ref.read(financeApiProvider).purchaseOrderAction(order.id, 'submit');
                        await _load();
                      },
                      child: Text(l.submitPo),
                    ),
                  if (manage)
                    FilledButton.tonal(
                      onPressed: () async {
                        await ref.read(financeApiProvider).purchaseOrderAction(order.id, 'receive');
                        await _load();
                      },
                      child: Text(l.receivePo),
                    ),
                  if (manage)
                    FilledButton(
                      onPressed: () async {
                        await ref.read(financeApiProvider).purchaseOrderAction(order.id, 'bill');
                        await _load();
                      },
                      child: Text(l.billPo),
                    ),
                  if (order.invoiceId != null)
                    TextButton(onPressed: () => context.push('/purchases/${order.invoiceId}'), child: Text(l.purchases)),
                ]),
              ],
            ),
    );
  }
}

class PurchaseOrderFormScreen extends ConsumerStatefulWidget {
  const PurchaseOrderFormScreen({super.key});
  @override
  ConsumerState<PurchaseOrderFormScreen> createState() => _PurchaseOrderFormScreenState();
}

class _PurchaseOrderFormScreenState extends ConsumerState<PurchaseOrderFormScreen> {
  int? _supplierId;
  final _orderDate = TextEditingController(text: isoDate());
  final _expected = TextEditingController();
  final _notes = TextEditingController();
  final List<LineDraft> _lines = [LineDraft(description: 'بند شراء', unitPrice: '50')];
  bool _busy = false;

  @override
  void dispose() {
    _orderDate.dispose();
    _expected.dispose();
    _notes.dispose();
    for (final line in _lines) {
      line.dispose();
    }
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final catalog = ref.watch(financeCatalogProvider).valueOrNull ?? const FinanceCatalog();
    return FinanceScaffold(
        title: l.purchaseOrders,
        showBack: true,
        body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          SupplierSelectField(selectedId: _supplierId, onSelected: (id) => setState(() => _supplierId = id)),
          FormGrid(children: [
            TextField(controller: _orderDate, decoration: InputDecoration(labelText: l.orderDate)),
            TextField(controller: _expected, decoration: InputDecoration(labelText: l.expectedDate)),
          ]),
          DocumentLinesEditor(lines: _lines, products: catalog.products, onChanged: () => setState(() {})),
          TextField(controller: _notes, decoration: InputDecoration(labelText: l.notesField)),
          FilledButton(
            onPressed: _busy || _supplierId == null
                ? null
                : () async {
                    setState(() => _busy = true);
                    try {
                      final saved = await ref.read(financeApiProvider).savePurchaseOrder({
                        'supplier_id': _supplierId,
                        'order_date': _orderDate.text.trim(),
                        'expected_date': _expected.text.trim(),
                        'notes': _notes.text.trim(),
                        'items': _lines.map((line) => line.toPayload()).toList(),
                      });
                      if (context.mounted) context.go('/purchase-orders/${saved.id}');
                    } catch (e) {
                      if (context.mounted) showFormError(context, e);
                    } finally {
                      if (mounted) setState(() => _busy = false);
                    }
                  },
            child: Text(l.save),
          ),
        ],
      ),
    );
  }
}

class LeadsScreen extends ConsumerWidget {
  const LeadsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l = AppLocalizations.of(context);
    final p = ref.watch(authControllerProvider).permissions;
    return PagedListScreen<LeadRecord>(
      title: l.leads,
      allowed: p.financeView,
      onCreate: p.financeManage ? () => context.push('/leads/new') : null,
      loader: (api, search, page) => api.leads(search: search, page: page),
      itemBuilder: (context, lead) => Card(
        child: ListTile(
          title: Text(lead.name),
          subtitle: Text('${lead.companyName ?? ''} · ${lead.status ?? ''} · ${lead.source ?? ''}'),
          trailing: Text(lead.estimatedValue),
          onTap: () => context.push('/leads/${lead.id}'),
        ),
      ),
    );
  }
}

class LeadDetailScreen extends ConsumerStatefulWidget {
  const LeadDetailScreen({super.key, required this.id});
  final int id;
  @override
  ConsumerState<LeadDetailScreen> createState() => _LeadDetailScreenState();
}

class _LeadDetailScreenState extends ConsumerState<LeadDetailScreen> {
  LeadRecord? _data;
  bool _loading = true;
  String? _error;

  Future<void> _load() async {
    try {
      final data = await ref.read(financeApiProvider).lead(widget.id);
      if (!mounted) return;
      setState(() {
        _data = data;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loading = false;
      });
    }
  }

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final lead = _data;
    final manage = ref.watch(authControllerProvider).permissions.financeManage;
    return DetailScaffold(
      title: lead?.name ?? l.leads,
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: lead == null
          ? const SizedBox.shrink()
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                InfoRow(label: l.company, value: lead.companyName),
                InfoRow(label: l.email, value: lead.email),
                InfoRow(label: l.phone, value: lead.phone),
                InfoRow(label: l.source, value: lead.source),
                InfoRow(label: l.status, value: lead.status),
                InfoRow(label: l.estimatedValue, value: lead.estimatedValue),
                InfoRow(label: l.notesField, value: lead.notes),
                Wrap(spacing: 8, children: [
                  if (manage && lead.customerId == null)
                    FilledButton(
                      onPressed: () async {
                        final result = await ref.read(financeApiProvider).leadAction(lead.id, 'convert');
                        final customer = result['customer'];
                        if (customer is Map && context.mounted) {
                          context.go('/customers/${customer['id']}');
                        }
                      },
                      child: Text(l.convertLead),
                    ),
                  if (manage)
                    OutlinedButton(
                      onPressed: () async {
                        await ref.read(financeApiProvider).leadAction(lead.id, 'lost', body: {'reason': 'lost'});
                        await _load();
                      },
                      child: Text(l.markLost),
                    ),
                ]),
              ],
            ),
    );
  }
}

class LeadFormScreen extends ConsumerStatefulWidget {
  const LeadFormScreen({super.key});
  @override
  ConsumerState<LeadFormScreen> createState() => _LeadFormScreenState();
}

class _LeadFormScreenState extends ConsumerState<LeadFormScreen> {
  final _name = TextEditingController();
  final _company = TextEditingController();
  final _email = TextEditingController();
  final _phone = TextEditingController();
  final _source = TextEditingController();
  final _value = TextEditingController();
  bool _busy = false;

  @override
  void dispose() {
    _name.dispose();
    _company.dispose();
    _email.dispose();
    _phone.dispose();
    _source.dispose();
    _value.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    return FinanceScaffold(
        title: l.leads,
        showBack: true,
        body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          FormSection(
            title: l.headerSection,
            child: FormGrid(children: [
              TextField(controller: _name, decoration: InputDecoration(labelText: l.fieldName)),
              TextField(controller: _company, decoration: InputDecoration(labelText: l.company)),
              TextField(controller: _email, decoration: InputDecoration(labelText: l.email)),
              TextField(controller: _phone, decoration: InputDecoration(labelText: l.phone)),
              TextField(controller: _source, decoration: InputDecoration(labelText: l.source)),
              TextField(controller: _value, decoration: InputDecoration(labelText: l.estimatedValue)),
            ]),
          ),
          FilledButton(
            onPressed: _busy
                ? null
                : () async {
                    setState(() => _busy = true);
                    try {
                      final saved = await ref.read(financeApiProvider).saveLead({
                        'name': _name.text.trim(),
                        'company_name': _company.text.trim(),
                        'email': _email.text.trim(),
                        'phone': _phone.text.trim(),
                        'source': _source.text.trim(),
                        'estimated_value': _value.text.trim(),
                      });
                      if (context.mounted) context.go('/leads/${saved.id}');
                    } catch (e) {
                      if (context.mounted) showFormError(context, e);
                    } finally {
                      if (mounted) setState(() => _busy = false);
                    }
                  },
            child: Text(l.save),
          ),
        ],
      ),
    );
  }
}

class SuppliersScreen extends ConsumerWidget {
  const SuppliersScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l = AppLocalizations.of(context);
    final p = ref.watch(authControllerProvider).permissions;
    return PagedListScreen<SupplierRecord>(
      title: l.suppliers,
      allowed: p.purchasesView,
      onCreate: p.purchasesManage ? () => context.push('/suppliers/new') : null,
      loader: (api, search, page) => api.suppliers(search: search, page: page),
      itemBuilder: (context, supplier) => Card(
        child: ListTile(
          title: Text(supplier.name),
          subtitle: Text('${supplier.vatNumber ?? ''} · ${supplier.phone ?? ''}'),
          trailing: Text(supplier.openingBalance),
          onTap: () => context.push('/suppliers/${supplier.id}'),
        ),
      ),
    );
  }
}

class SupplierDetailScreen extends ConsumerStatefulWidget {
  const SupplierDetailScreen({super.key, required this.id});
  final int id;
  @override
  ConsumerState<SupplierDetailScreen> createState() => _SupplierDetailScreenState();
}

class _SupplierDetailScreenState extends ConsumerState<SupplierDetailScreen> {
  SupplierRecord? _data;
  bool _loading = true;
  String? _error;

  Future<void> _load() async {
    try {
      final data = await ref.read(financeApiProvider).supplier(widget.id);
      if (!mounted) return;
      setState(() {
        _data = data;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loading = false;
      });
    }
  }

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final s = _data;
    return DetailScaffold(
      title: s?.name ?? l.suppliers,
      loading: _loading,
      error: _error,
      onRetry: _load,
      child: s == null
          ? const SizedBox.shrink()
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                InfoRow(label: l.vatNumber, value: s.vatNumber),
                InfoRow(label: l.crNumber, value: s.commercialRegistration),
                InfoRow(label: l.email, value: s.email),
                InfoRow(label: l.phone, value: s.phone),
                InfoRow(label: l.paymentTerms, value: s.paymentTerms),
                InfoRow(label: l.openingBalance, value: s.openingBalance),
                InfoRow(label: l.status, value: s.status),
                if (ref.watch(authControllerProvider).permissions.purchasesManage)
                  FilledButton(onPressed: () => context.push('/suppliers/${s.id}/edit'), child: Text(l.edit)),
              ],
            ),
    );
  }
}

class SupplierFormScreen extends ConsumerStatefulWidget {
  const SupplierFormScreen({super.key, this.id});
  final int? id;
  @override
  ConsumerState<SupplierFormScreen> createState() => _SupplierFormScreenState();
}

class _SupplierFormScreenState extends ConsumerState<SupplierFormScreen> {
  final _name = TextEditingController();
  final _arabic = TextEditingController();
  final _vat = TextEditingController();
  final _cr = TextEditingController();
  final _email = TextEditingController();
  final _phone = TextEditingController();
  final _address = TextEditingController();
  final _terms = TextEditingController();
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    if (widget.id != null) {
      ref.read(financeApiProvider).supplier(widget.id!).then((s) {
        _name.text = s.name;
        _arabic.text = s.arabicName ?? '';
        _vat.text = s.vatNumber ?? '';
        _cr.text = s.commercialRegistration ?? '';
        _email.text = s.email ?? '';
        _phone.text = s.phone ?? '';
        _address.text = s.address ?? '';
        _terms.text = s.paymentTerms ?? '';
        if (mounted) setState(() {});
      }).catchError((Object error) {
        if (mounted) showApiError(context, error);
      });
    }
  }

  @override
  void dispose() {
    _name.dispose();
    _arabic.dispose();
    _vat.dispose();
    _cr.dispose();
    _email.dispose();
    _phone.dispose();
    _address.dispose();
    _terms.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    return FinanceScaffold(
        title: l.suppliers,
        showBack: true,
        body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          FormSection(
            title: l.headerSection,
            child: FormGrid(children: [
              TextField(controller: _name, decoration: InputDecoration(labelText: l.fieldName)),
              TextField(controller: _arabic, decoration: InputDecoration(labelText: l.companyNameAr)),
              TextField(controller: _vat, decoration: InputDecoration(labelText: l.vatNumber)),
              TextField(controller: _cr, decoration: InputDecoration(labelText: l.crNumber)),
              TextField(controller: _email, decoration: InputDecoration(labelText: l.email)),
              TextField(controller: _phone, decoration: InputDecoration(labelText: l.phone)),
              TextField(controller: _terms, decoration: InputDecoration(labelText: l.paymentTerms)),
            ]),
          ),
          TextField(controller: _address, decoration: InputDecoration(labelText: l.addressLine), maxLines: 2),
          FilledButton(
            onPressed: _busy
                ? null
                : () async {
                    setState(() => _busy = true);
                    try {
                      final saved = await ref.read(financeApiProvider).saveSupplier({
                        'name': _name.text.trim(),
                        'arabic_name': _arabic.text.trim(),
                        'vat_number': _vat.text.trim(),
                        'commercial_registration': _cr.text.trim(),
                        'email': _email.text.trim(),
                        'phone': _phone.text.trim(),
                        'address': _address.text.trim(),
                        'payment_terms': _terms.text.trim(),
                      }, id: widget.id);
                      if (context.mounted) context.go('/suppliers/${saved.id}');
                    } catch (e) {
                      if (context.mounted) showFormError(context, e);
                    } finally {
                      if (mounted) setState(() => _busy = false);
                    }
                  },
            child: Text(l.save),
          ),
        ],
      ),
    );
  }
}
