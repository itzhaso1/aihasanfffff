import 'package:app_links/app_links.dart';
import 'package:url_launcher/url_launcher.dart';

abstract class ChannelBrowserLauncher {
  Future<bool> open(Uri url);
}

class UrlChannelBrowserLauncher implements ChannelBrowserLauncher {
  @override
  Future<bool> open(Uri url) {
    return launchUrl(url, mode: LaunchMode.externalApplication);
  }
}

abstract class ChannelCallbackLinkSource {
  Stream<Uri> get incoming;
  Future<Uri?> initial();
}

class AppLinksChannelCallbackSource implements ChannelCallbackLinkSource {
  AppLinksChannelCallbackSource([AppLinks? appLinks]) : _appLinks = appLinks ?? AppLinks();

  final AppLinks _appLinks;

  @override
  Stream<Uri> get incoming => _appLinks.uriLinkStream;

  @override
  Future<Uri?> initial() => _appLinks.getInitialLink();
}

class MemoryChannelCallbackLinkSource implements ChannelCallbackLinkSource {
  MemoryChannelCallbackLinkSource({Stream<Uri>? incoming})
      : incoming = incoming ?? const Stream.empty();

  @override
  final Stream<Uri> incoming;

  @override
  Future<Uri?> initial() async => null;
}
