// Fingerprint / face unlock (23 Nov 2026).
// The person turns it on in the app's Settings (the page talks to the app on the JavaScript channel ZRBio).
// When it is on, the app asks for the phone's fingerprint, face or screen lock when it opens and when it comes back
// after more than a minute away. The phone's PIN / pattern always works too, so nobody is locked out.
import 'package:flutter/material.dart';
import 'package:local_auth/local_auth.dart';
import 'package:shared_preferences/shared_preferences.dart';

class BioLock {
  final _auth = LocalAuthentication();
  final locked = ValueNotifier<bool>(false);
  bool on = false;
  bool can = false;
  DateTime? _away;
  bool _asking = false;
  static const _key = 'bio_unlock_on';
  static const awayLimit = Duration(minutes: 1);

  Future<void> load() async {
    try { can = await _auth.isDeviceSupported(); } catch (_) { can = false; }
    try { on = can && ((await SharedPreferences.getInstance()).getBool(_key) ?? false); } catch (_) { on = false; }
    if (on) locked.value = true;
  }

  Future<bool> check(String why) async {
    if (_asking) return false;
    _asking = true;
    try {
      return await _auth.authenticate(localizedReason: why, options: const AuthenticationOptions(stickyAuth: true, biometricOnly: false));
    } catch (_) {
      return false;
    } finally {
      _asking = false;
    }
  }

  Future<void> unlock(String title) async {
    if (!locked.value) return;
    if (await check('Unlock $title')) locked.value = false;
  }

  /// turning it on asks once, so the person knows it works on this phone
  Future<bool> setOn(bool v, String title) async {
    if (v && !await check('Turn on fingerprint or face unlock for $title')) return false;
    on = v;
    try { (await SharedPreferences.getInstance()).setBool(_key, v); } catch (_) {}
    return true;
  }

  void paused() { _away = DateTime.now(); }
  void resumed() {
    final a = _away; _away = null;
    if (on && a != null && DateTime.now().difference(a) > awayLimit) locked.value = true;
  }
}

class BioLockScreen extends StatelessWidget {
  final String title;
  final int color;
  final VoidCallback onUnlock;
  const BioLockScreen({super.key, required this.title, required this.color, required this.onUnlock});

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.white,
      child: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(28),
          child: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Icon(Icons.fingerprint, size: 84, color: Color(color)),
            const SizedBox(height: 18),
            Text(title, textAlign: TextAlign.center, style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w700)),
            const SizedBox(height: 8),
            const Text('Locked. Use your fingerprint, face or phone screen lock to open.', textAlign: TextAlign.center, style: TextStyle(fontSize: 15, color: Color(0xFF5B6670))),
            const SizedBox(height: 28),
            FilledButton.icon(
              style: FilledButton.styleFrom(backgroundColor: Color(color), minimumSize: const Size.fromHeight(54)),
              onPressed: onUnlock,
              icon: const Icon(Icons.lock_open),
              label: const Text('Unlock', style: TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
            ),
          ]),
        ),
      ),
    );
  }
}
