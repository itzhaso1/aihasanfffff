import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/features/settings/data/company_worker_discovery_repository.dart';
import 'package:hasim/features/settings/data/worker_offer_duration.dart';

void main() {
  test('duration presets expose the supported backend units', () {
    expect(WorkerOfferDuration.fromPreset('day')?.value, 1);
    expect(WorkerOfferDuration.fromPreset('day')?.unit, 'day');
    expect(WorkerOfferDuration.fromPreset('week')?.unit, 'week');
    expect(WorkerOfferDuration.fromPreset('month')?.unit, 'month');
    expect(WorkerOfferDuration.fromPreset('custom'), isNull);
  });

  test('calculates the display end date for supported durations', () {
    expect(
      formatWorkerOfferDate(
        calculateWorkerOfferEndDate(
          startsAt: '2026-10-01',
          value: 1,
          unit: 'day',
        )!,
      ),
      '2026-10-02',
    );
    expect(
      formatWorkerOfferDate(
        calculateWorkerOfferEndDate(
          startsAt: '2026-10-01',
          value: 1,
          unit: 'week',
        )!,
      ),
      '2026-10-08',
    );
    expect(
      formatWorkerOfferDate(
        calculateWorkerOfferEndDate(
          startsAt: '2026-10-01',
          value: 1,
          unit: 'month',
        )!,
      ),
      '2026-11-01',
    );
    expect(
      formatWorkerOfferDate(
        calculateWorkerOfferEndDate(
          startsAt: '2026-10-01',
          value: 3,
          unit: 'month',
        )!,
      ),
      '2027-01-01',
    );
    expect(
      formatWorkerOfferDate(
        calculateWorkerOfferEndDate(
          startsAt: '2026-10-01',
          value: 45,
          unit: 'day',
        )!,
      ),
      '2026-11-15',
    );
  });

  test('month display preview matches no-overflow calendar arithmetic', () {
    final result = calculateWorkerOfferEndDate(
      startsAt: '2026-01-31',
      value: 1,
      unit: 'month',
    );

    expect(formatWorkerOfferDate(result!), '2026-02-28');
  });

  test('formats the backend contract price as a read-only amount', () {
    const price = CompanyWorkerContractPrice(
      durationValue: 1,
      durationUnit: 'week',
      amountMinor: 60000,
      currency: 'SAR',
    );

    expect(price.amount, '600.00 SAR');
    expect(
      CompanyWorkerContractPrice.fromJson({
        'duration_value': 1,
        'duration_unit': 'day',
        'amount_minor': 10000,
        'currency': 'SAR',
      }).amount,
      '100.00 SAR',
    );
  });

  test('rejects invalid preview inputs without making a decision', () {
    expect(
      calculateWorkerOfferEndDate(
        startsAt: '2026-10-01',
        value: 0,
        unit: 'day',
      ),
      isNull,
    );
    expect(
      calculateWorkerOfferEndDate(
        startsAt: '2026-10-01',
        value: 1,
        unit: 'year',
      ),
      isNull,
    );
    expect(
      calculateWorkerOfferEndDate(
        startsAt: 'not-a-date',
        value: 1,
        unit: 'day',
      ),
      isNull,
    );
  });

  test(
    'offer requests send duration fields and never send a client end date',
    () {
      final request = CompanyWorkerOfferRequest(
        jobTitle: 'مندوب مبيعات',
        startsAt: '2026-10-01',
        durationValue: 45,
        durationUnit: 'day',
      );

      expect(request.toJson(), {
        'job_title': 'مندوب مبيعات',
        'starts_at': '2026-10-01',
        'duration_value': 45,
        'duration_unit': 'day',
      });
    },
  );

  test('engagement statuses use Arabic lifecycle labels', () {
    expect(workerEngagementStatusLabel('offered'), 'تم إرسال العرض');
    expect(workerEngagementStatusLabel('accepted'), 'تم قبول العرض');
    expect(workerEngagementStatusLabel('active'), 'التعاقد نشط');
    expect(workerEngagementStatusLabel('paused'), 'التعاقد متوقف مؤقتًا');
    expect(workerEngagementStatusLabel('completed'), 'التعاقد مكتمل');
    expect(workerEngagementStatusLabel('terminated'), 'التعاقد منتهٍ');
    expect(workerEngagementStatusLabel('unknown'), 'حالة التعاقد غير معروفة');
  });
}
