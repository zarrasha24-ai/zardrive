// ZarDrive Driver: the Android app opens the ZarDrive driver screens, with location and phone links handled by Android.
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:image_picker/image_picker.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:webview_flutter/webview_flutter.dart';
import 'package:webview_flutter_android/webview_flutter_android.dart';

import 'gmap.dart';

// The same app shell builds ZarDrive and the Zarrasha rides beta apps: values come from --dart-define at build time.
const String startUrl = String.fromEnvironment('START_URL', defaultValue: 'https://zardrive.zarrasha.com/driver');
const String ourHost = String.fromEnvironment('OUR_HOST', defaultValue: 'zardrive.zarrasha.com');
const String appTitle = String.fromEnvironment('APP_TITLE', defaultValue: 'ZarDrive Driver');
// GMAP=1 when the build has a Google Maps key: the web screens may then open Google's native map (free inside the app)
const bool gmapOn = String.fromEnvironment('GMAP') == '1' || String.fromEnvironment('GMAP') == 'true';
const int brandColor = int.fromEnvironment('BRAND_COLOR', defaultValue: 0xFF111418);

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

class _ShellState extends State<Shell> {
  late final WebViewController web;
  bool loading = true;
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
      ..setBackgroundColor(Colors.white)
      ..setNavigationDelegate(NavigationDelegate(
        // show the page as soon as it can be used, not after every map tile has arrived
        onProgress: (p) {
          if (p >= 60 && loading && mounted) setState(() { loading = false; });
        },
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
      // <input type="file"> in the page (application documents): take a photo or choose one
      p.setOnShowFileSelector((params) async {
        final src = await showModalBottomSheet<ImageSource>(context: context, builder: (c) => SafeArea(child: Wrap(children: [
          ListTile(leading: const Icon(Icons.photo_camera), title: const Text('Take a photo'), onTap: () => Navigator.pop(c, ImageSource.camera)),
          ListTile(leading: const Icon(Icons.photo_library), title: const Text('Choose from gallery'), onTap: () => Navigator.pop(c, ImageSource.gallery)),
        ])));
        if (src == null) return <String>[];
        final x = await ImagePicker().pickImage(source: src, maxWidth: 1400, imageQuality: 80);
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
      final bridge = GBridge(web, () => context);
      web.addJavaScriptChannel('ZRNative', onMessageReceived: (m) => bridge.onMessage(m.message));
    }
    // open the app at once; the location question is asked at the same time, not before
    web.loadRequest(Uri.parse(startUrl));
    Permission.locationWhenInUse.request();
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, result) async {
        if (didPop) return;
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
            WebViewWidget(controller: web),
            if (loading) const Center(child: CircularProgressIndicator()),
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
