import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/mobile/hasim_mobile_scaffold.dart';
import 'package:hasim/features/catalog/data/chat_catalog_settings_model.dart';
import 'package:hasim/features/catalog/providers/chat_catalog_settings_controller.dart';
import 'package:hasim/l10n/app_localizations.dart';

class ChatCatalogSettingsScreen extends ConsumerStatefulWidget {
  const ChatCatalogSettingsScreen({super.key, this.embedded = false});

  final bool embedded;

  @override
  ConsumerState<ChatCatalogSettingsScreen> createState() => _ChatCatalogSettingsScreenState();
}

class _ChatCatalogSettingsScreenState extends ConsumerState<ChatCatalogSettingsScreen>
    with SingleTickerProviderStateMixin {
  late TabController _tabs;
  ChatCatalogSettingsModel? _draft;

  @override
  void initState() {
    super.initState();
    _tabs = TabController(length: 4, vsync: this);
  }

  @override
  void dispose() {
    _tabs.dispose();
    super.dispose();
  }

  List<Tab> _tabsFor(AppLocalizations l10n) => [
        Tab(text: l10n.chatCatalogTabGeneral),
        Tab(text: l10n.chatCatalogTabAvailability),
        Tab(text: l10n.chatCatalogTabDiscovery),
        Tab(text: l10n.chatCatalogTabCommunication),
      ];

  Future<void> _saveGeneral() async {
    final draft = _draft;
    if (draft == null) return;
    final ok = await ref.read(chatCatalogSettingsControllerProvider.notifier).saveGeneral(draft);
    if (!mounted) return;
    final l10n = AppLocalizations.of(context);
    if (ok) {
      setState(() => _draft = ref.read(chatCatalogSettingsControllerProvider).settings);
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(l10n.settingsSaved)));
    } else {
      final err = ref.read(chatCatalogSettingsControllerProvider).error;
      if (err != null) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(err)));
      }
    }
  }

  Future<void> _saveCommunication() async {
    final draft = _draft;
    if (draft == null) return;
    final ok = await ref
        .read(chatCatalogSettingsControllerProvider.notifier)
        .saveCommunication(draft.communicationProfile);
    if (!mounted) return;
    if (ok) {
      setState(() => _draft = ref.read(chatCatalogSettingsControllerProvider).settings);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(AppLocalizations.of(context).settingsSaved)),
      );
    }
  }

  Future<void> _transition(String action) async {
    final ok = await ref.read(chatCatalogSettingsControllerProvider.notifier).transition(action);
    if (!mounted) return;
    if (ok) {
      setState(() => _draft = ref.read(chatCatalogSettingsControllerProvider).settings);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final state = ref.watch(chatCatalogSettingsControllerProvider);
    ref.listen(chatCatalogSettingsControllerProvider.select((s) => s.settings), (previous, next) {
      if (next?.id != _draft?.id || next == null) {
        setState(() => _draft = next);
      }
    });

    final tabs = _tabsFor(l10n);
    final body = state.loading
          ? const Center(child: CircularProgressIndicator())
          : state.error != null && _draft == null
              ? Center(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(state.error!),
                      TextButton(
                        onPressed: () => ref.read(chatCatalogSettingsControllerProvider.notifier).reload(),
                        child: Text(l10n.retry),
                      ),
                    ],
                  ),
                )
              : _draft == null
                  ? Center(child: Text(l10n.chatCatalogSettingsUnavailable))
                  : Column(
                      children: [
                        if (widget.embedded)
                          Material(
                            color: HasimTokens.cardBg(context),
                            child: TabBar(controller: _tabs, isScrollable: true, tabs: tabs),
                          ),
                        Expanded(
                          child: TabBarView(
                            controller: _tabs,
                            children: [
                              _GeneralTab(
                                draft: _draft!,
                                saving: state.saving,
                                onChanged: (d) => setState(() => _draft = d),
                                onSave: _saveGeneral,
                                onTransition: _transition,
                              ),
                              _AvailabilityTab(
                                draft: _draft!,
                                saving: state.saving,
                                onChanged: (d) => setState(() => _draft = d),
                                onSave: _saveGeneral,
                              ),
                              _DiscoveryTab(
                                draft: _draft!,
                                saving: state.saving,
                                onChanged: (d) => setState(() => _draft = d),
                                onSave: _saveGeneral,
                              ),
                              _CommunicationTab(
                                profile: _draft!.communicationProfile,
                                saving: state.saving,
                                onChanged: (p) => setState(
                                  () => _draft = _draft!.copyWith(communicationProfile: p),
                                ),
                                onSave: _saveCommunication,
                              ),
                            ],
                          ),
                        ),
                      ],
                    );

    if (!widget.embedded && HasimLayout.isCompact(context)) {
      return HasimMobileScaffold(
        title: l10n.chatCatalogSettingsTitle,
        appBarBottom: TabBar(controller: _tabs, isScrollable: true, tabs: tabs),
        body: body,
      );
    }

    return Scaffold(
      backgroundColor: widget.embedded ? Colors.transparent : HasimTokens.pageBg(context),
      appBar: widget.embedded
          ? null
          : AppBar(
              title: Text(l10n.chatCatalogSettingsTitle),
              bottom: TabBar(controller: _tabs, isScrollable: true, tabs: tabs),
            ),
      body: body,
    );
  }
}

class _GeneralTab extends StatefulWidget {
  const _GeneralTab({
    required this.draft,
    required this.saving,
    required this.onChanged,
    required this.onSave,
    required this.onTransition,
  });

  final ChatCatalogSettingsModel draft;
  final bool saving;
  final ValueChanged<ChatCatalogSettingsModel> onChanged;
  final VoidCallback onSave;
  final Future<void> Function(String action) onTransition;

  @override
  State<_GeneralTab> createState() => _GeneralTabState();
}

class _GeneralTabState extends State<_GeneralTab> {
  late final TextEditingController _name;
  late final TextEditingController _publicName;
  late final TextEditingController _currency;
  late final TextEditingController _website;

  @override
  void initState() {
    super.initState();
    _name = TextEditingController(text: widget.draft.name);
    _publicName = TextEditingController(text: widget.draft.publicName ?? '');
    _currency = TextEditingController(text: widget.draft.defaultCurrency);
    _website = TextEditingController(text: widget.draft.publicWebsite ?? '');
  }

  @override
  void didUpdateWidget(covariant _GeneralTab oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.draft.id != widget.draft.id) {
      _name.text = widget.draft.name;
      _publicName.text = widget.draft.publicName ?? '';
      _currency.text = widget.draft.defaultCurrency;
      _website.text = widget.draft.publicWebsite ?? '';
    }
  }

  @override
  void dispose() {
    _name.dispose();
    _publicName.dispose();
    _currency.dispose();
    _website.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final draft = widget.draft;
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text('${l10n.chatCatalogStatusLabel}: ${draft.status ?? '—'}'),
        const SizedBox(height: 8),
        Wrap(
          spacing: 8,
          children: [
            for (final action in ['publish', 'draft', 'pause'])
              OutlinedButton(
                onPressed: widget.saving ? null : () => widget.onTransition(action),
                child: Text(action),
              ),
          ],
        ),
        const SizedBox(height: 16),
        TextField(
          decoration: InputDecoration(labelText: l10n.chatCatalogFieldName),
          controller: _name,
          onChanged: (v) => widget.onChanged(draft.copyWith(name: v)),
        ),
        const SizedBox(height: 12),
        TextField(
          decoration: InputDecoration(labelText: l10n.chatCatalogFieldPublicName),
          controller: _publicName,
          onChanged: (v) => widget.onChanged(draft.copyWith(publicName: v)),
        ),
        const SizedBox(height: 12),
        TextField(
          decoration: InputDecoration(labelText: l10n.chatCatalogFieldCurrency),
          controller: _currency,
          onChanged: (v) => widget.onChanged(draft.copyWith(defaultCurrency: v.toUpperCase())),
        ),
        const SizedBox(height: 12),
        TextField(
          decoration: InputDecoration(labelText: l10n.chatCatalogFieldWebsite),
          controller: _website,
          onChanged: (v) => widget.onChanged(draft.copyWith(publicWebsite: v)),
        ),
        SwitchListTile(
          title: Text(l10n.chatCatalogFieldRtl),
          value: draft.rtlMode,
          onChanged: (v) => widget.onChanged(draft.copyWith(rtlMode: v)),
        ),
        const SizedBox(height: 16),
        FilledButton(
          onPressed: widget.saving ? null : widget.onSave,
          child: Text(widget.saving ? l10n.loading : l10n.save),
        ),
      ],
    );
  }
}

class _AvailabilityTab extends StatelessWidget {
  const _AvailabilityTab({
    required this.draft,
    required this.saving,
    required this.onChanged,
    required this.onSave,
  });

  final ChatCatalogSettingsModel draft;
  final bool saving;
  final ValueChanged<ChatCatalogSettingsModel> onChanged;
  final VoidCallback onSave;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final policy = Map<String, dynamic>.from(draft.customerServicePolicy);
    final schedule = Map<String, dynamic>.from(draft.availabilitySchedule);

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        _EnumField(
          label: l10n.chatCatalogAvailabilityMode,
          value: draft.availabilityMode ?? 'always_open',
          values: const ['always_open', 'scheduled', 'temporarily_unavailable', 'maintenance'],
          onChanged: (v) => onChanged(draft.copyWith(availabilityMode: v)),
        ),
        const SizedBox(height: 12),
        _EnumField(
          label: l10n.chatCatalogUnavailableBehavior,
          value: draft.unavailableItemBehavior ?? 'show_unavailable',
          values: const ['hide', 'show_unavailable', 'show_disable_purchase', 'show_inquiry_only'],
          onChanged: (v) => onChanged(draft.copyWith(unavailableItemBehavior: v)),
        ),
        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          title: Text(l10n.chatCatalogScheduleEnabled),
          value: draft.boolSetting(schedule, 'enabled'),
          onChanged: (v) {
            schedule['enabled'] = v;
            onChanged(draft.copyWith(availabilitySchedule: schedule));
          },
        ),
        TextField(
          decoration: InputDecoration(labelText: l10n.chatCatalogClosedMessage),
          controller: TextEditingController(text: schedule['custom_closed_message']?.toString() ?? '')
            ..selection = TextSelection.collapsed(
              offset: (schedule['custom_closed_message']?.toString() ?? '').length,
            ),
          maxLines: 2,
          onChanged: (v) {
            schedule['custom_closed_message'] = v;
            onChanged(draft.copyWith(availabilitySchedule: schedule));
          },
        ),
        const SizedBox(height: 16),
        Text(l10n.chatCatalogServicePolicy, style: Theme.of(context).textTheme.titleMedium),
        const SizedBox(height: 8),
        _EnumField(
          label: l10n.chatCatalogInquiryMode,
          value: policy['inquiry_mode']?.toString() ?? 'enabled',
          values: const ['enabled', 'inquiry_only', 'disabled'],
          onChanged: (v) {
            policy['inquiry_mode'] = v;
            onChanged(draft.copyWith(customerServicePolicy: policy));
          },
        ),
        const SizedBox(height: 12),
        TextField(
          decoration: InputDecoration(labelText: l10n.chatCatalogOutOfHoursMessage),
          controller: TextEditingController(text: policy['out_of_hours_message']?.toString() ?? ''),
          maxLines: 2,
          onChanged: (v) {
            policy['out_of_hours_message'] = v;
            onChanged(draft.copyWith(customerServicePolicy: policy));
          },
        ),
        const SizedBox(height: 12),
        TextField(
          decoration: InputDecoration(labelText: l10n.chatCatalogUnavailableProductMessage),
          controller: TextEditingController(text: policy['unavailable_product_message']?.toString() ?? ''),
          maxLines: 2,
          onChanged: (v) {
            policy['unavailable_product_message'] = v;
            onChanged(draft.copyWith(customerServicePolicy: policy));
          },
        ),
        const SizedBox(height: 16),
        FilledButton(
          onPressed: saving ? null : onSave,
          child: Text(saving ? l10n.loading : l10n.save),
        ),
      ],
    );
  }
}

class _DiscoveryTab extends StatelessWidget {
  const _DiscoveryTab({
    required this.draft,
    required this.saving,
    required this.onChanged,
    required this.onSave,
  });

  final ChatCatalogSettingsModel draft;
  final bool saving;
  final ValueChanged<ChatCatalogSettingsModel> onChanged;
  final VoidCallback onSave;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final channels = Map<String, dynamic>.from(draft.channelVisibility);
    final display = Map<String, dynamic>.from(draft.displaySettings);
    final discovery = Map<String, dynamic>.from(draft.discoverySettings);
    final seo = Map<String, dynamic>.from(draft.seo);
    final weights = Map<String, dynamic>.from(draft.searchWeights);

    void setMap(Map<String, dynamic> target, String key, Object value, ChatCatalogSettingsModel Function(Map<String, dynamic>) apply) {
      target[key] = value;
      onChanged(apply(target));
    }

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(l10n.chatCatalogSectionChannels, style: Theme.of(context).textTheme.titleMedium),
        SwitchListTile(
          title: const Text('Chat'),
          value: draft.boolSetting(channels, 'chat', fallback: true),
          onChanged: (v) => setMap(channels, 'chat', v, (m) => draft.copyWith(channelVisibility: m)),
        ),
        SwitchListTile(
          title: const Text('WhatsApp'),
          value: draft.boolSetting(channels, 'whatsapp', fallback: true),
          onChanged: (v) => setMap(channels, 'whatsapp', v, (m) => draft.copyWith(channelVisibility: m)),
        ),
        SwitchListTile(
          title: const Text('Web'),
          value: draft.boolSetting(channels, 'web'),
          onChanged: (v) => setMap(channels, 'web', v, (m) => draft.copyWith(channelVisibility: m)),
        ),
        const Divider(height: 32),
        Text(l10n.chatCatalogSectionDisplay, style: Theme.of(context).textTheme.titleMedium),
        SwitchListTile(
          title: Text(l10n.chatCatalogShowStock),
          value: draft.boolSetting(display, 'show_stock', fallback: true),
          onChanged: (v) => setMap(display, 'show_stock', v, (m) => draft.copyWith(displaySettings: m)),
        ),
        SwitchListTile(
          title: Text(l10n.chatCatalogShowSku),
          value: draft.boolSetting(display, 'show_sku'),
          onChanged: (v) => setMap(display, 'show_sku', v, (m) => draft.copyWith(displaySettings: m)),
        ),
        const Divider(height: 32),
        Text(l10n.chatCatalogSectionDiscovery, style: Theme.of(context).textTheme.titleMedium),
        SwitchListTile(
          title: Text(l10n.chatCatalogSearchable),
          value: draft.boolSetting(discovery, 'searchable', fallback: true),
          onChanged: (v) => setMap(discovery, 'searchable', v, (m) => draft.copyWith(discoverySettings: m)),
        ),
        SwitchListTile(
          title: Text(l10n.chatCatalogSkuSearch),
          value: draft.boolSetting(discovery, 'sku_search', fallback: true),
          onChanged: (v) => setMap(discovery, 'sku_search', v, (m) => draft.copyWith(discoverySettings: m)),
        ),
        const Divider(height: 32),
        Text(l10n.chatCatalogSeo, style: Theme.of(context).textTheme.titleMedium),
        TextField(
          decoration: InputDecoration(labelText: l10n.chatCatalogSeoTitle),
          controller: TextEditingController(text: seo['title']?.toString() ?? ''),
          onChanged: (v) => setMap(seo, 'title', v, (m) => draft.copyWith(seo: m)),
        ),
        const SizedBox(height: 12),
        TextField(
          decoration: InputDecoration(labelText: l10n.chatCatalogSeoDescription),
          controller: TextEditingController(text: seo['description']?.toString() ?? ''),
          maxLines: 2,
          onChanged: (v) => setMap(seo, 'description', v, (m) => draft.copyWith(seo: m)),
        ),
        const SizedBox(height: 12),
        _EnumField(
          label: l10n.chatCatalogSeoRobots,
          value: seo['robots']?.toString() ?? 'index,follow',
          values: const ['index,follow', 'noindex', 'nofollow'],
          onChanged: (v) => setMap(seo, 'robots', v, (m) => draft.copyWith(seo: m)),
        ),
        const Divider(height: 32),
        Text(l10n.chatCatalogSearchWeights, style: Theme.of(context).textTheme.titleMedium),
        for (final key in ['exact_name', 'sku', 'barcode', 'alias', 'category', 'tag', 'description'])
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Row(
              children: [
                Expanded(child: Text(key)),
                SizedBox(
                  width: 72,
                  child: TextFormField(
                    initialValue: '${draft.intSetting(weights, key, fallback: 1)}',
                    keyboardType: TextInputType.number,
                    onChanged: (v) {
                      final n = int.tryParse(v);
                      if (n == null) return;
                      setMap(weights, key, n.clamp(1, 10), (m) => draft.copyWith(searchWeights: m));
                    },
                  ),
                ),
              ],
            ),
          ),
        const SizedBox(height: 16),
        FilledButton(
          onPressed: saving ? null : onSave,
          child: Text(saving ? l10n.loading : l10n.save),
        ),
      ],
    );
  }
}

class _CommunicationTab extends StatelessWidget {
  const _CommunicationTab({
    required this.profile,
    required this.saving,
    required this.onChanged,
    required this.onSave,
  });

  final ChatCatalogCommunicationProfile profile;
  final bool saving;
  final ValueChanged<ChatCatalogCommunicationProfile> onChanged;
  final VoidCallback onSave;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        _EnumField(
          label: l10n.chatCatalogPrimaryLanguage,
          value: profile.primaryLanguage ?? 'ar',
          values: const ['ar', 'en', 'bilingual'],
          onChanged: (v) => onChanged(profile.copyWith(primaryLanguage: v)),
        ),
        const SizedBox(height: 12),
        _EnumField(
          label: l10n.chatCatalogArabicDialect,
          value: profile.arabicDialect ?? 'msa',
          values: const [
            'msa',
            'jordanian',
            'saudi',
            'gulf',
            'egyptian',
            'levantine',
            'iraqi',
            'moroccan',
            'algerian',
            'tunisian',
          ],
          onChanged: (v) => onChanged(profile.copyWith(arabicDialect: v)),
        ),
        const SizedBox(height: 12),
        _EnumField(
          label: l10n.chatCatalogFormality,
          value: profile.formality ?? 'neutral',
          values: const ['very_formal', 'formal', 'neutral', 'friendly', 'casual'],
          onChanged: (v) => onChanged(profile.copyWith(formality: v)),
        ),
        const SizedBox(height: 12),
        _EnumField(
          label: l10n.chatCatalogCommunicationStyle,
          value: profile.communicationStyle ?? 'balanced',
          values: const ['concise', 'balanced', 'detailed'],
          onChanged: (v) => onChanged(profile.copyWith(communicationStyle: v)),
        ),
        const SizedBox(height: 12),
        _EnumField(
          label: l10n.chatCatalogGreetingBehavior,
          value: profile.greetingBehavior ?? 'first_contact',
          values: const ['disabled', 'first_contact', 'always', 'channel_based', 'custom_greeting'],
          onChanged: (v) => onChanged(profile.copyWith(greetingBehavior: v)),
        ),
        const SizedBox(height: 12),
        _EnumField(
          label: l10n.chatCatalogEmojiPolicy,
          value: profile.emojiPolicy ?? 'limited',
          values: const ['disabled', 'limited', 'normal'],
          onChanged: (v) => onChanged(profile.copyWith(emojiPolicy: v)),
        ),
        const SizedBox(height: 16),
        FilledButton(
          onPressed: saving ? null : onSave,
          child: Text(saving ? l10n.loading : l10n.save),
        ),
      ],
    );
  }
}

class _EnumField extends StatelessWidget {
  const _EnumField({
    required this.label,
    required this.value,
    required this.values,
    required this.onChanged,
  });

  final String label;
  final String value;
  final List<String> values;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context) {
    final selected = values.contains(value) ? value : values.first;
    return DropdownButtonFormField<String>(
      value: selected,
      decoration: InputDecoration(labelText: label),
      items: [for (final v in values) DropdownMenuItem(value: v, child: Text(v))],
      onChanged: (v) {
        if (v != null) onChanged(v);
      },
    );
  }
}
