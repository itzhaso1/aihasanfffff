from pathlib import Path
path = Path(r"D:\projects\aihasan\apps\hasim\lib\features\settings\presentation\company_workers_section.dart")
extra = r"""

Future<void> _openWorkerOfferSheet(
  BuildContext context,
  CompanyWorkerDiscoveryController controller,
  CompanyWorkerDiscoveryWorker worker,
) async {
  final formKey = GlobalKey<FormState>();
  final jobTitle = TextEditingController();
  final startsAt = TextEditingController();
  final endsAt = TextEditingController();

  await showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    backgroundColor: HasimTokens.pageBg(context),
    shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
    builder: (sheetContext) {
      return StatefulBuilder(
        builder: (context, setSheetState) {
          final bottom = MediaQuery.viewInsetsOf(context).bottom;
          return Padding(
            padding: EdgeInsets.fromLTRB(16, 12, 16, 16 + bottom),
            child: Form(
              key: formKey,
              child: ListView(
                shrinkWrap: true,
                children: [
                  Text('Offer ${worker.displayName}', style: _aiTitle(context)),
                  const SizedBox(height: 12),
                  TextFormField(
                    controller: jobTitle,
                    decoration: const InputDecoration(labelText: 'job title'),
                    validator: (value) => (value == null || value.trim().isEmpty) ? 'required' : null,
                  ),
                  const SizedBox(height: 12),
                  TextFormField(
                    controller: startsAt,
                    decoration: const InputDecoration(labelText: 'starts'),
                    keyboardType: TextInputType.datetime,
                  ),
                  const SizedBox(height: 12),
                  TextFormField(
                    controller: endsAt,
                    decoration: const InputDecoration(labelText: 'ends'),
                    keyboardType: TextInputType.datetime,
                  ),
                  const SizedBox(height: 16),
                  FilledButton(
                    onPressed: controller.state.saving
                        ? null
                        : () async {
                            if (!(formKey.currentState?.validate() ?? false)) return;
                            final ok = await controller.sendOffer(
                              worker,
                              jobTitle: jobTitle.text,
                              startsAt: startsAt.text,
                              endsAt: endsAt.text,
                            );
                            if (!context.mounted) return;
                            if (ok) {
                              Navigator.of(sheetContext).pop();
                              ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('sent')));
                            } else if (controller.state.error != null) {
                              ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(controller.state.error!)));
                            }
                          },
                    child: Text(controller.state.saving ? 'saving' : 'send'),
                  ),
                ],
              ),
            ),
          );
        },
      );
    },
  );

  jobTitle.dispose();
  startsAt.dispose();
  endsAt.dispose();
}
"""
path.write_text(path.read_text(encoding='utf-8') + extra, encoding='utf-8')
print('appended')
