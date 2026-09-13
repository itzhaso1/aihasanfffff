import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim_finance/core/auth/auth_controller.dart';
import 'package:hasim_finance/core/layout/finance_chrome.dart';
import 'package:hasim_finance/core/models/models.dart';
import 'package:hasim_finance/l10n/app_localizations.dart';

class CustomerSelectField extends ConsumerStatefulWidget {
  const CustomerSelectField({
    super.key,
    required this.selectedId,
    required this.onSelected,
    this.compact = false,
    this.allowClear = false,
    this.label,
    this.floatingLabel = true,
  });

  final int? selectedId;
  final ValueChanged<int?> onSelected;
  final bool compact;
  final bool allowClear;
  final String? label;
  final bool floatingLabel;

  @override
  ConsumerState<CustomerSelectField> createState() => _CustomerSelectFieldState();
}

class _CustomerSelectFieldState extends ConsumerState<CustomerSelectField> {
  final _search = TextEditingController();
  Timer? _debounce;
  List<CustomerRecord> _customers = [];
  int _page = 0;
  int _lastPage = 1;
  int _requestId = 0;
  bool _loading = false;

  @override
  void initState() {
    super.initState();
    _load(reset: true);
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _search.dispose();
    super.dispose();
  }

  List<CustomerRecord> _unique(List<CustomerRecord> items) {
    final seen = <int>{};
    return [
      for (final row in items)
        if (seen.add(row.id)) row,
    ];
  }

  Future<void> _load({bool reset = false}) async {
    final requestId = ++_requestId;
    final pageNum = reset ? 1 : _page + 1;
    setState(() => _loading = true);
    try {
      final page = await ref.read(financeApiProvider).customers(
            search: _search.text.trim(),
            page: pageNum,
            perPage: widget.compact ? 100 : 25,
          );
      if (!mounted || requestId != _requestId) return;
      var items = reset ? page.items : [..._customers, ...page.items];
      final selectedId = widget.selectedId;
      if (selectedId != null && !items.any((row) => row.id == selectedId)) {
        try {
          final selected = await ref.read(financeApiProvider).customer(selectedId);
          if (!mounted || requestId != _requestId) return;
          items = [selected, ...items.where((row) => row.id != selected.id)];
        } catch (_) {}
      }
      setState(() {
        _customers = _unique(items);
        _page = page.page;
        _lastPage = page.lastPage;
        _loading = false;
      });
    } catch (_) {
      if (mounted && requestId == _requestId) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    if (widget.compact) {
      return DropdownButtonFormField<int?>(
        // ignore: deprecated_member_use
        value: widget.selectedId != null && _customers.any((row) => row.id == widget.selectedId)
            ? widget.selectedId
            : null,
        isExpanded: true,
        isDense: true,
        decoration: widget.floatingLabel
            ? InputDecoration(labelText: widget.label ?? l.selectCustomer)
            : FinanceFilterField.decoration(),
        items: [
          if (widget.allowClear) DropdownMenuItem<int?>(value: null, child: Text(l.filterAll)),
          for (final customer in _customers)
            DropdownMenuItem<int?>(value: customer.id, child: Text(customer.name)),
        ],
        onChanged: widget.onSelected,
      );
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          controller: _search,
          decoration: InputDecoration(prefixIcon: const Icon(Icons.search), hintText: l.selectCustomer),
          onChanged: (_) {
            _debounce?.cancel();
            _debounce = Timer(const Duration(milliseconds: 300), () => _load(reset: true));
          },
        ),
        const SizedBox(height: 8),
        SizedBox(
          height: 220,
          child: _customers.isEmpty && _loading
              ? const Center(child: CircularProgressIndicator())
              : SingleChildScrollView(
                  key: const Key('customer-select-list'),
                  child: Column(
                    children: [
                      for (final customer in _customers)
                        ListTile(
                          dense: true,
                          selected: widget.selectedId == customer.id,
                          title: Text(customer.name),
                          subtitle: Text(customer.outstandingBalance),
                          onTap: () => widget.onSelected(customer.id),
                        ),
                    ],
                  ),
                ),
        ),
        if (_page >= 1 && _page < _lastPage)
          Align(
            alignment: AlignmentDirectional.centerStart,
            child: TextButton(
              onPressed: _loading ? null : () => _load(),
              child: Text(l.loadMore),
            ),
          ),
      ],
    );
  }
}
