import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim_finance/core/auth/auth_controller.dart';
import 'package:hasim_finance/core/layout/finance_chrome.dart';
import 'package:hasim_finance/core/models/models.dart';
import 'package:hasim_finance/l10n/app_localizations.dart';

class SupplierSelectField extends ConsumerStatefulWidget {
  const SupplierSelectField({
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

  /// Renders a single dropdown (for filter bars) instead of search + list.
  final bool compact;
  final bool allowClear;
  final String? label;
  final bool floatingLabel;

  @override
  ConsumerState<SupplierSelectField> createState() => _SupplierSelectFieldState();
}

class _SupplierSelectFieldState extends ConsumerState<SupplierSelectField> {
  final _search = TextEditingController();
  Timer? _debounce;
  List<SupplierRecord> _suppliers = [];
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

  List<SupplierRecord> _unique(List<SupplierRecord> items) {
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
      final page = await ref.read(financeApiProvider).suppliers(
            search: _search.text.trim(),
            page: pageNum,
          );
      if (!mounted || requestId != _requestId) return;
      setState(() {
        _suppliers = _unique(reset ? page.items : [..._suppliers, ...page.items]);
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
        value: widget.selectedId != null && _suppliers.any((row) => row.id == widget.selectedId) ? widget.selectedId : null,
        isExpanded: true,
        isDense: true,
        decoration: widget.floatingLabel
            ? InputDecoration(labelText: widget.label ?? l.selectSupplier)
            : FinanceFilterField.decoration(),
        items: [
          if (widget.allowClear) DropdownMenuItem<int?>(value: null, child: Text(l.filterAll)),
          for (final supplier in _suppliers) DropdownMenuItem<int?>(value: supplier.id, child: Text(supplier.name)),
        ],
        onChanged: widget.onSelected,
      );
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          controller: _search,
          decoration: InputDecoration(prefixIcon: const Icon(Icons.search), hintText: l.selectSupplier),
          onChanged: (_) {
            _debounce?.cancel();
            _debounce = Timer(const Duration(milliseconds: 300), () => _load(reset: true));
          },
        ),
        const SizedBox(height: 8),
        SizedBox(
          height: 220,
          child: _suppliers.isEmpty && _loading
              ? const Center(child: CircularProgressIndicator())
              : SingleChildScrollView(
                  child: Column(
                    children: [
                      for (final supplier in _suppliers)
                        ListTile(
                          dense: true,
                          selected: widget.selectedId == supplier.id,
                          title: Text(supplier.name),
                          subtitle: Text([
                            if ((supplier.vatNumber ?? '').isNotEmpty) supplier.vatNumber,
                            if ((supplier.commercialRegistration ?? '').isNotEmpty) supplier.commercialRegistration,
                          ].join(' · ')),
                          onTap: () => widget.onSelected(supplier.id),
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
