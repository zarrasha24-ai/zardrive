// ZarDrive: the Android app opens the ZarDrive passenger screens, with location and phone links handled by Android.
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:image_picker/image_picker.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:webview_flutter/webview_flutter.dart';
import 'package:webview_flutter_android/webview_flutter_android.dart';

import 'bio.dart';
import 'gmap.dart';

// The same app shell builds ZarDrive and the Zarrasha rides beta apps: values come from --dart-define at build time.
const String startUrl = String.fromEnvironment('START_URL', defaultValue: 'https://zardrive.zarrasha.com/app');
const String ourHost = String.fromEnvironment('OUR_HOST', defaultValue: 'zardrive.zarrasha.com');
const String appTitle = String.fromEnvironment('APP_TITLE', defaultValue: 'ZarDrive');
// GMAP=1 when the build has a Google Maps key: the web screens may then open Google's native map (free inside the app)
const bool gmapOn = String.fromEnvironment('GMAP') == '1' || String.fromEnvironment('GMAP') == 'true';
// Pilot builds show a small "Pilot Test Run" label in the corner (empty in other builds)
const String pilotTag = String.fromEnvironment('PILOT_TAG');
const int brandColor = int.fromEnvironment('BRAND_COLOR', defaultValue: 0xFFC8102E);

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const ZarDriveApp());
}

class ZarDriveApp extends StatelessWidget {
  const ZarDriveApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: appTitle,
      debugShowCheckedModeBanner: false,
      theme: ThemeData(colorSchemeSeed: const Color(brandColor), useMaterial3: true),
      home: const Shell(),
    );
  }
}

class Shell extends StatefulWidget {
  const Shell({super.key});

  @override
  State<Shell> createState() => _ShellState();
}

class _ShellState extends State<Shell> with WidgetsBindingObserver {
  final bio = BioLock(); // fingerprint / face unlock
  late final WebViewController web;
  bool loading = true;
  GBridge? bridge; // Google's map (only in builds with a Google key)
  bool offline = false;
  DateTime? lastBack;

  bool _isOurs(Uri u) => u.host == ourHost || u.host.endsWith('openstreetmap.org') || u.host == 'unpkg.com' || u.host.endsWith('googleapis.com') || u.host.endsWith('gstatic.com') || u.host.endsWith('google.com');

  Future<void> _openOutside(Uri u) async {
    if (!await launchUrl(u, mode: LaunchMode.externalApplication)) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Could not open this link')));
    }
  }

  @override
  void initState() {
    super.initState();
    web = WebViewController()
      ..setJavaScriptMode(JavaScriptMode.unrestricted)
      ..setBackgroundColor(gmapOn ? Colors.transparent : Colors.white)
      ..setNavigationDelegate(NavigationDelegate(
        // show the page as soon as it can be used, not after every map tile has arrived
        onProgress: (p) {
          if (p >= 60 && loading && mounted) setState(() { loading = false; });
        },
        onPageStarted: (_) { bridge?.pageStarted(); },
        onPageFinished: (_) {
          if (loading && mounted) setState(() { loading = false; });
        },
        onWebResourceError: (e) {
          if ((e.isForMainFrame ?? false) && mounted) setState(() { offline = true; loading = false; });
        },
        onNavigationRequest: (r) {
          final u = Uri.tryParse(r.url);
          if (u == null) return NavigationDecision.prevent;
          if ((u.scheme == 'https' || u.scheme == 'http') && _isOurs(u)) return NavigationDecision.navigate;
          _openOutside(u);
          return NavigationDecision.prevent;
        },
      ));
    final p = web.platform;
    if (p is AndroidWebViewController) {
      p.setMediaPlaybackRequiresUserGesture(false);
      p.setTextZoom(100); // the phone's font size setting must not break the layout
      // <input type="file"> in the page (profile photo): pick from the gallery
      p.setOnShowFileSelector((params) async {
        final x = await ImagePicker().pickImage(source: ImageSource.gallery, maxWidth: 1200, imageQuality: 85);
        return x == null ? <String>[] : <String>[Uri.file(x.path).toString()];
      });
      p.setGeolocationPermissionsPromptCallbacks(
        onShowPrompt: (request) async {
          final s = await Permission.locationWhenInUse.request();
          return GeolocationPermissionsResponse(allow: s.isGranted, retain: s.isGranted);
        },
      );
    }
    if (gmapOn) {
      final b = bridge = GBridge(web, () => context);
      web.addJavaScriptChannel('ZRNative', onMessageReceived: (m) => b.onMessage(m.message));
      // tells the page from the very start that the app draws Google's map underneath (version 2)
      web.addJavaScriptChannel('ZRGMap2', onMessageReceived: (_) {});
    }
    // fingerprint / face unlock: the page's Settings switch talks to the app here
    web.addJavaScriptChannel('ZRBio', onMessageReceived: (m) => _bioMsg(m.message));
    WidgetsBinding.instance.addObserver(this);
    bio.load().then((_) { if (mounted) bio.unlock(appTitle); });
    _start();
    Permission.locationWhenInUse.request();
  }

  // open the app at once; the location question is asked at the same time, not before.
  // With Google's map the page is told (ZRGMap/2 in the browser name) that the app draws the map underneath.
  Future<void> _start() async {
    if (gmapOn) {
      try {
        final ua = await web.getUserAgent();
        await web.setUserAgent('${ua ?? ''} ZRGMap/2'.trim());
      } catch (_) {}
    }
    await web.loadRequest(Uri.parse(startUrl));
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState s) {
    if (s == AppLifecycleState.paused) bio.paused();
    if (s == AppLifecycleState.resumed) { bio.resumed(); if (bio.locked.value) bio.unlock(appTitle); }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  // 'status' asks, 'on' / 'off' switches; the page is told the result (can this phone do it, is it on)
  Future<void> _bioMsg(String m) async {
    if (m == 'on' || m == 'off') await bio.setOn(m == 'on', appTitle);
    try { await web.runJavaScript('window.ZD && ZD.bio && ZD.bio.got(${bio.can}, ${bio.on})'); } catch (_) {}
  }

  @override
  Widget build(BuildContext context) => Stack(children: [
        _body(context),
        ValueListenableBuilder<bool>(
          valueListenable: bio.locked,
          builder: (_, l, __) => l ? BioLockScreen(title: appTitle, color: brandColor, onUnlock: () => bio.unlock(appTitle)) : const SizedBox.shrink(),
        ),
      ]);

  Widget _body(BuildContext context) {
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, result) async {
        if (didPop || bio.locked.value) return;
        // the page goes back one screen (window.zrBack); on its main screen, press back twice to leave the app
        var handled = false;
        try {
          final r = (await web.runJavaScriptReturningResult('window.zrBack ? (window.zrBack() ? 1 : 0) : -1')).toString();
          handled = r == '1' || r == '1.0';
          // pages without their own back (older ZarDrive screens): browser history
          if ((r == '-1' || r == '-1.0') && await web.canGoBack()) { web.goBack(); return; }
        } catch (_) {
          handled = false;
        }
        if (handled) return;
        final now = DateTime.now();
        if (lastBack != null && now.difference(lastBack!) < const Duration(seconds: 2)) {
          SystemNavigator.pop();
          return;
        }
        lastBack = now;
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Press back again to close'), duration: Duration(seconds: 2)));
        }
      },
      child: Scaffold(
        backgroundColor: Colors.white,
        body: SafeArea(
          child: Stack(children: [
            // Google's map is drawn only while the page uses it (the page turns it on in Settings); otherwise nothing runs underneath
            if (bridge != null)
              ValueListenableBuilder<bool>(
                valueListenable: bridge!.layoutSeen,
                builder: (_, seen, __) => seen ? BaseMap(bridge: bridge!) : const SizedBox.shrink(),
              ),
            if (bridge != null)
              ValueListenableBuilder<bool>(
                valueListenable: bridge!.layoutSeen,
                builder: (_, seen, __) => ValueListenableBuilder<List<Rect>>(
                  valueListenable: bridge!.rects,
                  builder: (_, rects, __) => HitFilter(on: seen, rects: rects, child: WebViewWidget(controller: web)),
                ),
              )
            else
              WebViewWidget(controller: web),
            if (loading) const Center(child: CircularProgressIndicator()),
            if (pilotTag != '')
              Positioned(
                left: 0,
                bottom: 0,
                child: IgnorePointer(
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                    decoration: const BoxDecoration(color: Color(0xE6F79009), borderRadius: BorderRadius.only(topRight: Radius.circular(8))),
                    child: const Text(pilotTag, style: TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.w800, letterSpacing: 0.5)),
                  ),
                ),
              ),
            if (offline)
              Container(
                color: Colors.white,
                padding: const EdgeInsets.all(24),
                child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
                  const Text('No internet connection', style: TextStyle(fontSize: 20, fontWeight: FontWeight.w700)),
                  const SizedBox(height: 8),
                  const Text('Check your mobile data or Wi-Fi, then try again.', textAlign: TextAlign.center),
                  const SizedBox(height: 20),
                  FilledButton(
                    style: FilledButton.styleFrom(backgroundColor: const Color(brandColor), minimumSize: const Size.fromHeight(52)),
                    onPressed: () { setState(() { offline = false; loading = true; }); web.reload(); },
                    child: const Text('Try again'),
                  ),
                ]),
              ),
          ]),
        ),
      ),
    );
  }
}
