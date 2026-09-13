import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim_cashier/core/widgets/hasim_top_notice.dart';

void main() {
  testWidgets('sync notice is a compact floating bar at the top', (tester) async {
    tester.view.physicalSize = const Size(1200, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(
      const MaterialApp(
        home: _NoticeHost(),
      ),
    );
    await tester.tap(find.text('show'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));

    expect(find.text('ما زال 8 جاهزاً'), findsOneWidget);
    final bar = tester.widget<SnackBar>(find.byType(SnackBar));
    expect(bar.behavior, SnackBarBehavior.floating);
    final margin = bar.margin! as EdgeInsets;
    expect(margin.top, lessThan(40));
    expect(margin.bottom, greaterThan(600));
  });
}

class _NoticeHost extends StatelessWidget {
  const _NoticeHost();

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Center(
        child: TextButton(
          onPressed: () => showHasimTopNotice(context, 'ما زال 8 جاهزاً'),
          child: const Text('show'),
        ),
      ),
    );
  }
}
