part of 'ai_settings_screen.dart';

class _RealWorkersSection extends ConsumerStatefulWidget {
  const _RealWorkersSection();

  @override
  ConsumerState<_RealWorkersSection> createState() =>
      _RealWorkersSectionState();
}

class _RealWorkersSectionState extends ConsumerState<_RealWorkersSection>
    with SingleTickerProviderStateMixin {
  final TextEditingController _search = TextEditingController();
  final TextEditingController _country = TextEditingController();
  final TextEditingController _availability = TextEditingController();
  final TextEditingController _experience = TextEditingController();
  late final TabController _sectionTabs;
  var _selectedSection = 0;
  String? _skillKey;

  @override
  void initState() {
    super.initState();
    _sectionTabs = TabController(length: 2, vsync: this);
  }

  @override
  void dispose() {
    _sectionTabs.dispose();
    _search.dispose();
    _country.dispose();
    _availability.dispose();
    _experience.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(companyWorkerDiscoveryControllerProvider);
    final controller = ref.read(
      companyWorkerDiscoveryControllerProvider.notifier,
    );

    final compact = HasimLayout.isCompact(context);
    final discoveryContent = Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (!compact) ...[
          _SectionCard(
            child: _WorkerDiscoveryFilters(
              searchController: _search,
              countryController: _country,
              availabilityController: _availability,
              experienceController: _experience,
              skillKey: _skillKey,
              skills: state.skills,
              onSkillChanged: (value) => setState(() => _skillKey = value),
              onApply: () => controller.applyFilters(
                search: _search.text,
                countryCode: _country.text,
                availabilityStatus: _availability.text,
                salesExperienceLevel: _experience.text,
                skillKey: _skillKey,
              ),
              onClear: () {
                _search.clear();
                _country.clear();
                _availability.clear();
                _experience.clear();
                setState(() => _skillKey = null);
                controller.clearFilters();
              },
            ),
          ),
          const SizedBox(height: 14),
        ],
        if (state.loading && state.workers.isEmpty)
          const Padding(
            padding: EdgeInsets.symmetric(vertical: 28),
            child: Center(child: CircularProgressIndicator()),
          )
        else if (state.error != null && state.workers.isEmpty)
          _WorkerDiscoveryError(
            message: state.error!,
            onRetry: controller.reload,
          )
        else if (state.workers.isEmpty)
          const _WorkerDiscoveryEmptyState()
        else ...[
          for (final worker in state.workers) ...[
            _WorkerDiscoveryCard(
              key: ValueKey('real-worker-${worker.id}'),
              worker: worker,
              onView: () async {
                await context.push('/company-worker-profile', extra: worker);
                await controller.reload();
              },
              onOffer: () => _openWorkerOfferSheet(context, ref, worker),
            ),
            const SizedBox(height: 12),
          ],
          if (state.hasMore)
            OutlinedButton.icon(
              onPressed: state.loadingMore ? null : controller.loadMore,
              icon: state.loadingMore
                  ? const SizedBox(
                      width: 16,
                      height: 16,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Icon(Icons.expand_more),
              label: Text(state.loadingMore ? 'جارٍ التحميل' : 'تحميل المزيد'),
            ),
        ],
      ],
    );

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 28),
      children: [
        const _WorkerDiscoveryHeader(),
        const SizedBox(height: 14),
        Directionality(
          textDirection: TextDirection.rtl,
          child: Material(
            color: HasimTokens.cardBg(context),
            child: TabBar(
              controller: _sectionTabs,
              onTap: (index) {
                if (_selectedSection != index) {
                  setState(() => _selectedSection = index);
                }
              },
              tabs: const [
                Tab(
                  key: ValueKey('company-worker-discovery-tab'),
                  text: 'الموظفون الحقيقيون',
                ),
                Tab(
                  key: ValueKey('company-worker-open-contracts-tab'),
                  text: 'العقود المفتوحة',
                ),
              ],
              labelPadding: const EdgeInsets.symmetric(horizontal: 6),
              labelColor: HasimTokens.inkColor(context),
              unselectedLabelColor: HasimTokens.mutedColor(context),
              indicatorColor: HasimTokens.primary,
              indicatorSize: TabBarIndicatorSize.tab,
              dividerColor: HasimTokens.lineColor(context),
            ),
          ),
        ),
        const SizedBox(height: 14),
        if (_selectedSection == 0)
          discoveryContent
        else
          _OpenContractsSection(
            engagements: state.openEngagements,
            loading: state.openEngagementsLoading,
            error: state.openEngagementsError,
            onRetry: controller.reloadOpenEngagements,
            onView: (engagement) async {
              await context.push(
                '/company-worker-profile',
                extra: engagement.workerProfileId,
              );
              await controller.reload();
            },
            onManageCapabilities: (engagement) => context.push(
              '$companyWorkerEngagementCapabilitiesLocation/${engagement.id}',
              extra: engagement,
            ),
          ),
      ],
    );
  }
}

class _OpenContractsSection extends StatelessWidget {
  const _OpenContractsSection({
    required this.engagements,
    required this.loading,
    required this.error,
    required this.onRetry,
    required this.onView,
    required this.onManageCapabilities,
  });

  final List<CompanyWorkerOpenEngagement> engagements;
  final bool loading;
  final String? error;
  final VoidCallback onRetry;
  final Future<void> Function(CompanyWorkerOpenEngagement engagement) onView;
  final void Function(CompanyWorkerOpenEngagement engagement)
  onManageCapabilities;

  @override
  Widget build(BuildContext context) {
    return _SectionCard(
      title: 'العقود المفتوحة',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            'العقود المقبولة أو النشطة أو المتوقفة مؤقتًا مع العمال المستقلين.',
            style: _aiMuted(context).copyWith(height: 1.5),
          ),
          const SizedBox(height: 12),
          if (loading)
            const Center(
              child: Padding(
                padding: EdgeInsets.symmetric(vertical: 18),
                child: CircularProgressIndicator(),
              ),
            )
          else if (error != null)
            Column(
              children: [
                Text(
                  error!,
                  textAlign: TextAlign.center,
                  style: _aiMuted(context).copyWith(color: HasimTokens.danger),
                ),
                const SizedBox(height: 8),
                TextButton.icon(
                  onPressed: onRetry,
                  icon: const Icon(Icons.refresh_rounded),
                  label: const Text('إعادة المحاولة'),
                ),
              ],
            )
          else if (engagements.isEmpty)
            Text(
              'لا توجد عقود مفتوحة حاليًا.',
              textAlign: TextAlign.center,
              style: _aiMuted(context),
            )
          else
            Column(
              children: [
                for (var index = 0; index < engagements.length; index++) ...[
                  _OpenContractCard(
                    engagement: engagements[index],
                    onView: () => onView(engagements[index]),
                    onManageCapabilities: () =>
                        onManageCapabilities(engagements[index]),
                  ),
                  if (index < engagements.length - 1)
                    const SizedBox(height: 10),
                ],
              ],
            ),
        ],
      ),
    );
  }
}

class _OpenContractCard extends StatelessWidget {
  const _OpenContractCard({
    required this.engagement,
    required this.onView,
    required this.onManageCapabilities,
  });

  final CompanyWorkerOpenEngagement engagement;
  final VoidCallback onView;
  final VoidCallback onManageCapabilities;

  @override
  Widget build(BuildContext context) {
    final photoUrl = _resolveWorkerPhotoUrl(engagement.profilePhoto);

    return DecoratedBox(
      decoration: BoxDecoration(
        color: HasimTokens.pageBg(context),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: HasimTokens.lineColor(context)),
      ),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _WorkerAvatar(
                  name: engagement.workerDisplayName,
                  photoUrl: photoUrl,
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        engagement.workerDisplayName,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: _aiTitle(context),
                      ),
                      const SizedBox(height: 3),
                      Text(
                        engagement.jobTitle,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: _aiMuted(context),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            Wrap(
              spacing: 7,
              runSpacing: 7,
              children: [
                _WorkerInfoPill(
                  icon: Icons.info_outline_rounded,
                  label: engagement.statusLabel,
                  highlighted: true,
                ),
              ],
            ),
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                _ContractDetail(
                  label: 'البداية',
                  value: engagement.startsAt ?? 'غير محدد',
                ),
                _ContractDetail(
                  label: 'المدة',
                  value: engagement.durationLabel,
                ),
                _ContractDetail(
                  label: 'النهاية',
                  value: engagement.endsAt ?? 'غير محدد',
                ),
              ],
            ),
            const SizedBox(height: 10),
            Wrap(
              spacing: 7,
              runSpacing: 7,
              children: [
                if (engagement.hasGuide)
                  const _WorkerInfoPill(
                    icon: Icons.menu_book_outlined,
                    label: 'دليل عمل',
                  ),
                _WorkerInfoPill(
                  icon: Icons.lock_outline_rounded,
                  label: '${engagement.grantedCapabilitiesCount} صلاحية',
                ),
                _WorkerInfoPill(
                  icon: Icons.inventory_2_outlined,
                  label: '${engagement.assignedProductsCount} منتج',
                ),
              ],
            ),
            const SizedBox(height: 12),
            Builder(
              builder: (context) {
                final compact = HasimLayout.isCompact(context);
                final detailsButtonStyle = compact
                    ? OutlinedButton.styleFrom(
                        minimumSize: const Size(0, 46),
                        padding: const EdgeInsets.symmetric(horizontal: 8),
                        textStyle: _aiTitle(context).copyWith(fontSize: 12),
                      )
                    : null;
                final detailsButton = OutlinedButton.icon(
                  onPressed: onView,
                  icon: const Icon(Icons.open_in_new_rounded, size: 18),
                  label: const Text('عرض تفاصيل التعاقد'),
                  style: detailsButtonStyle,
                );
                final capabilitiesButton = FilledButton.icon(
                  key: ValueKey('company-worker-capabilities-${engagement.id}'),
                  onPressed: onManageCapabilities,
                  icon: const Icon(
                    Icons.admin_panel_settings_outlined,
                    size: 18,
                  ),
                  label: const Text('إدارة الصلاحيات'),
                  style: compact
                      ? FilledButton.styleFrom(
                          minimumSize: const Size(0, 46),
                          padding: const EdgeInsets.symmetric(horizontal: 8),
                          textStyle: _aiTitle(
                            context,
                          ).copyWith(fontSize: 12, color: Colors.white),
                        )
                      : null,
                );

                if (compact) {
                  return Row(
                    children: [
                      Expanded(child: detailsButton),
                      const SizedBox(width: 8),
                      Expanded(child: capabilitiesButton),
                    ],
                  );
                }

                return Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [detailsButton, capabilitiesButton],
                );
              },
            ),
          ],
        ),
      ),
    );
  }
}

class _ContractDetail extends StatelessWidget {
  const _ContractDetail({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: HasimTokens.cardBg(context),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 7),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(label, style: _aiMuted(context).copyWith(fontSize: 10)),
            const SizedBox(height: 2),
            Text(
              value,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: _aiTitle(context).copyWith(fontSize: 11),
            ),
          ],
        ),
      ),
    );
  }
}

class _WorkerDiscoveryHeader extends StatelessWidget {
  const _WorkerDiscoveryHeader();

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        DecoratedBox(
          decoration: BoxDecoration(
            color: HasimTokens.mintFill(context),
            borderRadius: BorderRadius.circular(16),
          ),
          child: Padding(
            padding: const EdgeInsets.all(10),
            child: Icon(
              Icons.groups_2_outlined,
              color: HasimTokens.mutedColor(context),
            ),
          ),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'الموظفون الحقيقيون',
                style: _aiTitle(context).copyWith(fontSize: 18),
              ),
              const SizedBox(height: 4),
              Text(
                'اختر عامل مستقل موثوق وقابل للاكتشاف ثم افتح ملفه المهني وأرسل عرضًا.',
                style: _aiMuted(context).copyWith(height: 1.55),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _WorkerDiscoveryError extends StatelessWidget {
  const _WorkerDiscoveryError({required this.message, required this.onRetry});

  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return _SectionCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            message,
            style: _aiMuted(context).copyWith(color: HasimTokens.danger),
          ),
          const SizedBox(height: 8),
          TextButton(
            onPressed: onRetry,
            child: Text(AppLocalizations.of(context).retry),
          ),
        ],
      ),
    );
  }
}

class _WorkerDiscoveryEmptyState extends StatelessWidget {
  const _WorkerDiscoveryEmptyState();

  @override
  Widget build(BuildContext context) {
    return _SectionCard(
      child: Column(
        children: [
          Icon(
            Icons.person_search_outlined,
            size: 40,
            color: HasimTokens.mutedColor(context),
          ),
          const SizedBox(height: 10),
          Text(
            'لا يوجد موظفون متاحون حاليًا.',
            style: _aiTitle(context),
            textAlign: TextAlign.center,
          ),
          const SizedBox(height: 4),
          Text(
            'جرّب تعديل البحث أو الفلاتر للعثور على عامل مناسب.',
            style: _aiMuted(context),
            textAlign: TextAlign.center,
          ),
        ],
      ),
    );
  }
}

Future<void> _openWorkerOfferSheet(
  BuildContext context,
  WidgetRef ref,
  CompanyWorkerDiscoveryWorker worker,
) {
  final messenger = ScaffoldMessenger.of(context);
  return showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    backgroundColor: HasimLayout.isCompact(context)
        ? Colors.transparent
        : HasimTokens.pageBg(context),
    shape: RoundedRectangleBorder(
      borderRadius: HasimLayout.modalSheetRadius(context),
    ),
    builder: (_) => _WorkerOfferSheet(worker: worker, messenger: messenger),
  );
}

Widget _offerSheetHandle(BuildContext context) {
  return Padding(
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
  );
}

class _WorkerOfferSheet extends ConsumerStatefulWidget {
  const _WorkerOfferSheet({required this.worker, required this.messenger});

  final CompanyWorkerDiscoveryWorker worker;
  final ScaffoldMessengerState messenger;

  @override
  ConsumerState<_WorkerOfferSheet> createState() => _WorkerOfferSheetState();
}

class _WorkerOfferSheetState extends ConsumerState<_WorkerOfferSheet> {
  final _formKey = GlobalKey<FormState>();
  final _jobTitle = TextEditingController();
  final _jobDescription = TextEditingController();
  final _startsAt = TextEditingController();
  final _durationValue = TextEditingController(text: '1');
  final _productSearch = TextEditingController();
  final _guideCompanyOverview = TextEditingController();
  final _guideProductsContext = TextEditingController();
  final _guideResponsibilities = TextEditingController();
  final _guideWorkflowSteps = TextEditingController();
  final _guideImportantRules = TextEditingController();
  final _guideCustomerGuidance = TextEditingController();
  final _guideEscalationRules = TextEditingController();
  final _guideSchedule = TextEditingController();
  final _guideNotes = TextEditingController();
  final Map<int, _SelectedWorkerOfferProduct> _selectedProducts = {};
  final List<_WorkerGuideFaqDraft> _guideFaqs = [];
  List<ChatCatalogItemModel> _products = const [];
  String _durationPreset = 'day';
  String _durationUnit = 'day';
  String? _productsError;
  String? _productValidationError;
  CompanyWorkerContractPrice? _contractPrice;
  String? _contractPriceError;
  var _productsLoading = false;
  var _contractPriceLoading = false;
  var _contractPriceRequest = 0;
  var _saving = false;

  @override
  void initState() {
    super.initState();
    unawaited(_loadProducts());
    unawaited(_loadContractPrice());
  }

  @override
  void dispose() {
    _jobTitle.dispose();
    _jobDescription.dispose();
    _startsAt.dispose();
    _durationValue.dispose();
    _productSearch.dispose();
    _guideCompanyOverview.dispose();
    _guideProductsContext.dispose();
    _guideResponsibilities.dispose();
    _guideWorkflowSteps.dispose();
    _guideImportantRules.dispose();
    _guideCustomerGuidance.dispose();
    _guideEscalationRules.dispose();
    _guideSchedule.dispose();
    _guideNotes.dispose();
    for (final product in _selectedProducts.values) {
      product.dispose();
    }
    for (final faq in _guideFaqs) {
      faq.dispose();
    }
    super.dispose();
  }

  Future<void> _loadProducts() async {
    if (_productsLoading) {
      return;
    }
    setState(() {
      _productsLoading = true;
      _productsError = null;
    });
    try {
      final page = await ref
          .read(chatCatalogRepositoryProvider)
          .list(
            search: _productSearch.text.trim().isEmpty
                ? null
                : _productSearch.text.trim(),
            activeOnly: true,
            perPage: 50,
          );
      if (!mounted) {
        return;
      }
      setState(() {
        _products = page.items;
        _productsLoading = false;
      });
    } catch (_) {
      if (!mounted) {
        return;
      }
      setState(() {
        _productsLoading = false;
        _productsError = 'تعذر تحميل منتجات الشركة.';
      });
    }
  }

  Future<void> _loadContractPrice() async {
    final durationValue = _selectedDurationValue;
    final durationUnit = _selectedDurationUnit;
    if (durationValue == null || durationUnit == null) {
      setState(() {
        _contractPrice = null;
        _contractPriceError = null;
        _contractPriceLoading = false;
      });
      return;
    }

    final requestId = ++_contractPriceRequest;
    setState(() {
      _contractPriceLoading = true;
      _contractPriceError = null;
    });
    try {
      final price = await ref
          .read(companyWorkerDiscoveryRepositoryProvider)
          .fetchContractPrice(
            widget.worker.id,
            durationValue: durationValue,
            durationUnit: durationUnit,
          );
      if (!mounted || requestId != _contractPriceRequest) return;
      setState(() {
        _contractPrice = price;
        _contractPriceLoading = false;
      });
    } on ApiException catch (error) {
      if (!mounted || requestId != _contractPriceRequest) return;
      setState(() {
        _contractPrice = null;
        _contractPriceError = error.userMessage;
        _contractPriceLoading = false;
      });
    } catch (_) {
      if (!mounted || requestId != _contractPriceRequest) return;
      setState(() {
        _contractPrice = null;
        _contractPriceError = 'لا يوجد سعر منصة لهذه المدة.';
        _contractPriceLoading = false;
      });
    }
  }

  void _toggleProduct(ChatCatalogItemModel product, bool selected) {
    setState(() {
      if (selected) {
        _selectedProducts.putIfAbsent(
          product.id,
          () => _SelectedWorkerOfferProduct(product),
        );
      } else {
        _selectedProducts.remove(product.id)?.dispose();
      }
      _productValidationError = null;
    });
  }

  String? _validateSelectedProducts() {
    for (final selected in _selectedProducts.values) {
      final minimum = _parsePrice(selected.minimumController.text);
      final maximum = _parsePrice(selected.maximumController.text);
      if (minimum == null &&
          selected.minimumController.text.trim().isNotEmpty) {
        return 'أدخل حدًا أدنى صالحًا للمنتج ${selected.product.name}.';
      }
      if (maximum == null &&
          selected.maximumController.text.trim().isNotEmpty) {
        return 'أدخل حدًا أعلى صالحًا للمنتج ${selected.product.name}.';
      }
      if (minimum != null && maximum != null && minimum > maximum) {
        return 'يجب ألا يكون الحد الأدنى أكبر من الحد الأعلى للمنتج ${selected.product.name}.';
      }
    }
    return null;
  }

  Future<void> _submit() async {
    if (!(_formKey.currentState?.validate() ?? false) || _saving) {
      return;
    }
    final productValidationError = _validateSelectedProducts();
    if (productValidationError != null) {
      setState(() => _productValidationError = productValidationError);
      return;
    }
    setState(() => _saving = true);
    final ok = await ref
        .read(companyWorkerDiscoveryControllerProvider.notifier)
        .sendOffer(
          widget.worker,
          jobTitle: _jobTitle.text,
          jobDescription: _jobDescription.text,
          startsAt: _startsAt.text,
          durationValue: _selectedDurationValue!,
          durationUnit: _selectedDurationUnit!,
          guide: _buildGuide(),
          products: [
            for (final selected in _selectedProducts.values)
              CompanyWorkerOfferProduct(
                id: selected.product.id,
                minimumSellingPrice: _nullableText(
                  selected.minimumController.text,
                ),
                maximumSellingPrice: _nullableText(
                  selected.maximumController.text,
                ),
              ),
          ],
        );
    if (!mounted) {
      return;
    }
    if (ok) {
      Navigator.of(context).pop();
      widget.messenger.showSnackBar(
        const SnackBar(content: Text('تم إرسال العرض')),
      );
      return;
    }
    final error = ref.read(companyWorkerDiscoveryControllerProvider).error;
    setState(() => _saving = false);
    if (error != null) {
      widget.messenger.showSnackBar(SnackBar(content: Text(error)));
    }
  }

  WorkerOfferDuration? get _presetDuration =>
      WorkerOfferDuration.fromPreset(_durationPreset);

  int? get _selectedDurationValue {
    final preset = _presetDuration;
    if (preset != null) return preset.value;

    return int.tryParse(_durationValue.text.trim());
  }

  String? get _selectedDurationUnit => _presetDuration?.unit ?? _durationUnit;

  String? get _calculatedEndDate {
    final value = _selectedDurationValue;
    final unit = _selectedDurationUnit;
    if (value == null || unit == null) return null;

    final endDate = calculateWorkerOfferEndDate(
      startsAt: _startsAt.text.trim(),
      value: value,
      unit: unit,
    );

    return endDate == null ? null : formatWorkerOfferDate(endDate);
  }

  void _selectDurationPreset(String? value) {
    if (value == null) return;
    final preset = WorkerOfferDuration.fromPreset(value);

    setState(() {
      _durationPreset = value;
      if (preset != null) {
        _durationValue.text = preset.value.toString();
        _durationUnit = preset.unit;
      } else {
        _durationValue.text = '1';
        _durationUnit = 'day';
      }
    });
    unawaited(_loadContractPrice());
  }

  CompanyWorkerOfferGuide? _buildGuide() {
    final guide = CompanyWorkerOfferGuide(
      companyOverview: _nullableText(_guideCompanyOverview.text),
      productsContext: _nullableText(_guideProductsContext.text),
      responsibilities: _guideLines(_guideResponsibilities),
      workflowSteps: _guideLines(_guideWorkflowSteps),
      importantRules: _guideLines(_guideImportantRules),
      customerGuidance: _guideLines(_guideCustomerGuidance),
      escalationRules: _guideLines(_guideEscalationRules),
      workScheduleNotes: _nullableText(_guideSchedule.text),
      importantNotes: _nullableText(_guideNotes.text),
      faqItems: [
        for (final faq in _guideFaqs)
          if (faq.question.text.trim().isNotEmpty ||
              faq.answer.text.trim().isNotEmpty)
            CompanyWorkerOfferGuideFaq(
              question: faq.question.text,
              answer: faq.answer.text,
            ),
      ],
    );

    return guide.isEmpty ? null : guide;
  }

  List<String> _guideLines(TextEditingController controller) {
    return controller.text
        .split(RegExp(r'\r?\n'))
        .map((line) => line.trim())
        .where((line) => line.isNotEmpty)
        .toList();
  }

  Widget _guideField({
    Key? key,
    required String label,
    required TextEditingController controller,
    int maxLines = 3,
    int? maxLength,
    String? hint,
  }) {
    return TextField(
      key: key,
      controller: controller,
      maxLines: maxLines,
      maxLength: maxLength,
      decoration: InputDecoration(
        labelText: label,
        hintText: hint,
        alignLabelWithHint: maxLines > 1,
      ),
    );
  }

  Widget _guideEditor(BuildContext context) {
    return Directionality(
      textDirection: TextDirection.rtl,
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: HasimTokens.pageBg(context),
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: HasimTokens.lineColor(context)),
        ),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text('دليل العمل', style: _aiTitle(context)),
              const SizedBox(height: 4),
              Text(
                'أضف إرشادات تشغيلية خاصة بهذا العامل وهذا العرض. اترك الأقسام الاختيارية فارغة عند الحاجة.',
                style: _aiMuted(context),
              ),
              const SizedBox(height: 12),
              _guideField(
                key: const ValueKey('worker-offer-guide-company-overview'),
                label: 'عن الشركة',
                controller: _guideCompanyOverview,
                maxLength: 2000,
              ),
              _guideField(
                key: const ValueKey('worker-offer-guide-products-context'),
                label: 'ماذا نبيع؟',
                controller: _guideProductsContext,
                maxLength: 2000,
              ),
              _guideField(
                key: const ValueKey('worker-offer-guide-responsibilities'),
                label: 'المهام والمسؤوليات',
                controller: _guideResponsibilities,
                maxLines: 4,
                maxLength: 3600,
                hint: 'مهمة في كل سطر',
              ),
              _guideField(
                key: const ValueKey('worker-offer-guide-workflow-steps'),
                label: 'طريقة العمل',
                controller: _guideWorkflowSteps,
                maxLines: 4,
                maxLength: 3600,
                hint: 'خطوة في كل سطر',
              ),
              _guideField(
                key: const ValueKey('worker-offer-guide-important-rules'),
                label: 'قواعد وتعليمات مهمة',
                controller: _guideImportantRules,
                maxLines: 4,
                maxLength: 3600,
                hint: 'قاعدة في كل سطر',
              ),
              _guideField(
                key: const ValueKey('worker-offer-guide-customer-guidance'),
                label: 'التعامل مع العملاء',
                controller: _guideCustomerGuidance,
                maxLines: 4,
                maxLength: 3600,
                hint: 'إرشاد في كل سطر',
              ),
              _guideField(
                key: const ValueKey('worker-offer-guide-escalation-rules'),
                label: 'متى أتواصل مع المسؤول؟',
                controller: _guideEscalationRules,
                maxLines: 3,
                maxLength: 3600,
                hint: 'حالة في كل سطر',
              ),
              _guideField(
                key: const ValueKey('worker-offer-guide-schedule'),
                label: 'أوقات ونظام العمل',
                controller: _guideSchedule,
                maxLength: 1000,
              ),
              _guideField(
                key: const ValueKey('worker-offer-guide-notes'),
                label: 'ملاحظات مهمة',
                controller: _guideNotes,
                maxLength: 2000,
              ),
              const SizedBox(height: 4),
              Text(
                'أسئلة شائعة / معلومات سريعة',
                style: Theme.of(
                  context,
                ).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w800),
              ),
              for (var index = 0; index < _guideFaqs.length; index++)
                _guideFaqEditor(context, index, _guideFaqs[index]),
              Align(
                alignment: AlignmentDirectional.centerStart,
                child: TextButton.icon(
                  onPressed: _guideFaqs.length >= 12
                      ? null
                      : () => setState(
                          () => _guideFaqs.add(_WorkerGuideFaqDraft()),
                        ),
                  icon: const Icon(Icons.add),
                  label: const Text('إضافة سؤال'),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _guideFaqEditor(
    BuildContext context,
    int index,
    _WorkerGuideFaqDraft faq,
  ) {
    return Padding(
      padding: const EdgeInsets.only(top: 10),
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: HasimTokens.cardBg(context),
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: HasimTokens.lineColor(context)),
        ),
        child: Padding(
          padding: const EdgeInsets.all(10),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Align(
                alignment: AlignmentDirectional.centerStart,
                child: IconButton(
                  tooltip: 'حذف السؤال',
                  onPressed: () => setState(() {
                    _guideFaqs.removeAt(index).dispose();
                  }),
                  icon: const Icon(Icons.delete_outline),
                ),
              ),
              TextField(
                key: ValueKey('worker-offer-guide-faq-question-$index'),
                controller: faq.question,
                maxLength: 300,
                decoration: const InputDecoration(labelText: 'السؤال'),
              ),
              TextField(
                key: ValueKey('worker-offer-guide-faq-answer-$index'),
                controller: faq.answer,
                maxLines: 2,
                maxLength: 1000,
                decoration: const InputDecoration(
                  labelText: 'الإجابة',
                  alignLabelWithHint: true,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: HasimLayout.modalSheetMargin(context),
      child: SizedBox(
        height: HasimLayout.modalSheetHeight(context),
        width: double.infinity,
        child: Material(
          key: const ValueKey('worker-offer-sheet'),
          color: HasimTokens.pageBg(context),
          borderRadius: HasimLayout.modalSheetRadius(context),
          clipBehavior: Clip.antiAlias,
          child: Column(
            children: [
              _offerSheetHandle(context),
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
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            'إرسال عرض إلى ${widget.worker.displayName}',
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                            style: _aiTitle(context).copyWith(
                              fontSize: 16,
                              fontWeight: FontWeight.w800,
                            ),
                          ),
                          const SizedBox(height: 4),
                          Text(
                            'حدد مدة التعاقد ودليل العمل والمنتجات ثم أرسل العرض.',
                            style: _aiMuted(context).copyWith(height: 1.45),
                          ),
                        ],
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
                  key: _formKey,
                  child: ListView(
                    padding: EdgeInsets.fromLTRB(
                      HasimTokens.spaceMd,
                      HasimTokens.spaceMd,
                      HasimTokens.spaceMd,
                      HasimTokens.spaceLg +
                          MediaQuery.viewInsetsOf(context).bottom,
                    ),
                    children: [
                      TextField(
                        controller: _jobDescription,
                        maxLines: 4,
                        maxLength: 5000,
                        decoration: const InputDecoration(
                          labelText: 'وصف العمل',
                          hintText: 'اشرح للعامل المطلوب منه وما يجب إنجازه',
                          alignLabelWithHint: true,
                        ),
                      ),
                      const SizedBox(height: HasimTokens.spaceMd),
                      TextFormField(
                        controller: _jobTitle,
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
                        key: const ValueKey('worker-offer-starts-at'),
                        controller: _startsAt,
                        decoration: const InputDecoration(
                          labelText: 'تاريخ البداية',
                        ),
                        keyboardType: TextInputType.datetime,
                        onChanged: (_) => setState(() {}),
                        validator: (value) {
                          if (value == null || value.trim().isEmpty) {
                            return 'أدخل تاريخ البداية';
                          }
                          if (calculateWorkerOfferEndDate(
                                startsAt: value.trim(),
                                value: _selectedDurationValue ?? 0,
                                unit: _selectedDurationUnit ?? '',
                              ) ==
                              null) {
                            return 'أدخل تاريخ بداية صالحاً';
                          }
                          return null;
                        },
                      ),
                      const SizedBox(height: HasimTokens.spaceMd),
                      DropdownButtonFormField<String>(
                        key: const ValueKey('worker-offer-duration-preset'),
                        initialValue: _durationPreset,
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
                        onChanged: _selectDurationPreset,
                      ),
                      if (_durationPreset == 'custom') ...[
                        const SizedBox(height: HasimTokens.spaceMd),
                        TextFormField(
                          key: const ValueKey('worker-offer-duration-value'),
                          controller: _durationValue,
                          keyboardType: TextInputType.number,
                          decoration: const InputDecoration(
                            labelText: 'قيمة المدة',
                          ),
                          validator: (value) {
                            final parsed = int.tryParse(value?.trim() ?? '');
                            return parsed == null || parsed < 1
                                ? 'أدخل رقماً صحيحاً أكبر من صفر'
                                : null;
                          },
                          onChanged: (_) {
                            setState(() {});
                            unawaited(_loadContractPrice());
                          },
                        ),
                        const SizedBox(height: HasimTokens.spaceMd),
                        DropdownButtonFormField<String>(
                          key: const ValueKey('worker-offer-duration-unit'),
                          initialValue: _durationUnit,
                          decoration: const InputDecoration(
                            labelText: 'وحدة المدة',
                          ),
                          items: const [
                            DropdownMenuItem(value: 'day', child: Text('يوم')),
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
                            setState(() {
                              _durationUnit = value;
                            });
                            unawaited(_loadContractPrice());
                          },
                        ),
                      ],
                      const SizedBox(height: HasimTokens.spaceSm),
                      AnimatedBuilder(
                        animation: Listenable.merge([
                          _startsAt,
                          _durationValue,
                        ]),
                        builder: (context, _) {
                          final calculatedEnd = _calculatedEndDate;

                          return Text(
                            calculatedEnd == null
                                ? 'ستظهر النهاية المحسوبة بعد إدخال تاريخ البداية والمدة.'
                                : 'النهاية المحسوبة: $calculatedEnd',
                            key: const ValueKey('worker-offer-calculated-end'),
                            style: _aiMuted(context),
                          );
                        },
                      ),
                      const SizedBox(height: 4),
                      Text(
                        'يحسِب الخادم تاريخ النهاية النهائي ويحفظه في التعاقد.',
                        style: _aiMuted(context),
                      ),
                      const SizedBox(height: HasimTokens.spaceSm),
                      DecoratedBox(
                        decoration: BoxDecoration(
                          color: HasimTokens.mintFill(context),
                          borderRadius: BorderRadius.circular(
                            HasimTokens.radiusMd,
                          ),
                        ),
                        child: Padding(
                          padding: const EdgeInsets.all(HasimTokens.spaceMd),
                          child: Text(
                            _contractPriceLoading
                                ? 'جارٍ تحميل سعر التعاقد...'
                                : (_contractPrice == null
                                      ? (_contractPriceError ??
                                            'سيظهر سعر التعاقد بعد اختيار المدة.')
                                      : 'سعر التعاقد: ${_contractPrice!.amount}'),
                            key: const ValueKey('worker-offer-contract-price'),
                            style: _aiTitle(context),
                          ),
                        ),
                      ),
                      const SizedBox(height: HasimTokens.spaceMd),
                      _guideEditor(context),
                      const SizedBox(height: HasimTokens.spaceMd),
                      _WorkerOfferProductsSelector(
                        products: _products,
                        selectedProducts: _selectedProducts,
                        loading: _productsLoading,
                        error: _productsError,
                        validationError: _productValidationError,
                        searchController: _productSearch,
                        onSearch: () => unawaited(_loadProducts()),
                        onToggle: _toggleProduct,
                        onProductChanged: () {
                          if (_productValidationError != null) {
                            setState(() => _productValidationError = null);
                          }
                        },
                      ),
                    ],
                  ),
                ),
              ),
              DecoratedBox(
                decoration: BoxDecoration(
                  color: HasimTokens.cardBg(context),
                  border: Border(
                    top: BorderSide(color: HasimTokens.lineColor(context)),
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
                        key: const ValueKey('worker-offer-submit'),
                        onPressed: _saving ? null : _submit,
                        child: Text(_saving ? 'جارٍ الإرسال' : 'إرسال العرض'),
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
  }
}

class _WorkerGuideFaqDraft {
  final question = TextEditingController();
  final answer = TextEditingController();

  void dispose() {
    question.dispose();
    answer.dispose();
  }
}

class _SelectedWorkerOfferProduct {
  _SelectedWorkerOfferProduct(this.product);

  final ChatCatalogItemModel product;
  final minimumController = TextEditingController();
  final maximumController = TextEditingController();

  void dispose() {
    minimumController.dispose();
    maximumController.dispose();
  }
}

class _WorkerOfferProductsSelector extends StatelessWidget {
  const _WorkerOfferProductsSelector({
    required this.products,
    required this.selectedProducts,
    required this.loading,
    required this.error,
    required this.validationError,
    required this.searchController,
    required this.onSearch,
    required this.onToggle,
    required this.onProductChanged,
  });

  final List<ChatCatalogItemModel> products;
  final Map<int, _SelectedWorkerOfferProduct> selectedProducts;
  final bool loading;
  final String? error;
  final String? validationError;
  final TextEditingController searchController;
  final VoidCallback onSearch;
  final void Function(ChatCatalogItemModel product, bool selected) onToggle;
  final VoidCallback onProductChanged;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: HasimTokens.pageBg(context),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: HasimTokens.lineColor(context)),
      ),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('المنتجات لهذا العمل', style: _aiTitle(context)),
            const SizedBox(height: 4),
            Text(
              'حدد المنتجات التي سيعمل عليها العامل. يمكنك ضبط حدود البيع لكل منتج.',
              style: _aiMuted(context),
            ),
            const SizedBox(height: 10),
            Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: searchController,
                    textAlign: TextAlign.right,
                    onSubmitted: (_) => onSearch(),
                    decoration: const InputDecoration(
                      labelText: 'ابحث في منتجات الشركة',
                      prefixIcon: Icon(Icons.search_rounded),
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                IconButton(
                  tooltip: 'بحث',
                  onPressed: loading ? null : onSearch,
                  icon: const Icon(Icons.arrow_forward_rounded),
                ),
              ],
            ),
            if (loading) ...[
              const SizedBox(height: 12),
              const LinearProgressIndicator(minHeight: 2),
            ],
            if (error != null) ...[
              const SizedBox(height: 10),
              Text(
                error!,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            ],
            if (!loading && error == null && products.isEmpty) ...[
              const SizedBox(height: 12),
              Text(
                'لا توجد منتجات منشورة متاحة حاليًا.',
                style: _aiMuted(context),
                textAlign: TextAlign.center,
              ),
            ],
            if (products.isNotEmpty) ...[
              const SizedBox(height: 8),
              ConstrainedBox(
                constraints: const BoxConstraints(maxHeight: 220),
                child: ListView.separated(
                  shrinkWrap: true,
                  itemCount: products.length,
                  separatorBuilder: (_, _) => const SizedBox(height: 4),
                  itemBuilder: (context, index) {
                    final product = products[index];
                    return Material(
                      color: Colors.transparent,
                      child: CheckboxListTile(
                        value: selectedProducts.containsKey(product.id),
                        onChanged: (value) => onToggle(product, value == true),
                        contentPadding: EdgeInsets.zero,
                        dense: true,
                        controlAffinity: ListTileControlAffinity.leading,
                        title: Text(
                          product.name,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                        subtitle: Text(
                          'السعر الأساسي: ${_offerPrice(product.price, product.currency)}'
                          ' · الحد الأدنى: ${_offerPrice(product.workerMinimumPrice, product.currency)}',
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                        ),
                        secondary: _OfferProductThumbnail(
                          url: product.imageUrl,
                        ),
                      ),
                    );
                  },
                ),
              ),
            ],
            if (selectedProducts.isNotEmpty) ...[
              const SizedBox(height: 12),
              Text('المنتجات المحددة', style: _aiTitle(context)),
              const SizedBox(height: 8),
              for (final selected in selectedProducts.values) ...[
                _SelectedWorkerOfferProductCard(
                  selected: selected,
                  onRemove: () => onToggle(selected.product, false),
                  onChanged: onProductChanged,
                ),
                if (selected != selectedProducts.values.last)
                  const SizedBox(height: 8),
              ],
            ],
            if (validationError != null) ...[
              const SizedBox(height: 8),
              Text(
                validationError!,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _SelectedWorkerOfferProductCard extends StatelessWidget {
  const _SelectedWorkerOfferProductCard({
    required this.selected,
    required this.onRemove,
    required this.onChanged,
  });

  final _SelectedWorkerOfferProduct selected;
  final VoidCallback onRemove;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context) {
    final product = selected.product;
    return DecoratedBox(
      decoration: BoxDecoration(
        color: HasimTokens.cardBg(context),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: HasimTokens.lineColor(context)),
      ),
      child: Padding(
        padding: const EdgeInsets.all(10),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    product.name,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: _aiTitle(context),
                  ),
                ),
                IconButton(
                  tooltip: 'إزالة المنتج',
                  onPressed: onRemove,
                  icon: const Icon(Icons.close_rounded),
                ),
              ],
            ),
            Text(
              'السعر الأساسي: ${_offerPrice(product.price, product.currency)}',
              style: _aiMuted(context),
            ),
            const SizedBox(height: 8),
            Row(
              children: [
                Expanded(
                  child: TextFormField(
                    key: ValueKey('worker-offer-product-min-${product.id}'),
                    controller: selected.minimumController,
                    onChanged: (_) => onChanged(),
                    keyboardType: const TextInputType.numberWithOptions(
                      decimal: true,
                    ),
                    decoration: const InputDecoration(
                      labelText: 'الحد الأدنى لهذا العمل',
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: TextFormField(
                    key: ValueKey('worker-offer-product-max-${product.id}'),
                    controller: selected.maximumController,
                    onChanged: (_) => onChanged(),
                    keyboardType: const TextInputType.numberWithOptions(
                      decimal: true,
                    ),
                    decoration: const InputDecoration(
                      labelText: 'الحد الأعلى لهذا العمل',
                    ),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _OfferProductThumbnail extends StatelessWidget {
  const _OfferProductThumbnail({required this.url});

  final String? url;

  @override
  Widget build(BuildContext context) {
    final raw = url?.trim() ?? '';
    if (raw.isEmpty) {
      return const CircleAvatar(
        radius: 18,
        child: Icon(Icons.inventory_2_outlined, size: 18),
      );
    }
    final imageUrl = raw.startsWith('http://') || raw.startsWith('https://')
        ? raw
        : '${AppConfig.hostBase}${raw.startsWith('/') ? raw : '/$raw'}';
    return ClipRRect(
      borderRadius: BorderRadius.circular(10),
      child: CachedNetworkImage(
        imageUrl: imageUrl,
        width: 36,
        height: 36,
        fit: BoxFit.cover,
        errorWidget: (_, _, _) => const CircleAvatar(
          radius: 18,
          child: Icon(Icons.inventory_2_outlined, size: 18),
        ),
      ),
    );
  }
}

String? _nullableText(String value) {
  final text = value.trim();
  return text.isEmpty ? null : text;
}

num? _parsePrice(String value) {
  final text = value.trim();
  if (text.isEmpty) {
    return null;
  }
  final parsed = num.tryParse(text);
  if (parsed == null || parsed.isNaN || parsed.isInfinite || parsed < 0) {
    return null;
  }
  return parsed;
}

String _offerPrice(num? price, String currency) {
  if (price == null) {
    return 'غير محدد';
  }
  return '${price.toStringAsFixed(2)} ${currency.trim()}'.trim();
}

class _WorkerDiscoveryFilters extends StatelessWidget {
  const _WorkerDiscoveryFilters({
    required this.searchController,
    required this.countryController,
    required this.availabilityController,
    required this.experienceController,
    required this.skillKey,
    required this.skills,
    required this.onSkillChanged,
    required this.onApply,
    required this.onClear,
  });

  final TextEditingController searchController;
  final TextEditingController countryController;
  final TextEditingController availabilityController;
  final TextEditingController experienceController;
  final String? skillKey;
  final List<CompanyWorkerDiscoverySkill> skills;
  final ValueChanged<String?> onSkillChanged;
  final VoidCallback onApply;
  final VoidCallback onClear;

  @override
  Widget build(BuildContext context) {
    final selectedSkill = skills.any((skill) => skill.skillKey == skillKey)
        ? skillKey
        : null;

    return Directionality(
      textDirection: TextDirection.rtl,
      child: LayoutBuilder(
        builder: (context, constraints) {
          final stacked =
              HasimLayout.isCompact(context) || constraints.maxWidth < 600;
          final skillField = _DiscoveryFilterItem(
            width: stacked ? null : 190,
            expand: stacked,
            icon: Icons.auto_awesome_outlined,
            child: DropdownButtonFormField<String?>(
              initialValue: selectedSkill,
              isExpanded: true,
              alignment: AlignmentDirectional.centerEnd,
              decoration: const InputDecoration(labelText: 'كل المهارات'),
              items: [
                const DropdownMenuItem<String?>(
                  value: null,
                  child: Text('كل المهارات'),
                ),
                for (final skill in skills)
                  DropdownMenuItem<String?>(
                    value: skill.skillKey,
                    child: Text(
                      skill.displayName,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
              ],
              onChanged: onSkillChanged,
            ),
          );
          final fields = [
            _DiscoveryFilterItem(
              width: stacked ? null : 300,
              expand: stacked,
              icon: Icons.search_rounded,
              child: TextField(
                controller: searchController,
                textAlign: TextAlign.right,
                textInputAction: TextInputAction.search,
                decoration: const InputDecoration(
                  labelText: 'ابحث عن اسم الموظف أو المهارة...',
                ),
              ),
            ),
            skillField,
            _DiscoveryFilterItem(
              width: stacked ? null : 150,
              expand: stacked,
              icon: Icons.location_on_outlined,
              child: TextField(
                controller: countryController,
                textAlign: TextAlign.right,
                decoration: const InputDecoration(labelText: 'البلد'),
              ),
            ),
            _DiscoveryFilterItem(
              width: stacked ? null : 170,
              expand: stacked,
              icon: Icons.schedule_outlined,
              child: TextField(
                controller: availabilityController,
                textAlign: TextAlign.right,
                decoration: const InputDecoration(labelText: 'حالة التوفر'),
              ),
            ),
            _DiscoveryFilterItem(
              width: stacked ? null : 170,
              expand: stacked,
              icon: Icons.bar_chart_outlined,
              child: TextField(
                controller: experienceController,
                textAlign: TextAlign.right,
                decoration: const InputDecoration(labelText: 'مستوى الخبرة'),
              ),
            ),
          ];
          final apply = _DiscoveryFilterAction(
            label: 'تطبيق',
            icon: Icons.filter_alt_outlined,
            onPressed: onApply,
            primary: true,
            expand: stacked,
          );
          final clear = _DiscoveryFilterAction(
            label: 'مسح',
            icon: Icons.restart_alt_rounded,
            onPressed: onClear,
            primary: false,
            expand: stacked,
          );
          if (stacked) {
            return Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                for (final field in fields) ...[
                  field,
                  const SizedBox(height: 8),
                ],
                Row(
                  children: [
                    Expanded(child: apply),
                    const SizedBox(width: 8),
                    Expanded(child: clear),
                  ],
                ),
              ],
            );
          }
          final children = [...fields, apply, clear];
          final wide = constraints.maxWidth >= 900;
          final content = Wrap(
            spacing: 8,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: children,
          );
          return wide
              ? content
              : SingleChildScrollView(
                  scrollDirection: Axis.horizontal,
                  child: Row(
                    children: [
                      for (var index = 0; index < children.length; index++) ...[
                        children[index],
                        if (index < children.length - 1)
                          const SizedBox(width: 8),
                      ],
                    ],
                  ),
                );
        },
      ),
    );
  }
}

class _DiscoveryFilterItem extends StatelessWidget {
  const _DiscoveryFilterItem({
    this.width,
    this.expand = false,
    required this.icon,
    required this.child,
  });

  final double? width;
  final bool expand;
  final IconData icon;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final row = Row(
      textDirection: TextDirection.rtl,
      crossAxisAlignment: CrossAxisAlignment.center,
      children: [
        _DiscoveryFilterIconSlot(
          icon: icon,
          color: HasimTokens.mutedColor(context),
        ),
        const SizedBox(width: 8),
        Expanded(child: child),
      ],
    );
    if (expand) {
      return row;
    }
    return SizedBox(width: width ?? 190, child: row);
  }
}

class _DiscoveryFilterAction extends StatelessWidget {
  const _DiscoveryFilterAction({
    required this.label,
    required this.icon,
    required this.onPressed,
    required this.primary,
    this.expand = false,
  });

  final String label;
  final IconData icon;
  final VoidCallback onPressed;
  final bool primary;
  final bool expand;

  @override
  Widget build(BuildContext context) {
    final content = Row(
      textDirection: TextDirection.rtl,
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.center,
      children: [
        _DiscoveryFilterIconSlot(icon: icon),
        const SizedBox(width: 8),
        Text(label),
      ],
    );

    final minSize = Size(expand ? double.infinity : 0, 48);
    return primary
        ? FilledButton(
            onPressed: onPressed,
            style: FilledButton.styleFrom(
              minimumSize: minSize,
              padding: const EdgeInsets.symmetric(horizontal: 14),
            ),
            child: content,
          )
        : OutlinedButton(
            onPressed: onPressed,
            style: OutlinedButton.styleFrom(
              minimumSize: minSize,
              padding: const EdgeInsets.symmetric(horizontal: 14),
            ),
            child: content,
          );
  }
}

class _DiscoveryFilterIconSlot extends StatelessWidget {
  const _DiscoveryFilterIconSlot({required this.icon, this.color});

  final IconData icon;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: 22,
      height: 22,
      child: Center(child: Icon(icon, size: 20, color: color)),
    );
  }
}

class _WorkerDiscoveryCard extends StatelessWidget {
  const _WorkerDiscoveryCard({
    super.key,
    required this.worker,
    required this.onView,
    required this.onOffer,
  });

  final CompanyWorkerDiscoveryWorker worker;
  final VoidCallback onView;
  final VoidCallback onOffer;

  @override
  Widget build(BuildContext context) {
    final photo = _resolveWorkerPhotoUrl(worker.profilePhoto);
    final compact = HasimLayout.isCompact(context);
    return Material(
      color: HasimTokens.cardBg(context),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(HasimTokens.radiusCard),
        side: BorderSide(color: HasimTokens.lineColor(context)),
      ),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onView,
        child: Padding(
          padding: EdgeInsets.all(compact ? 12 : 16),
          child: LayoutBuilder(
            builder: (context, constraints) {
              final wide = !compact && constraints.maxWidth >= 620;
              final information = _WorkerDiscoveryInformation(
                worker: worker,
                photoUrl: photo,
                compact: compact,
              );
              final actions = _WorkerDiscoveryActions(
                worker: worker,
                onView: onView,
                onOffer: onOffer,
                compact: compact,
              );

              return wide
                  ? Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Expanded(child: information),
                        const SizedBox(width: 16),
                        actions,
                      ],
                    )
                  : Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        information,
                        SizedBox(height: compact ? 10 : 14),
                        actions,
                      ],
                    );
            },
          ),
        ),
      ),
    );
  }
}

class _WorkerDiscoveryInformation extends StatelessWidget {
  const _WorkerDiscoveryInformation({
    required this.worker,
    required this.photoUrl,
    this.compact = false,
  });

  final CompanyWorkerDiscoveryWorker worker;
  final String? photoUrl;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _WorkerAvatar(
          name: worker.displayName,
          photoUrl: photoUrl,
          radius: compact ? 22 : 28,
        ),
        SizedBox(width: compact ? 10 : 12),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Flexible(
                    child: Text(
                      worker.displayName,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: _aiTitle(
                        context,
                      ).copyWith(fontSize: compact ? 14 : 15),
                    ),
                  ),
                  if (worker.verifiedBadge) ...[
                    const SizedBox(width: 5),
                    const Icon(
                      Icons.verified_rounded,
                      size: 17,
                      color: HasimTokens.primary,
                    ),
                  ],
                ],
              ),
              const SizedBox(height: 3),
              Text(
                worker.professionalSummary,
                maxLines: compact ? 1 : 2,
                overflow: TextOverflow.ellipsis,
                style: _aiMuted(context).copyWith(height: 1.45),
              ),
              SizedBox(height: compact ? 6 : 9),
              Wrap(
                spacing: 6,
                runSpacing: 6,
                children: [
                  if (worker.countryCode.isNotEmpty)
                    _WorkerInfoPill(
                      icon: Icons.location_on_outlined,
                      label: worker.countryCode,
                      compact: compact,
                    ),
                  if (worker.salesExperienceLevel.isNotEmpty)
                    _WorkerInfoPill(
                      icon: Icons.bar_chart_outlined,
                      label: _workerExperienceLabel(
                        worker.salesExperienceLevel,
                      ),
                      compact: compact,
                    ),
                  if (worker.availabilityStatus.isNotEmpty)
                    _WorkerInfoPill(
                      icon: Icons.circle,
                      label: _workerAvailabilityLabel(
                        worker.availabilityStatus,
                      ),
                      highlighted: worker.availabilityStatus == 'available',
                      compact: compact,
                    ),
                  if (worker.verifiedBadge)
                    _WorkerInfoPill(
                      icon: Icons.verified_rounded,
                      label: 'موثّق',
                      highlighted: true,
                      compact: compact,
                    ),
                  if (worker.engagementStatusLabel != null)
                    _WorkerInfoPill(
                      icon: Icons.info_outline_rounded,
                      label: worker.engagementStatusLabel!,
                      compact: compact,
                    ),
                  if (compact)
                    for (final skill in worker.skills.take(2))
                      _WorkerSkillChip(label: skill.displayName),
                ],
              ),
              if (!compact && worker.skills.isNotEmpty) ...[
                const SizedBox(height: 10),
                Wrap(
                  spacing: 6,
                  runSpacing: 6,
                  children: [
                    for (final skill in worker.skills.take(6))
                      _WorkerSkillChip(label: skill.displayName),
                  ],
                ),
              ],
            ],
          ),
        ),
      ],
    );
  }
}

class _WorkerDiscoveryActions extends StatelessWidget {
  const _WorkerDiscoveryActions({
    required this.worker,
    required this.onView,
    required this.onOffer,
    this.compact = false,
  });

  final CompanyWorkerDiscoveryWorker worker;
  final VoidCallback onView;
  final VoidCallback onOffer;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final offerLabel = worker.hasOpenEngagement
        ? (worker.engagementStatusLabel ?? 'يوجد تعاقد')
        : 'إرسال عرض';
    final compactLabelStyle = _aiTitle(context).copyWith(fontSize: 11);
    final viewButton = OutlinedButton.icon(
      key: ValueKey('real-worker-view-${worker.id}'),
      onPressed: onView,
      icon: Icon(Icons.person_outline_rounded, size: compact ? 14 : 18),
      label: Text(
        'عرض الملف الشخصي',
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
        style: compact ? compactLabelStyle : null,
      ),
      style: compact
          ? OutlinedButton.styleFrom(
              minimumSize: const Size(0, 34),
              padding: const EdgeInsets.symmetric(horizontal: 6),
              tapTargetSize: MaterialTapTargetSize.shrinkWrap,
              visualDensity: VisualDensity.compact,
              textStyle: compactLabelStyle,
            )
          : null,
    );
    final offerButton = FilledButton.icon(
      key: ValueKey('real-worker-offer-${worker.id}'),
      onPressed: worker.hasOpenEngagement ? null : onOffer,
      icon: Icon(Icons.send_rounded, size: compact ? 14 : 18),
      label: Text(
        offerLabel,
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
        style: compact ? compactLabelStyle.copyWith(color: Colors.white) : null,
      ),
      style: compact
          ? FilledButton.styleFrom(
              minimumSize: const Size(0, 34),
              padding: const EdgeInsets.symmetric(horizontal: 6),
              tapTargetSize: MaterialTapTargetSize.shrinkWrap,
              visualDensity: VisualDensity.compact,
              textStyle: compactLabelStyle,
            )
          : null,
    );

    if (compact) {
      return Row(
        children: [
          Expanded(child: viewButton),
          const SizedBox(width: 8),
          Expanded(child: offerButton),
        ],
      );
    }

    return Wrap(
      spacing: 8,
      runSpacing: 8,
      alignment: WrapAlignment.end,
      children: [viewButton, offerButton],
    );
  }
}

class _WorkerInfoPill extends StatelessWidget {
  const _WorkerInfoPill({
    required this.icon,
    required this.label,
    this.highlighted = false,
    this.compact = false,
  });

  final IconData icon;
  final String label;
  final bool highlighted;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: highlighted
            ? HasimTokens.mintFill(context)
            : HasimTokens.pageBg(context),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: HasimTokens.lineColor(context)),
      ),
      child: Padding(
        padding: EdgeInsets.symmetric(
          horizontal: compact ? 7 : 9,
          vertical: compact ? 4 : 6,
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              icon,
              size: compact ? 12 : 14,
              color: highlighted
                  ? HasimTokens.accentDark
                  : HasimTokens.mutedColor(context),
            ),
            const SizedBox(width: 5),
            Text(label, style: _aiMuted(context).copyWith(fontSize: 10)),
          ],
        ),
      ),
    );
  }
}

class _WorkerSkillChip extends StatelessWidget {
  const _WorkerSkillChip({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: HasimTokens.mintFill(context),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 5),
        child: Text(
          label,
          style: _aiMuted(
            context,
          ).copyWith(color: HasimTokens.accentDark, fontSize: 10),
        ),
      ),
    );
  }
}

class _WorkerAvatar extends StatelessWidget {
  const _WorkerAvatar({required this.name, this.photoUrl, this.radius = 28});

  final String name;
  final String? photoUrl;
  final double radius;

  @override
  Widget build(BuildContext context) {
    final initial = name.trim().isEmpty ? 'ع' : name.trim().characters.first;
    final fallback = CircleAvatar(
      radius: radius,
      backgroundColor: HasimTokens.mintFill(context),
      child: Text(
        initial,
        style: _aiTitle(context).copyWith(color: HasimTokens.primary),
      ),
    );
    if (photoUrl == null || photoUrl!.isEmpty) return fallback;
    final size = radius * 2;
    return ClipOval(
      child: CachedNetworkImage(
        imageUrl: photoUrl!,
        width: size,
        height: size,
        fit: BoxFit.cover,
        errorWidget: (_, _, _) => fallback,
      ),
    );
  }
}

String _workerAvailabilityLabel(String value) {
  return switch (value) {
    'available' => 'متاح',
    'limited' => 'متاح بشكل محدود',
    'unavailable' => 'غير متاح',
    _ => value,
  };
}

String _workerExperienceLabel(String value) {
  return switch (value) {
    'junior' => 'خبرة مبتدئة',
    'mid' || 'middle' => 'خبرة متوسطة',
    'senior' => 'خبرة متقدمة',
    _ => 'مستوى الخبرة',
  };
}

String? _resolveWorkerPhotoUrl(String? value) {
  final raw = value?.trim() ?? '';
  if (raw.isEmpty) return null;
  if (raw.startsWith('http://') || raw.startsWith('https://')) return raw;
  final path = raw.startsWith('/') ? raw : '/$raw';
  return '${AppConfig.hostBase}$path';
}
