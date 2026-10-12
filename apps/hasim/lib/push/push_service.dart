import 'dart:async';

import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/features/settings/data/session_repository.dart';
import 'package:hasim/push/push_token_source.dart';

abstract class PushService {
  Future<void> initializeAndRegister();

  Future<void> revokeCurrent();

  String get statusLabel;
}

/// Company device registration for `POST /devices` and `DELETE /devices/{id}`.
///
/// Independent workers are skipped. Web and desktop are skipped. An empty
/// token is never posted.
class CompanyDevicePushService implements PushService {
  CompanyDevicePushService({
    required DeviceRepository devices,
    required PushTokenSource tokens,
    required PrefsStore prefs,
    required int? Function() companyWorkspaceId,
    bool Function()? isCompanyAccount,
    String? Function()? provider,
    String Function()? platformName,
  }) : _devices = devices,
       _tokens = tokens,
       _prefs = prefs,
       _companyWorkspaceId = companyWorkspaceId,
       _isCompanyAccount = isCompanyAccount ?? (() => true),
       _provider = provider ?? pushProviderForPlatform,
       _platformName = platformName ?? pushPlatformName;

  final DeviceRepository _devices;
  final PushTokenSource _tokens;
  final PrefsStore _prefs;
  final int? Function() _companyWorkspaceId;
  final bool Function() _isCompanyAccount;
  final String? Function() _provider;
  final String Function() _platformName;

  StreamSubscription<String>? _refreshSub;
  Future<void>? _inFlight;
  String _status = 'لم يُسجَّل الجهاز بعد';

  @override
  String get statusLabel => _status;

  @override
  Future<void> initializeAndRegister() {
    _refreshSub ??= _tokens.refreshes.listen((_) {
      unawaited(initializeAndRegister());
    });
    if (_inFlight != null) return _inFlight!;
    final run = _register();
    _inFlight = run.whenComplete(() => _inFlight = null);
    return run;
  }

  Future<void> _register() async {
    if (!_isCompanyAccount()) {
      _status = 'تسجيل الجهاز متاح لحساب الشركة داخل مساحة العمل';
      return;
    }
    final workspaceId = _companyWorkspaceId();
    if (workspaceId == null) {
      _status = 'تسجيل الجهاز متاح لحساب الشركة داخل مساحة العمل';
      return;
    }
    if (!_tokens.supported || _provider() == null) {
      _status = 'هذه المنصة لا تسجّل إشعارات الجهاز';
      return;
    }
    final token = (await _tokens.currentToken())?.trim() ?? '';
    if (token.isEmpty) {
      _status = 'لا يوجد رمز جهاز حقيقي. لم يُرسل شيء إلى الخادم';
      return;
    }
    if (_prefs.pushDeviceToken == token &&
        _prefs.pushDeviceWorkspaceId == workspaceId &&
        _prefs.pushDeviceId != null) {
      _status = 'الجهاز مسجّل';
      return;
    }
    if (_prefs.pushDeviceId != null &&
        _prefs.pushDeviceWorkspaceId == workspaceId &&
        _prefs.pushDeviceToken != null &&
        _prefs.pushDeviceToken != token) {
      await _revokeStored();
    }
    try {
      final id = await _devices.register(
        token: token,
        provider: _provider()!,
        platform: _platformName(),
        deviceName: _platformName(),
      );
      if (id != null) {
        await _prefs.savePushDevice(
          id: id,
          workspaceId: workspaceId,
          token: token,
        );
      }
      _status = 'الجهاز مسجّل';
    } on ApiException catch (error) {
      _status = error.userMessage;
    }
  }

  @override
  Future<void> revokeCurrent() async {
    await _revokeStored();
    _status = 'أُلغي تسجيل الجهاز';
  }

  Future<void> _revokeStored() async {
    final id = _prefs.pushDeviceId;
    final workspaceId = _prefs.pushDeviceWorkspaceId;
    if (id == null || workspaceId == null) return;
    if (_companyWorkspaceId() != workspaceId) return;
    try {
      await _devices.revoke(id);
    } on ApiException {
      // Logout and workspace switch still continue.
    }
    await _prefs.clearPushDevice();
  }

  void dispose() {
    _refreshSub?.cancel();
  }
}
