import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/features/worker/data/worker_profile.dart';
import 'package:hasim/features/worker/presentation/worker_account_widgets.dart';
import 'package:hasim/features/worker/providers/worker_account_controller.dart';

Widget _reviewIssueMessage(WorkerReviewIssue issue) {
  return Container(
    key: Key('worker-review-issue-${issue.id}'),
    margin: const EdgeInsets.only(top: 8),
    padding: const EdgeInsets.all(10),
    decoration: BoxDecoration(
      color: const Color(0xFFFFF4E5),
      borderRadius: BorderRadius.circular(10),
      border: Border.all(color: const Color(0xFFF2C98A)),
    ),
    child: Text(
      'سبب المراجعة: ${issue.reason}',
      style: const TextStyle(color: Color(0xFF7A4B00), height: 1.4),
    ),
  );
}

Widget _validationMessages(WorkerValidationErrors errors) {
  return Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      for (final message in errors.messages.values)
        WorkerInlineMessage(message, error: true),
    ],
  );
}

Widget _verificationSummary(BuildContext context, WorkerProfile profile) {
  final issueTypes = profile.verificationIssues
      .map((issue) => issue.targetType)
      .toSet();
  final rows = <({String label, String targetType})>[
    (label: 'الملف الشخصي', targetType: 'profile'),
    (label: 'المهارات', targetType: 'skills'),
    (label: 'اللغات', targetType: 'languages'),
    (label: 'الخبرات', targetType: 'experience'),
    (label: 'الهوية', targetType: 'identity'),
    (label: 'المستندات', targetType: 'document'),
  ];

  return Container(
    key: const Key('worker-verification-summary'),
    margin: const EdgeInsets.only(top: 8),
    padding: const EdgeInsets.all(12),
    decoration: BoxDecoration(
      color: HasimTokens.pageBg(context),
      borderRadius: BorderRadius.circular(10),
      border: Border.all(color: HasimTokens.lineColor(context)),
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const Text(
          'ملخص المراجعة',
          style: TextStyle(fontWeight: FontWeight.w800),
        ),
        const SizedBox(height: 8),
        for (final row in rows)
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Row(
              children: [
                Text(
                  issueTypes.contains(row.targetType) ? '⚠️' : '⏳',
                  textDirection: TextDirection.ltr,
                ),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(
                    '${row.label} — ${issueTypes.contains(row.targetType) ? 'تحتاج تعديل' : profile.verificationStatusLabel}',
                  ),
                ),
              ],
            ),
          ),
      ],
    ),
  );
}

class WorkerProfessionalSection extends ConsumerStatefulWidget {
  const WorkerProfessionalSection({super.key});

  @override
  ConsumerState<WorkerProfessionalSection> createState() =>
      _WorkerProfessionalSectionState();
}

class _WorkerProfessionalSectionState
    extends ConsumerState<WorkerProfessionalSection> {
  final _displayName = TextEditingController();
  final _nationality = TextEditingController();
  final _summary = TextEditingController();
  final _salesLevel = TextEditingController();
  String _country = 'JO';
  String _availability = 'available';
  String? _source;
  WorkerUploadFile? _photoFile;

  @override
  void dispose() {
    _displayName.dispose();
    _nationality.dispose();
    _summary.dispose();
    _salesLevel.dispose();
    super.dispose();
  }

  void _apply(WorkerProfile profile) {
    final stamp = [
      profile.displayName,
      profile.countryCode,
      profile.nationality,
      profile.professionalSummary,
      profile.salesExperienceLevel,
      profile.availabilityStatus,
    ].join('|');
    if (stamp == _source) return;
    _source = stamp;
    _displayName.text = profile.displayName;
    _nationality.text = profile.nationality;
    _summary.text = profile.professionalSummary;
    _salesLevel.text = profile.salesExperienceLevel;
    _country = profile.countryCode;
    _availability = profile.availabilityStatus;
  }

  Future<void> _pickPhoto() async {
    final file = await ref.read(workerPhotoPickerProvider)();
    if (!mounted || file == null) return;
    setState(() => _photoFile = file);
  }

  Future<void> _save() async {
    final ok = await ref
        .read(workerAccountControllerProvider.notifier)
        .saveProfile(
          WorkerProfessionalUpdate(
            displayName: _displayName.text.trim(),
            countryCode: _country,
            nationality: _nationality.text.trim(),
            professionalSummary: _summary.text.trim(),
            salesExperienceLevel: _salesLevel.text.trim(),
            availabilityStatus: _availability,
          ),
          photoFile: _photoFile,
        );
    if (!mounted) return;
    if (ok) {
      setState(() => _photoFile = null);
      showWorkerSnack(context, 'تم حفظ المعلومات المهنية');
    }
  }

  @override
  Widget build(BuildContext context) {
    ref.listen<WorkerProfile?>(
      workerAccountControllerProvider.select((state) => state.profile),
      (previous, profile) {
        if (profile == null || !mounted) return;
        setState(() => _apply(profile));
      },
    );
    final state = ref.watch(
      workerAccountControllerProvider.select(
        (state) => (
          profile: state.profile,
          profileLoading: state.profileLoading,
          profileError: state.profileError,
          profileBusy: state.profileBusy,
          profileActionError: state.profileActionError,
          profileValidationErrors: state.profileValidationErrors,
          photoProgress: state.photoProgress,
        ),
      ),
    );
    final profile = state.profile;
    final photoError = state.profileValidationErrors.forField('profile_photo');

    return WorkerSectionCard(
      key: const Key('worker-professional'),
      title: 'المعلومات المهنية',
      child: state.profileLoading && profile == null
          ? const Center(child: CircularProgressIndicator())
          : profile == null
          ? _Retry(
              message: state.profileError ?? 'تعذر تحميل الملف المهني.',
              onRetry: () => ref
                  .read(workerAccountControllerProvider.notifier)
                  .loadProfile(force: true),
            )
          : Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text('حالة التحقق: ${profile.verificationStatusLabel}'),
                const SizedBox(height: 4),
                Text('حالة الحساب: ${profile.lifecycleStatusLabel}'),
                const SizedBox(height: 4),
                Text('التوفر: ${profile.availabilityStatusLabel}'),
                for (final issue in profile.verificationIssues)
                  if (issue.targetType == 'profile') _reviewIssueMessage(issue),
                const SizedBox(height: 12),
                WorkerTextField(
                  key: const Key('worker-display-name'),
                  controller: _displayName,
                  label: 'الاسم المعروض',
                  errorText: state.profileValidationErrors.forField(
                    'display_name',
                  ),
                ),
                const SizedBox(height: 12),
                OutlinedButton(
                  key: const Key('worker-photo-pick'),
                  onPressed: state.profileBusy ? null : _pickPhoto,
                  child: Text(
                    _photoFile == null
                        ? 'اختيار الصورة الشخصية'
                        : 'تم اختيار صورة',
                  ),
                ),
                if (photoError != null)
                  WorkerInlineMessage(photoError, error: true),
                if (state.photoProgress != null) ...[
                  const SizedBox(height: 8),
                  LinearProgressIndicator(value: state.photoProgress),
                ],
                const SizedBox(height: 12),
                WorkerChoiceField(
                  label: 'الدولة',
                  value: _country,
                  options: workerCountryLabels,
                  errorText: state.profileValidationErrors.forField(
                    'country_code',
                  ),
                  onChanged: (value) => setState(() => _country = value),
                ),
                const SizedBox(height: 12),
                WorkerTextField(
                  controller: _nationality,
                  label: 'الجنسية',
                  errorText: state.profileValidationErrors.forField(
                    'nationality',
                  ),
                ),
                const SizedBox(height: 12),
                WorkerTextField(
                  controller: _salesLevel,
                  label: 'مستوى خبرة المبيعات',
                  errorText: state.profileValidationErrors.forField(
                    'sales_experience_level',
                  ),
                ),
                const SizedBox(height: 12),
                WorkerChoiceField(
                  label: 'التوفر',
                  value: _availability,
                  options: workerAvailabilityLabels,
                  errorText: state.profileValidationErrors.forField(
                    'availability_status',
                  ),
                  onChanged: (value) => setState(() => _availability = value),
                ),
                const SizedBox(height: 12),
                WorkerTextField(
                  controller: _summary,
                  label: 'الوصف المهني',
                  maxLines: 4,
                  errorText: state.profileValidationErrors.forField(
                    'professional_summary',
                  ),
                ),
                const SizedBox(height: 12),
                WorkerSaveButton(
                  key: const Key('worker-profile-save'),
                  label: 'حفظ',
                  busy: state.profileBusy,
                  onPressed: _save,
                ),
                if (state.profileActionError != null)
                  WorkerInlineMessage(
                    state.profileActionError!,
                    key: const Key('worker-profile-error'),
                    error: true,
                  ),
              ],
            ),
    );
  }
}

class WorkerSkillsSection extends ConsumerStatefulWidget {
  const WorkerSkillsSection({super.key});

  @override
  ConsumerState<WorkerSkillsSection> createState() =>
      _WorkerSkillsSectionState();
}

class _WorkerSkillsSectionState extends ConsumerState<WorkerSkillsSection> {
  Set<int> _selected = {};
  String _source = '';

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      ref.read(workerAccountControllerProvider.notifier).loadSkillCatalog();
    });
  }

  void _apply(WorkerProfile? profile) {
    final stamp = profile?.skills.map((skill) => skill.id).join(',') ?? '';
    if (stamp == _source) return;
    _source = stamp;
    _selected = {
      for (final skill in profile?.skills ?? const <WorkerSkillItem>[])
        skill.id,
    };
  }

  Future<void> _save() async {
    final ok = await ref
        .read(workerAccountControllerProvider.notifier)
        .saveSkills(_selected.toList());
    if (!mounted) return;
    if (ok) showWorkerSnack(context, 'تم حفظ المهارات');
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(
      workerAccountControllerProvider.select(
        (state) => (
          profile: state.profile,
          profileLoading: state.profileLoading,
          skillCatalog: state.skillCatalog,
          skillsLoading: state.skillsLoading,
          skillsError: state.skillsError,
          skillsBusy: state.skillsBusy,
          skillsValidationErrors: state.skillsValidationErrors,
        ),
      ),
    );
    _apply(state.profile);
    final catalog = state.skillCatalog;
    final saved = state.profile?.skills ?? const <WorkerSkillItem>[];

    return WorkerSectionCard(
      key: const Key('worker-skills'),
      title: 'المهارات',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (state.skillsLoading)
            const Center(child: CircularProgressIndicator())
          else if (state.skillsError != null && catalog.isEmpty)
            _Retry(
              message: state.skillsError!,
              onRetry: () => ref
                  .read(workerAccountControllerProvider.notifier)
                  .loadSkillCatalog(force: true),
            )
          else if (catalog.isEmpty)
            const WorkerInlineMessage('لا توجد مهارات في الدليل.')
          else
            for (final skill in catalog)
              CheckboxListTile(
                key: Key('worker-skill-${skill.id}'),
                contentPadding: EdgeInsets.zero,
                value: _selected.contains(skill.id),
                title: Text(
                  skill.displayName.isEmpty
                      ? skill.skillKey
                      : skill.displayName,
                ),
                onChanged: state.skillsBusy
                    ? null
                    : (checked) {
                        setState(() {
                          if (checked ?? false) {
                            _selected.add(skill.id);
                          } else {
                            _selected.remove(skill.id);
                          }
                        });
                      },
              ),
          for (final issue in state.profile?.verificationIssues ?? const [])
            if (issue.targetType == 'skills') _reviewIssueMessage(issue),
          if (saved.isEmpty && !state.profileLoading)
            const WorkerInlineMessage('لا توجد مهارات مضافة بعد'),
          if (state.skillsError != null && catalog.isNotEmpty)
            WorkerInlineMessage(state.skillsError!, error: true),
          if (!state.skillsValidationErrors.isEmpty)
            _validationMessages(state.skillsValidationErrors),
          const SizedBox(height: 8),
          WorkerSaveButton(
            key: const Key('worker-skills-save'),
            label: 'حفظ المهارات',
            busy: state.skillsBusy,
            onPressed: catalog.isEmpty ? null : _save,
          ),
        ],
      ),
    );
  }
}

class WorkerLanguagesSection extends ConsumerStatefulWidget {
  const WorkerLanguagesSection({super.key});

  @override
  ConsumerState<WorkerLanguagesSection> createState() =>
      _WorkerLanguagesSectionState();
}

class _WorkerLanguagesSectionState
    extends ConsumerState<WorkerLanguagesSection> {
  Set<String> _selected = {};
  String _source = '';

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      ref.read(workerAccountControllerProvider.notifier).loadLanguageCatalog();
    });
  }

  void _apply(WorkerProfile? profile) {
    final stamp =
        profile?.languages.map((language) => language.code).join(',') ?? '';
    if (stamp == _source) return;
    _source = stamp;
    _selected = {
      for (final language in profile?.languages ?? const <WorkerLanguageItem>[])
        language.code,
    };
  }

  Future<void> _save() async {
    final ok = await ref
        .read(workerAccountControllerProvider.notifier)
        .saveLanguages(_selected.toList());
    if (!mounted) return;
    if (ok) showWorkerSnack(context, 'تم حفظ اللغات');
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(
      workerAccountControllerProvider.select(
        (state) => (
          profile: state.profile,
          profileLoading: state.profileLoading,
          languageCatalog: state.languageCatalog,
          languagesLoading: state.languagesLoading,
          languagesError: state.languagesError,
          languagesBusy: state.languagesBusy,
          languagesValidationErrors: state.languagesValidationErrors,
        ),
      ),
    );
    _apply(state.profile);
    final catalog = state.languageCatalog;

    return WorkerSectionCard(
      key: const Key('worker-languages'),
      title: 'اللغات',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (state.languagesLoading)
            const Center(child: CircularProgressIndicator())
          else if (state.languagesError != null && catalog.isEmpty)
            _Retry(
              message: state.languagesError!,
              onRetry: () => ref
                  .read(workerAccountControllerProvider.notifier)
                  .loadLanguageCatalog(force: true),
            )
          else if (catalog.isEmpty)
            const WorkerInlineMessage('لا توجد لغات في الدليل.')
          else
            for (final language in catalog)
              CheckboxListTile(
                key: Key('worker-language-${language.code}'),
                contentPadding: EdgeInsets.zero,
                value: _selected.contains(language.code),
                title: Text(
                  language.displayName?.isNotEmpty == true
                      ? language.displayName!
                      : language.code,
                ),
                onChanged: state.languagesBusy
                    ? null
                    : (checked) {
                        setState(() {
                          if (checked ?? false) {
                            _selected.add(language.code);
                          } else {
                            _selected.remove(language.code);
                          }
                        });
                      },
              ),
          for (final issue in state.profile?.verificationIssues ?? const [])
            if (issue.targetType == 'languages') _reviewIssueMessage(issue),
          if ((state.profile?.languages.isEmpty ?? true) &&
              !state.profileLoading)
            const WorkerInlineMessage('لا توجد لغات مضافة بعد'),
          if (state.languagesError != null && catalog.isNotEmpty)
            WorkerInlineMessage(state.languagesError!, error: true),
          if (!state.languagesValidationErrors.isEmpty)
            _validationMessages(state.languagesValidationErrors),
          const SizedBox(height: 8),
          WorkerSaveButton(
            key: const Key('worker-languages-save'),
            label: 'حفظ اللغات',
            busy: state.languagesBusy,
            onPressed: catalog.isEmpty ? null : _save,
          ),
        ],
      ),
    );
  }
}

class WorkerExperienceSection extends ConsumerWidget {
  const WorkerExperienceSection({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(
      workerAccountControllerProvider.select(
        (state) => (
          profile: state.profile,
          profileLoading: state.profileLoading,
          profileError: state.profileError,
          experienceBusy: state.experienceBusy,
          experienceError: state.experienceError,
          experienceValidationErrors: state.experienceValidationErrors,
        ),
      ),
    );
    final experiences =
        state.profile?.experiences ?? const <WorkerExperience>[];

    return WorkerSectionCard(
      key: const Key('worker-experiences'),
      title: 'الخبرات',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (state.profileLoading && state.profile == null)
            const Center(child: CircularProgressIndicator())
          else if (state.profile == null)
            _Retry(
              message: state.profileError ?? 'تعذر عرض الخبرات.',
              onRetry: () => ref
                  .read(workerAccountControllerProvider.notifier)
                  .loadProfile(force: true),
            )
          else if (experiences.isEmpty)
            const WorkerInlineMessage('لا توجد خبرات مضافة بعد')
          else
            for (final experience in experiences)
              ListTile(
                key: Key('worker-experience-${experience.id}'),
                contentPadding: EdgeInsets.zero,
                title: Text(experience.title),
                subtitle: Text(_experienceSubtitle(experience)),
                trailing: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    IconButton(
                      tooltip: 'تعديل',
                      onPressed: state.experienceBusy
                          ? null
                          : () => _editExperience(context, ref, experience),
                      icon: const Icon(Icons.edit_outlined),
                    ),
                    IconButton(
                      key: Key('worker-experience-delete-${experience.id}'),
                      tooltip: 'حذف',
                      onPressed: state.experienceBusy
                          ? null
                          : () => _deleteExperience(context, ref, experience),
                      icon: const Icon(Icons.delete_outline),
                    ),
                  ],
                ),
              ),
          for (final issue in state.profile?.verificationIssues ?? const [])
            if (issue.targetType == 'experience') _reviewIssueMessage(issue),
          if (state.experienceError != null)
            WorkerInlineMessage(
              state.experienceError!,
              key: const Key('worker-experience-error'),
              error: true,
            ),
          if (!state.experienceValidationErrors.isEmpty)
            _validationMessages(state.experienceValidationErrors),
          const SizedBox(height: 8),
          OutlinedButton(
            key: const Key('worker-experience-add'),
            onPressed: state.profile == null || state.experienceBusy
                ? null
                : () => _editExperience(context, ref, null),
            child: const Text('إضافة خبرة'),
          ),
        ],
      ),
    );
  }
}

class WorkerIdentitySection extends ConsumerStatefulWidget {
  const WorkerIdentitySection({super.key});

  @override
  ConsumerState<WorkerIdentitySection> createState() =>
      _WorkerIdentitySectionState();
}

class _WorkerIdentitySectionState extends ConsumerState<WorkerIdentitySection> {
  final _legalName = TextEditingController();
  final _birth = TextEditingController();
  final _number = TextEditingController();
  final _address = TextEditingController();
  String? _source;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      ref.read(workerAccountControllerProvider.notifier).loadIdentity();
    });
  }

  @override
  void dispose() {
    _legalName.dispose();
    _birth.dispose();
    _number.dispose();
    _address.dispose();
    super.dispose();
  }

  void _apply(WorkerIdentity identity) {
    final stamp = [
      identity.legalName,
      identity.dateOfBirth,
      identity.identityNumber,
      identity.address,
    ].join('|');
    if (stamp == _source) return;
    _source = stamp;
    _legalName.text = identity.legalName ?? '';
    _birth.text = identity.dateOfBirth ?? '';
    _number.text = identity.identityNumber ?? '';
    _address.text = identity.address ?? '';
  }

  Future<void> _pickBirth() async {
    final now = DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: DateTime(now.year - 25),
      firstDate: DateTime(1940),
      lastDate: DateTime(
        now.year,
        now.month,
        now.day,
      ).subtract(const Duration(days: 1)),
    );
    if (picked == null || !mounted) return;
    setState(() => _birth.text = _formatDate(picked));
  }

  Future<void> _save() async {
    final ok = await ref
        .read(workerAccountControllerProvider.notifier)
        .saveIdentity(
          WorkerIdentityInput(
            legalName: _legalName.text.trim(),
            dateOfBirth: _birth.text.trim(),
            identityNumber: _number.text.trim(),
            address: _address.text.trim().isEmpty ? null : _address.text.trim(),
          ),
        );
    if (!mounted) return;
    if (ok) showWorkerSnack(context, 'تم حفظ الهوية');
  }

  @override
  Widget build(BuildContext context) {
    ref.listen<WorkerIdentity?>(
      workerAccountControllerProvider.select((state) => state.identity),
      (previous, identity) {
        if (identity == null || !mounted) return;
        setState(() => _apply(identity));
      },
    );
    final state = ref.watch(
      workerAccountControllerProvider.select(
        (state) => (
          profile: state.profile,
          identity: state.identity,
          identityLoading: state.identityLoading,
          identityError: state.identityError,
          identityBusy: state.identityBusy,
          identityValidationErrors: state.identityValidationErrors,
        ),
      ),
    );
    final identity = state.identity;

    return WorkerSectionCard(
      key: const Key('worker-identity'),
      title: 'الهوية',
      child: state.identityLoading && identity == null
          ? const Center(child: CircularProgressIndicator())
          : identity == null
          ? _Retry(
              message: state.identityError ?? 'تعذر تحميل الهوية.',
              onRetry: () => ref
                  .read(workerAccountControllerProvider.notifier)
                  .loadIdentity(force: true),
            )
          : Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (identity.isEmpty)
                  const WorkerInlineMessage('لم تُضف الهوية الخاصة بعد'),
                for (final issue
                    in state.profile?.verificationIssues ?? const [])
                  if (issue.targetType == 'identity')
                    _reviewIssueMessage(issue),
                WorkerTextField(
                  key: const Key('worker-legal-name'),
                  controller: _legalName,
                  label: 'الاسم القانوني',
                  errorText: state.identityValidationErrors.forField(
                    'legal_name',
                  ),
                ),
                const SizedBox(height: 12),
                WorkerTextField(
                  key: const Key('worker-birth-date'),
                  controller: _birth,
                  label: 'تاريخ الميلاد',
                  errorText: state.identityValidationErrors.forField(
                    'date_of_birth',
                  ),
                ),
                Align(
                  alignment: AlignmentDirectional.centerStart,
                  child: TextButton(
                    onPressed: _pickBirth,
                    child: const Text('اختيار التاريخ'),
                  ),
                ),
                WorkerTextField(
                  key: const Key('worker-identity-number'),
                  controller: _number,
                  label: 'رقم الهوية',
                  errorText: state.identityValidationErrors.forField(
                    'identity_number',
                  ),
                ),
                const SizedBox(height: 12),
                WorkerTextField(
                  controller: _address,
                  label: 'العنوان',
                  maxLines: 3,
                  errorText: state.identityValidationErrors.forField('address'),
                ),
                const SizedBox(height: 12),
                WorkerSaveButton(
                  key: const Key('worker-identity-save'),
                  label: 'حفظ الهوية',
                  busy: state.identityBusy,
                  onPressed: _save,
                ),
                if (state.identityError != null && !state.identityLoading)
                  WorkerInlineMessage(state.identityError!, error: true),
              ],
            ),
    );
  }
}

class WorkerVerificationSection extends ConsumerStatefulWidget {
  const WorkerVerificationSection({super.key});

  @override
  ConsumerState<WorkerVerificationSection> createState() =>
      _WorkerVerificationSectionState();
}

class _WorkerVerificationSectionState
    extends ConsumerState<WorkerVerificationSection> {
  final _typeCode = TextEditingController();
  WorkerUploadFile? _selectedFile;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      ref.read(workerAccountControllerProvider.notifier).loadDocuments();
    });
  }

  @override
  void dispose() {
    _typeCode.dispose();
    super.dispose();
  }

  Future<void> _pickFile() async {
    final file = await ref.read(workerDocumentPickerProvider)();
    if (!mounted || file == null) return;
    setState(() => _selectedFile = file);
  }

  Future<void> _upload() async {
    final type = _typeCode.text.trim();
    if (type.isEmpty) {
      showWorkerSnack(context, 'أدخل رمز نوع المستند.');
      return;
    }
    final file = _selectedFile;
    if (file == null) {
      showWorkerSnack(context, 'اختر ملفاً أولاً.');
      return;
    }
    final ok = await ref
        .read(workerAccountControllerProvider.notifier)
        .uploadDocument(documentTypeCode: type, file: file);
    if (!mounted) return;
    if (ok) {
      setState(() => _selectedFile = null);
      showWorkerSnack(context, 'تم رفع المستند');
    }
  }

  Future<void> _submit() async {
    final ok = await ref
        .read(workerAccountControllerProvider.notifier)
        .submitVerification();
    if (!mounted) return;
    if (ok) showWorkerSnack(context, 'تم إرسال طلب التحقق');
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(
      workerAccountControllerProvider.select(
        (state) => (
          profile: state.profile,
          documents: state.documents,
          documentsLoading: state.documentsLoading,
          documentsError: state.documentsError,
          documentsBusy: state.documentsBusy,
          documentsValidationErrors: state.documentsValidationErrors,
          documentProgress: state.documentProgress,
          verificationBusy: state.verificationBusy,
          verificationError: state.verificationError,
          verificationValidationErrors: state.verificationValidationErrors,
        ),
      ),
    );
    final profile = state.profile;
    final blocked = profile?.uploadBlocked ?? false;
    final requiredTypes = profile?.requiredDocumentTypes ?? const <String>[];
    final notice = profile?.verificationNotice;
    final documentError = state.documentsValidationErrors.forField('document');

    return WorkerSectionCard(
      key: const Key('worker-verification'),
      title: 'التحقق',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (profile != null)
            Text('حالة التحقق: ${profile.verificationStatusLabel}'),
          if (profile != null) _verificationSummary(context, profile),
          if (notice != null)
            Padding(
              key: const Key('worker-verification-notice'),
              padding: const EdgeInsets.only(top: 8),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    notice.title,
                    style: const TextStyle(fontWeight: FontWeight.w800),
                  ),
                  const SizedBox(height: 4),
                  Text(notice.next),
                  if (notice.reason != null) ...[
                    const SizedBox(height: 4),
                    Text('سبب المراجعة: ${notice.reason}'),
                  ],
                ],
              ),
            ),
          const SizedBox(height: 8),
          if (requiredTypes.isEmpty)
            const WorkerInlineMessage(
              'لا توجد أنواع مستندات إلزامية معتمدة حالياً لهذه الدولة.',
              key: Key('worker-required-documents'),
            )
          else
            Wrap(
              key: const Key('worker-required-documents'),
              spacing: 8,
              children: [
                for (final type in requiredTypes)
                  ActionChip(
                    label: Text(type),
                    onPressed: () => _typeCode.text = type,
                  ),
              ],
            ),
          const SizedBox(height: 8),
          const WorkerInlineMessage(
            'رفع ملف بنفس رمز النوع يستبدل المستند المُرسل السابق.',
          ),
          if (state.documentsLoading)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 12),
              child: Center(child: CircularProgressIndicator()),
            )
          else if (state.documentsError != null && state.documents.isEmpty)
            _Retry(
              message: state.documentsError!,
              onRetry: () => ref
                  .read(workerAccountControllerProvider.notifier)
                  .loadDocuments(force: true),
            )
          else if (state.documents.isEmpty)
            const WorkerInlineMessage(
              'لا توجد مستندات تحقق مرفوعة',
              key: Key('worker-documents-empty'),
            )
          else
            for (final document in state.documents)
              Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  ListTile(
                    key: Key('worker-document-${document.id}'),
                    contentPadding: EdgeInsets.zero,
                    title: Text(
                      '${document.documentTypeCode} — ${document.originalName}',
                    ),
                    subtitle: Text(
                      [
                        document.status,
                        if (document.rejectionReason != null)
                          document.rejectionReason!,
                      ].join(' · '),
                    ),
                    trailing: TextButton(
                      onPressed: blocked
                          ? null
                          : () => setState(
                              () => _typeCode.text = document.documentTypeCode,
                            ),
                      child: const Text('استبدال'),
                    ),
                  ),
                  for (final issue in profile?.verificationIssues ?? const [])
                    if (issue.targetType == 'document' &&
                        issue.targetId == document.id)
                      _reviewIssueMessage(issue),
                ],
              ),
          const SizedBox(height: 8),
          WorkerTextField(
            key: const Key('worker-document-type'),
            controller: _typeCode,
            label: 'رمز نوع المستند',
            errorText: state.documentsValidationErrors.forField(
              'document_type_code',
            ),
          ),
          const SizedBox(height: 8),
          OutlinedButton(
            key: const Key('worker-document-pick'),
            onPressed: blocked || state.documentsBusy ? null : _pickFile,
            child: Text(_selectedFile == null ? 'اختيار ملف' : 'تم اختيار ملف'),
          ),
          if (documentError != null)
            WorkerInlineMessage(documentError, error: true),
          if (state.documentProgress != null) ...[
            const SizedBox(height: 8),
            LinearProgressIndicator(value: state.documentProgress),
          ],
          const SizedBox(height: 8),
          FilledButton(
            key: const Key('worker-document-upload'),
            onPressed: blocked || state.documentsBusy ? null : _upload,
            child: Text(state.documentsBusy ? 'جارٍ الرفع...' : 'رفع المستند'),
          ),
          if (state.documentsError != null && state.documents.isNotEmpty)
            WorkerInlineMessage(state.documentsError!, error: true),
          const SizedBox(height: 12),
          FilledButton(
            key: const Key('worker-verification-submit'),
            onPressed: blocked || state.verificationBusy || profile == null
                ? null
                : _submit,
            child: Text(
              state.verificationBusy ? 'جارٍ الإرسال...' : 'إرسال طلب التحقق',
            ),
          ),
          if (state.verificationError != null)
            WorkerInlineMessage(
              state.verificationError!,
              key: const Key('worker-verification-error'),
              error: true,
            ),
          if (!state.verificationValidationErrors.isEmpty)
            _validationMessages(state.verificationValidationErrors),
        ],
      ),
    );
  }
}

class _Retry extends StatelessWidget {
  const _Retry({required this.message, required this.onRetry});

  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        WorkerInlineMessage(message, error: true),
        TextButton(onPressed: onRetry, child: const Text('إعادة المحاولة')),
      ],
    );
  }
}

Future<void> _editExperience(
  BuildContext context,
  WidgetRef ref,
  WorkerExperience? existing,
) async {
  final input = await showDialog<WorkerExperienceInput>(
    context: context,
    builder: (dialogContext) => _ExperienceDialog(existing: existing),
  );
  if (input == null || !context.mounted) return;
  final notifier = ref.read(workerAccountControllerProvider.notifier);
  final ok = existing == null
      ? await notifier.createExperience(input)
      : await notifier.updateExperience(existing.id, input);
  if (!context.mounted) return;
  if (ok) showWorkerSnack(context, 'تم حفظ الخبرة');
}

class _ExperienceDialog extends StatefulWidget {
  const _ExperienceDialog({required this.existing});

  final WorkerExperience? existing;

  @override
  State<_ExperienceDialog> createState() => _ExperienceDialogState();
}

class _ExperienceDialogState extends State<_ExperienceDialog> {
  late final TextEditingController _title;
  late final TextEditingController _organization;
  late final TextEditingController _started;
  late final TextEditingController _ended;
  late final TextEditingController _description;
  late bool _current;

  @override
  void initState() {
    super.initState();
    final existing = widget.existing;
    _title = TextEditingController(text: existing?.title ?? '');
    _organization = TextEditingController(
      text: existing?.organizationName ?? '',
    );
    _started = TextEditingController(text: existing?.startedOn ?? '');
    _ended = TextEditingController(text: existing?.endedOn ?? '');
    _description = TextEditingController(text: existing?.description ?? '');
    _current = existing?.isCurrent ?? false;
  }

  @override
  void dispose() {
    _title.dispose();
    _organization.dispose();
    _started.dispose();
    _ended.dispose();
    _description.dispose();
    super.dispose();
  }

  void _save() {
    Navigator.pop(
      context,
      WorkerExperienceInput(
        title: _title.text.trim(),
        organizationName: _organization.text.trim(),
        startedOn: _started.text.trim(),
        isCurrent: _current,
        endedOn: _ended.text.trim().isEmpty ? null : _ended.text.trim(),
        description: _description.text.trim().isEmpty
            ? null
            : _description.text.trim(),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: Text(widget.existing == null ? 'إضافة خبرة' : 'تعديل الخبرة'),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            WorkerTextField(
              key: const Key('worker-experience-title'),
              controller: _title,
              label: 'المسمى',
            ),
            const SizedBox(height: 8),
            WorkerTextField(controller: _organization, label: 'الجهة'),
            const SizedBox(height: 8),
            WorkerTextField(
              key: const Key('worker-experience-started'),
              controller: _started,
              label: 'تاريخ البداية',
            ),
            const SizedBox(height: 8),
            WorkerTextField(controller: _ended, label: 'تاريخ النهاية'),
            CheckboxListTile(
              contentPadding: EdgeInsets.zero,
              value: _current,
              title: const Text('ما زلت في هذه الخبرة'),
              onChanged: (value) => setState(() => _current = value ?? false),
            ),
            WorkerTextField(
              controller: _description,
              label: 'الوصف',
              maxLines: 3,
            ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: const Text('إلغاء'),
        ),
        FilledButton(
          key: const Key('worker-experience-save'),
          onPressed: _save,
          child: const Text('حفظ'),
        ),
      ],
    );
  }
}

Future<void> _deleteExperience(
  BuildContext context,
  WidgetRef ref,
  WorkerExperience experience,
) async {
  final confirmed = await showDialog<bool>(
    context: context,
    builder: (dialogContext) => AlertDialog(
      title: const Text('حذف الخبرة'),
      content: Text('حذف «${experience.title}»؟'),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(dialogContext, false),
          child: const Text('إلغاء'),
        ),
        TextButton(
          key: const Key('worker-experience-delete-confirm'),
          onPressed: () => Navigator.pop(dialogContext, true),
          child: const Text('حذف'),
        ),
      ],
    ),
  );
  if (confirmed != true || !context.mounted) return;
  final ok = await ref
      .read(workerAccountControllerProvider.notifier)
      .deleteExperience(experience.id);
  if (!context.mounted) return;
  if (ok) showWorkerSnack(context, 'تم حذف الخبرة');
}

String _experienceSubtitle(WorkerExperience experience) {
  final end = experience.isCurrent ? 'حتى الآن' : (experience.endedOn ?? '');
  return '${experience.organizationName} · ${experience.startedOn ?? ''} - $end';
}

String _formatDate(DateTime date) {
  final month = date.month.toString().padLeft(2, '0');
  final day = date.day.toString().padLeft(2, '0');
  return '${date.year}-$month-$day';
}
