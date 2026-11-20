// ZarDrive Driver: the Android app opens the ZarDrive driver screens, with location and phone links handled by Android.
import 'package:flutter/material.dart';
import 'package:flutter_inappwebview/flutter_inappwebview.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:url_launcher/url_launcher.dart';

const String startUrl = 'https://zardrive.zarrasha.com/driver';
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
      title: 'ZarDrive Driver',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(colorSchemeSeed: const Color(0xFF111418), useMaterial3: true),
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
  InAppWebViewController? web;
  bool loading = true;
  bool offline = false;

  @override
  void initState() {
    super.initState();
    Permission.locationWhenInUse.request();
  }

  bool _isOurs(Uri u) => u.host == ourHost || u.host.endsWith('openstreetmap.org') || u.host == 'unpkg.com' || u.host.endsWith('googleapis.com') || u.host.endsWith('gstatic.com');

  Future<void> _openOutside(Uri u) async {
    if (!await launchUrl(u, mode: LaunchMode.externalApplication)) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Could not open this link')));
    }
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, result) async {
        if (didPop) return;
        final c = web;
        if (c != null && await c.canGoBack()) {
          c.goBack();
        }
      },
      child: Scaffold(
        backgroundColor: Colors.white,
        body: SafeArea(
          child: Stack(children: [
            InAppWebView(
              initialUrlRequest: URLRequest(url: WebUri(startUrl)),
              initialSettings: InAppWebViewSettings(
                javaScriptEnabled: true,
                domStorageEnabled: true,
                geolocationEnabled: true,
                useShouldOverrideUrlLoading: true,
                supportMultipleWindows: true,
                javaScriptCanOpenWindowsAutomatically: true,
                mediaPlaybackRequiresUserGesture: false,
              ),
              onWebViewCreated: (c) => web = c,
              onLoadStop: (c, url) => setState(() { loading = false; offline = false; }),
              onReceivedError: (c, req, err) {
                if (req.isForMainFrame ?? false) setState(() { offline = true; loading = false; });
              },
              onGeolocationPermissionsShowPrompt: (c, origin) async =>
                  GeolocationPermissionShowPromptResponse(origin: origin, allow: true, retain: true),
              shouldOverrideUrlLoading: (c, action) async {
                final u = action.request.url;
                if (u == null) return NavigationActionPolicy.ALLOW;
                if ((u.scheme == 'https' || u.scheme == 'http') && _isOurs(u)) return NavigationActionPolicy.ALLOW;
                await _openOutside(u);
                return NavigationActionPolicy.CANCEL;
              },
              onCreateWindow: (c, req) async {
                final u = req.request.url;
                if (u != null) await _openOutside(u);
                return false;
              },
            ),
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
                    onPressed: () { setState(() { offline = false; loading = true; }); web?.reload(); },
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
