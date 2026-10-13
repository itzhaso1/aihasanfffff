import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/hasim_buttons.dart';
import 'package:hasim/core/widgets/hasim_card.dart';
import 'package:hasim/core/widgets/hasim_form_field.dart';
import 'package:hasim/core/widgets/hasim_settings_body.dart';
import 'package:hasim/core/widgets/mobile/hasim_mobile_scaffold.dart';
import 'package:hasim/features/settings/data/company_profile_model.dart';
import 'package:hasim/features/settings/providers/company_profile_controller.dart';
import 'package:hasim/l10n/app_localizations.dart';

class CompanyProfileScreen extends ConsumerStatefulWidget {
  const CompanyProfileScreen({super.key, this.embedded = false});

  final bool embedded;

  @override
  ConsumerState<CompanyProfileScreen> createState() => _CompanyProfileScreenState();
}

class _CompanyProfileScreenState extends ConsumerState<CompanyProfileScreen> {
  late final TextEditingController _name;
  late final TextEditingController _about;
  late final TextEditingController _offerings;
  late final TextEditingController _services;
  late final TextEditingController _audience;
  late final TextEditingController _guidelines;
  int? _boundHash;
  String? _nameError;
  String? _aboutError;
  String? _offeringsError;
  String? _servicesError;
  String? _audienceError;
  String? _guidelinesError;

  @override
  void initState() {
    super.initState();
    _name = TextEditingController();
    _about = TextEditingController();
    _offerings = TextEditingController();
    _services = TextEditingController();
    _audience = TextEditingController();
    _guidelines = TextEditingController();
  }

  @override
  void dispose() {
    _name.dispose();
    _about.dispose();
    _offerings.dispose();
    _services.dispose();
    _audience.dispose();
    _guidelines.dispose();
    super.dispose();
  }

  void _bind(CompanyProfileModel? profile) {
    if (profile == null) {
      _boundHash = null;
      return;
    }
    final hash = profile.hashCode;
    if (_boundHash == hash) {
      return;
    }
    _boundHash = hash;
    _name.text = profile.companyName;
    _about.text = profile.about;
    _offerings.text = profile.offerings;
    _services.text = profile.services;
    _audience.text = profile.audience;
    _guidelines.text = profile.replyGuidelines;
  }

  CompanyProfileModel _draft() {
    return CompanyProfileModel(
      companyName: _name.text,
      about: _about.text,
      offerings: _offerings.text,
      services: _services.text,
      audience: _audience.text,
      replyGuidelines: _guidelines.text,
    );
  }

  bool _validate(CompanyProfileModel draft) {
    setState(() {
      _nameError = draft.fieldError(draft.companyName, CompanyProfileModel.nameMax);
      _aboutError = draft.fieldError(draft.about, CompanyProfileModel.aboutMax);
      _offeringsError = draft.fieldError(draft.offerings, CompanyProfileModel.offeringsMax);
      _servicesError = draft.fieldError(draft.services, CompanyProfileModel.servicesMax);
      _audienceError = draft.fieldError(draft.audience, CompanyProfileModel.audienceMax);
      _guidelinesError = draft.fieldError(
        draft.replyGuidelines,
        CompanyProfileModel.replyGuidelinesMax,
      );
    });
    return [
      _nameError,
      _aboutError,
      _offeringsError,
      _servicesError,
      _audienceError,
      _guidelinesError,
    ].every((error) => error == null);
  }

  Future<void> _save() async {
    final draft = _draft();
    if (!_validate(draft)) {
      return;
    }
    final ok = await ref.read(companyProfileControllerProvider.notifier).save(draft);
    if (!mounted) {
      return;
    }
    if (ok) {
      _boundHash = null;
      _bind(ref.read(companyProfileControllerProvider).profile);
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('تم حفظ نبذة الشركة.')),
      );
    } else {
      final err = ref.read(companyProfileControllerProvider).error;
      if (err != null) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(err)));
      }
    }
  }

  void _cancel() {
    final profile = ref.read(companyProfileControllerProvider).profile;
    _boundHash = null;
    if (profile == null) {
      _name.clear();
      _about.clear();
      _offerings.clear();
      _services.clear();
      _audience.clear();
      _guidelines.clear();
    } else {
      _bind(profile);
    }
    setState(() {
      _nameError = null;
      _aboutError = null;
      _offeringsError = null;
      _servicesError = null;
      _audienceError = null;
      _guidelinesError = null;
    });
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final state = ref.watch(companyProfileControllerProvider);
    _bind(state.profile);

    Widget body;
    if (state.loading && state.profile == null && state.error == null) {
      body = const Center(child: CircularProgressIndicator(strokeWidth: 2));
    } else if (state.forbidden) {
      body = Center(
        child: Padding(
          padding: const EdgeInsets.all(HasimTokens.spaceLg),
          child: Text(
            state.error ?? 'لا تملك صلاحية تعديل نبذة الشركة.',
            textAlign: TextAlign.center,
          ),
        ),
      );
    } else if (state.error != null && state.profile == null) {
      body = Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(state.error!),
            TextButton(
              onPressed: () => ref.read(companyProfileControllerProvider.notifier).reload(),
              child: Text(l10n.retry),
            ),
          ],
        ),
      );
    } else {
      body = HasimSettingsBody(
        mobileLayout: HasimLayout.isCompact(context) && !widget.embedded,
        children: [
          HasimCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  'تساعد هذه المعلومات المساعد على فهم نشاط الشركة عند الرد التلقائي واقتراح الردود. الأسعار والمخزون تُؤخذ من المنتجات، وليست من هذه النبذة.',
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    color: HasimTokens.mutedColor(context),
                  ),
                ),
                const SizedBox(height: HasimTokens.spaceMd),
                HasimFormField(
                  controller: _name,
                  label: 'اسم الشركة',
                  errorText: _nameError,
                ),
                const SizedBox(height: HasimTokens.spaceMd),
                HasimFormField(
                  controller: _about,
                  label: 'نبذة عن الشركة',
                  maxLines: 4,
                  errorText: _aboutError,
                ),
                const SizedBox(height: HasimTokens.spaceMd),
                HasimFormField(
                  controller: _offerings,
                  label: 'ماذا نبيع؟',
                  maxLines: 3,
                  errorText: _offeringsError,
                ),
                const SizedBox(height: HasimTokens.spaceMd),
                HasimFormField(
                  controller: _services,
                  label: 'ما الخدمات التي نقدمها؟',
                  maxLines: 3,
                  errorText: _servicesError,
                ),
                const SizedBox(height: HasimTokens.spaceMd),
                HasimFormField(
                  controller: _audience,
                  label: 'من هم عملاؤنا؟ (اختياري)',
                  maxLines: 2,
                  errorText: _audienceError,
                ),
                const SizedBox(height: HasimTokens.spaceMd),
                HasimFormField(
                  controller: _guidelines,
                  label: 'معلومات إضافية وتعليمات للرد (اختياري)',
                  maxLines: 3,
                  errorText: _guidelinesError,
                ),
              ],
            ),
          ),
          const SizedBox(height: HasimTokens.spaceMd),
          Wrap(
            spacing: HasimTokens.spaceSm,
            runSpacing: HasimTokens.spaceSm,
            children: [
              HasimPrimaryButton(
                key: const Key('company-profile-save'),
                label: state.saving ? l10n.loading : l10n.save,
                loading: state.saving,
                onPressed: state.saving ? null : _save,
              ),
              HasimSecondaryButton(
                label: 'إلغاء',
                onPressed: state.saving ? null : _cancel,
              ),
            ],
          ),
        ],
      );
    }

    if (!widget.embedded && HasimLayout.isCompact(context)) {
      return HasimMobileScaffold(
        title: 'نبذة عن الشركة',
        body: body,
      );
    }

    if (widget.embedded) {
      return body;
    }

    return Scaffold(
      backgroundColor: HasimTokens.pageBg(context),
      appBar: AppBar(title: const Text('نبذة عن الشركة')),
      body: body,
    );
  }
}
