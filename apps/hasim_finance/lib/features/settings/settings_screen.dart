import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim_finance/core/config/app_config.dart';
import 'package:hasim_finance/core/auth/auth_controller.dart';
import 'package:hasim_finance/core/layout/finance_layout.dart';
import 'package:hasim_finance/core/network/api_exception.dart';
import 'package:hasim_finance/core/theme/finance_tokens.dart';
import 'package:hasim_finance/core/theme/theme_controllers.dart';
import 'package:hasim_finance/core/utils/files.dart';
import 'package:hasim_finance/core/widgets/widgets.dart';
import 'package:hasim_finance/features/shared/paged.dart';
import 'package:hasim_finance/l10n/app_localizations.dart';

enum _SettingsTab { general, invoices, taxes, address, appearance, system }

class SettingsScreen extends ConsumerStatefulWidget {
  const SettingsScreen({super.key});
  @override
  ConsumerState<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends ConsumerState<SettingsScreen> {
  final _host = TextEditingController();
  final _company = TextEditingController();
  final _companyAr = TextEditingController();
  final _vat = TextEditingController();
  final _cr = TextEditingController();
  final _phone = TextEditingController();
  final _email = TextEditingController();
  final _website = TextEditingController();
  final _address = TextEditingController();
  final _building = TextEditingController();
  final _street = TextEditingController();
  final _district = TextEditingController();
  final _city = TextEditingController();
  final _postal = TextEditingController();
  final _currency = TextEditingController();
  final _vatRate = TextEditingController();
  final _terms = TextEditingController();
  final _prefix = TextEditingController();
  final _color = TextEditingController(text: '#06C2A4');
  final _footer = TextEditingController();
  final _country = TextEditingController(text: 'SA');
  final _taxName = TextEditingController();
  final _taxCode = TextEditingController();
  final _taxPct = TextEditingController(text: '15');
  String _taxType = 'standard';
  bool _taxDefault = false;
  bool _taxActive = true;
  bool _allowManual = false;
  final _treasuryName = TextEditingController();
  final _iban = TextEditingController();
  final _bankName = TextEditingController();
  final _accountNumber = TextEditingController();
  final _openingBal = TextEditingController();
  final _currentBal = TextEditingController();
  String _treasuryType = 'bank';
  int? _linkedAccountId;
  Map<String, dynamic>? _settings;
  Uint8List? _logoBytes;
  bool _logoBusy = false;
  bool _saving = false;
  bool _loading = true;
  String? _error;
  _SettingsTab _tab = _SettingsTab.general;

  @override
  void initState() {
    super.initState();
    _host.text = ref.read(prefsStoreProvider).apiBaseOverride ?? '';
    _reloadSettings();
  }

  Future<void> _reloadSettings() async {
    try {
      final next = await ref.read(financeApiProvider).settings();
      _applySettings(next);
      if (mounted) setState(() { _loading = false; _error = null; });
    } catch (e) {
      if (mounted) {
        setState(() {
          _loading = false;
          _error = e is ApiException ? e.message : e.toString();
        });
      }
    }
  }

  List<Map<String, dynamic>> _maps(String key) {
    final raw = _settings?[key];
    if (raw is! List) return const [];
    return [
      for (final row in raw)
        if (row is Map) Map<String, dynamic>.from(row),
    ];
  }

  void _applySettings(Map<String, dynamic> s) {
    _company.text = '${s['company_name'] ?? ''}';
    _companyAr.text = '${s['company_name_ar'] ?? ''}';
    _vat.text = '${s['vat_number'] ?? ''}';
    _cr.text = '${s['commercial_registration'] ?? ''}';
    _phone.text = '${s['phone'] ?? ''}';
    _email.text = '${s['email'] ?? ''}';
    _website.text = '${s['website'] ?? ''}';
    _address.text = '${s['address_line'] ?? ''}';
    _building.text = '${s['building_number'] ?? ''}';
    _street.text = '${s['street'] ?? ''}';
    _district.text = '${s['district'] ?? ''}';
    _city.text = '${s['city'] ?? ''}';
    _postal.text = '${s['postal_code'] ?? ''}';
    _currency.text = '${s['currency'] ?? 'SAR'}';
    _vatRate.text = '${s['default_vat_rate'] ?? ''}';
    _terms.text = '${s['default_payment_terms'] ?? ''}';
    _prefix.text = '${s['invoice_prefix'] ?? ''}';
    _color.text = '${s['invoice_primary_color'] ?? '#06C2A4'}';
    _footer.text = '${s['invoice_footer_text'] ?? ''}';
    _country.text = '${s['country_code'] ?? 'SA'}';
    _allowManual = s['allow_manual_invoice_numbers'] == true;
    if (mounted) setState(() => _settings = s);
    _loadLogo(s['has_logo'] == true);
  }

  Future<void> _loadLogo(bool hasLogo) async {
    if (!hasLogo) {
      if (mounted) setState(() => _logoBytes = null);
      return;
    }
    try {
      final bytes = await ref.read(financeApiProvider).downloadCompanyLogo();
      if (mounted) setState(() => _logoBytes = bytes);
    } catch (_) {
      if (mounted) setState(() => _logoBytes = null);
    }
  }

  Future<void> _saveSettings() async {
    setState(() => _saving = true);
    try {
      final next = await ref.read(financeApiProvider).updateSettings({
        'company_name': _company.text.trim(),
        'company_name_ar': _companyAr.text.trim(),
        'vat_number': _vat.text.trim(),
        'commercial_registration': _cr.text.trim(),
        'phone': _phone.text.trim(),
        'email': _email.text.trim(),
        'website': _website.text.trim(),
        'address_line': _address.text.trim(),
        'building_number': _building.text.trim(),
        'street': _street.text.trim(),
        'district': _district.text.trim(),
        'city': _city.text.trim(),
        'postal_code': _postal.text.trim(),
        'country_code': _country.text.trim(),
        'currency': _currency.text.trim(),
        'default_vat_rate': _vatRate.text.trim(),
        'default_payment_terms': _terms.text.trim(),
        'invoice_prefix': _prefix.text.trim(),
        'invoice_primary_color': _color.text.trim(),
        'invoice_footer_text': _footer.text.trim(),
        'allow_manual_invoice_numbers': _allowManual,
      });
      _applySettings(next);
      if (mounted) showSnack(context, AppLocalizations.of(context).success);
    } catch (e) {
      if (mounted) showApiError(context, e);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  void _resetForm() {
    final current = _settings;
    if (current == null) return;
    _applySettings(current);
  }

  Future<void> _pickLogo() async {
    final result = await FilePicker.platform.pickFiles(type: FileType.image);
    if (result == null || result.files.isEmpty) return;
    final part = await multipartFromPicked(result.files.first);
    if (part == null) return;
    setState(() => _logoBusy = true);
    try {
      final form = FormData();
      form.files.add(MapEntry('logo', part));
      final next = await ref.read(financeApiProvider).uploadCompanyLogo(form);
      _applySettings(next);
      if (mounted) showSnack(context, AppLocalizations.of(context).success);
    } catch (e) {
      if (mounted) showApiError(context, e);
    } finally {
      if (mounted) setState(() => _logoBusy = false);
    }
  }

  @override
  void dispose() {
    _host.dispose();
    _company.dispose();
    _companyAr.dispose();
    _vat.dispose();
    _cr.dispose();
    _phone.dispose();
    _email.dispose();
    _website.dispose();
    _address.dispose();
    _building.dispose();
    _street.dispose();
    _district.dispose();
    _city.dispose();
    _postal.dispose();
    _currency.dispose();
    _vatRate.dispose();
    _terms.dispose();
    _prefix.dispose();
    _color.dispose();
    _footer.dispose();
    _country.dispose();
    _taxName.dispose();
    _taxCode.dispose();
    _taxPct.dispose();
    _treasuryName.dispose();
    _iban.dispose();
    _bankName.dispose();
    _accountNumber.dispose();
    _openingBal.dispose();
    _currentBal.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final auth = ref.watch(authControllerProvider);
    return FinanceScaffold(
      title: l.settings,
      subtitle: l.settingsManageSubtitle,
      showBack: true,
      body: FinancePage(
        child: AsyncBody(
          loading: _loading,
          error: _error,
          onRetry: _reloadSettings,
          child: LayoutBuilder(
            builder: (context, constraints) {
              final useRail = constraints.maxWidth >= 720;
              final compactRail = constraints.maxWidth < 980;
              final rtl = Directionality.of(context) == TextDirection.rtl;
              final nav = _SettingsRail(
                selected: _tab,
                compact: !useRail,
                narrow: compactRail,
                onSelect: (tab) => setState(() => _tab = tab),
              );
              final content = _buildSection(context, l, auth);
              final pane = Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Expanded(child: content),
                  if (auth.permissions.settings) _buildFooter(l),
                ],
              );
              Widget layout;
              if (!useRail) {
                layout = Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    nav,
                    const SizedBox(height: 12),
                    Expanded(child: pane),
                  ],
                );
              } else {
                final children = rtl
                    ? [Expanded(child: pane), const SizedBox(width: 16), nav]
                    : [nav, const SizedBox(width: 16), Expanded(child: pane)];
                layout = Row(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: children,
                );
              }
              final height = constraints.hasBoundedHeight
                  ? constraints.maxHeight
                  : MediaQuery.sizeOf(context).height;
              return SizedBox(width: constraints.maxWidth, height: height, child: layout);
            },
          ),
        ),
      ),
    );
  }

  Widget _buildFooter(AppLocalizations l) {
    final p = context.hs;
    return Container(
      padding: const EdgeInsets.fromLTRB(4, 12, 4, 4),
      decoration: BoxDecoration(
        border: Border(top: BorderSide(color: p.border)),
      ),
      child: Wrap(
        alignment: WrapAlignment.end,
        spacing: 8,
        runSpacing: 8,
        children: [
          OutlinedButton(
            onPressed: _saving ? null : _resetForm,
            child: Text(l.resetForm),
          ),
          FilledButton(
            onPressed: _saving ? null : _saveSettings,
            child: Text(l.saveChanges),
          ),
        ],
      ),
    );
  }

  Widget _buildSection(BuildContext context, AppLocalizations l, AuthState auth) {
    final canEdit = auth.permissions.settings;
    return ListView(
      padding: const EdgeInsets.only(bottom: 8),
      children: [
        switch (_tab) {
          _SettingsTab.general => _generalSection(l, canEdit),
          _SettingsTab.invoices => _invoicesSection(l),
          _SettingsTab.taxes => _taxesSection(l, canEdit),
          _SettingsTab.address => _addressSection(l),
          _SettingsTab.appearance => _appearanceSection(l),
          _SettingsTab.system => _systemSection(l, auth, canEdit),
        },
      ],
    );
  }

  Widget _generalSection(AppLocalizations l, bool canEdit) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        FormSection(
          title: l.companyProfile,
          subtitle: l.companyProfileHint,
          child: LayoutBuilder(
            builder: (context, constraints) {
              final stacked = constraints.maxWidth < 720;
              final fields = FormGrid(children: [
                TextField(controller: _companyAr, decoration: InputDecoration(labelText: l.companyNameAr)),
                TextField(controller: _company, decoration: InputDecoration(labelText: l.companyNameEn)),
                TextField(controller: _cr, decoration: InputDecoration(labelText: l.crNumber)),
                TextField(controller: _vat, decoration: InputDecoration(labelText: l.vatNumber)),
                TextField(controller: _phone, decoration: InputDecoration(labelText: l.phone)),
                TextField(controller: _email, decoration: InputDecoration(labelText: l.email)),
                TextField(controller: _website, decoration: InputDecoration(labelText: l.website)),
              ]);
              final logo = canEdit ? _logoCard(l) : const SizedBox.shrink();
              if (stacked) {
                return Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [logo, const SizedBox(height: 16), fields],
                );
              }
              return Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  SizedBox(width: 188, child: logo),
                  const SizedBox(width: 16),
                  Expanded(child: fields),
                ],
              );
            },
          ),
        ),
        FormSection(
          title: l.additionalInfo,
          subtitle: l.additionalInfoHint,
          child: TextField(controller: _footer, decoration: InputDecoration(labelText: l.invoiceFooter), maxLines: 3),
        ),
      ],
    );
  }

  Widget _logoCard(AppLocalizations l) {
    final p = context.hs;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(l.companyLogo, style: Theme.of(context).textTheme.labelLarge),
        const SizedBox(height: 8),
        Material(
          color: p.input,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(FinanceTokens.radiusLg),
            side: BorderSide(color: p.borderStrong),
          ),
          child: InkWell(
            onTap: _logoBusy ? null : _pickLogo,
            borderRadius: BorderRadius.circular(FinanceTokens.radiusLg),
            child: SizedBox(
              height: 148,
              child: _logoBusy
                  ? const Center(child: CircularProgressIndicator(strokeWidth: 2))
                  : _logoBytes != null
                      ? Padding(
                          padding: const EdgeInsets.all(12),
                          child: Image.memory(_logoBytes!, fit: BoxFit.contain),
                        )
                      : Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Icon(Icons.cloud_upload_outlined, color: p.brandAccent, size: 28),
                            const SizedBox(height: 8),
                            Text(l.chooseLogo, style: Theme.of(context).textTheme.labelLarge),
                          ],
                        ),
            ),
          ),
        ),
        if (_logoBytes != null) ...[
          const SizedBox(height: 8),
          Center(
            child: OutlinedButton.icon(
              onPressed: _logoBusy
                  ? null
                  : () => confirmAndRun(context, () async {
                        setState(() => _logoBusy = true);
                        try {
                          final next = await ref.read(financeApiProvider).removeCompanyLogo();
                          _applySettings(next);
                        } finally {
                          if (mounted) setState(() => _logoBusy = false);
                        }
                      }),
              icon: const Icon(Icons.delete_outline, size: 16),
              label: Text(l.removeLogo),
            ),
          ),
        ],
      ],
    );
  }

  Widget _invoicesSection(AppLocalizations l) {
    return FormSection(
      title: l.settingsInvoices,
      subtitle: l.settingsInvoicesHint,
      child: Column(
        children: [
          FormGrid(children: [
            TextField(controller: _prefix, decoration: InputDecoration(labelText: l.invoicePrefix)),
            TextField(controller: _currency, decoration: InputDecoration(labelText: l.currency)),
            TextField(controller: _terms, decoration: InputDecoration(labelText: l.paymentTerms)),
            TextField(controller: _color, decoration: InputDecoration(labelText: l.invoiceColor)),
          ]),
          const SizedBox(height: 8),
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            title: Text(l.allowManualNumbers),
            value: _allowManual,
            onChanged: (v) => setState(() => _allowManual = v),
          ),
        ],
      ),
    );
  }

  Widget _taxesSection(AppLocalizations l, bool canEdit) {
    return FormSection(
      title: l.taxRates,
      subtitle: l.settingsTaxesHint,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          FormGrid(children: [
            TextField(controller: _vatRate, decoration: InputDecoration(labelText: l.defaultVatRate)),
          ]),
          const SizedBox(height: 12),
          if (canEdit) ...[
            if (_maps('tax_rates').isEmpty)
              Text(l.empty, style: Theme.of(context).textTheme.bodySmall),
            for (final rate in _maps('tax_rates'))
              ListTile(
                dense: true,
                contentPadding: EdgeInsets.zero,
                title: Text('${rate['name'] ?? ''}'),
                subtitle: Text('${rate['code'] ?? ''} · ${rate['type'] ?? ''} · ${rate['rate'] ?? ''}%'),
                trailing: Text([
                  if (rate['is_default'] == true) l.isDefault,
                  if (rate['is_active'] == true) l.isActive,
                ].join(' · ')),
              ),
            const SizedBox(height: 8),
            FormGrid(children: [
              TextField(controller: _taxName, decoration: InputDecoration(labelText: l.fieldName)),
              TextField(controller: _taxCode, decoration: InputDecoration(labelText: l.sku)),
              TextField(controller: _taxPct, decoration: InputDecoration(labelText: l.taxRate)),
              DropdownButtonFormField(
                isExpanded: true,
                // ignore: deprecated_member_use
                value: _taxType,
                decoration: InputDecoration(labelText: l.taxProfile),
                items: [
                  DropdownMenuItem(value: 'standard', child: Text(l.standardTax)),
                  DropdownMenuItem(value: 'zero_rated', child: Text(l.zeroRated)),
                  DropdownMenuItem(value: 'exempt', child: Text(l.exempt)),
                  DropdownMenuItem(value: 'out_of_scope', child: Text(l.outOfScope)),
                ],
                onChanged: (v) => setState(() => _taxType = v ?? 'standard'),
              ),
            ]),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              title: Text(l.isDefault),
              value: _taxDefault,
              onChanged: (v) => setState(() => _taxDefault = v),
            ),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              title: Text(l.isActive),
              value: _taxActive,
              onChanged: (v) => setState(() => _taxActive = v),
            ),
            Align(
              alignment: AlignmentDirectional.centerStart,
              child: FilledButton.tonal(
                onPressed: () async {
                  try {
                    await ref.read(financeApiProvider).storeTaxRate({
                      'name': _taxName.text.trim(),
                      'code': _taxCode.text.trim(),
                      'type': _taxType,
                      'rate': _taxPct.text.trim(),
                      'is_default': _taxDefault,
                      'is_active': _taxActive,
                    });
                    await _reloadSettings();
                    if (mounted) showSnack(context, l.success);
                  } catch (e) {
                    if (mounted) showFormError(context, e);
                  }
                },
                child: Text(l.createTaxRate),
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _addressSection(AppLocalizations l) {
    return FormSection(
      title: l.settingsAddress,
      subtitle: l.settingsAddressHint,
      child: FormGrid(children: [
        TextField(controller: _address, decoration: InputDecoration(labelText: l.addressLine)),
        TextField(controller: _city, decoration: InputDecoration(labelText: l.city)),
        TextField(controller: _district, decoration: InputDecoration(labelText: l.district)),
        TextField(controller: _street, decoration: InputDecoration(labelText: l.street)),
        TextField(controller: _building, decoration: InputDecoration(labelText: l.buildingNumber)),
        TextField(controller: _postal, decoration: InputDecoration(labelText: l.postalCode)),
        TextField(controller: _country, decoration: InputDecoration(labelText: l.countryCode)),
      ]),
    );
  }

  Widget _appearanceSection(AppLocalizations l) {
    final locale = Localizations.localeOf(context).languageCode;
    final mode = ref.watch(themeModeProvider);
    return FormSection(
      title: l.settingsAppearance,
      subtitle: l.settingsAppearanceHint,
      child: FormGrid(minWidth: 260, children: [
        _LabeledPills(
          label: l.language,
          children: [
            _Pill(
              label: l.arabic,
              selected: locale == 'ar',
              icon: locale == 'ar' ? Icons.check : null,
              onTap: () => ref.read(localeProvider.notifier).setLocale('ar'),
            ),
            _Pill(
              label: l.english,
              selected: locale == 'en',
              icon: locale == 'en' ? Icons.check : null,
              onTap: () => ref.read(localeProvider.notifier).setLocale('en'),
            ),
          ],
        ),
        _LabeledPills(
          label: l.theme,
          children: [
            _Pill(
              label: l.themeLight,
              selected: mode == ThemeMode.light,
              icon: Icons.wb_sunny_outlined,
              onTap: () => ref.read(themeModeProvider.notifier).setMode(ThemeMode.light),
            ),
            _Pill(
              label: l.themeDark,
              selected: mode == ThemeMode.dark,
              icon: Icons.dark_mode_outlined,
              onTap: () => ref.read(themeModeProvider.notifier).setMode(ThemeMode.dark),
            ),
            _Pill(
              label: l.themeSystem,
              selected: mode == ThemeMode.system,
              icon: Icons.settings_suggest_outlined,
              onTap: () => ref.read(themeModeProvider.notifier).setMode(ThemeMode.system),
            ),
          ],
        ),
      ]),
    );
  }

  Widget _systemSection(AppLocalizations l, AuthState auth, bool canEdit) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        FormSection(
          title: l.settingsSystem,
          subtitle: l.settingsSystemHint,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              ListTile(
                contentPadding: EdgeInsets.zero,
                title: Text(l.workspace),
                subtitle: Text(auth.workspace?.name ?? '-'),
              ),
              if (auth.workspaces.length > 1)
                DropdownButtonFormField<int>(
                  isExpanded: true,
                  // ignore: deprecated_member_use
                  value: auth.workspace?.id,
                  items: [
                    for (final w in auth.workspaces) DropdownMenuItem(value: w.id, child: Text(w.name)),
                  ],
                  onChanged: (id) {
                    if (id != null) ref.read(authControllerProvider.notifier).switchWorkspace(id);
                  },
                ),
              const SizedBox(height: 8),
              TextField(controller: _host, decoration: InputDecoration(labelText: l.apiHost)),
              const SizedBox(height: 8),
              Align(
                alignment: AlignmentDirectional.centerStart,
                child: FilledButton.tonal(
                  onPressed: () async {
                    await ref.read(prefsStoreProvider).setApiBaseOverride(_host.text.trim().isEmpty ? null : _host.text.trim());
                    final nextHost = _host.text.trim().isEmpty
                        ? (kDebugMode ? AppConfig.debugFallbackApiBase : '')
                        : _host.text.trim();
                    ref.read(apiClientProvider).updateBaseUrl(nextHost);
                    if (mounted) showSnack(context, l.success);
                  },
                  child: Text(l.save),
                ),
              ),
              if (_settings != null) ...[
                const SizedBox(height: 12),
                InfoRow(label: l.zatcaMode, value: '${_settings!['zatca_integration_mode'] ?? ''}'),
              ],
              const SizedBox(height: 12),
              FilledButton(
                onPressed: () => ref.read(authControllerProvider.notifier).logout(),
                child: Text(l.logout),
              ),
            ],
          ),
        ),
        if (canEdit)
          FormSection(
            title: l.createTreasuryAccount,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (_maps('treasury_accounts').isEmpty)
                  Text(l.empty, style: Theme.of(context).textTheme.bodySmall),
                for (final account in _maps('treasury_accounts'))
                  ListTile(
                    dense: true,
                    contentPadding: EdgeInsets.zero,
                    title: Text('${account['name'] ?? ''}'),
                    subtitle: Text([
                      account['type'] == 'cash' ? l.cashAccount : l.bankAccount,
                      '${account['current_balance'] ?? '0.00'} ${account['currency'] ?? ''}',
                      if ((account['linked_account_code'] ?? '').toString().isNotEmpty)
                        '${account['linked_account_code']} ${account['linked_account_name'] ?? ''}',
                    ].join(' · ')),
                  ),
                const SizedBox(height: 8),
                FormGrid(children: [
                  TextField(controller: _treasuryName, decoration: InputDecoration(labelText: l.fieldName)),
                  DropdownButtonFormField<String>(
                    isExpanded: true,
                    // ignore: deprecated_member_use
                    value: _treasuryType,
                    decoration: InputDecoration(labelText: l.accountType),
                    items: [
                      DropdownMenuItem(value: 'cash', child: Text(l.cashAccount)),
                      DropdownMenuItem(value: 'bank', child: Text(l.bankAccount)),
                    ],
                    onChanged: (v) => setState(() => _treasuryType = v ?? 'bank'),
                  ),
                  TextField(controller: _openingBal, decoration: InputDecoration(labelText: l.openingBalance)),
                  TextField(controller: _currentBal, decoration: InputDecoration(labelText: l.currentBalance)),
                  TextField(controller: _bankName, decoration: InputDecoration(labelText: l.bankName)),
                  TextField(controller: _iban, decoration: InputDecoration(labelText: l.iban)),
                  TextField(controller: _accountNumber, decoration: InputDecoration(labelText: l.accountNumber)),
                  OptionPicker(
                    label: l.linkedLedgerAccount,
                    options: [
                      for (final account in _maps('finance_accounts'))
                        NamedOption(
                          id: int.parse('${account['id']}'),
                          name: '${account['code'] ?? ''} ${account['name'] ?? ''}'.trim(),
                        ),
                    ],
                    value: _linkedAccountId,
                    onChanged: (id) => setState(() => _linkedAccountId = id),
                  ),
                ]),
                Align(
                  alignment: AlignmentDirectional.centerStart,
                  child: FilledButton.tonal(
                    onPressed: () async {
                      try {
                        await ref.read(financeApiProvider).storeTreasuryAccount({
                          'name': _treasuryName.text.trim(),
                          'type': _treasuryType,
                          'currency': _currency.text.trim().isEmpty ? 'SAR' : _currency.text.trim(),
                          if (_openingBal.text.trim().isNotEmpty) 'opening_balance': _openingBal.text.trim(),
                          if (_currentBal.text.trim().isNotEmpty) 'current_balance': _currentBal.text.trim(),
                          'iban': _iban.text.trim(),
                          'bank_name': _bankName.text.trim(),
                          'account_number': _accountNumber.text.trim(),
                          if (_linkedAccountId != null) 'linked_finance_account_id': _linkedAccountId,
                        });
                        await _reloadSettings();
                        if (mounted) showSnack(context, l.success);
                      } catch (e) {
                        if (mounted) showFormError(context, e);
                      }
                    },
                    child: Text(l.createTreasuryAccount),
                  ),
                ),
              ],
            ),
          ),
      ],
    );
  }
}

class _SettingsRail extends StatelessWidget {
  const _SettingsRail({
    required this.selected,
    required this.onSelect,
    required this.compact,
    required this.narrow,
  });

  final _SettingsTab selected;
  final ValueChanged<_SettingsTab> onSelect;
  final bool compact;
  final bool narrow;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final items = [
      (tab: _SettingsTab.general, icon: Icons.settings_outlined, title: l.settingsGeneral, hint: l.settingsGeneralHint),
      (tab: _SettingsTab.invoices, icon: Icons.description_outlined, title: l.settingsInvoices, hint: l.settingsInvoicesHint),
      (tab: _SettingsTab.taxes, icon: Icons.percent_rounded, title: l.settingsTaxes, hint: l.settingsTaxesHint),
      (tab: _SettingsTab.address, icon: Icons.location_on_outlined, title: l.settingsAddress, hint: l.settingsAddressHint),
      (tab: _SettingsTab.appearance, icon: Icons.palette_outlined, title: l.settingsAppearance, hint: l.settingsAppearanceHint),
      (tab: _SettingsTab.system, icon: Icons.tune_rounded, title: l.settingsSystem, hint: l.settingsSystemHint),
    ];
    if (compact) {
      return Wrap(
        spacing: 8,
        runSpacing: 8,
        children: [
          for (final item in items)
            _Pill(
              label: item.title,
              selected: selected == item.tab,
              icon: item.icon,
              onTap: () => onSelect(item.tab),
            ),
        ],
      );
    }
    return SizedBox(
      width: narrow ? 196 : 248,
      child: DecoratedBox(
        decoration: FinanceTokens.card(context, radius: FinanceTokens.radiusXl),
        child: ListView(
          padding: const EdgeInsets.fromLTRB(14, 16, 14, 16),
          children: [
            Text(l.settingsSections, style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w800)),
            const SizedBox(height: 12),
            for (final item in items) ...[
              _RailTile(
                icon: item.icon,
                title: item.title,
                hint: item.hint,
                selected: selected == item.tab,
                compact: narrow,
                onTap: () => onSelect(item.tab),
              ),
              const SizedBox(height: 6),
            ],
          ],
        ),
      ),
    );
  }
}

class _RailTile extends StatelessWidget {
  const _RailTile({
    required this.icon,
    required this.title,
    required this.hint,
    required this.selected,
    required this.onTap,
    this.compact = false,
  });

  final IconData icon;
  final String title;
  final String hint;
  final bool selected;
  final bool compact;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final p = context.hs;
    final selectedFg = Theme.of(context).colorScheme.onPrimary;
    return Material(
      color: selected ? FinanceTokens.brand : Colors.transparent,
      borderRadius: BorderRadius.circular(14),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(14),
        child: Padding(
          padding: EdgeInsets.fromLTRB(12, compact ? 10 : 12, 12, compact ? 10 : 12),
          child: Row(
            children: [
              Icon(icon, size: 20, color: selected ? selectedFg : p.textMuted),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      title,
                      style: Theme.of(context).textTheme.titleSmall?.copyWith(
                            color: selected ? selectedFg : p.text,
                            fontWeight: FontWeight.w800,
                          ),
                    ),
                    if (!compact) ...[
                      const SizedBox(height: 2),
                      Text(
                        hint,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(
                              color: selected ? selectedFg.withValues(alpha: 0.86) : p.textMuted,
                            ),
                      ),
                    ],
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _LabeledPills extends StatelessWidget {
  const _LabeledPills({required this.label, required this.children});

  final String label;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: Theme.of(context).textTheme.labelLarge),
        const SizedBox(height: 8),
        Wrap(spacing: 8, runSpacing: 8, children: children),
      ],
    );
  }
}

class _Pill extends StatelessWidget {
  const _Pill({
    required this.label,
    required this.selected,
    required this.onTap,
    this.icon,
  });

  final String label;
  final bool selected;
  final VoidCallback onTap;
  final IconData? icon;

  @override
  Widget build(BuildContext context) {
    final p = context.hs;
    final onPrimary = Theme.of(context).colorScheme.onPrimary;
    return Material(
      color: selected ? FinanceTokens.brand : p.input,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(FinanceTokens.radiusPill),
        side: BorderSide(color: selected ? FinanceTokens.brand : p.border),
      ),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(FinanceTokens.radiusPill),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              if (icon != null) ...[
                Icon(icon, size: 16, color: selected ? onPrimary : p.textMuted),
                const SizedBox(width: 6),
              ],
              Text(
                label,
                style: Theme.of(context).textTheme.labelLarge?.copyWith(
                      color: selected ? onPrimary : p.text,
                      fontWeight: FontWeight.w700,
                    ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
