import 'package:flutter_test/flutter_test.dart';
import 'package:hasim_cashier/core/pos/table_session_orders.dart';

void main() {
  final opened = DateTime.utc(2026, 9, 8, 12, 0);

  test('unpaid orders without a session stamp do not follow a new sitting', () {
    expect(
      isOrderInOpenTableSession(
        posStatus: 'new',
        paymentStatus: 'unpaid',
        createdAt: opened.subtract(const Duration(hours: 2)),
        openedAt: opened,
        currentSessionLocalId: 'sess-new',
      ),
      isFalse,
    );
  });

  test('unpaid orders stamped for this sitting stay visible', () {
    expect(
      isOrderInOpenTableSession(
        posStatus: 'new',
        paymentStatus: 'unpaid',
        createdAt: opened.subtract(const Duration(hours: 2)),
        openedAt: opened,
        orderSessionLocalId: 'sess-new',
        currentSessionLocalId: 'sess-new',
      ),
      isTrue,
    );
  });

  test('paid orders from an earlier sitting do not follow a new session', () {
    expect(
      isOrderInOpenTableSession(
        posStatus: 'completed',
        paymentStatus: 'paid',
        createdAt: opened.subtract(const Duration(minutes: 1)),
        completedAt: opened.subtract(const Duration(minutes: 1)),
        openedAt: opened,
        currentSessionLocalId: 'sess-new',
      ),
      isFalse,
    );
  });

  test('paid orders stay visible only when stamped for this sitting', () {
    expect(
      isOrderInOpenTableSession(
        posStatus: 'completed',
        paymentStatus: 'paid',
        createdAt: opened.add(const Duration(minutes: 2)),
        openedAt: opened,
        currentSessionLocalId: 'sess-now',
      ),
      isFalse,
    );
    expect(
      isOrderInOpenTableSession(
        posStatus: 'completed',
        paymentStatus: 'paid',
        createdAt: opened.add(const Duration(minutes: 2)),
        openedAt: opened,
        orderSessionLocalId: 'sess-now',
        currentSessionLocalId: 'sess-now',
      ),
      isTrue,
    );
  });

  test('session id mismatch drops paid history even if timestamps overlap', () {
    expect(
      isOrderInOpenTableSession(
        posStatus: 'completed',
        paymentStatus: 'paid',
        createdAt: opened.add(const Duration(minutes: 1)),
        openedAt: opened,
        orderSessionLocalId: 'sess-old',
        currentSessionLocalId: 'sess-new',
      ),
      isFalse,
    );
  });

  test('no current session means nothing is operationally active', () {
    expect(
      isOrderInOpenTableSession(
        posStatus: 'new',
        paymentStatus: 'unpaid',
        createdAt: opened,
        openedAt: opened,
      ),
      isFalse,
    );
  });

  test('occupy snapshots stay only when they carry this sitting id', () {
    expect(
      isSnapshotInOpenTableSession(
        {
          'pos_status': 'completed',
          'payment_status': 'paid',
          'session_client_id': 'sess-now',
          'total_amount': 15,
        },
        openedAt: opened,
        currentSessionLocalId: 'sess-now',
      ),
      isTrue,
    );
    expect(
      isSnapshotInOpenTableSession(
        {
          'pos_status': 'completed',
          'payment_status': 'paid',
          'total_amount': 15,
        },
        openedAt: opened,
        currentSessionLocalId: 'sess-now',
      ),
      isTrue,
    );
  });

  test('dated paid snapshots from before opened_at are dropped', () {
    expect(
      isSnapshotInOpenTableSession(
        {
          'pos_status': 'completed',
          'payment_status': 'paid',
          'created_at': opened
              .subtract(const Duration(minutes: 2))
              .toIso8601String(),
          'total_amount': 90,
        },
        openedAt: opened,
        currentSessionLocalId: 'sess-now',
      ),
      isFalse,
    );
  });
}
