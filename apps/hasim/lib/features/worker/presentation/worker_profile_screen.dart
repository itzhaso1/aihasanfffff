import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/config/app_config.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/hasim_page_header.dart';
import 'package:hasim/features/worker/data/worker_profile.dart';
import 'package:hasim/features/worker/presentation/worker_account_widgets.dart';
import 'package:hasim/features/worker/presentation/worker_scaffold.dart';
import 'package:hasim/features/worker/providers/worker_account_controller.dart';
import 'package:hasim/router/worker_nav.dart';

class WorkerProfileScreen extends ConsumerStatefulWidget {
  const WorkerProfileScreen({super.key, this.embedded = false});

  final bool embedded;

  @override
  ConsumerState<WorkerProfileScreen> createState() =>
      _WorkerProfileScreenState();
}

class _WorkerProfileScreenState extends ConsumerState<WorkerProfileScreen> {
  final _displayName = TextEditingController();
  WorkerUploadFile? _photoFile;
  String? _source;

  @override
  void dispose() {
    _displayName.dispose();
    super.dispose();
  }

  void _applyProfile(WorkerProfile profile) {
    final source = '${profile.displayName}|${profile.profilePhoto ?? ''}';
    if (source == _source) return;
    _source = source;
    _displayName.text = profile.displayName;
  }

  Future<void> _pickPhoto() async {
    final file = await ref.read(workerPhotoPickerProvider)();
    if (!mounted || file == null) return;
    setState(() => _photoFile = file);
  }

  Future<void> _save(WorkerProfile profile) async {
    final ok = await ref
        .read(workerAccountControllerProvider.notifier)
        .saveProfile(
          WorkerProfessionalUpdate(
            displayName: _displayName.text.trim(),
            countryCode: profile.countryCode,
            nationality: profile.nationality,
            professionalSummary: profile.professionalSummary,
            salesExperienceLevel: profile.salesExperienceLevel,
            availabilityStatus: profile.availabilityStatus,
          ),
          photoFile: _photoFile,
        );
    if (!mounted) return;
    if (ok) {
      setState(() {
        _photoFile = null;
        _source = null;
      });
      final updated = ref.read(workerAccountControllerProvider).profile;
      if (updated != null) _applyProfile(updated);
      showWorkerSnack(context, 'تم حفظ الملف الشخصي');
    }
  }

  void _back(BuildContext context) {
    final sidePane = WorkerSidePaneScope.maybeOf(context);
    if (sidePane != null) {
      sidePane.onBack();
      return;
    }
    if (context.canPop()) {
      context.pop();
      return;
    }
    context.go('/settings');
  }

  @override
  Widget build(BuildContext context) {
    ref.listen(workerAccountControllerProvider, (previous, next) {
      final profile = next.profile;
      if (profile != null && mounted) _applyProfile(profile);
    });

    final state = ref.watch(workerAccountControllerProvider);
    final profile = state.profile;
    if (profile != null) _applyProfile(profile);

    final body = SafeArea(
      child: ListView(
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 28),
        children: [
          HasimPageHeader(
            title: 'ملفي الشخصي',
            subtitle: 'تعديل الصورة واسم الحساب فقط',
            onBack: () => _back(context),
          ),
          const SizedBox(height: 16),
          if (state.profileLoading && profile == null)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 40),
              child: Center(child: CircularProgressIndicator()),
            )
          else if (profile == null)
            _WorkerProfileError(
              message: state.profileError ?? 'تعذر تحميل الملف الشخصي.',
              onRetry: () => ref
                  .read(workerAccountControllerProvider.notifier)
                  .loadProfile(force: true),
            )
          else
            _profileContent(context, state, profile),
        ],
      ),
    );

    if (widget.embedded) {
      return body;
    }

    return WorkerScaffold(
      selectedIndex: WorkerNav.accountIndex,
      showBottomNavigation: false,
      body: body,
    );
  }

  Widget _profileContent(
    BuildContext context,
    WorkerAccountState state,
    WorkerProfile profile,
  ) {
    final photoError = state.profileValidationErrors.forField('profile_photo');
    final nameError = state.profileValidationErrors.forField('display_name');

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        WorkerSectionCard(
          title: 'الصورة الشخصية',
          child: Column(
            children: [
              _WorkerProfileAvatar(
                name: profile.displayName,
                selectedFile: _photoFile,
                profilePhoto: profile.profilePhoto,
              ),
              const SizedBox(height: 12),
              OutlinedButton.icon(
                key: const Key('worker-profile-photo-pick'),
                onPressed: state.profileBusy ? null : _pickPhoto,
                icon: const Icon(Icons.camera_alt_outlined),
                label: Text(
                  _photoFile == null ? 'تغيير الصورة' : 'تم اختيار صورة جديدة',
                ),
              ),
              if (photoError != null)
                WorkerInlineMessage(photoError, error: true),
              if (state.photoProgress != null) ...[
                const SizedBox(height: 8),
                LinearProgressIndicator(value: state.photoProgress),
              ],
            ],
          ),
        ),
        const SizedBox(height: 12),
        WorkerSectionCard(
          title: 'اسم الحساب',
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              WorkerTextField(
                key: const Key('worker-profile-account-name'),
                controller: _displayName,
                label: 'الاسم',
                errorText: nameError,
              ),
              if (state.profileActionError != null)
                WorkerInlineMessage(state.profileActionError!, error: true),
              const SizedBox(height: 16),
              WorkerSaveButton(
                key: const Key('worker-profile-save'),
                label: 'حفظ التغييرات',
                busy: state.profileBusy,
                onPressed: () => _save(profile),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _WorkerProfileAvatar extends StatelessWidget {
  const _WorkerProfileAvatar({
    required this.name,
    required this.selectedFile,
    required this.profilePhoto,
  });

  final String name;
  final WorkerUploadFile? selectedFile;
  final String? profilePhoto;

  @override
  Widget build(BuildContext context) {
    final fallback = CircleAvatar(
      radius: 48,
      backgroundColor: HasimTokens.mintFill(context),
      child: Text(
        name.trim().isEmpty ? 'ع' : name.trim().characters.first,
        style: Theme.of(context).textTheme.headlineMedium?.copyWith(
          color: HasimTokens.primary,
          fontWeight: FontWeight.w800,
        ),
      ),
    );

    if (selectedFile != null) {
      return ClipOval(
        child: Image.memory(
          selectedFile!.bytes,
          width: 96,
          height: 96,
          fit: BoxFit.cover,
          errorBuilder: (_, _, _) => fallback,
        ),
      );
    }

    final url = _resolvePhotoUrl(profilePhoto);
    if (url == null) return fallback;
    return ClipOval(
      child: CachedNetworkImage(
        imageUrl: url,
        width: 96,
        height: 96,
        fit: BoxFit.cover,
        errorWidget: (_, _, _) => fallback,
      ),
    );
  }
}

class _WorkerProfileError extends StatelessWidget {
  const _WorkerProfileError({required this.message, required this.onRetry});

  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return WorkerSectionCard(
      title: 'ملفي الشخصي',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          WorkerInlineMessage(message, error: true),
          TextButton(onPressed: onRetry, child: const Text('إعادة المحاولة')),
        ],
      ),
    );
  }
}

String? _resolvePhotoUrl(String? value) {
  final raw = value?.trim() ?? '';
  if (raw.isEmpty) return null;
  if (raw.startsWith('http://') || raw.startsWith('https://')) return raw;
  final path = raw.startsWith('/') ? raw : '/$raw';
  return '${AppConfig.hostBase}$path';
}
