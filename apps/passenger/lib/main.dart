// ZarDrive: the Android app opens the ZarDrive passenger screens, with location and phone links handled by Android.
import 'package:flutter/material.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:webview_flutter/webview_flutter.dart';
import 'package:webview_flutter_android/webview_flutter_android.dart';

const String startUrl = 'https://zardrive.zarrasha.com/app';
const String ourHost = 'zardrive.zarrasha.com';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const ZarDriveApp());
}

class ZarDriveApp extends StatelessWidget {
  const ZarDriveApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'ZarDrive',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(colorSchemeSeed: const Color(0xFFC8102E), useMaterial3: true),
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
        onPageFinished: (_) {
          if (mounted) setState(() { loading = false; });
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
      p.setGeolocationPermissionsPromptCallbacks(
        onShowPrompt: (request) async {
          final s = await Permission.locationWhenInUse.request();
          return GeolocationPermissionsResponse(allow: s.isGranted, retain: s.isGranted);
        },
      );
    }
    Permission.locationWhenInUse.request().whenComplete(() => web.loadRequest(Uri.parse(startUrl)));
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, result) async {
        if (didPop) return;
        if (await web.canGoBack()) web.goBack();
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
                    style: FilledButton.styleFrom(backgroundColor: const Color(0xFFC8102E), minimumSize: const Size.fromHeight(52)),
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
