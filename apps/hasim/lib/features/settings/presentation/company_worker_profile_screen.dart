import 'dart:async';

import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/config/app_config.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/empty_state.dart';
import 'package:hasim/core/widgets/hasim_settings_body.dart';
import 'package:hasim/core/widgets/mobile/hasim_mobile_scaffold.dart';
import 'package:hasim/features/settings/data/company_worker_discovery_repository.dart';
import 'package:hasim/features/settings/data/worker_offer_duration.dart';
import 'package:hasim/features/settings/providers/company_worker_discovery_controller.dart';

class CompanyWorkerProfileScreen extends ConsumerStatefulWidget {
  const CompanyWorkerProfileScreen({
    super.key,
    this.worker,
    this.workerProfileId,
  });

  final CompanyWorkerDiscoveryWorker? worker;
  final int? workerProfileId;

  @override
  ConsumerState<CompanyWorkerProfileScreen> createState() =>
      _CompanyWorkerProfileScreenState();
}

class _CompanyWorkerProfileScreenState
    extends ConsumerState<CompanyWorkerProfileScreen> {
  CompanyWorkerDiscoveryWorker? _worker;
  bool _loading = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _worker = widget.worker;
    unawaited(_refreshWorker());
  }

  Future<void> _refreshWorker() async {
    final workerProfileId = widget.workerProfileId ?? _worker?.id;
    if (workerProfileId == null) return;

    if (mounted) {
      setState(() {
        _loading = true;
        _error = null;
      });
    }

    try {
      final worker = await ref
          .read(companyWorkerDiscoveryRepositoryProvider)
          .fetchWorker(workerProfileId);
      if (!mounted) return;
      setState(() {
        _worker = worker;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _worker = null;
        _error = e.userMessage;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _worker = null;
        _error = 'تعذر تحديث بيانات العامل.';
      });
    }
  }

  Future<void> _openOfferSheet() async {
    final worker = _worker;
    if (worker == null) return;
    final controller = ref.read(
      companyWorkerDiscoveryControllerProvider.notifier,
    );
    final jobTitle = TextEditingController();
    final startsAt = TextEditingController();
    final durationValue = TextEditingController(text: '1');
    final formKey = GlobalKey<FormState>();
    var durationPreset = 'day';
    var durationUnit = 'day';
    var saving = false;

    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: HasimLayout.isCompact(context)
          ? Colors.transparent
          : HasimTokens.pageBg(context),
      shape: RoundedRectangleBorder(
        borderRadius: HasimLayout.modalSheetRadius(context),
      ),
      builder: (_) {
        return StatefulBuilder(
          builder: (context, setSheetState) {
            final presetDuration = WorkerOfferDuration.fromPreset(
              durationPreset,
            );
            final selectedDurationValue =
                presetDuration?.value ??
                int.tryParse(durationValue.text.trim());
            final selectedDurationUnit = presetDuration?.unit ?? durationUnit;

            void selectDurationPreset(String? value) {
              if (value == null) return;
              final selected = WorkerOfferDuration.fromPreset(value);
              setSheetState(() {
                durationPreset = value;
                if (selected != null) {
                  durationValue.text = selected.value.toString();
                  durationUnit = selected.unit;
                } else {
                  durationValue.text = '1';
                  durationUnit = 'day';
                }
              });
            }

            return Padding(
              padding: HasimLayout.modalSheetMargin(context),
              child: SizedBox(
                height: HasimLayout.modalSheetHeight(context),
                width: double.infinity,
                child: Material(
                  color: HasimTokens.pageBg(context),
                  borderRadius: HasimLayout.modalSheetRadius(context),
                  clipBehavior: Clip.antiAlias,
                child: Column(
                  children: [
                    Padding(
                      padding: const EdgeInsets.only(top: 10, bottom: 4),
                      child: Center(
                        child: DecoratedBox(
                          decoration: BoxDecoration(
                            color: HasimTokens.lineColor(context),
                            borderRadius: BorderRadius.circular(999),
                          ),
                          child: const SizedBox(width: 42, height: 4),
                        ),
                      ),
                    ),
                    Padding(
                      padding: const EdgeInsets.fromLTRB(
                        HasimTokens.spaceMd,
                        4,
                        8,
                        HasimTokens.spaceSm,
                      ),
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Expanded(
                            child: Text(
                              'إرسال عرض إلى ${worker.displayName}',
                              maxLines: 2,
                              overflow: TextOverflow.ellipsis,
                              style: _title(context),
                            ),
                          ),
                          IconButton(
                            tooltip: 'إغلاق',
                            onPressed: () => Navigator.of(context).pop(),
                            icon: const Icon(Icons.close_rounded),
                          ),
                        ],
                      ),
                    ),
                    Divider(height: 1, color: HasimTokens.lineColor(context)),
                    Expanded(
                      child: Form(
                        key: formKey,
                        child: ListView(
                          padding: EdgeInsets.fromLTRB(
                            HasimTokens.spaceMd,
                            HasimTokens.spaceMd,
                            HasimTokens.spaceMd,
                            HasimTokens.spaceLg +
                                MediaQuery.viewInsetsOf(context).bottom,
                          ),
                          children: [
                            TextFormField(
                              controller: jobTitle,
                              decoration: const InputDecoration(
                                labelText: 'الدور أو المسمى',
                              ),
                              validator: (value) =>
                                  (value == null || value.trim().isEmpty)
                                  ? 'أدخل المسمى الوظيفي'
                                  : null,
                            ),
                            const SizedBox(height: HasimTokens.spaceMd),
                            TextFormField(
                              key: const ValueKey(
                                'worker-profile-offer-starts-at',
                              ),
                              controller: startsAt,
                              decoration: const InputDecoration(
                                labelText: 'تاريخ البداية',
                              ),
                              keyboardType: TextInputType.datetime,
                              onChanged: (_) => setSheetState(() {}),
                              validator: (value) {
                                if (value == null || value.trim().isEmpty) {
                                  return 'أدخل تاريخ البداية';
                                }
                                if (calculateWorkerOfferEndDate(
                                      startsAt: value.trim(),
                                      value: selectedDurationValue ?? 0,
                                      unit: selectedDurationUnit,
                                    ) ==
                                    null) {
                                  return 'أدخل تاريخ بداية صالحاً';
                                }
                                return null;
                              },
                            ),
                            const SizedBox(height: HasimTokens.spaceMd),
                            DropdownButtonFormField<String>(
                              key: const ValueKey(
                                'worker-profile-offer-duration-preset',
                              ),
                              initialValue: durationPreset,
                              decoration: const InputDecoration(
                                labelText: 'مدة التعاقد',
                              ),
                              items: const [
                                DropdownMenuItem(
                                  value: 'day',
                                  child: Text('يوم واحد'),
                                ),
                                DropdownMenuItem(
                                  value: 'week',
                                  child: Text('أسبوع واحد'),
                                ),
                                DropdownMenuItem(
                                  value: 'month',
                                  child: Text('شهر واحد'),
                                ),
                                DropdownMenuItem(
                                  value: 'custom',
                                  child: Text('مدة مخصصة'),
                                ),
                              ],
                              onChanged: selectDurationPreset,
                            ),
                            if (durationPreset == 'custom') ...[
                              const SizedBox(height: HasimTokens.spaceMd),
                              TextFormField(
                                key: const ValueKey(
                                  'worker-profile-offer-duration-value',
                                ),
                                controller: durationValue,
                                keyboardType: TextInputType.number,
                                decoration: const InputDecoration(
                                  labelText: 'قيمة المدة',
                                ),
                                validator: (value) {
                                  final parsed = int.tryParse(
                                    value?.trim() ?? '',
                                  );
                                  return parsed == null || parsed < 1
                                      ? 'أدخل رقماً صحيحاً أكبر من صفر'
                                      : null;
                                },
                                onChanged: (_) => setSheetState(() {}),
                              ),
                              const SizedBox(height: HasimTokens.spaceMd),
                              DropdownButtonFormField<String>(
                                key: const ValueKey(
                                  'worker-profile-offer-duration-unit',
                                ),
                                initialValue: durationUnit,
                                decoration: const InputDecoration(
                                  labelText: 'وحدة المدة',
                                ),
                                items: const [
                                  DropdownMenuItem(
                                    value: 'day',
                                    child: Text('يوم'),
                                  ),
                                  DropdownMenuItem(
                                    value: 'week',
                                    child: Text('أسبوع'),
                                  ),
                                  DropdownMenuItem(
                                    value: 'month',
                                    child: Text('شهر'),
                                  ),
                                ],
                                onChanged: (value) {
                                  if (value == null) return;
                                  setSheetState(() => durationUnit = value);
                                },
                              ),
                            ],
                            const SizedBox(height: HasimTokens.spaceSm),
                            AnimatedBuilder(
                              animation: Listenable.merge([
                                startsAt,
                                durationValue,
                              ]),
                              builder: (context, _) {
                                final currentValue =
                                    presetDuration?.value ??
                                    int.tryParse(durationValue.text.trim());
                                final calculatedEnd = currentValue == null
                                    ? null
                                    : calculateWorkerOfferEndDate(
                                        startsAt: startsAt.text.trim(),
                                        value: currentValue,
                                        unit: selectedDurationUnit,
                                      );

                                return Text(
                                  calculatedEnd == null
                                      ? 'ستظهر النهاية المحسوبة بعد إدخال تاريخ البداية والمدة.'
                                      : 'النهاية المحسوبة: ${formatWorkerOfferDate(calculatedEnd)}',
                                  key: const ValueKey(
                                    'worker-profile-offer-calculated-end',
                                  ),
                                  style: _muted(context),
                                );
                              },
                            ),
                            const SizedBox(height: 4),
                            Text(
                              'يحسِب الخادم تاريخ النهاية النهائي ويحفظه في التعاقد.',
                              style: _muted(context),
                            ),
                          ],
                        ),
                      ),
                    ),
                    DecoratedBox(
                      decoration: BoxDecoration(
                        color: HasimTokens.cardBg(context),
                        border: Border(
                          top: BorderSide(
                            color: HasimTokens.lineColor(context),
                          ),
                        ),
                      ),
                      child: SafeArea(
                        top: false,
                        child: Padding(
                          padding: const EdgeInsets.fromLTRB(
                            HasimTokens.spaceMd,
                            HasimTokens.spaceSm,
                            HasimTokens.spaceMd,
                            HasimTokens.spaceMd,
                          ),
                          child: SizedBox(
                            width: double.infinity,
                            child: FilledButton(
                              onPressed: saving
                                  ? null
                                  : () async {
                                      if (!(formKey.currentState?.validate() ??
                                          false)) {
                                        return;
                                      }
                                      setSheetState(() => saving = true);
                                      final ok = await controller.sendOffer(
                                        worker,
                                        jobTitle: jobTitle.text,
                                        startsAt: startsAt.text,
                                        durationValue: selectedDurationValue!,
                                        durationUnit: selectedDurationUnit,
                                      );
                                      if (!context.mounted) return;
                                      if (ok) {
                                        await _refreshWorker();
                                        if (!context.mounted) return;
                                        Navigator.of(context).pop();
                                        ScaffoldMessenger.of(
                                          context,
                                        ).showSnackBar(
                                          const SnackBar(
                                            content: Text('تم إرسال العرض'),
                                          ),
                                        );
                                      } else {
                                        final error = ref
                                            .read(
                                              companyWorkerDiscoveryControllerProvider,
                                            )
                                            .error;
                                        setSheetState(() => saving = false);
                                        if (error != null) {
                                          ScaffoldMessenger.of(
                                            context,
                                          ).showSnackBar(
                                            SnackBar(content: Text(error)),
                                          );
                                        }
                                      }
                                    },
                              child: Text(
                                saving ? 'جارٍ الإرسال' : 'إرسال العرض',
                              ),
                            ),
                          ),
                        ),
                      ),
                    ),
                  ],
                ),
                ),
              ),
            );
          },
        );
      },
    );

    jobTitle.dispose();
    startsAt.dispose();
    durationValue.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final worker = _worker;
    if (worker == null) {
      final empty = _loading
          ? const Center(child: CircularProgressIndicator())
          : EmptyState(
              icon: Icons.person_outline,
              title: 'العامل غير متاح',
              subtitle: _error ?? 'تعذر فتح الملف المهني.',
            );
      if (HasimLayout.isCompact(context)) {
        return HasimMobileScaffold(title: 'الملف المهني للعامل', body: empty);
      }
      return Scaffold(
        backgroundColor: HasimTokens.pageBg(context),
        body: SafeArea(child: empty),
      );
    }

    final compact = HasimLayout.isCompact(context);
    final body = HasimSettingsBody(
      mobileLayout: compact,
      children: [
        if (_loading) const LinearProgressIndicator(),
        if (_error != null) ...[
          _SectionCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  _error!,
                  style: _muted(
                    context,
                  ).copyWith(color: Theme.of(context).colorScheme.error),
                ),
                TextButton.icon(
                  onPressed: _refreshWorker,
                  icon: const Icon(Icons.refresh_rounded),
                  label: const Text('إعادة المحاولة'),
                ),
              ],
            ),
          ),
          const SizedBox(height: HasimTokens.spaceMd),
        ],
        _ProfileHero(worker: worker, compact: compact),
        const SizedBox(height: HasimTokens.spaceMd),
        _SectionCard(
          title: 'المهارات',
          child: Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              if (worker.skills.isEmpty)
                Text('لا توجد مهارات ظاهرة حالياً.', style: _muted(context)),
              for (final skill in worker.skills)
                _Pill(label: skill.displayName, highlighted: true),
            ],
          ),
        ),
        const SizedBox(height: HasimTokens.spaceMd),
        _SectionCard(
          title: 'اللغات',
          child: Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              if (worker.languages.isEmpty)
                Text('لا توجد لغات ظاهرة حالياً.', style: _muted(context)),
              for (final language in worker.languages)
                _Pill(label: language, highlighted: false),
            ],
          ),
        ),
        const SizedBox(height: HasimTokens.spaceMd),
        _SectionCard(
          title: 'الخبرات',
          child: worker.experiences.isEmpty
              ? Text('لا توجد خبرات ظاهرة حالياً.', style: _muted(context))
              : Column(
                  children: [
                    for (final experience in worker.experiences) ...[
                      Align(
                        alignment: AlignmentDirectional.centerStart,
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              experience.title,
                              style: _sectionTitle(context),
                            ),
                            const SizedBox(height: 2),
                            Text(
                              experience.organizationName,
                              style: _muted(context),
                            ),
                            if (experience.description != null &&
                                experience.description!.isNotEmpty) ...[
                              const SizedBox(height: 4),
                              Text(
                                experience.description!,
                                style: _muted(context).copyWith(height: 1.5),
                              ),
                            ],
                          ],
                        ),
                      ),
                      if (experience != worker.experiences.last)
                        const Divider(height: 24),
                    ],
                  ],
                ),
        ),
        if (worker.existingEngagement != null) ...[
          const SizedBox(height: HasimTokens.spaceMd),
          _SectionCard(
            title: 'العلاقة الحالية',
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                _ProfileMetaRow(
                  label: 'الحالة',
                  value: worker.existingEngagement!.statusLabel,
                ),
                if (worker.existingEngagement!.jobTitle != null)
                  _ProfileMetaRow(
                    label: 'المسمى',
                    value: worker.existingEngagement!.jobTitle!,
                  ),
                if (worker.existingEngagement!.startsAt != null ||
                    worker.existingEngagement!.endsAt != null)
                  _ProfileMetaRow(
                    label: 'الفترة',
                    value:
                        '${worker.existingEngagement!.startsAt ?? 'غير محدد'}'
                        ' — ${worker.existingEngagement!.endsAt ?? 'غير محدد'}',
                  ),
                if (worker.existingEngagement!.durationValue != null &&
                    worker.existingEngagement!.durationUnit != null)
                  _ProfileMetaRow(
                    label: 'المدة',
                    value: workerEngagementDurationLabel(
                      worker.existingEngagement!.durationValue,
                      worker.existingEngagement!.durationUnit,
                    ),
                  ),
              ],
            ),
          ),
        ],
        const SizedBox(height: HasimTokens.spaceLg),
        SizedBox(
          width: double.infinity,
          child: FilledButton(
            onPressed: worker.hasOpenEngagement ? null : _openOfferSheet,
            style: FilledButton.styleFrom(
              minimumSize: const Size(0, 48),
            ),
            child: Text(
              worker.hasOpenEngagement
                  ? worker.existingEngagement!.statusLabel
                  : 'إرسال عرض',
            ),
          ),
        ),
        const SizedBox(height: HasimTokens.spaceSm),
        Text(
          'تعرض هذه الصفحة بيانات مهنية مختارة فقط ولا تعرض بيانات الهوية الخاصة أو مستندات التحقق أو بيانات الشركات الأخرى.',
          style: _muted(context).copyWith(height: 1.6),
        ),
      ],
    );

    if (compact) {
      return HasimMobileScaffold(title: 'الملف المهني للعامل', body: body);
    }

    return Scaffold(
      backgroundColor: HasimTokens.pageBg(context),
      appBar: AppBar(
        title: Text('الملف المهني للعامل', style: _title(context)),
      ),
      body: body,
    );
  }
}

class _ProfileHero extends StatelessWidget {
  const _ProfileHero({required this.worker, required this.compact});

  final CompanyWorkerDiscoveryWorker worker;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final photoUrl = _resolvePhotoUrl(worker.profilePhoto);
    final details = Column(
      crossAxisAlignment: compact
          ? CrossAxisAlignment.center
          : CrossAxisAlignment.start,
      children: [
        Row(
          mainAxisAlignment: compact
              ? MainAxisAlignment.center
              : MainAxisAlignment.start,
          children: [
            Flexible(
              child: Text(
                worker.displayName,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                textAlign: compact ? TextAlign.center : TextAlign.start,
                style: _title(context).copyWith(fontSize: compact ? 20 : 22),
              ),
            ),
            if (worker.verifiedBadge) ...[
              const SizedBox(width: 6),
              const Icon(
                Icons.verified_rounded,
                size: 20,
                color: HasimTokens.primary,
              ),
            ],
          ],
        ),
        const SizedBox(height: 6),
        Text(
          worker.professionalSummary,
          textAlign: compact ? TextAlign.center : TextAlign.start,
          style: _muted(context).copyWith(height: 1.55),
        ),
        const SizedBox(height: HasimTokens.spaceSm),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          alignment: compact ? WrapAlignment.center : WrapAlignment.start,
          children: [
            if (worker.countryCode.isNotEmpty)
              _Pill(label: worker.countryCode, highlighted: true),
            if (worker.availabilityStatus.isNotEmpty)
              _Pill(
                label: _availabilityLabel(worker.availabilityStatus),
                highlighted: worker.availabilityStatus == 'available',
              ),
            if (worker.salesExperienceLevel.isNotEmpty)
              _Pill(
                label: _experienceLabel(worker.salesExperienceLevel),
                highlighted: false,
              ),
            if (worker.verifiedBadge)
              const _Pill(label: 'موثّق', highlighted: true),
            if (worker.hasOpenEngagement)
              _Pill(
                label: worker.existingEngagement!.statusLabel,
                highlighted: false,
              ),
          ],
        ),
      ],
    );

    return _SectionCard(
      child: compact
          ? Column(
              children: [
                _Avatar(
                  name: worker.displayName,
                  photoUrl: photoUrl,
                  radius: 36,
                ),
                const SizedBox(height: HasimTokens.spaceMd),
                details,
              ],
            )
          : Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _Avatar(
                  name: worker.displayName,
                  photoUrl: photoUrl,
                  radius: 34,
                ),
                const SizedBox(width: HasimTokens.spaceMd),
                Expanded(child: details),
              ],
            ),
    );
  }
}

class _ProfileMetaRow extends StatelessWidget {
  const _ProfileMetaRow({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 72,
            child: Text(label, style: _muted(context)),
          ),
          Expanded(
            child: Text(
              value,
              style: _sectionTitle(context),
            ),
          ),
        ],
      ),
    );
  }
}

class _SectionCard extends StatelessWidget {
  const _SectionCard({this.title, required this.child});

  final String? title;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: HasimTokens.cardBg(context),
      elevation: 0,
      shadowColor: Colors.transparent,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(HasimTokens.radiusCard),
        side: BorderSide(color: HasimTokens.lineColor(context)),
      ),
      child: Padding(
        padding: const EdgeInsets.all(HasimTokens.spaceMd),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (title != null) ...[
              Text(title!, style: _title(context)),
              const SizedBox(height: HasimTokens.spaceSm),
            ],
            child,
          ],
        ),
      ),
    );
  }
}

class _Avatar extends StatelessWidget {
  const _Avatar({required this.name, this.photoUrl, this.radius = 30});

  final String name;
  final String? photoUrl;
  final double radius;

  @override
  Widget build(BuildContext context) {
    final fallback = CircleAvatar(
      radius: radius,
      backgroundColor: HasimTokens.mintFill(context),
      child: Text(
        name.isNotEmpty ? name.trim()[0] : 'ع',
        style: _title(context).copyWith(color: HasimTokens.primary),
      ),
    );

    final resolved = photoUrl == null || photoUrl!.trim().isEmpty
        ? null
        : _resolvePhotoUrl(photoUrl);

    if (resolved == null) return fallback;

    return CircleAvatar(
      radius: radius,
      backgroundColor: HasimTokens.mintFill(context),
      backgroundImage: CachedNetworkImageProvider(resolved),
      onBackgroundImageError: (_, _) {},
      child: const SizedBox.shrink(),
    );
  }
}

class _Pill extends StatelessWidget {
  const _Pill({required this.label, required this.highlighted});

  final String label;
  final bool highlighted;

  @override
  Widget build(BuildContext context) {
    final bg = highlighted
        ? HasimTokens.mintFill(context)
        : HasimTokens.lineColor(context);
    final fg = highlighted
        ? HasimTokens.primary
        : HasimTokens.mutedColor(context);
    return DecoratedBox(
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(HasimTokens.radiusPill),
        border: Border.all(color: HasimTokens.lineColor(context)),
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
        child: Text(
          label,
          style: _muted(context).copyWith(
            color: fg,
            fontWeight: FontWeight.w600,
            fontSize: 12,
          ),
        ),
      ),
    );
  }
}

String _availabilityLabel(String value) {
  return switch (value) {
    'available' => 'متاح',
    'limited' => 'متاح بشكل محدود',
    'unavailable' => 'غير متاح',
    _ => value,
  };
}

String _experienceLabel(String value) {
  return switch (value) {
    'junior' => 'خبرة مبتدئة',
    'mid' || 'middle' => 'خبرة متوسطة',
    'senior' => 'خبرة متقدمة',
    _ => value,
  };
}

String? _resolvePhotoUrl(String? value) {
  final raw = value?.trim() ?? '';
  if (raw.isEmpty) return null;
  if (raw.startsWith('http://') || raw.startsWith('https://')) return raw;
  return Uri.parse(
    AppConfig.apiBase,
  ).resolve(raw.startsWith('/') ? raw.substring(1) : raw).toString();
}

TextStyle _title(BuildContext context) {
  return Theme.of(context).textTheme.titleMedium!.copyWith(
    fontWeight: FontWeight.w800,
    height: 1.3,
    color: HasimTokens.inkColor(context),
  );
}

TextStyle _sectionTitle(BuildContext context) {
  return Theme.of(context).textTheme.titleSmall!.copyWith(
    fontWeight: FontWeight.w700,
    height: 1.35,
    color: HasimTokens.inkColor(context),
  );
}

TextStyle _muted(BuildContext context) {
  return Theme.of(context).textTheme.bodyMedium!.copyWith(
    color: HasimTokens.mutedColor(context),
    height: 1.45,
  );
}
