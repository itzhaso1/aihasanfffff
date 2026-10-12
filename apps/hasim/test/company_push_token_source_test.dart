import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/push/company_push_open.dart';
import 'package:hasim/push/push_token_source.dart';

class _FakeGateway implements NativeMessagingGateway {
  _FakeGateway({this.available = true, this.tokenValue = 'fcm-real-token', this.allow = true});

  bool available;
  bool allow;
  String? tokenValue;
  bool throwOnInit = false;
  final refreshesController = StreamController<String>.broadcast();
  final openedController = StreamController<Map<String, dynamic>>.broadcast();
  Map<String, dynamic>? launch;
  var initialized = false;

  @override
  bool get isAvailable => available;

  @override
  Future<void> initialize() async {
    if (throwOnInit) throw StateError('missing firebase');
    initialized = true;
  }

  @override
  Future<bool> requestPermission() async => allow;

  @override
  Future<String?> token() async => tokenValue;

  @override
  Stream<String> get tokenRefreshes => refreshesController.stream;

  @override
  Stream<Map<String, dynamic>> get openedNotifications => openedController.stream;

  @override
  Future<Map<String, dynamic>?> takeLaunchNotification() async {
    await initialize();
    final pending = launch;
    launch = null;
    return pending;
  }
}

void main() {
  test('native source is unsupported when the gateway is unavailable', () async {
    final source = NativeDevicePushTokenSource(_FakeGateway(available: false));
    expect(source.supported, isFalse);
    expect(await source.currentToken(), isNull);
  });

  test('native source returns a real token after permission', () async {
    final source = NativeDevicePushTokenSource(_FakeGateway());
    expect(source.supported, isTrue);
    expect(await source.currentToken(), 'fcm-real-token');
  });

  test('native source returns null when permission is denied', () async {
    final source = NativeDevicePushTokenSource(_FakeGateway(allow: false));
    expect(await source.currentToken(), isNull);
  });

  test('native source returns null when the gateway has no token', () async {
    final source = NativeDevicePushTokenSource(_FakeGateway(tokenValue: '  '));
    expect(await source.currentToken(), isNull);
  });

  test('native source swallows initialize failures', () async {
    final gateway = _FakeGateway()..throwOnInit = true;
    final source = NativeDevicePushTokenSource(gateway);
    expect(await source.currentToken(), isNull);
  });

  test('native source exposes token refresh', () async {
    final gateway = _FakeGateway();
    final source = NativeDevicePushTokenSource(gateway);
    late String received;
    final sub = source.refreshes.listen((value) => received = value);
    gateway.refreshesController.add('next-fcm');
    await Future<void>.delayed(Duration.zero);
    expect(received, 'next-fcm');
    await sub.cancel();
  });

  test('launch notification is consumed once', () async {
    final gateway = _FakeGateway()..launch = {'conversation_id': 7, 'workspace_id': '4'};
    final source = NativeDevicePushTokenSource(gateway);
    expect(await source.takeLaunchNotification(), {'conversation_id': 7, 'workspace_id': '4'});
    expect(await source.takeLaunchNotification(), isNull);
  });

  test('unconfigured source never invents a token', () async {
    const source = UnconfiguredNativePushTokenSource();
    expect(await source.currentToken(), isNull);
    debugDefaultTargetPlatformOverride = TargetPlatform.android;
    expect(source.supported, isTrue);
    debugDefaultTargetPlatformOverride = null;
  });

  test('push payload plan uses existing conversation deep link', () {
    final plan = planCompanyPushOpen({
      'type': 'message',
      'conversation_id': 11,
      'workspace_id': '4',
    });
    expect(plan.route, '/conversations/11');
    expect(plan.workspaceId, 4);
  });

  test('company session rejects another workspace that is not on the account', () {
    const auth = AuthState(
      bootstrapping: false,
      token: 't',
      workspace: WorkspaceModel(id: 4, name: 'A'),
      workspaces: [WorkspaceModel(id: 4, name: 'A')],
    );
    final plan = planCompanyPushOpen({'conversation_id': 1, 'workspace_id': 9});
    expect(companyPushMatchesSession(auth: auth, plan: plan), isFalse);
  });

  test('company session accepts a workspace the user belongs to', () {
    const auth = AuthState(
      bootstrapping: false,
      token: 't',
      workspace: WorkspaceModel(id: 4, name: 'A'),
      workspaces: [
        WorkspaceModel(id: 4, name: 'A'),
        WorkspaceModel(id: 9, name: 'B'),
      ],
    );
    final plan = planCompanyPushOpen({'conversation_id': 1, 'workspace_id': 9});
    expect(companyPushMatchesSession(auth: auth, plan: plan), isTrue);
  });
}
