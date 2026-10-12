import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/widgets/hasim_inner_chrome_corner.dart';

void main() {
  testWidgets('inner chrome corner stays a fixed overlay and ignores hits', (tester) async {
    await tester.pumpWidget(
      const MaterialApp(
        home: Scaffold(
          body: Align(
            alignment: Alignment.topRight,
            child: HasimInnerChromeCorner(
              color: Color(0xFFF3FCFA),
              lineColor: Color(0xFFE7F0ED),
            ),
          ),
        ),
      ),
    );

    final size = tester.getSize(find.byType(HasimInnerChromeCorner));
    expect(size, const Size(HasimInnerChromeCorner.radius, HasimInnerChromeCorner.radius));
    final ignoring = tester.widgetList<IgnorePointer>(find.byType(IgnorePointer)).where((widget) => widget.ignoring);
    expect(ignoring, isNotEmpty);
  });
}
