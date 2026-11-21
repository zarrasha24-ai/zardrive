// Google's native map inside the Zarrasha Rides apps (Maps SDK for Android: free, unlimited).
// The web screens ask for it through the JavaScript channel "ZRNative":
//   {cmd:'pick', id, title, lat, lng}  → full-screen map with the red pin; the address comes from our own place list (asked from the page)
//   {cmd:'track', title, chip, pins, lines, buttons} and {cmd:'update', …} → live map of a trip or request
// Only the map is Google's. Search, addresses and routes stay Zarrasha's own (free).
import 'dart:async';
import 'dart:convert';
import 'dart:math' as math;
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart';
import 'package:webview_flutter/webview_flutter.dart';

const Color _red = Color(0xFFCC021B);
const Color _ink = Color(0xFF111418);

class GBridge {
  GBridge(this.web, this.context);
  final WebViewController web;
  final BuildContext Function() context;
  final _addr = StreamController<Map<String, dynamic>>.broadcast();
  final _updates = StreamController<Map<String, dynamic>>.broadcast();
  bool _trackOpen = false;
  // Google's map under the whole app (version 2): where the page takes taps, the map padding, what to draw, camera moves
  final rects = ValueNotifier<List<Rect>>(const []);
  final layoutSeen = ValueNotifier<bool>(false);
  final insets = ValueNotifier<EdgeInsets>(EdgeInsets.zero);
  final scene = ValueNotifier<Map<String, dynamic>>(const {});
  final _cam = StreamController<Map<String, dynamic>>.broadcast();

  /** A new page is loading: until it reports its layout, the page takes every tap. */
  void pageStarted() { layoutSeen.value = false; }

  void onMessage(String raw) {
    Map<String, dynamic> m;
    try {
      m = Map<String, dynamic>.from(jsonDecode(raw) as Map);
    } catch (_) {
      return;
    }
    switch (m['cmd']) {
      case 'pick':
        _pick(m);
        break;
      case 'addr':
        _addr.add(m);
        break;
      case 'track':
        _track(m);
        break;
      case 'update':
        _updates.add(m);
        break;
      case 'close':
        if (_trackOpen) Navigator.of(context()).maybePop();
        break;
      case 'layout':
        rects.value = (m['rects'] is List ? m['rects'] as List : const []).whereType<List>().where((r) => r.length >= 4)
            .map((r) => Rect.fromLTWH(_num(r[0], 0), _num(r[1], 0), _num(r[2], 0), _num(r[3], 0))).toList();
        insets.value = EdgeInsets.only(top: _num(m['top'], 0), bottom: _num(m['bottom'], 0));
        layoutSeen.value = true;
        break;
      case 'scene':
        scene.value = m;
        break;
      case 'camera':
      case 'fit':
        _cam.add(m);
        break;
    }
  }

  Future<void> _js(String code) async {
    try {
      await web.runJavaScript(code);
    } catch (_) {}
  }

  static double _num(dynamic v, double d) => v is num ? v.toDouble() : (double.tryParse('$v') ?? d);

  Future<void> _pick(Map<String, dynamic> m) async {
    final id = (m['id'] as num?)?.toInt() ?? 0;
    final start = LatLng(_num(m['lat'], 34.0086), _num(m['lng'], 71.5249));
    final r = await Navigator.of(context()).push<Map<String, dynamic>>(MaterialPageRoute(
        builder: (_) => PickScreen(bridge: this, id: id, title: '${m['title'] ?? 'Choose the place'}', start: start)));
    await _js('window.ZD && ZD.gm && ZD.gm._done($id, ${jsonEncode(r)})');
  }

  void askAddress(int id, LatLng p) => _js('window.ZD && ZD.gm && ZD.gm._addr($id, ${p.latitude}, ${p.longitude})');

  Future<void> _track(Map<String, dynamic> m) async {
    if (_trackOpen) {
      _updates.add(m);
      return;
    }
    _trackOpen = true;
    final r = await Navigator.of(context()).push<int>(MaterialPageRoute(builder: (_) => TrackScreen(bridge: this, first: m)));
    _trackOpen = false;
    await _js(r != null ? 'window.ZD && ZD.gm && ZD.gm._button($r)' : 'window.ZD && ZD.gm && ZD.gm._closed()');
  }
}

/// Round marker with a letter or an icon (drawn once, cached).
final Map<String, BitmapDescriptor> _icons = {};
Future<BitmapDescriptor> _dot(String key, Color bg, {String text = '', IconData? icon, double size = 34}) async {
  final hit = _icons[key];
  if (hit != null) return hit;
  const scale = 3.0;
  final s = size * scale;
  final rec = ui.PictureRecorder();
  final c = Canvas(rec);
  c.drawCircle(Offset(s / 2, s / 2), s / 2, Paint()..color = Colors.white);
  c.drawCircle(Offset(s / 2, s / 2), s / 2 - 3 * scale, Paint()..color = bg);
  final tp = TextPainter(
    textDirection: TextDirection.ltr,
    text: TextSpan(
      text: icon != null ? String.fromCharCode(icon.codePoint) : text,
      style: TextStyle(color: Colors.white, fontSize: s * (icon != null ? 0.56 : 0.46), fontWeight: FontWeight.w700, fontFamily: icon?.fontFamily, package: icon?.fontPackage),
    ),
  )..layout();
  tp.paint(c, Offset((s - tp.width) / 2, (s - tp.height) / 2));
  final img = await rec.endRecording().toImage(s.toInt(), s.toInt());
  final bytes = await img.toByteData(format: ui.ImageByteFormat.png);
  final d = BitmapDescriptor.bytes(bytes!.buffer.asUint8List(), width: size, height: size);
  _icons[key] = d;
  return d;
}

/// Markers and lines for what the page asked to show (pins: pickup/from = A, flag/to = B, car).
Future<(Set<Marker>, Set<Polyline>, List<LatLng>)> buildScene(Map<String, dynamic> m) async {
  final pins = (m['pins'] is List ? m['pins'] as List : const []).whereType<Map>().toList();
  final mk = <Marker>{};
  final all = <LatLng>[];
  var i = 0;
  for (final p in pins) {
    final pos = LatLng(GBridge._num(p['lat'], 0), GBridge._num(p['lng'], 0));
    all.add(pos);
    final k = '${p['kind'] ?? ''}';
    final BitmapDescriptor ic;
    if (k == 'car') {
      ic = await _dot('car', _ink, icon: Icons.directions_car, size: 38);
    } else if (k == 'pickup' || k == 'from') {
      ic = await _dot('a', _ink, text: 'A');
    } else if (k == 'flag' || k == 'to') {
      ic = await _dot('b', _red, text: 'B');
    } else {
      ic = await _dot('p', _red, text: '•', size: 26);
    }
    mk.add(Marker(markerId: MarkerId('p${i++}'), position: pos, icon: ic, anchor: const Offset(0.5, 0.5), zIndex: k == 'car' ? 3.0 : 1.0));
  }
  final ls = <Polyline>{};
  var j = 0;
  for (final l in (m['lines'] is List ? m['lines'] as List : const [])) {
    final pts = (l is List ? l : const []).whereType<List>().where((p) => p.length >= 2).map((p) => LatLng(GBridge._num(p[0], 0), GBridge._num(p[1], 0))).toList();
    if (pts.length < 2) continue;
    all.addAll(pts);
    ls.add(Polyline(polylineId: PolylineId('l${j++}'), points: pts, color: _red, width: 6, jointType: JointType.round, startCap: Cap.roundCap, endCap: Cap.roundCap));
  }
  return (mk, ls, all);
}

Future<void> fitCamera(GoogleMapController c, List<LatLng> all) async {
  if (all.isEmpty) return;
  if (all.length == 1) {
    await c.animateCamera(CameraUpdate.newLatLngZoom(all.first, 15));
    return;
  }
  var s0 = all.first.latitude, n0 = s0, w0 = all.first.longitude, e0 = w0;
  for (final p in all) {
    s0 = math.min(s0, p.latitude); n0 = math.max(n0, p.latitude);
    w0 = math.min(w0, p.longitude); e0 = math.max(e0, p.longitude);
  }
  if ((n0 - s0).abs() < 0.0005 && (e0 - w0).abs() < 0.0005) {
    await c.animateCamera(CameraUpdate.newLatLngZoom(all.first, 16));
    return;
  }
  try {
    await c.animateCamera(CameraUpdate.newLatLngBounds(LatLngBounds(southwest: LatLng(s0, w0), northeast: LatLng(n0, e0)), 56));
  } catch (_) {}
}

/// Lets taps through to Google's map underneath, except where the page has its sheet, buttons and cards.
class HitFilter extends SingleChildRenderObjectWidget {
  const HitFilter({super.key, required this.rects, required this.on, super.child});
  final List<Rect> rects;
  final bool on;
  @override
  RenderHitFilter createRenderObject(BuildContext context) => RenderHitFilter(rects, on);
  @override
  void updateRenderObject(BuildContext context, RenderHitFilter r) { r..rects = rects..on = on; }
}

class RenderHitFilter extends RenderProxyBox {
  RenderHitFilter(this.rects, this.on);
  List<Rect> rects;
  bool on;
  @override
  bool hitTest(BoxHitTestResult result, {required Offset position}) {
    if (on && !rects.any((r) => r.inflate(2).contains(position))) return false;
    return super.hitTest(result, position: position);
  }
}

/// Google's map under the whole app: draws what the page asks for and tells the page where the map was moved to.
class BaseMap extends StatefulWidget {
  const BaseMap({super.key, required this.bridge});
  final GBridge bridge;
  @override
  State<BaseMap> createState() => _BaseMapState();
}

class _BaseMapState extends State<BaseMap> {
  GoogleMapController? c;
  Set<Marker> markers = {};
  Set<Polyline> lines = {};
  CameraPosition pos = const CameraPosition(target: LatLng(34.0086, 71.5249), zoom: 15);
  Map<String, dynamic>? pending;
  StreamSubscription<Map<String, dynamic>>? sub;
  int ver = 0;

  @override
  void initState() {
    super.initState();
    widget.bridge.scene.addListener(_scene);
    sub = widget.bridge._cam.stream.listen(_cmd);
  }

  @override
  void dispose() {
    widget.bridge.scene.removeListener(_scene);
    sub?.cancel();
    super.dispose();
  }

  Future<void> _scene() async {
    final v = ++ver;
    final (mk, ls, _) = await buildScene(widget.bridge.scene.value);
    if (!mounted || v != ver) return;
    setState(() { markers = mk; lines = ls; });
  }

  Future<void> _cmd(Map<String, dynamic> m) async {
    final ctl = c;
    if (ctl == null) { pending = m; return; }
    if (m['cmd'] == 'camera') {
      final t = LatLng(GBridge._num(m['lat'], 34.0086), GBridge._num(m['lng'], 71.5249));
      final z = GBridge._num(m['zoom'], 0);
      await ctl.animateCamera(z > 0 ? CameraUpdate.newLatLngZoom(t, z) : CameraUpdate.newLatLng(t));
    } else {
      final pts = (m['points'] is List ? m['points'] as List : const []).whereType<List>().where((p) => p.length >= 2)
          .map((p) => LatLng(GBridge._num(p[0], 0), GBridge._num(p[1], 0))).toList();
      await fitCamera(ctl, pts);
    }
  }

  void _idle() => widget.bridge._js('window.ZD && ZD.gm && ZD.gm._idle(${pos.target.latitude}, ${pos.target.longitude}, ${pos.zoom})');

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<EdgeInsets>(
      valueListenable: widget.bridge.insets,
      builder: (_, pad, __) => GoogleMap(
        initialCameraPosition: pos,
        padding: pad,
        myLocationEnabled: true,
        myLocationButtonEnabled: false,
        zoomControlsEnabled: false,
        mapToolbarEnabled: false,
        compassEnabled: false,
        rotateGesturesEnabled: false,
        tiltGesturesEnabled: false,
        markers: markers,
        polylines: lines,
        onMapCreated: (ctl) {
          c = ctl;
          final p = pending;
          pending = null;
          if (p != null) _cmd(p);
          _idle();
        },
        onCameraMoveStarted: () => widget.bridge._js('window.ZD && ZD.gm && ZD.gm._moving()'),
        onCameraMove: (p) => pos = p,
        onCameraIdle: _idle,
      ),
    );
  }
}

class _Pin extends StatelessWidget {
  const _Pin();
  @override
  Widget build(BuildContext context) => CustomPaint(size: const Size(40, 52), painter: _PinPainter());
}

class _PinPainter extends CustomPainter {
  @override
  void paint(Canvas c, Size s) {
    final p = Path()
      ..moveTo(s.width / 2, s.height)
      ..cubicTo(s.width / 2, s.height, 0, s.height * 0.62, 0, s.width / 2)
      ..arcToPoint(Offset(s.width, s.width / 2), radius: Radius.circular(s.width / 2))
      ..cubicTo(s.width, s.height * 0.62, s.width / 2, s.height, s.width / 2, s.height)
      ..close();
    c.drawShadow(p, Colors.black, 3, false);
    c.drawPath(p, Paint()..color = _red);
    c.drawCircle(Offset(s.width / 2, s.width / 2), 7, Paint()..color = Colors.white);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

Widget _roundBtn(IconData i, VoidCallback onTap, {String label = ''}) => Material(
      color: _red,
      shape: const CircleBorder(),
      elevation: 3,
      child: InkWell(customBorder: const CircleBorder(), onTap: onTap, child: SizedBox(width: 46, height: 46, child: Icon(i, color: Colors.white, semanticLabel: label))),
    );

Widget _chip(String t) => Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
      decoration: BoxDecoration(color: _ink, borderRadius: BorderRadius.circular(18)),
      child: Text(t, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700, fontSize: 13)),
    );

ButtonStyle _primary() => FilledButton.styleFrom(backgroundColor: _red, minimumSize: const Size.fromHeight(52), shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
    textStyle: const TextStyle(fontSize: 15, fontWeight: FontWeight.w700));
ButtonStyle _ghost() => FilledButton.styleFrom(backgroundColor: const Color(0xFFF2F1EE), foregroundColor: _ink, minimumSize: const Size.fromHeight(52),
    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)), textStyle: const TextStyle(fontSize: 15, fontWeight: FontWeight.w700));

// ------------------------------------------------------------------ choose a place under the pin
class PickScreen extends StatefulWidget {
  const PickScreen({super.key, required this.bridge, required this.id, required this.title, required this.start});
  final GBridge bridge;
  final int id;
  final String title;
  final LatLng start;
  @override
  State<PickScreen> createState() => _PickScreenState();
}

class _PickScreenState extends State<PickScreen> {
  static const double cardH = 178;
  late LatLng center = widget.start;
  String name = '';
  String sub = '';
  bool looking = true;
  StreamSubscription<Map<String, dynamic>>? sub0;
  Timer? wait;

  @override
  void initState() {
    super.initState();
    sub0 = widget.bridge._addr.stream.listen((m) {
      if ((m['id'] as num?)?.toInt() != widget.id || !mounted) return;
      final la = GBridge._num(m['lat'], 0), lo = GBridge._num(m['lng'], 0);
      if ((la - center.latitude).abs() > 0.0002 || (lo - center.longitude).abs() > 0.0002) return; // an older answer
      setState(() {
        name = '${m['name'] ?? ''}'.trim();
        sub = '${m['sub'] ?? ''}'.trim();
        if (name.isEmpty) name = 'Pinned location';
        looking = false;
      });
    });
  }

  @override
  void dispose() {
    sub0?.cancel();
    wait?.cancel();
    super.dispose();
  }

  void _idle() {
    wait?.cancel();
    wait = Timer(const Duration(milliseconds: 250), () => widget.bridge.askAddress(widget.id, center));
  }

  @override
  Widget build(BuildContext context) {
    final label = widget.title.toLowerCase();
    return Scaffold(
      body: Stack(children: [
        GoogleMap(
          initialCameraPosition: CameraPosition(target: widget.start, zoom: 17),
          padding: const EdgeInsets.only(bottom: cardH, top: 64),
          myLocationEnabled: true,
          myLocationButtonEnabled: true,
          zoomControlsEnabled: false,
          mapToolbarEnabled: false,
          compassEnabled: false,
          onMapCreated: (_) => _idle(),
          onCameraMoveStarted: () => setState(() { looking = true; }),
          onCameraMove: (p) => center = p.target,
          onCameraIdle: _idle,
        ),
        Positioned(left: 0, right: 0, top: 64, bottom: cardH,
            child: IgnorePointer(child: Center(child: Transform.translate(offset: const Offset(0, -26), child: const _Pin())))),
        SafeArea(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(12, 10, 12, 0),
            child: Column(children: [
              Row(children: [
                _roundBtn(Icons.arrow_back, () => Navigator.of(context).pop(), label: 'Back'),
                const SizedBox(width: 8),
                Expanded(
                  child: Material(
                    color: Colors.white,
                    elevation: 3,
                    borderRadius: BorderRadius.circular(23),
                    child: InkWell(
                      borderRadius: BorderRadius.circular(23),
                      onTap: () => Navigator.of(context).pop(<String, dynamic>{'search': true}),
                      child: const SizedBox(height: 46, child: Row(children: [
                        SizedBox(width: 14), Icon(Icons.search, color: Color(0xFF5F6368)), SizedBox(width: 8),
                        Text('Search a place', style: TextStyle(color: Color(0xFF5F6368), fontSize: 15)),
                      ])),
                    ),
                  ),
                ),
              ]),
              const SizedBox(height: 10),
              _chip('Move the map to set the $label'),
            ]),
          ),
        ),
        Positioned(
          left: 0, right: 0, bottom: 0, height: cardH,
          child: Container(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 12),
            decoration: const BoxDecoration(color: Colors.white, borderRadius: BorderRadius.vertical(top: Radius.circular(22)),
                boxShadow: [BoxShadow(color: Color(0x22000000), blurRadius: 14, offset: Offset(0, -4))]),
            child: SafeArea(
              top: false,
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(widget.title, style: const TextStyle(fontSize: 12.5, color: Color(0xFF5F6368))),
                const SizedBox(height: 2),
                Text(looking ? 'Finding the address…' : name, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700, color: _ink)),
                Text(looking ? ' ' : sub, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 13, color: Color(0xFF5F6368))),
                const Spacer(),
                FilledButton(
                  style: _primary(),
                  onPressed: looking ? null : () => Navigator.of(context).pop(<String, dynamic>{'lat': center.latitude, 'lng': center.longitude, 'name': name, 'sub': sub}),
                  child: Text('Confirm $label'),
                ),
              ]),
            ),
          ),
        ),
      ]),
    );
  }
}

// ------------------------------------------------------------------ live trip / request on the map
class TrackScreen extends StatefulWidget {
  const TrackScreen({super.key, required this.bridge, required this.first});
  final GBridge bridge;
  final Map<String, dynamic> first;
  @override
  State<TrackScreen> createState() => _TrackScreenState();
}

class _TrackScreenState extends State<TrackScreen> {
  GoogleMapController? map;
  StreamSubscription<Map<String, dynamic>>? sub;
  Map<String, dynamic> s = {};
  Set<Marker> markers = {};
  Set<Polyline> lines = {};
  int pinCount = -1;

  @override
  void initState() {
    super.initState();
    sub = widget.bridge._updates.stream.listen(_apply);
    _apply(widget.first);
  }

  @override
  void dispose() {
    sub?.cancel();
    super.dispose();
  }

  List<LatLng> _pts(dynamic l) => (l is List ? l : const [])
      .whereType<List>()
      .where((p) => p.length >= 2)
      .map((p) => LatLng(GBridge._num(p[0], 0), GBridge._num(p[1], 0)))
      .toList();

  Future<void> _apply(Map<String, dynamic> m) async {
    final pins = (m['pins'] is List ? m['pins'] as List : const []).whereType<Map>().toList();
    final mk = <Marker>{};
    var i = 0;
    final all = <LatLng>[];
    for (final p in pins) {
      final pos = LatLng(GBridge._num(p['lat'], 0), GBridge._num(p['lng'], 0));
      all.add(pos);
      final k = '${p['kind'] ?? ''}';
      final BitmapDescriptor ic;
      if (k == 'car') {
        ic = await _dot('car', _ink, icon: Icons.directions_car, size: 38);
      } else if (k == 'pickup' || k == 'from') {
        ic = await _dot('a', _ink, text: 'A');
      } else if (k == 'flag' || k == 'to') {
        ic = await _dot('b', _red, text: 'B');
      } else {
        ic = await _dot('p', _red, text: '•', size: 26);
      }
      mk.add(Marker(markerId: MarkerId('p${i++}'), position: pos, icon: ic, anchor: const Offset(0.5, 0.5), zIndex: k == 'car' ? 3.0 : 1.0));
    }
    final ls = <Polyline>{};
    var j = 0;
    for (final l in (m['lines'] is List ? m['lines'] as List : const [])) {
      final pts = _pts(l);
      if (pts.length < 2) continue;
      all.addAll(pts);
      ls.add(Polyline(polylineId: PolylineId('l${j++}'), points: pts, color: _red, width: 6, jointType: JointType.round, startCap: Cap.roundCap, endCap: Cap.roundCap));
    }
    if (!mounted) return;
    setState(() { s = m; markers = mk; lines = ls; });
    if (pins.length != pinCount && all.isNotEmpty) {
      pinCount = pins.length;
      _fit(all);
    }
  }

  Future<void> _fit(List<LatLng> all) async {
    final c = map;
    if (c == null) return;
    if (all.length == 1) {
      await c.animateCamera(CameraUpdate.newLatLngZoom(all.first, 15));
      return;
    }
    var s0 = all.first.latitude, n0 = s0, w0 = all.first.longitude, e0 = w0;
    for (final p in all) {
      s0 = math.min(s0, p.latitude); n0 = math.max(n0, p.latitude);
      w0 = math.min(w0, p.longitude); e0 = math.max(e0, p.longitude);
    }
    if ((n0 - s0).abs() < 0.0005 && (e0 - w0).abs() < 0.0005) {
      await c.animateCamera(CameraUpdate.newLatLngZoom(all.first, 16));
      return;
    }
    try {
      await c.animateCamera(CameraUpdate.newLatLngBounds(LatLngBounds(southwest: LatLng(s0, w0), northeast: LatLng(n0, e0)), 70));
    } catch (_) {}
  }

  @override
  Widget build(BuildContext context) {
    final buttons = (s['buttons'] is List ? s['buttons'] as List : const []).map((b) => '$b').where((b) => b.trim().isNotEmpty).take(1).toList();
    final chip = '${s['chip'] ?? ''}'.trim();
    final title = '${s['title'] ?? 'Map'}'.trim();
    final subtitle = '${s['sub'] ?? ''}'.trim();
    return Scaffold(
      body: Stack(children: [
        GoogleMap(
          initialCameraPosition: CameraPosition(target: markers.isNotEmpty ? markers.first.position : const LatLng(34.0086, 71.5249), zoom: 14),
          padding: const EdgeInsets.only(bottom: 150, top: 110),
          myLocationEnabled: true,
          myLocationButtonEnabled: false,
          zoomControlsEnabled: false,
          mapToolbarEnabled: false,
          compassEnabled: false,
          markers: markers,
          polylines: lines,
          onMapCreated: (c) {
            map = c;
            final all = [...markers.map((m) => m.position), ...lines.expand((l) => l.points)];
            if (all.isNotEmpty) Future.delayed(const Duration(milliseconds: 300), () => _fit(all));
          },
        ),
        SafeArea(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(12, 10, 12, 0),
            child: Column(children: [
              Row(children: [
                _roundBtn(Icons.arrow_back, () => Navigator.of(context).pop(), label: 'Back'),
                const SizedBox(width: 8),
                Expanded(
                  child: Container(
                    height: 46,
                    alignment: Alignment.center,
                    padding: const EdgeInsets.symmetric(horizontal: 12),
                    decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(23), boxShadow: const [BoxShadow(color: Color(0x33000000), blurRadius: 6)]),
                    child: Text(title, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15, color: _ink)),
                  ),
                ),
              ]),
              if (chip.isNotEmpty) ...[const SizedBox(height: 10), _chip(chip)],
            ]),
          ),
        ),
        Positioned(
          left: 0, right: 0, bottom: 0,
          child: Container(
            padding: const EdgeInsets.fromLTRB(16, 14, 16, 12),
            decoration: const BoxDecoration(color: Colors.white, borderRadius: BorderRadius.vertical(top: Radius.circular(22)),
                boxShadow: [BoxShadow(color: Color(0x22000000), blurRadius: 14, offset: Offset(0, -4))]),
            child: SafeArea(
              top: false,
              child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
                if (subtitle.isNotEmpty) Padding(padding: const EdgeInsets.only(bottom: 10), child: Text(subtitle, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 14, color: _ink))),
                Row(children: [
                  Expanded(child: FilledButton(style: buttons.isEmpty ? _primary() : _ghost(), onPressed: () => Navigator.of(context).pop(), child: const Text('Back'))),
                  if (buttons.isNotEmpty) ...[
                    const SizedBox(width: 8),
                    Expanded(child: FilledButton(style: _primary(), onPressed: () => Navigator.of(context).pop(0), child: Text(buttons.first, maxLines: 1, overflow: TextOverflow.ellipsis))),
                  ],
                ]),
              ]),
            ),
          ),
        ),
      ]),
    );
  }
}
