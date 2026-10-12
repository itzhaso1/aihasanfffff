import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/features/customers/presentation/customer_profile_screen.dart';
import 'package:hasim/features/settings/data/plan_channel_repository.dart';
import 'package:shared_preferences/shared_preferences.dart';

class _MemorySecureStore extends SecureStore {
  String? _token;

  @override
  Future<void> saveToken(String token) async {
    _token = token;
  }

  @override
  Future<String?> readToken() async => _token;

  @override
  Future<void> clearToken() async {
    _token = null;
  }
}

class _BlockingCustomerRepository extends CustomerRepository {
  _BlockingCustomerRepository(super.api);

  final showResult = Completer<CustomerModel>();
  final conversationsResult = Completer<List<ConversationModel>>();
  bool showRequested = false;

  @override
  Future<CustomerModel> show(int id) {
    showRequested = true;
    return showResult.future;
  }

  @override
  Future<List<ConversationModel>> conversations(int id) {
    return conversationsResult.future;
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  testWidgets(
    'customer profile renders cached customer before refresh completes',
    (tester) async {
      SharedPreferences.setMockInitialValues({});
      final prefs = await SharedPreferences.getInstance();
      final api = ApiClient(
        secureStore: _MemorySecureStore(),
        prefsStore: PrefsStore(prefs),
        dio: Dio(BaseOptions(baseUrl: 'http://127.0.0.1:1/')),
      );
      final repository = _BlockingCustomerRepository(api);
      const customer = CustomerModel(
        id: 7,
        name: 'أحمد السالم',
        phone: '+966501234567',
      );

      await tester.pumpWidget(
        ProviderScope(
          overrides: [customerRepositoryProvider.overrideWithValue(repository)],
          child: MaterialApp(
            home: const CustomerProfileScreen(
              customerId: 7,
              initialCustomer: customer,
            ),
          ),
        ),
      );
      await tester.pump();

      expect(find.text(customer.name), findsOneWidget);
      expect(repository.showRequested, isTrue);
      expect(find.text('تعذر تحميل ملف العميل.'), findsNothing);
    },
  );
}
